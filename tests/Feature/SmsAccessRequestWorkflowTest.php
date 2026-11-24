<?php

namespace Tests\Feature;

use App\Models\FeatureEntitlement;
use App\Models\Organization;
use App\Models\OrganizationFeatureRequest;
use App\Models\User;
use App\Services\BaseSubscriptionService;
use App\Services\FeatureEntitlementService;
use App\Services\SmsAccessRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SmsAccessRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $role = 'owner'): User
    {
        $organization = Organization::create(['name' => 'Tenant '.uniqid(), 'type' => 'school']);

        return User::factory()->create(['organization_id' => $organization->id, 'role' => $role]);
    }

    private function admin(): User
    {
        return User::factory()->create(['organization_id' => null, 'role' => User::ROLE_SUPER_ADMIN]);
    }

    public function test_owner_and_admin_can_request_but_operational_roles_cannot(): void
    {
        foreach (['owner', 'admin'] as $role) {
            $user = $this->tenant($role);
            $this->actingAs($user)->postJson('/api/organization/sms/access/request', self::SMS_ANSWERS)->assertCreated()->assertJsonPath('request.status', 'pending');
            $this->assertDatabaseHas('organization_feature_requests', ['organization_id' => $user->organization_id, 'requested_by' => $user->id, 'status' => 'pending']);
        }

        foreach (['supervisor', 'scanner'] as $role) {
            $user = $this->tenant($role);
            $this->actingAs($user)->postJson('/api/organization/sms/access/request', self::SMS_ANSWERS)->assertForbidden();
        }
    }

    public function test_requests_are_idempotent_active_access_is_blocked_and_expired_access_can_request_again(): void
    {
        $user = $this->tenant();
        $first = $this->actingAs($user)->postJson('/api/organization/sms/access/request', self::SMS_ANSWERS)->assertCreated()->json('request.id');
        $second = $this->actingAs($user)->postJson('/api/organization/sms/access/request', self::SMS_ANSWERS)->assertOk()->json('request.id');
        $this->assertSame($first, $second);
        $this->assertDatabaseCount('organization_feature_requests', 1);

        FeatureEntitlement::create(['organization_id' => $user->organization_id, 'feature' => 'sms', 'enabled' => true, 'expires_at' => '2026-09-01']);
        $this->actingAs($user)->postJson('/api/organization/sms/access/request', self::SMS_ANSWERS)->assertOk();
        $this->assertDatabaseCount('organization_feature_requests', 1);

        OrganizationFeatureRequest::whereKey($first)->update(['status' => 'rejected']);
        $this->actingAs($user)->postJson('/api/organization/sms/access/request', self::SMS_ANSWERS)->assertCreated();
        $this->assertDatabaseCount('organization_feature_requests', 2);
        FeatureEntitlement::where('organization_id', $user->organization_id)->update(['expires_at' => '2026-12-31']);
        OrganizationFeatureRequest::where('organization_id', $user->organization_id)->where('status', 'pending')->update(['status' => 'approved']);
        $this->actingAs($user)->postJson('/api/organization/sms/access/request', self::SMS_ANSWERS)->assertStatus(409);
    }

    public function test_super_admin_can_approve_and_activate_in_one_workflow(): void
    {
        $user = $this->tenant();
        $requestId = $this->actingAs($user)->postJson('/api/organization/sms/access/request', self::SMS_ANSWERS)->json('request.id');
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/super-admin/sms-requests/'.$requestId.'/approve', [
            'amount' => '4000.00', 'reference_number' => 'GCASH-TEST-001', 'paid_at' => '2026-09-30 10:00:00',
            'starts_at' => '2026-10-01', 'expires_at' => '2026-10-31', 'coverage_start' => '2026-10-01', 'coverage_end' => '2026-10-31',
        ])->assertOk()->assertJsonPath('request.status', 'approved');

        $request = OrganizationFeatureRequest::findOrFail($requestId);
        $this->assertSame($admin->id, $request->reviewed_by);
        $this->assertNotNull($request->reviewed_at);
        $this->assertDatabaseHas('payments', ['organization_id' => $user->organization_id, 'amount_minor' => 400000, 'payment_method' => 'gcash', 'reference_number' => 'GCASH-TEST-001', 'recorded_by' => $admin->id]);
        Carbon::setTestNow('2026-10-15');
        $this->assertTrue($user->organization->featureEntitlements()->where('feature', FeatureEntitlement::SMS)->where('enabled', true)->exists());
        Carbon::setTestNow('2026-11-01');
        $this->assertFalse(app(FeatureEntitlementService::class)->canUseSms($user->organization->fresh(), now()));
        Carbon::setTestNow();
    }

    public function test_sms_quote_then_combined_bill_payment_activates_sms(): void
    {
        $user = $this->tenant();
        app(BaseSubscriptionService::class)->createTrial($user->organization);
        $requestId = $this->actingAs($user)->postJson('/api/organization/sms/access/request', self::SMS_ANSWERS)->json('request.id');
        $admin = $this->admin();

        $this->actingAs($admin)->putJson('/api/super-admin/sms-requests/'.$requestId.'/pricing', [
            'sms_monthly_price' => '499.00', 'effective_from' => now()->toDateString(),
        ])->assertOk()->assertJsonPath('monthly_total_minor', 349800);
        $this->actingAs($admin)->postJson('/api/super-admin/sms-requests/'.$requestId.'/send-quote', [])->assertOk()->assertJsonPath('request.quote_sent_at', fn ($value) => $value !== null);
        $this->actingAs($user)->getJson('/api/organization/sms/access')->assertJsonPath('sms.pricing.sms_monthly_price_minor', 49900)->assertJsonPath('sms.quote_sent_at', fn ($value) => $value !== null);

        $billing = $this->actingAs($user)->getJson('/api/billing')->assertOk()->json();
        $this->assertSame(349800, $billing['current_bill']['total_minor']);
        $this->assertCount(2, $billing['current_bill']['items']);
        $paymentId = $this->actingAs($user)->postJson('/api/billing/payments', [
            'amount' => '3498.00', 'bill_id' => $billing['current_bill']['id'], 'reference_number' => 'GCASH-COMBINED-001', 'paid_at' => now()->toDateString(),
        ])->assertCreated()->json('payment.id');

        $this->actingAs($admin)->postJson('/api/super-admin/billing/payments/'.$paymentId.'/approve')->assertOk();
        $this->assertTrue($user->organization->featureEntitlements()->where('feature', FeatureEntitlement::SMS)->where('enabled', true)->exists());
    }

    public function test_immediate_sms_request_starts_subscription_and_sms_on_payment_approval(): void
    {
        $user = $this->tenant();
        app(BaseSubscriptionService::class)->createTrial($user->organization);
        $admin = $this->admin();
        $requestId = $this->actingAs($user)->postJson('/api/organization/sms/access/request', ['activation_mode' => 'immediate', ...self::SMS_ANSWERS])->assertCreated()->json('request.id');
        $this->assertDatabaseHas('organization_feature_requests', ['id' => $requestId, 'activation_mode' => 'immediate']);
        $this->actingAs($admin)->putJson('/api/super-admin/sms-requests/'.$requestId.'/pricing', ['sms_monthly_price' => '499.00', 'effective_from' => now()->toDateString()])->assertOk();
        $this->actingAs($admin)->postJson('/api/super-admin/sms-requests/'.$requestId.'/send-quote')->assertOk();
        $billing = $this->actingAs($user)->getJson('/api/billing')->json();
        $paymentId = $this->actingAs($user)->postJson('/api/billing/payments', ['amount' => '3498.00', 'bill_id' => $billing['current_bill']['id'], 'reference_number' => 'GCASH-IMMEDIATE-001', 'paid_at' => now()->toDateString()])->assertCreated()->json('payment.id');

        $this->actingAs($admin)->postJson('/api/super-admin/billing/payments/'.$paymentId.'/approve')->assertOk();
        $subscription = $user->organization->baseSubscription()->firstOrFail();
        $entitlement = $user->organization->featureEntitlements()->where('feature', FeatureEntitlement::SMS)->firstOrFail();
        $this->assertSame(now()->toDateString(), $subscription->subscription_starts_at->toDateString());
        $this->assertSame(now()->toDateString(), $entitlement->starts_at->toDateString());
        $this->assertTrue(app(FeatureEntitlementService::class)->canUseSms($user->organization->fresh(), now()));
        $this->assertDatabaseHas('organization_feature_requests', ['id' => $requestId, 'status' => 'approved']);
    }

    public function test_rejection_preserves_history_and_allows_request_again(): void
    {
        $user = $this->tenant();
        $requestId = $this->actingAs($user)->postJson('/api/organization/sms/access/request', self::SMS_ANSWERS)->json('request.id');
        $admin = $this->admin();
        $this->actingAs($admin)->postJson('/api/super-admin/sms-requests/'.$requestId.'/reject', ['reason' => 'Payment not received.'])->assertOk()->assertJsonPath('request.status', 'rejected');
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('feature_entitlements', 0);
        $this->actingAs($user)->getJson('/api/organization/sms/access')->assertOk()->assertJsonPath('sms.request_status', 'rejected')->assertJsonPath('sms.request_reason', 'Payment not received.');
        $this->actingAs($user)->postJson('/api/organization/sms/access/request', self::SMS_ANSWERS)->assertCreated();
        $this->assertDatabaseCount('organization_feature_requests', 2);
    }

    public function test_tenants_cannot_review_or_access_other_organizations_requests(): void
    {
        $userA = $this->tenant();
        $userB = $this->tenant();
        $requestId = $this->actingAs($userA)->postJson('/api/organization/sms/access/request', self::SMS_ANSWERS)->json('request.id');
        $this->actingAs($userB)->getJson('/api/super-admin/sms-requests/'.$requestId)->assertForbidden();
        $this->actingAs($userB)->postJson('/api/super-admin/sms-requests/'.$requestId.'/approve', [])->assertForbidden();
        $this->actingAs($this->admin())->getJson('/api/super-admin/sms-requests?status=pending')->assertOk()->assertJsonPath('data.0.id', $requestId);
    }

    public function test_approval_rolls_back_payment_when_entitlement_activation_fails(): void
    {
        $user = $this->tenant();
        $request = OrganizationFeatureRequest::create(['organization_id' => $user->organization_id, 'feature' => 'sms', 'status' => 'pending', 'requested_by' => $user->id, 'requested_at' => now()]);
        $admin = $this->admin();
        $this->mock(FeatureEntitlementService::class, function ($mock): void {
            $mock->shouldReceive('enableSms')->once()->andThrow(new \RuntimeException('simulated activation failure'));
        });

        $this->expectException(\RuntimeException::class);
        try {
            app(SmsAccessRequestService::class)->approve($request, $admin, ['amount_minor' => 400000, 'reference_number' => 'FAIL', 'paid_at' => now(), 'starts_at' => '2026-10-01', 'expires_at' => '2026-10-31']);
        } finally {
            $this->assertDatabaseCount('payments', 0);
            $this->assertDatabaseHas('organization_feature_requests', ['id' => $request->id, 'status' => 'pending']);
        }
    }
}

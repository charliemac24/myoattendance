<?php

namespace Tests\Feature;

use App\Models\BaseSubscription;
use App\Models\Bill;
use App\Models\Organization;
use App\Models\OrganizationFeatureRequest;
use App\Models\Payment;
use App\Models\SmsPricingAgreement;
use App\Models\User;
use App\Services\FeatureEntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BillingEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private const SMS_PRICE_MINOR = 49900;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-10 02:00:00');
        Http::fake();
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        parent::tearDown();
    }

    private function owner(string $state = 'trial', bool $price = false): User
    {
        $organization = Organization::create(['name' => 'E2E '.uniqid(), 'type' => 'school', 'timezone' => 'Asia/Manila']);
        $owner = User::factory()->create(['organization_id' => $organization->id, 'role' => 'owner']);
        if ($state === 'trial') {
            BaseSubscription::create(['organization_id' => $organization->id, 'trial_starts_at' => now()->subDays(20), 'trial_ends_at' => now()->addDays(10), 'status' => 'trialing']);
        } elseif ($state === 'active') {
            BaseSubscription::create(['organization_id' => $organization->id, 'trial_ends_at' => now()->subDays(5), 'subscription_starts_at' => now()->subDays(5), 'subscription_expires_at' => now()->addDays(25), 'status' => 'active']);
        } else {
            BaseSubscription::create(['organization_id' => $organization->id, 'trial_ends_at' => now()->subDay(), 'status' => 'expired']);
        }
        if ($price) {
            SmsPricingAgreement::create(['organization_id' => $organization->id, 'sms_monthly_price_minor' => self::SMS_PRICE_MINOR, 'person_count_snapshot' => 50, 'effective_from' => '2026-11-01', 'status' => SmsPricingAgreement::STATUS_ACTIVE, 'created_by' => $owner->id, 'agreed_at' => now()]);
        }

        return $owner;
    }

    private function admin(): User
    {
        return User::factory()->create(['organization_id' => null, 'role' => User::ROLE_SUPER_ADMIN]);
    }

    private function bill(User $owner, string $type = Bill::TYPE_BASE, int $months = 1): array
    {
        return $this->actingAs($owner)->getJson('/api/billing?type='.$type.'&months='.$months)->assertOk()->json('current_bill');
    }

    private function pay(User $owner, array $bill, string $reference): int
    {
        return $this->actingAs($owner)->postJson('/api/billing/payments', ['amount' => number_format($bill['total_minor'] / 100, 2, '.', ''), 'bill_id' => $bill['id'], 'reference_number' => $reference, 'paid_at' => '2026-11-10'])->assertCreated()->json('payment.id');
    }

    private function approve(int $payment): void
    {
        $this->actingAs($this->admin())->postJson('/api/super-admin/billing/payments/'.$payment.'/approve')->assertOk();
    }

    public function test_trial_flow_and_start_choice_are_visible_to_school_and_admin(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->getJson('/api/billing')->assertOk()->assertJsonPath('current_plan.label', 'Free trial')->assertJsonPath('current_plan.status', 'trialing')->assertJsonPath('current_plan.in_trial', true)->assertJsonPath('current_plan.sms.state', 'off')->assertJsonPath('bill_type', 'base')->assertJsonPath('current_bill.billing_period_start', '2026-11-20T00:00:00.000000Z');
        $this->actingAs($owner)->get('/scanner')->assertOk();
        $this->actingAs($owner)->postJson('/api/billing/start-choice', ['choice' => 'immediate'])->assertOk();
        $this->assertSame('2026-11-10', substr($this->bill($owner)['billing_period_start'], 0, 10));
        $this->actingAs($owner)->postJson('/api/billing/start-choice', ['choice' => 'tomorrow'])->assertStatus(422)->assertJsonValidationErrors('choice');
        $request = $this->actingAs($owner)->postJson('/api/organization/sms/access/request')->assertCreated()->json('request');
        $this->assertSame(OrganizationFeatureRequest::ACTIVATION_IMMEDIATE, $request['activation_mode']);
        $this->actingAs($this->admin())->getJson('/api/super-admin/billing/payments')->assertOk();
    }

    public function test_after_trial_base_payment_is_reviewed_and_approved_without_sms(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->postJson('/api/billing/start-choice', ['choice' => 'after_trial'])->assertOk();
        $bill = $this->bill($owner);
        $payment = $this->pay($owner, $bill, 'E2E-BASE');
        $this->actingAs($owner)->postJson('/api/billing/payments', ['amount' => '2999.00', 'bill_id' => $bill['id'], 'reference_number' => 'E2E-BASE-2', 'paid_at' => '2026-11-10'])->assertStatus(422)->assertJsonPath('message', 'A payment for this bill is already under review.');
        $this->actingAs($this->admin())->getJson('/api/super-admin/billing/payments/'.$payment)->assertOk()->assertJsonPath('sms_price_set', false);
        $this->approve($payment);
        $owner->refresh();
        $this->assertSame('2026-11-20', $owner->organization->baseSubscription->subscription_starts_at->toDateString());
        $this->assertDatabaseHas('payments', ['id' => $payment, 'status' => Payment::STATUS_APPROVED]);
        $this->assertFalse(app(FeatureEntitlementService::class)->canUseSms($owner->organization->fresh()));
    }

    public function test_admin_sees_after_trial_choice_and_approval_converts_trial_to_base_at_trial_end(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->postJson('/api/billing/start-choice', ['choice' => 'after_trial'])->assertOk();

        $schoolBilling = $this->actingAs($owner)->getJson('/api/billing?type=base')->assertOk();
        $schoolBilling->assertJsonPath('current_plan.start_choice', 'after_trial')
            ->assertJsonPath('current_bill.billing_period_start', '2026-11-20T00:00:00.000000Z')
            ->assertJsonPath('plan_change.message', 'Starts paid Base on Nov 20, 2026, when your free trial ends (you chose "After my free trial ends"). Covers Nov 20, 2026 to Dec 19, 2026 (1 month).');

        $payment = $this->pay($owner, $schoolBilling->json('current_bill'), 'AFTER-TRIAL-ADMIN-VIEW');
        $this->actingAs($this->admin())->getJson('/api/super-admin/billing/payments/'.$payment)->assertOk()
            ->assertJsonPath('plan_change.message', "Starts paid Base on Nov 20, 2026, when the school's free trial ends (the school chose \"After my free trial ends\"). Covers Nov 20, 2026 to Dec 19, 2026 (1 month).")
            ->assertJsonPath('sms_price_set', false);

        $this->approve($payment);
        $owner->refresh();
        $this->assertSame('2026-11-20', $owner->organization->baseSubscription->subscription_starts_at->toDateString());

        $this->travelTo('2026-11-20 02:00:00');
        $owner->refresh();
        $this->actingAs($owner)->getJson('/api/billing')->assertOk()
            ->assertJsonPath('current_plan.label', 'Base')
            ->assertJsonPath('current_plan.status', 'active')
            ->assertJsonPath('current_plan.in_trial', false)
            ->assertJsonPath('current_plan.sms.state', 'off');
        $this->actingAs($owner)->get('/scanner')->assertOk();
    }

    public function test_immediate_base_payment_rejection_can_be_resubmitted_and_approved(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->postJson('/api/billing/start-choice', ['choice' => 'immediate'])->assertOk();
        $bill = $this->bill($owner);
        $this->actingAs($owner)->postJson('/api/billing/payments', ['amount' => '2000.00', 'bill_id' => $bill['id'], 'reference_number' => 'BAD', 'paid_at' => '2026-11-10'])->assertStatus(422)->assertJsonPath('message', 'The amount must match the bill total of PHP 2,999.00. Refresh the page and try again.');
        $payment = $this->pay($owner, $bill, 'REUSE-ME');
        $this->actingAs($this->admin())->postJson('/api/super-admin/billing/payments/'.$payment.'/reject')->assertStatus(422);
        $this->actingAs($this->admin())->postJson('/api/super-admin/billing/payments/'.$payment.'/reject', ['reason' => 'Reference not found'])->assertOk();
        $retry = $this->pay($owner, $bill, 'REUSE-ME');
        $this->assertNotSame($payment, $retry);
    }

    public function test_base_plus_sms_price_change_and_approval_cover_both_features(): void
    {
        $owner = $this->owner(price: false);
        $this->actingAs($owner)->postJson('/api/organization/sms/access/request')->assertCreated();
        $this->actingAs($this->admin())->putJson('/api/super-admin/organizations/'.$owner->organization_id.'/sms-pricing', ['sms_monthly_price' => '499'])->assertOk();
        $bill = $this->bill($owner, Bill::TYPE_PACKAGE);
        $this->assertSame(349800, $bill['total_minor']);
        $payment = $this->pay($owner, $bill, 'E2E-PACKAGE');
        $this->approve($payment);
        $owner->refresh();
        $this->travelTo('2026-11-21 02:00:00');
        $this->assertTrue(app(FeatureEntitlementService::class)->canUseSms($owner->organization->fresh()));
        $this->actingAs($owner)->getJson('/api/billing')->assertJsonPath('current_plan.label', 'Base + SMS')->assertJsonPath('current_plan.sms.state', 'on');
    }

    public function test_expired_school_can_renew_and_restore_scanner(): void
    {
        $owner = $this->owner('expired');
        $this->actingAs($owner)->get('/scanner')->assertRedirect('/billing');
        $payment = $this->pay($owner, $this->bill($owner), 'E2E-EXPIRED');
        $this->approve($payment);
        $owner->refresh();
        $this->assertSame('2026-11-10', $owner->organization->baseSubscription->subscription_starts_at->toDateString());
        $this->actingAs($owner)->get('/scanner')->assertOk();
    }
}

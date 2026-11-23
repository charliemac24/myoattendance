<?php

namespace Tests\Feature;

use App\Models\BaseSubscription;
use App\Models\Bill;
use App\Models\Organization;
use App\Models\OrganizationFeatureRequest;
use App\Models\Payment;
use App\Models\SmsPricingAgreement;
use App\Models\User;
use App\Services\BaseSubscriptionService;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BaseSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_owner_is_redirected_to_billing_from_scanner(): void
    {
        $organization = Organization::create(['name' => 'Expired Scanner Org', 'type' => 'company']);
        $owner = User::factory()->create(['organization_id' => $organization->id, 'role' => 'owner']);
        BaseSubscription::create(['organization_id' => $organization->id, 'trial_ends_at' => now()->subDay(), 'status' => BaseSubscription::STATUS_EXPIRED]);

        $this->actingAs($owner)->get('/scanner')
            ->assertRedirect('/billing')
            ->assertSessionHas('subscription_expired', true);
    }

    public function test_expired_renewal_can_include_or_exclude_the_sms_add_on(): void
    {
        $organization = Organization::create(['name' => 'Renewal Choice Org', 'type' => 'company']);
        $owner = User::factory()->create(['organization_id' => $organization->id, 'role' => 'owner']);
        BaseSubscription::create(['organization_id' => $organization->id, 'trial_ends_at' => now()->subDay(), 'status' => BaseSubscription::STATUS_EXPIRED]);
        SmsPricingAgreement::create([
            'organization_id' => $organization->id,
            'sms_monthly_price_minor' => 49900,
            'person_count_snapshot' => 10,
            'effective_from' => now()->toDateString(),
            'status' => SmsPricingAgreement::STATUS_ACTIVE,
            'created_by' => $owner->id,
            'agreed_at' => now(),
        ]);

        $baseOnly = app(BillingService::class)->currentBill($organization, false);
        $this->assertFalse($baseOnly->fresh()->sms_included);
        $this->assertSame(299900, $baseOnly->fresh()->total_minor);

        $withSms = app(BillingService::class)->currentBill($organization, true);
        $this->assertTrue($withSms->fresh()->sms_included);
        $this->assertSame(349800, $withSms->fresh()->total_minor);
        $this->assertTrue($withSms->items()->where('type', 'sms_addon')->exists());
    }

    public function test_registration_creates_exact_thirty_day_trial(): void
    {
        $this->travelTo('2026-09-18 10:00:00');
        $this->postJson('/api/auth/register', [
            'name' => 'Owner', 'organization_name' => 'Trial Org', 'organization_type' => 'company',
            'username' => 'trial.owner', 'email' => 'trial@example.test', 'contact_number' => '09171234567', 'password' => 'password', 'password_confirmation' => 'password',
        ])->assertCreated();

        $trial = BaseSubscription::firstOrFail();
        $this->assertSame('2026-09-18 10:00:00', $trial->trial_starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-18 10:00:00', $trial->trial_ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertTrue(app(BaseSubscriptionService::class)->canUseScanner($trial->organization));
    }

    public function test_expired_subscription_can_submit_payment_but_is_not_activated_until_review(): void
    {
        Storage::fake('local');
        $organization = Organization::create(['name' => 'Expired Org', 'type' => 'company']);
        $owner = User::factory()->create(['organization_id' => $organization->id, 'role' => 'owner']);
        BaseSubscription::create(['organization_id' => $organization->id, 'trial_ends_at' => now()->subDay(), 'status' => 'expired']);
        $bill = app(BillingService::class)->currentBill($organization);

        $response = $this->actingAs($owner)->post('/api/billing/payments', [
            'amount' => '2999', 'bill_id' => $bill->id, 'reference_number' => 'GCASH-001', 'paid_at' => '2026-09-18', 'proof' => UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'),
        ]);
        $response->assertCreated()->assertJsonPath('payment.status', Payment::STATUS_PENDING);
        $this->assertTrue(Storage::disk('local')->allFiles('payment-proofs') !== []);
        $this->assertSame('expired', app(BaseSubscriptionService::class)->statusFor($organization));
    }

    public function test_super_admin_approval_extends_base_subscription_transactionally(): void
    {
        $organization = Organization::create(['name' => 'Review Org', 'type' => 'company']);
        $owner = User::factory()->create(['organization_id' => $organization->id, 'role' => 'owner']);
        $admin = User::factory()->create(['organization_id' => null, 'role' => User::ROLE_SUPER_ADMIN]);
        BaseSubscription::create(['organization_id' => $organization->id, 'status' => 'expired']);
        $payment = Payment::create(['organization_id' => $organization->id, 'payment_type' => Payment::TYPE_BASE_SUBSCRIPTION, 'status' => Payment::STATUS_PENDING, 'amount_minor' => 299900, 'currency' => 'PHP', 'payment_method' => 'gcash', 'reference_number' => 'GCASH-002', 'paid_at' => now(), 'submitted_by' => $owner->id]);

        $this->actingAs($admin)->postJson('/api/super-admin/billing/payments/'.$payment->id.'/approve')->assertOk();
        $this->assertSame(Payment::STATUS_APPROVED, $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->coverage_start);
        $this->assertNotNull($payment->fresh()->coverage_end);
        $this->assertSame(BaseSubscription::STATUS_ACTIVE, app(BaseSubscriptionService::class)->statusFor($organization->fresh()));
        $this->assertTrue($organization->baseSubscription()->firstOrFail()->subscription_expires_at->isFuture());
    }

    public function test_second_approval_does_not_extend_the_same_payment_again(): void
    {
        $organization = Organization::create(['name' => 'Idempotent Org', 'type' => 'company']);
        $owner = User::factory()->create(['organization_id' => $organization->id, 'role' => 'owner']);
        $admin = User::factory()->create(['organization_id' => null, 'role' => User::ROLE_SUPER_ADMIN]);
        BaseSubscription::create(['organization_id' => $organization->id, 'status' => 'expired']);
        $payment = Payment::create(['organization_id' => $organization->id, 'payment_type' => Payment::TYPE_BASE_SUBSCRIPTION, 'status' => Payment::STATUS_PENDING, 'amount_minor' => 299900, 'currency' => 'PHP', 'payment_method' => 'gcash', 'reference_number' => 'GCASH-IDEMPOTENT', 'paid_at' => now(), 'submitted_by' => $owner->id]);

        $this->actingAs($admin)->postJson('/api/super-admin/billing/payments/'.$payment->id.'/approve')->assertOk();
        $paidThrough = $organization->baseSubscription()->firstOrFail()->subscription_expires_at;
        $this->actingAs($admin)->postJson('/api/super-admin/billing/payments/'.$payment->id.'/approve')->assertStatus(409);
        $this->assertTrue($paidThrough->equalTo($organization->baseSubscription()->firstOrFail()->subscription_expires_at));
    }

    public function test_early_trial_payment_preserves_the_trial_boundary(): void
    {
        $this->travelTo('2026-09-10 10:00:00');
        $organization = Organization::create(['name' => 'Early Trial Org', 'type' => 'company']);
        $admin = User::factory()->create(['organization_id' => null, 'role' => User::ROLE_SUPER_ADMIN]);
        BaseSubscription::create(['organization_id' => $organization->id, 'trial_starts_at' => now()->subDays(22), 'trial_ends_at' => now()->addDays(8), 'status' => 'trialing']);
        $subscription = app(BaseSubscriptionService::class)->activateOneMonth($organization, $admin);

        $this->assertSame('2026-09-18', $subscription->subscription_starts_at->toDateString());
        $this->assertSame('2026-10-18', $subscription->subscription_expires_at->toDateString());
    }

    public function test_rejected_attempt_can_be_resubmitted_without_editing_history(): void
    {
        Storage::fake('local');
        $organization = Organization::create(['name' => 'Recovery Org', 'type' => 'company']);
        $owner = User::factory()->create(['organization_id' => $organization->id, 'role' => 'owner']);
        $admin = User::factory()->create(['organization_id' => null, 'role' => User::ROLE_SUPER_ADMIN]);
        BaseSubscription::create(['organization_id' => $organization->id, 'status' => 'expired']);
        $bill = app(BillingService::class)->currentBill($organization);

        $first = $this->actingAs($owner)->post('/api/billing/payments', ['amount' => '2999', 'bill_id' => $bill->id, 'reference_number' => 'RECOVER-001', 'paid_at' => '2026-09-18', 'proof' => UploadedFile::fake()->create('wrong.pdf', 10, 'application/pdf')])->assertCreated()->json('payment');
        $this->actingAs($admin)->postJson('/api/super-admin/billing/payments/'.$first['id'].'/reject', ['reason' => 'Unable to verify proof.'])->assertOk();

        $second = $this->actingAs($owner)->post('/api/billing/payments', ['amount' => '2999', 'bill_id' => $bill->id, 'reference_number' => 'RECOVER-001', 'paid_at' => '2026-09-18', 'proof' => UploadedFile::fake()->create('correct.pdf', 10, 'application/pdf')])->assertCreated()->json('payment');

        $this->assertSame(Payment::STATUS_REJECTED, Payment::findOrFail($first['id'])->status);
        $this->assertSame(Payment::STATUS_PENDING, Payment::findOrFail($second['id'])->status);
        $this->assertSame(Bill::STATUS_PAYMENT_PENDING, $bill->fresh()->status);
    }

    public function test_after_a_bill_is_paid_the_next_bill_covers_the_following_period(): void
    {
        $organization = Organization::create(['name' => 'Paid Bill Org', 'type' => 'company']);
        $owner = User::factory()->create(['organization_id' => $organization->id, 'role' => 'owner']);
        $admin = User::factory()->create(['organization_id' => null, 'role' => User::ROLE_SUPER_ADMIN]);
        BaseSubscription::create(['organization_id' => $organization->id, 'status' => 'expired']);
        $bill = app(BillingService::class)->currentBill($organization->fresh());
        $payment = Payment::create(['organization_id' => $organization->id, 'bill_id' => $bill->id, 'payment_type' => Payment::TYPE_MONTHLY_BILL, 'status' => Payment::STATUS_PENDING, 'amount_minor' => $bill->total_minor, 'currency' => 'PHP', 'payment_method' => 'gcash', 'reference_number' => 'PAID-001', 'paid_at' => now(), 'submitted_by' => $owner->id]);

        $this->actingAs($admin)->postJson('/api/super-admin/billing/payments/'.$payment->id.'/approve')->assertOk();

        $next = app(BillingService::class)->currentBill($organization->fresh());
        $this->assertNotSame($bill->id, $next->id);
        $this->assertSame(Bill::STATUS_UNPAID, $next->status);
        $this->assertSame($bill->billing_period_end->addDay()->toDateString(), $next->billing_period_start->toDateString());
        $this->assertSame($bill->id, app(BillingService::class)->latestUnexpiredPaidBill($organization->fresh(), Bill::TYPE_BASE)->id);
    }

    public function test_editing_sms_price_updates_an_unpaid_bill_snapshot(): void
    {
        $organization = Organization::create(['name' => 'Price Update Org', 'type' => 'company']);
        $owner = User::factory()->create(['organization_id' => $organization->id, 'role' => 'owner']);
        $admin = User::factory()->create(['organization_id' => null, 'role' => User::ROLE_SUPER_ADMIN]);
        app(BaseSubscriptionService::class)->createTrial($organization);
        $request = OrganizationFeatureRequest::create([
            'organization_id' => $organization->id,
            'feature' => OrganizationFeatureRequest::FEATURE_SMS,
            'status' => OrganizationFeatureRequest::STATUS_PENDING,
            'requested_by' => $owner->id,
            'requested_at' => now(),
        ]);

        $this->actingAs($admin)->putJson('/api/super-admin/sms-requests/'.$request->id.'/pricing', [
            'sms_monthly_price' => '499.00', 'effective_from' => now()->toDateString(),
        ])->assertOk();
        $this->actingAs($admin)->putJson('/api/super-admin/sms-requests/'.$request->id.'/pricing', [
            'sms_monthly_price' => '799.00', 'effective_from' => now()->toDateString(),
        ])->assertOk();

        $bill = app(BillingService::class)->currentBill($organization);
        $this->assertSame(379800, $bill->fresh('items')->total_minor);
        $this->assertSame(79900, $bill->items()->where('type', 'sms_addon')->value('amount_minor'));
    }
}

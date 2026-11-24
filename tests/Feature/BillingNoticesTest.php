<?php

namespace Tests\Feature;

use App\Models\BaseSubscription;
use App\Models\Organization;
use App\Models\OrganizationFeatureRequest;
use App\Models\SmsPricingAgreement;
use App\Models\User;
use App\Services\BillingNoticeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Notices on Home, and the count on the Billing menu item, when MYO Attendance has answered a school or gym on billing.
 */
class BillingNoticesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-10 02:00:00');
    }

    private function owner(string $type = 'school'): User
    {
        $organization = Organization::create(['name' => 'Notice Org '.uniqid(), 'type' => $type, 'timezone' => 'Asia/Manila']);
        BaseSubscription::create(['organization_id' => $organization->id, 'trial_starts_at' => now()->subDays(20), 'trial_ends_at' => now()->addDays(10), 'status' => 'trialing']);

        return User::factory()->create(['organization_id' => $organization->id, 'role' => 'owner']);
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['organization_id' => null, 'role' => User::ROLE_SUPER_ADMIN]);
    }

    private function submitBasePayment(User $owner, string $reference): int
    {
        $bill = $this->actingAs($owner)->getJson('/api/billing?type=base')->assertOk()->json('current_bill');

        return $this->actingAs($owner)->postJson('/api/billing/payments', [
            'amount' => number_format($bill['total_minor'] / 100, 2, '.', ''), 'bill_id' => $bill['id'], 'reference_number' => $reference, 'paid_at' => '2026-11-10',
        ])->assertCreated()->json('payment.id');
    }

    public function test_sms_price_set_by_super_admin_shows_on_home_until_dismissed(): void
    {
        $owner = $this->owner('fitness');
        OrganizationFeatureRequest::create(['organization_id' => $owner->organization_id, 'feature' => OrganizationFeatureRequest::FEATURE_SMS, 'status' => OrganizationFeatureRequest::STATUS_PENDING, 'requested_by' => $owner->id, 'requested_at' => now()]);
        $this->actingAs($owner)->postJson('/api/billing/sms-estimate', ['people' => 50, 'texts' => ['time_in', 'time_out'], 'days' => 'weekdays'])->assertOk();
        $this->actingAs($owner)->get('/app')->assertOk()->assertDontSee('Your SMS price is ready');

        $this->actingAs($this->superAdmin())->putJson('/api/super-admin/organizations/'.$owner->organization_id.'/sms-pricing', ['sms_monthly_price' => '1200'])->assertOk();
        $key = 'sms-price-'.SmsPricingAgreement::query()->where('organization_id', $owner->organization_id)->where('status', SmsPricingAgreement::STATUS_ACTIVE)->value('id');

        $this->actingAs($owner)->get('/app')->assertOk()
            ->assertSee('Your SMS price is ready')
            ->assertSee('₱1,200.00/month')
            ->assertSee('data-billing-badge="1"', false);

        $this->actingAs($owner)->postJson('/api/billing/notices/'.$key.'/dismiss')->assertOk()->assertJsonCount(0, 'notices');
        $this->actingAs($owner)->get('/app')->assertOk()->assertDontSee('Your SMS price is ready');
    }

    public function test_approved_payment_shows_until_billing_is_opened(): void
    {
        $owner = $this->owner();
        $payment = $this->submitBasePayment($owner, 'APPROVE-ME');
        $this->actingAs($this->superAdmin())->postJson('/api/super-admin/billing/payments/'.$payment.'/approve')->assertOk();

        $this->actingAs($owner)->get('/app')->assertOk()->assertSee('Payment approved')->assertSee('Base is active until');

        $this->actingAs($owner)->get('/billing')->assertOk();
        $this->actingAs($owner)->get('/app')->assertOk()->assertDontSee('Payment approved');
    }

    public function test_rejected_payment_shows_the_reason_until_a_new_payment_is_sent(): void
    {
        $owner = $this->owner();
        $payment = $this->submitBasePayment($owner, 'WRONG-REF');
        $this->actingAs($this->superAdmin())->postJson('/api/super-admin/billing/payments/'.$payment.'/reject', ['reason' => 'Reference not found.'])->assertOk();

        $this->actingAs($owner)->get('/app')->assertOk()->assertSee('Payment not accepted')->assertSee('Reason: Reference not found. Please check');

        $this->submitBasePayment($owner, 'RIGHT-REF');
        $this->actingAs($owner)->get('/app')->assertOk()->assertDontSee('Payment not accepted');
    }

    public function test_only_known_notice_keys_can_be_dismissed_and_staff_see_no_notices(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->postJson('/api/billing/notices/anything-else/dismiss')->assertNotFound();

        $staff = User::factory()->create(['organization_id' => $owner->organization_id, 'role' => 'staff']);
        $this->assertSame([], app(BillingNoticeService::class)->forUser($staff));
    }

    public function test_asking_for_a_new_price_shows_no_notice_until_super_admin_sets_it(): void
    {
        $owner = $this->owner();
        $admin = $this->superAdmin();
        $this->actingAs($admin)->putJson('/api/super-admin/organizations/'.$owner->organization_id.'/sms-pricing', ['sms_monthly_price' => '1200'])->assertOk();
        $this->actingAs($owner)->get('/app')->assertOk()->assertDontSee('Your SMS price is ready');

        $this->travel(1)->minutes();
        $this->actingAs($owner)->postJson('/api/organization/sms/access/request', ['people' => 80, 'texts' => ['time_in', 'time_out'], 'days' => 'weekdays'])->assertSuccessful();
        $this->actingAs($owner)->get('/app')->assertOk()->assertDontSee('Your SMS price is ready');

        $this->travel(1)->minutes();
        $this->actingAs($admin)->putJson('/api/super-admin/organizations/'.$owner->organization_id.'/sms-pricing', ['sms_monthly_price' => '1800'])->assertOk();
        $this->actingAs($owner)->get('/app')->assertOk()->assertSee('₱1,800.00/month');
    }
}

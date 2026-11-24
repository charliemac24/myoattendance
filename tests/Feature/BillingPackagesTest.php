<?php

namespace Tests\Feature;

use App\Models\BaseSubscription;
use App\Models\Bill;
use App\Models\FeatureEntitlement;
use App\Models\Organization;
use App\Models\OrganizationFeatureRequest;
use App\Models\Payment;
use App\Models\SmsPricingAgreement;
use App\Models\User;
use App\Services\BaseSubscriptionService;
use App\Services\FeatureEntitlementService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;
use Tests\TestCase;

class BillingPackagesTest extends TestCase
{
    use RefreshDatabase;

    private const SMS_PRICE_MINOR = 49900;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-10 02:00:00');
        Storage::fake('local');
    }

    private function owner(string $subscription = 'trialing', bool $smsPricing = true): User
    {
        $organization = Organization::create(['name' => 'School '.uniqid(), 'type' => 'school', 'timezone' => 'Asia/Manila']);
        $owner = User::factory()->create(['organization_id' => $organization->id, 'role' => 'owner']);
        match ($subscription) {
            'trialing' => BaseSubscription::create(['organization_id' => $organization->id, 'trial_starts_at' => now()->subDays(20), 'trial_ends_at' => now()->addDays(10), 'status' => 'trialing']),
            'active' => BaseSubscription::create(['organization_id' => $organization->id, 'subscription_starts_at' => now()->subDays(5), 'subscription_expires_at' => now()->addDays(25), 'status' => 'active']),
            'expired' => BaseSubscription::create(['organization_id' => $organization->id, 'trial_ends_at' => now()->subDay(), 'status' => 'expired']),
            'legacy' => null,
        };
        if ($smsPricing) {
            SmsPricingAgreement::create(['organization_id' => $organization->id, 'sms_monthly_price_minor' => self::SMS_PRICE_MINOR, 'person_count_snapshot' => 50, 'effective_from' => '2026-11-01', 'status' => SmsPricingAgreement::STATUS_ACTIVE, 'created_by' => $owner->id, 'agreed_at' => now()]);
        }

        return $owner;
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['organization_id' => null, 'role' => User::ROLE_SUPER_ADMIN]);
    }

    /**
     * @return array<string, mixed>
     */
    private function billFor(User $owner, string $type): array
    {
        return $this->actingAs($owner)->getJson('/api/billing?type='.$type)->assertOk()->json('current_bill');
    }

    private function pay(User $owner, array $bill, string $reference, array $overrides = []): TestResponse
    {
        return $this->actingAs($owner)->post('/api/billing/payments', array_merge([
            'amount' => number_format($bill['total_minor'] / 100, 2, '.', ''),
            'bill_id' => $bill['id'],
            'reference_number' => $reference,
            'paid_at' => '2026-11-10',
        ], $overrides), ['Accept' => 'application/json']);
    }

    private function approve(int $paymentId): TestResponse
    {
        return $this->actingAs($this->superAdmin())->postJson('/api/super-admin/billing/payments/'.$paymentId.'/approve');
    }

    public function test_each_plan_contains_only_its_own_line_items(): void
    {
        $owner = $this->owner();

        $base = $this->billFor($owner, Bill::TYPE_BASE);
        $package = $this->billFor($owner, Bill::TYPE_PACKAGE);

        $this->assertSame(['base_subscription'], array_column($base['items'], 'type'));
        $this->assertSame(BaseSubscriptionService::MONTHLY_PRICE_MINOR, $base['total_minor']);
        $this->assertEqualsCanonicalizing(['base_subscription', 'sms_addon'], array_column($package['items'], 'type'));
        $this->assertSame(BaseSubscriptionService::MONTHLY_PRICE_MINOR + self::SMS_PRICE_MINOR, $package['total_minor']);
        $this->assertNotSame($base['id'], $package['id']);
    }

    public function test_multi_month_bill_multiplies_each_line_and_spans_the_whole_period(): void
    {
        $owner = $this->owner();

        $bill = $this->actingAs($owner)->getJson('/api/billing?type=package&months=3')->assertOk()
            ->assertJsonPath('months', 3)
            ->assertJsonPath('next_period_start', '2026-11-20')
            ->json('current_bill');

        $this->assertSame(3 * (BaseSubscriptionService::MONTHLY_PRICE_MINOR + self::SMS_PRICE_MINOR), $bill['total_minor']);
        $this->assertSame([3, 3], array_column($bill['items'], 'quantity'));
        $this->assertSame('2026-11-20', substr($bill['billing_period_start'], 0, 10));
        $this->assertSame('2027-02-19', substr($bill['billing_period_end'], 0, 10));
    }

    public function test_changing_the_plan_length_replaces_the_unpaid_bill(): void
    {
        $owner = $this->owner(smsPricing: false);
        $oneMonth = $this->billFor($owner, Bill::TYPE_BASE);

        $twelve = $this->actingAs($owner)->getJson('/api/billing?type=base&months=12')->assertOk()->json('current_bill');

        $this->assertNotSame($oneMonth['id'], $twelve['id']);
        $this->assertSame(Bill::STATUS_CANCELLED, Bill::findOrFail($oneMonth['id'])->status);
        $this->assertSame(12 * BaseSubscriptionService::MONTHLY_PRICE_MINOR, $twelve['total_minor']);
        $this->actingAs($owner)->getJson('/api/billing?type=base')->assertJsonPath('current_bill.id', $twelve['id']);
    }

    public function test_unsupported_plan_length_is_a_validation_error(): void
    {
        $this->actingAs($this->owner())->getJson('/api/billing?type=base&months=2')->assertStatus(422)->assertJsonValidationErrors('months');
    }

    public function test_billing_page_reports_which_plans_are_available(): void
    {
        $this->actingAs($this->owner())->getJson('/api/billing')->assertOk()
            ->assertJsonPath('available_types', ['base' => true, 'package' => true, 'sms_now' => false])
            ->assertJsonPath('month_options', [1, 3, 6, 12]);

        $this->actingAs($this->owner(smsPricing: false))->getJson('/api/billing')->assertOk()
            ->assertJsonPath('available_types', ['base' => true, 'package' => false, 'sms_now' => false])
            ->assertJsonPath('bill_type', Bill::TYPE_BASE);
    }

    public function test_billing_without_a_type_defaults_to_the_current_plan(): void
    {
        $this->actingAs($this->owner())->getJson('/api/billing')->assertOk()
            ->assertJsonPath('bill_type', Bill::TYPE_BASE);

        $requested = $this->owner();
        OrganizationFeatureRequest::create(['organization_id' => $requested->organization_id, 'feature' => OrganizationFeatureRequest::FEATURE_SMS, 'status' => OrganizationFeatureRequest::STATUS_PENDING, 'requested_by' => $requested->id, 'requested_at' => now()]);
        $this->actingAs($requested)->getJson('/api/billing')->assertOk()
            ->assertJsonPath('bill_type', Bill::TYPE_PACKAGE)
            ->assertJsonPath('current_plan.sms.state', 'price_ready');

        $withSms = $this->owner('active');
        $this->approve($this->pay($withSms, $this->billFor($withSms, Bill::TYPE_PACKAGE), 'DEFAULT-SMS')->json('payment.id'))->assertOk();
        $this->actingAs($withSms)->getJson('/api/billing')->assertOk()
            ->assertJsonPath('bill_type', Bill::TYPE_PACKAGE);
    }

    public function test_expired_school_is_offered_the_plan_it_last_paid_for(): void
    {
        $owner = $this->owner('active');
        $this->approve($this->pay($owner, $this->billFor($owner, Bill::TYPE_PACKAGE), 'LAST-PKG')->json('payment.id'))->assertOk();

        $this->travelTo('2027-01-10 02:00:00');
        $owner->refresh();

        $this->actingAs($owner)->getJson('/api/billing')->assertOk()
            ->assertJsonPath('current_plan.label', 'Expired')
            ->assertJsonPath('current_plan.sms.state', 'available')
            ->assertJsonPath('bill_type', Bill::TYPE_PACKAGE)
            ->assertJsonPath('plan_change.kind', 'add_sms');
    }

    public function test_renewing_on_the_expiry_day_continues_from_the_exact_expiry_time(): void
    {
        $owner = $this->owner('active');
        $owner->organization->baseSubscription()->update(['subscription_expires_at' => '2026-11-10 08:00:00']);
        $owner->refresh();

        $this->approve($this->pay($owner, $this->billFor($owner, Bill::TYPE_BASE), 'EXPIRY-DAY')->json('payment.id'))->assertOk();

        $subscription = $owner->organization->baseSubscription()->firstOrFail();
        $this->assertSame('2026-11-10 08:00:00', $subscription->subscription_starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-10 08:00:00', $subscription->subscription_expires_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_renewing_after_expiry_starts_at_approval_and_unblocks_scanning(): void
    {
        $owner = $this->owner('expired', smsPricing: false);
        $paymentId = $this->pay($owner, $this->billFor($owner, Bill::TYPE_BASE), 'AFTER-EXPIRY')->json('payment.id');
        $this->actingAs($owner)->get('/scanner')->assertRedirect('/billing');

        $this->travelTo('2026-11-10 05:00:00');
        $this->approve($paymentId)->assertOk();
        $owner->refresh();

        $subscription = $owner->organization->baseSubscription()->firstOrFail();
        $this->assertSame('2026-11-10 05:00:00', $subscription->subscription_starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-10 05:00:00', $subscription->subscription_expires_at->utc()->format('Y-m-d H:i:s'));
        $this->actingAs($owner)->get('/scanner')->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function smsAccess(User $owner): array
    {
        return $this->actingAs($owner)->getJson('/api/organization/sms/access')->assertOk()->json('sms');
    }

    public function test_only_trial_schools_choose_when_sms_starts(): void
    {
        $trial = $this->owner(smsPricing: false);
        $this->assertSame('choose_trial_timing', $this->smsAccess($trial)['next_step']);
        $this->assertTrue($this->smsAccess($trial)['in_trial']);

        $paidBase = $this->owner('active', smsPricing: false);
        $this->assertSame('request', $this->smsAccess($paidBase)['next_step']);
        $this->assertFalse($this->smsAccess($paidBase)['in_trial']);

        $expired = $this->owner('expired', smsPricing: false);
        $this->assertSame('renew_first', $this->smsAccess($expired)['next_step']);
    }

    public function test_former_base_plus_sms_school_is_told_to_pay_base_plus_sms_not_to_request_again(): void
    {
        $owner = $this->owner('active');
        $this->approve($this->pay($owner, $this->billFor($owner, Bill::TYPE_PACKAGE), 'FORMER-PKG')->json('payment.id'))->assertOk();
        $this->assertSame('scheduled', $this->smsAccess($owner)['next_step']);

        $this->approve($this->pay($owner, $this->billFor($owner, Bill::TYPE_BASE), 'FORMER-BASE')->json('payment.id'))->assertOk();
        $this->travelTo('2027-01-06 01:00:00');
        $owner->refresh();

        $this->assertSame('pay_package', $this->smsAccess($owner)['next_step']);
        $this->actingAs($owner)->getJson('/api/billing')->assertJsonPath('current_plan.sms.state', 'available');
        // The school may ask for a new price (e.g. more people); Base + SMS keeps the current price until then.
        $this->actingAs($owner)->postJson('/api/organization/sms/access/request', ['activation_mode' => 'immediate', ...self::SMS_ANSWERS])->assertSuccessful();
        $this->actingAs($owner)->getJson('/api/billing')->assertJsonPath('current_plan.sms.state', 'price_ready');
        $this->assertSame('pay_package', $this->smsAccess($owner)['next_step']);

        $this->travelTo('2027-02-06 01:00:00');
        $owner->refresh();
        $this->assertSame('renew_with_package', $this->smsAccess($owner)['next_step']);
    }

    public function test_paid_school_request_ignores_trial_timing(): void
    {
        $owner = $this->owner('active', smsPricing: false);

        $this->actingAs($owner)->postJson('/api/organization/sms/access/request', ['activation_mode' => 'immediate', ...self::SMS_ANSWERS])->assertCreated()
            ->assertJsonPath('request.activation_mode', OrganizationFeatureRequest::ACTIVATION_NEXT_PAYMENT);
        $this->assertSame('waiting_for_price', $this->smsAccess($owner)['next_step']);
    }

    public function test_trial_school_keeps_its_chosen_timing(): void
    {
        $owner = $this->owner(smsPricing: false);

        $this->actingAs($owner)->postJson('/api/organization/sms/access/request', ['activation_mode' => 'immediate', ...self::SMS_ANSWERS])->assertCreated()
            ->assertJsonPath('request.activation_mode', OrganizationFeatureRequest::ACTIVATION_IMMEDIATE);
    }

    public function test_admin_can_change_a_schools_sms_price_without_an_sms_request(): void
    {
        $owner = $this->owner('active');
        $admin = $this->superAdmin();

        $this->actingAs($admin)->putJson('/api/super-admin/organizations/'.$owner->organization_id.'/sms-pricing', ['sms_monthly_price' => '750.50', 'notes' => 'More students this term'])
            ->assertOk()
            ->assertJsonPath('agreement.sms_monthly_price_minor', 75050)
            ->assertJsonPath('agreement.effective_from', '2026-11-10T00:00:00.000000Z')
            ->assertJsonCount(2, 'history');

        $this->assertSame(BaseSubscriptionService::MONTHLY_PRICE_MINOR + 75050, $this->billFor($owner, Bill::TYPE_PACKAGE)['total_minor']);
        $this->actingAs($owner)->getJson('/api/organization/sms/access')->assertJsonPath('sms.pricing.sms_monthly_price_minor', 75050);
        $this->actingAs($admin)->getJson('/api/super-admin/organizations/'.$owner->organization_id)->assertOk()
            ->assertJsonPath('organization.sms_pricing_history.0.sms_monthly_price_minor', 75050)
            ->assertJsonPath('organization.sms_pricing_history.1.sms_monthly_price_minor', self::SMS_PRICE_MINOR)
            ->assertJsonPath('organization.sms_pricing_history.1.effective_until', '2026-11-09T00:00:00.000000Z');
    }

    public function test_new_sms_price_does_not_touch_the_current_paid_period(): void
    {
        $owner = $this->owner('active');
        $this->approve($this->pay($owner, $this->billFor($owner, Bill::TYPE_PACKAGE), 'PAID-BEFORE-PRICE')->json('payment.id'))->assertOk();
        $paidBill = Bill::where('organization_id', $owner->organization_id)->where('status', Bill::STATUS_PAID)->firstOrFail();
        $smsEndsAt = $owner->organization->featureEntitlements()->firstOrFail()->expires_at->toDateString();

        $this->actingAs($this->superAdmin())->putJson('/api/super-admin/organizations/'.$owner->organization_id.'/sms-pricing', ['sms_monthly_price' => '999'])->assertOk();

        $this->assertSame(BaseSubscriptionService::MONTHLY_PRICE_MINOR + self::SMS_PRICE_MINOR, $paidBill->fresh()->total_minor);
        $this->assertSame($smsEndsAt, $owner->organization->featureEntitlements()->firstOrFail()->expires_at->toDateString());
        $this->assertSame(BaseSubscriptionService::MONTHLY_PRICE_MINOR + 99900, $this->billFor($owner, Bill::TYPE_PACKAGE)['total_minor']);
    }

    public function test_future_sms_price_only_applies_to_bills_starting_on_or_after_its_date(): void
    {
        $owner = $this->owner('active');
        $this->actingAs($this->superAdmin())->putJson('/api/super-admin/organizations/'.$owner->organization_id.'/sms-pricing', ['sms_monthly_price' => '999', 'effective_from' => '2027-01-01'])->assertOk();

        $december = $this->billFor($owner, Bill::TYPE_PACKAGE);
        $this->assertSame('2026-12-05', substr($december['billing_period_start'], 0, 10));
        $this->assertSame(BaseSubscriptionService::MONTHLY_PRICE_MINOR + self::SMS_PRICE_MINOR, $december['total_minor']);

        $this->approve($this->pay($owner, $december, 'DECEMBER')->json('payment.id'))->assertOk();
        $this->assertSame(BaseSubscriptionService::MONTHLY_PRICE_MINOR + 99900, $this->billFor($owner, Bill::TYPE_PACKAGE)['total_minor']);
    }

    public function test_sms_price_must_be_a_positive_amount_and_only_super_admin_can_set_it(): void
    {
        $owner = $this->owner('active');

        $this->actingAs($this->superAdmin())->putJson('/api/super-admin/organizations/'.$owner->organization_id.'/sms-pricing', ['sms_monthly_price' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('sms_monthly_price');
        $this->actingAs($this->superAdmin())->putJson('/api/super-admin/organizations/'.$owner->organization_id.'/sms-pricing', ['sms_monthly_price' => '0'])->assertStatus(422);
        $this->actingAs($owner)->putJson('/api/super-admin/organizations/'.$owner->organization_id.'/sms-pricing', ['sms_monthly_price' => '1'])->assertForbidden();
    }

    public function test_paid_base_school_is_offered_sms_now_for_the_days_left(): void
    {
        $owner = $this->owner('active');

        $this->actingAs($owner)->getJson('/api/billing')->assertOk()
            ->assertJsonPath('available_types.sms_now', true)
            ->assertJsonPath('sms_now.available', true)
            ->assertJsonPath('sms_now.start', '2026-11-10')
            ->assertJsonPath('sms_now.end', '2026-12-04')
            ->assertJsonPath('sms_now.sms_ends_at', '2026-12-05')
            ->assertJsonPath('sms_now.days_left', 25)
            ->assertJsonPath('sms_now.partial_month_days', 30)
            ->assertJsonPath('sms_now.amount_minor', 41583);

        $bill = $this->actingAs($owner)->getJson('/api/billing?type=sms_now')->assertOk()
            ->assertJsonPath('bill_type', Bill::TYPE_SMS_NOW)
            ->assertJsonPath('plan_change.kind', 'sms_now')
            ->assertJsonPath('plan_change.message', 'Starts SMS as soon as this payment is approved. SMS stays on until Dec 5, 2026, when your paid Base time ends. Your Base plan is not changed. To keep SMS after Dec 5, 2026, choose Base + SMS when you renew.')
            ->json('current_bill');
        $this->assertSame(41583, $bill['total_minor']);
        $this->assertSame(['sms_addon'], array_column($bill['items'], 'type'));
        $this->assertSame('SMS Notifications for the rest of this period (25 days)', $bill['items'][0]['description']);
    }

    public function test_approving_sms_now_turns_sms_on_until_base_ends_without_touching_base(): void
    {
        Http::fake();
        $owner = $this->owner('active');
        $baseEnds = $owner->organization->baseSubscription()->firstOrFail()->subscription_expires_at;
        $paymentId = $this->pay($owner, $this->billFor($owner, Bill::TYPE_SMS_NOW), 'SMS-NOW-1')->assertCreated()->json('payment.id');

        $this->actingAs($owner)->getJson('/api/billing')->assertOk()
            ->assertJsonPath('bill_type', Bill::TYPE_SMS_NOW)
            ->assertJsonPath('current_plan.pending_payment.plan_label', 'SMS for the rest of this period');
        $this->actingAs($this->superAdmin())->getJson('/api/super-admin/billing/payments/'.$paymentId)->assertOk()->assertJsonPath('plan_change.kind', 'sms_now');

        $this->approve($paymentId)->assertOk();
        $owner->refresh();

        $entitlement = $owner->organization->featureEntitlements()->where('feature', FeatureEntitlement::SMS)->firstOrFail();
        $this->assertSame('2026-11-10', $entitlement->starts_at->toDateString());
        $this->assertSame('2026-12-05', $entitlement->expires_at->toDateString());
        $this->assertTrue(app(FeatureEntitlementService::class)->canUseSms($owner->organization->fresh()));
        $this->assertTrue($baseEnds->equalTo($owner->organization->baseSubscription()->firstOrFail()->subscription_expires_at));
        $this->assertSame('2026-12-05', Payment::findOrFail($paymentId)->coverage_end->toDateString());

        $this->actingAs($owner)->getJson('/api/billing')->assertOk()
            ->assertJsonPath('current_plan.label', 'Base + SMS')
            ->assertJsonPath('sms_now.available', false)
            ->assertJsonPath('sms_now.reason', 'sms_already_on')
            ->assertJsonPath('bill_type', Bill::TYPE_PACKAGE)
            ->assertJsonPath('plan_change.kind', 'renew');
        Http::assertNothingSent();
    }

    public function test_renewing_base_plus_sms_after_sms_now_keeps_both_on_the_same_dates(): void
    {
        $owner = $this->owner('active');
        $this->approve($this->pay($owner, $this->billFor($owner, Bill::TYPE_SMS_NOW), 'SMS-NOW-RENEW')->json('payment.id'))->assertOk();
        $this->approve($this->pay($owner, $this->billFor($owner, Bill::TYPE_PACKAGE), 'PKG-AFTER-SMS-NOW')->json('payment.id'))->assertOk();

        $entitlement = $owner->organization->featureEntitlements()->where('feature', FeatureEntitlement::SMS)->firstOrFail();
        $this->assertSame('2026-11-10', $entitlement->starts_at->toDateString());
        $this->assertSame('2027-01-05', $entitlement->expires_at->toDateString());
        $this->assertSame('2027-01-05', $owner->organization->baseSubscription()->firstOrFail()->subscription_expires_at->toDateString());
    }

    public function test_sms_now_is_not_offered_to_trial_expired_unpriced_or_sms_on_schools(): void
    {
        $this->actingAs($trial = $this->owner())->getJson('/api/billing')->assertJsonPath('sms_now.reason', 'not_paid_base');
        $this->actingAs($this->owner('expired'))->getJson('/api/billing')->assertJsonPath('sms_now.reason', 'not_paid_base');
        $this->actingAs($this->owner('active', smsPricing: false))->getJson('/api/billing')->assertJsonPath('sms_now.reason', 'no_price');
        $this->actingAs($trial)->getJson('/api/billing?type=sms_now')->assertStatus(422)
            ->assertJsonPath('message', 'Add SMS is only available while your paid Base period is running.');

        $withSms = $this->owner('active');
        $this->approve($this->pay($withSms, $this->billFor($withSms, Bill::TYPE_PACKAGE), 'ALREADY-PKG')->json('payment.id'))->assertOk();
        $this->travelTo('2026-12-06 02:00:00');
        $withSms->refresh();
        $this->actingAs($withSms)->getJson('/api/billing')->assertJsonPath('sms_now.reason', 'sms_already_on');
    }

    public function test_school_that_renewed_early_can_start_sms_now_until_the_new_end_date(): void
    {
        $owner = $this->owner('active');
        $owner->organization->baseSubscription()->update(['subscription_expires_at' => '2026-11-11 02:00:00']);
        $owner->refresh();
        $this->actingAs($owner)->getJson('/api/billing')->assertJsonPath('sms_now.reason', 'too_few_days');

        $this->approve($this->pay($owner, $this->billFor($owner, Bill::TYPE_BASE), 'RENEWED-EARLY')->json('payment.id'))->assertOk();
        $owner->refresh();

        $this->actingAs($owner)->getJson('/api/billing')->assertOk()
            ->assertJsonPath('sms_now.available', true)
            ->assertJsonPath('sms_now.start', '2026-11-10')
            ->assertJsonPath('sms_now.sms_ends_at', '2026-12-11')
            ->assertJsonPath('sms_now.days_left', 31)
            ->assertJsonPath('sms_now.full_months', 1);
    }

    public function test_sms_now_is_not_offered_in_the_last_days_of_the_period(): void
    {
        $owner = $this->owner('active');
        $this->travelTo('2026-12-03 02:00:00');
        $owner->refresh();

        $this->actingAs($owner)->getJson('/api/billing')->assertOk()
            ->assertJsonPath('sms_now.available', false)
            ->assertJsonPath('sms_now.reason', 'too_few_days')
            ->assertJsonPath('sms_now.days_left', 2);
        $this->actingAs($owner)->getJson('/api/billing?type=sms_now')->assertStatus(422)
            ->assertJsonPath('message', 'Add SMS is not available in the last 3 days of your paid Base time. Choose Base + SMS for your next payment instead.');

        $this->travelTo('2026-12-02 02:00:00');
        $owner->refresh();
        $this->actingAs($owner)->getJson('/api/billing')->assertJsonPath('sms_now.available', true)->assertJsonPath('sms_now.days_left', 3);
    }

    public function test_sms_now_amount_is_recalculated_each_day_and_old_bills_are_refused(): void
    {
        $owner = $this->owner('active');
        $yesterday = $this->billFor($owner, Bill::TYPE_SMS_NOW);

        $this->travelTo('2026-11-11 02:00:00');
        $owner->refresh();
        $this->pay($owner, $yesterday, 'STALE-SMS-NOW')->assertStatus(422)
            ->assertJsonPath('message', "This SMS amount was for an earlier day. Refresh the page to see today's amount.");

        $today = $this->billFor($owner, Bill::TYPE_SMS_NOW);
        $this->assertNotSame($yesterday['id'], $today['id']);
        $this->assertSame(39920, $today['total_minor']);
        $this->assertSame(Bill::STATUS_CANCELLED, Bill::findOrFail($yesterday['id'])->status);
        $this->pay($owner, $today, 'FRESH-SMS-NOW')->assertCreated();
    }

    public function test_sms_now_uses_the_sms_price_in_effect_today(): void
    {
        $owner = $this->owner('active');
        $this->actingAs($this->superAdmin())->putJson('/api/super-admin/organizations/'.$owner->organization_id.'/sms-pricing', ['sms_monthly_price' => '600'])->assertOk();

        $this->actingAs($owner)->getJson('/api/billing')->assertJsonPath('sms_now.monthly_price_minor', 60000)->assertJsonPath('sms_now.amount_minor', 50000);
    }

    public function test_sms_now_and_base_plus_sms_payments_block_each_other_but_base_does_not(): void
    {
        $message = 'Another payment covering the same plan is already under review. Wait for it to be reviewed before paying again.';
        $owner = $this->owner('active');
        $smsNow = $this->billFor($owner, Bill::TYPE_SMS_NOW);
        $this->pay($owner, $smsNow, 'SMS-NOW-PENDING')->assertCreated();

        $this->pay($owner, $smsNow, 'SMS-NOW-TWICE')->assertStatus(422)->assertJsonPath('message', 'A payment for this bill is already under review.');
        $this->pay($owner, $this->billFor($owner, Bill::TYPE_PACKAGE), 'PKG-BLOCKED-BY-SMS-NOW')->assertStatus(422)->assertJsonPath('message', $message);
        $this->pay($owner, $this->billFor($owner, Bill::TYPE_BASE), 'BASE-WITH-SMS-NOW')->assertCreated();
    }

    public function test_rejected_sms_now_payment_is_shown_and_can_be_paid_again(): void
    {
        $owner = $this->owner('active');
        $bill = $this->billFor($owner, Bill::TYPE_SMS_NOW);
        $paymentId = $this->pay($owner, $bill, 'SMS-NOW-REJECT')->json('payment.id');
        $this->actingAs($this->superAdmin())->postJson('/api/super-admin/billing/payments/'.$paymentId.'/reject', ['reason' => 'Reference not found'])->assertOk();

        $this->actingAs($owner)->getJson('/api/billing')->assertOk()
            ->assertJsonPath('latest_payment.status', Payment::STATUS_REJECTED)
            ->assertJsonPath('latest_payment.bill.bill_type', Bill::TYPE_SMS_NOW)
            ->assertJsonPath('current_plan.sms.state', 'available');
        $this->pay($owner, $this->billFor($owner, Bill::TYPE_SMS_NOW), 'SMS-NOW-RETRY')->assertCreated();
        $this->assertFalse(app(FeatureEntitlementService::class)->canUseSms($owner->organization->fresh()));
    }

    public function test_trial_school_can_change_when_sms_starts_until_it_is_approved(): void
    {
        $owner = $this->owner(smsPricing: false);
        $this->actingAs($owner)->postJson('/api/organization/sms/access/request', ['activation_mode' => 'after_trial', ...self::SMS_ANSWERS])->assertCreated();

        $this->actingAs($owner)->postJson('/api/organization/sms/access/request', ['activation_mode' => 'immediate', ...self::SMS_ANSWERS])->assertOk()
            ->assertJsonPath('request.activation_mode', OrganizationFeatureRequest::ACTIVATION_IMMEDIATE);
        $this->actingAs($owner)->getJson('/api/organization/sms/access')->assertJsonPath('sms.activation_mode', 'immediate')->assertJsonPath('sms.in_trial', true);
        $this->actingAs($owner)->postJson('/api/organization/sms/access/request', self::SMS_ANSWERS)->assertOk()
            ->assertJsonPath('request.activation_mode', OrganizationFeatureRequest::ACTIVATION_IMMEDIATE);
        $this->assertDatabaseCount('organization_feature_requests', 1);
    }

    public function test_trial_school_can_pick_its_start_choice_after_a_price_is_set(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->postJson('/api/organization/sms/access/request', ['activation_mode' => 'immediate', ...self::SMS_ANSWERS])->assertCreated()
            ->assertJsonPath('request.activation_mode', OrganizationFeatureRequest::ACTIVATION_IMMEDIATE);
        $this->actingAs($owner)->getJson('/api/billing?type=package')->assertOk()
            ->assertJsonPath('current_plan.sms.state', 'price_ready')
            ->assertJsonPath('current_bill.billing_period_start', '2026-11-10T00:00:00.000000Z');
    }

    public function test_trial_school_can_choose_its_paid_plan_start_without_requesting_sms(): void
    {
        $owner = $this->owner(smsPricing: false);
        $this->assertSame('2026-11-20', substr($this->billFor($owner, Bill::TYPE_BASE)['billing_period_start'], 0, 10));

        $this->actingAs($owner)->postJson('/api/billing/start-choice', ['choice' => 'immediate'])->assertOk();
        $this->actingAs($owner)->getJson('/api/billing?type=base')->assertOk()
            ->assertJsonPath('current_plan.start_choice', 'immediate')
            ->assertJsonPath('current_bill.billing_period_start', '2026-11-10T00:00:00.000000Z');
        $this->assertDatabaseCount('organization_feature_requests', 0);

        $this->actingAs($owner)->postJson('/api/organization/sms/access/request', self::SMS_ANSWERS)->assertCreated()
            ->assertJsonPath('request.activation_mode', 'immediate');
        $this->actingAs($owner)->postJson('/api/billing/start-choice', ['choice' => 'after_trial'])->assertOk();
        $this->assertSame('after_trial', $owner->organization->featureRequests()->firstOrFail()->activation_mode);
        $this->actingAs($owner)->getJson('/api/billing?type=base')->assertJsonPath('current_bill.billing_period_start', '2026-11-20T00:00:00.000000Z');
    }

    public function test_right_away_wording_uses_the_school_timezone_after_utc_midnight(): void
    {
        $owner = $this->owner(smsPricing: false);
        $this->travelTo('2026-11-10 17:00:00');
        $this->actingAs($owner)->postJson('/api/billing/start-choice', ['choice' => 'immediate'])->assertOk();

        $this->actingAs($owner)->getJson('/api/billing?type=base')->assertOk()
            ->assertJsonPath('current_bill.billing_period_start', '2026-11-11T00:00:00.000000Z')
            ->assertJsonPath('plan_change.message', 'Starts paid Base as soon as this payment is approved, which ends your free trial early (you chose "Right away"). Covers Nov 11, 2026 to Dec 10, 2026 (1 month).');
    }

    public function test_only_trial_schools_can_set_a_start_choice(): void
    {
        $this->actingAs($this->owner('active'))->postJson('/api/billing/start-choice', ['choice' => 'immediate'])->assertStatus(422)
            ->assertJsonPath('message', 'Only schools on their free trial can choose when their paid plan starts.');
        $this->actingAs($this->owner())->postJson('/api/billing/start-choice', ['choice' => 'tomorrow'])->assertStatus(422)->assertJsonValidationErrors('choice');
    }

    public function test_paid_school_cannot_switch_its_request_to_trial_timing(): void
    {
        $owner = $this->owner('active', smsPricing: false);
        $this->actingAs($owner)->postJson('/api/organization/sms/access/request', self::SMS_ANSWERS)->assertCreated();

        $this->actingAs($owner)->postJson('/api/organization/sms/access/request', ['activation_mode' => 'immediate', ...self::SMS_ANSWERS])->assertOk()
            ->assertJsonPath('request.activation_mode', OrganizationFeatureRequest::ACTIVATION_NEXT_PAYMENT);
    }

    public function test_start_right_away_base_plus_sms_bill_starts_today_for_a_trial_school(): void
    {
        $owner = $this->owner(smsPricing: false);
        $this->actingAs($owner)->postJson('/api/organization/sms/access/request', ['activation_mode' => 'immediate', ...self::SMS_ANSWERS])->assertCreated();
        $this->actingAs($this->superAdmin())->putJson('/api/super-admin/organizations/'.$owner->organization_id.'/sms-pricing', ['sms_monthly_price' => '499'])->assertOk();

        $bill = $this->actingAs($owner)->getJson('/api/billing?type=package')->assertOk()
            ->assertJsonPath('plan_change.kind', 'add_sms')
            ->assertJsonPath('plan_change.message', 'Adds SMS. SMS and paid Base start as soon as this payment is approved, which ends your free trial early (you chose "Right away"). This payment covers Base + SMS from Nov 10, 2026 to Dec 9, 2026 (1 month).')
            ->json('current_bill');
        $this->assertSame('2026-11-10', substr($bill['billing_period_start'], 0, 10));

        $this->approve($this->pay($owner, $bill, 'RIGHT-AWAY')->json('payment.id'))->assertOk();
        $this->assertSame('2026-11-10', $owner->organization->baseSubscription()->firstOrFail()->subscription_starts_at->toDateString());
        $this->assertSame('2026-11-10', $owner->organization->featureEntitlements()->firstOrFail()->starts_at->toDateString());
    }

    public function test_start_right_away_base_payment_starts_paid_base_today(): void
    {
        $owner = $this->owner(smsPricing: false);
        $this->actingAs($owner)->postJson('/api/organization/sms/access/request', ['activation_mode' => 'immediate', ...self::SMS_ANSWERS])->assertCreated();

        $bill = $this->actingAs($owner)->getJson('/api/billing?type=base')->assertOk()
            ->assertJsonPath('plan_change.message', 'Starts paid Base as soon as this payment is approved, which ends your free trial early (you chose "Right away"). Covers Nov 10, 2026 to Dec 9, 2026 (1 month). This payment is for Base only. Your SMS price is not ready yet. SMS is added once Base + SMS is paid and approved.')
            ->json('current_bill');
        $paymentId = $this->pay($owner, $bill, 'BASE-RIGHT-AWAY')->json('payment.id');

        $this->actingAs($this->superAdmin())->getJson('/api/super-admin/billing/payments/'.$paymentId)->assertOk()
            ->assertJsonPath('sms_activation_request.activation_mode', 'immediate')
            ->assertJsonPath('sms_price_set', false)
            ->assertJsonPath('plan_change.message', "Starts paid Base as soon as this payment is approved, which ends the school's free trial early (the school chose \"Right away\"). Covers Nov 10, 2026 to Dec 9, 2026 (1 month). This payment is for Base only. The school's SMS price is not ready yet. SMS is added once Base + SMS is paid and approved.");
        $this->approve($paymentId)->assertOk();

        $subscription = $owner->organization->baseSubscription()->firstOrFail();
        $this->assertSame('2026-11-10 02:00:00', $subscription->subscription_starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-10', $subscription->subscription_expires_at->toDateString());
        $this->assertDatabaseCount('feature_entitlements', 0);
    }

    public function test_start_after_trial_payments_start_when_the_trial_ends(): void
    {
        $owner = $this->owner(smsPricing: false);
        $this->actingAs($owner)->postJson('/api/organization/sms/access/request', ['activation_mode' => 'after_trial', ...self::SMS_ANSWERS])->assertCreated();

        $this->actingAs($owner)->getJson('/api/billing?type=base')->assertOk()
            ->assertJsonPath('plan_change.message', 'Starts paid Base on Nov 20, 2026, when your free trial ends (you chose "After my free trial ends"). Covers Nov 20, 2026 to Dec 19, 2026 (1 month). This payment is for Base only. Your SMS price is not ready yet. SMS is added once Base + SMS is paid and approved.');

        $this->actingAs($this->superAdmin())->putJson('/api/super-admin/organizations/'.$owner->organization_id.'/sms-pricing', ['sms_monthly_price' => '499'])->assertOk();
        $bill = $this->actingAs($owner)->getJson('/api/billing?type=package')->assertOk()
            ->assertJsonPath('plan_change.message', 'Adds SMS. SMS and paid Base start on Nov 20, 2026, when your free trial ends (you chose "After my free trial ends"). This payment covers Base + SMS from Nov 20, 2026 to Dec 19, 2026 (1 month).')
            ->json('current_bill');
        $this->approve($this->pay($owner, $bill, 'PKG-AFTER-TRIAL')->json('payment.id'))->assertOk();

        $this->assertSame('2026-11-20', $owner->organization->baseSubscription()->firstOrFail()->subscription_starts_at->toDateString());
        $entitlement = $owner->organization->featureEntitlements()->firstOrFail();
        $this->assertSame('2026-11-20', $entitlement->starts_at->toDateString());
        $this->assertFalse(app(FeatureEntitlementService::class)->canUseSms($owner->organization->fresh()));
        $this->assertSame(OrganizationFeatureRequest::STATUS_APPROVED, $owner->organization->featureRequests()->firstOrFail()->status);
    }

    public function test_changing_the_choice_after_paying_moves_the_submitted_bill_and_approval_follows_it(): void
    {
        $owner = $this->owner(smsPricing: false);
        $this->actingAs($owner)->postJson('/api/organization/sms/access/request', ['activation_mode' => 'after_trial', ...self::SMS_ANSWERS])->assertCreated();
        $bill = $this->billFor($owner, Bill::TYPE_BASE);
        $paymentId = $this->pay($owner, $bill, 'SWITCH-CHOICE')->json('payment.id');
        $this->assertSame('2026-11-20', substr($bill['billing_period_start'], 0, 10));

        $this->actingAs($owner)->postJson('/api/organization/sms/access/request', ['activation_mode' => 'immediate', ...self::SMS_ANSWERS])->assertOk();
        $this->assertSame('2026-11-10', Bill::findOrFail($bill['id'])->billing_period_start->toDateString());
        $this->assertSame(BaseSubscriptionService::MONTHLY_PRICE_MINOR, Bill::findOrFail($bill['id'])->total_minor);

        $this->approve($paymentId)->assertOk();
        $this->assertSame('2026-11-10', $owner->organization->baseSubscription()->firstOrFail()->subscription_starts_at->toDateString());
    }

    public function test_current_plan_shows_what_the_school_has_now(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->getJson('/api/billing')->assertOk()
            ->assertJsonPath('current_plan.plan', Bill::TYPE_BASE)
            ->assertJsonPath('current_plan.label', 'Free trial')
            ->assertJsonPath('current_plan.status', 'trialing')
            ->assertJsonPath('current_plan.base_paid_through', null)
            ->assertJsonPath('current_plan.sms.state', 'available')
            ->assertJsonPath('current_plan.pending_payment', null);
    }

    public function test_current_plan_shows_a_payment_under_review_without_changing_the_plan(): void
    {
        $owner = $this->owner('active');
        $bill = $this->actingAs($owner)->getJson('/api/billing?type=package&months=3')->json('current_bill');
        $this->pay($owner, $bill, 'REVIEW-VISIBLE')->assertCreated();

        $this->actingAs($owner)->getJson('/api/billing')->assertOk()
            ->assertJsonPath('current_plan.label', 'Base')
            ->assertJsonPath('current_plan.sms.state', 'available')
            ->assertJsonPath('current_plan.pending_payment.plan_label', 'Base + SMS')
            ->assertJsonPath('current_plan.pending_payment.months', 3)
            ->assertJsonPath('current_plan.pending_payment.status', Payment::STATUS_PENDING);
    }

    public function test_current_plan_after_approval_and_after_sms_ends(): void
    {
        $owner = $this->owner('active');
        $this->approve($this->pay($owner, $this->billFor($owner, Bill::TYPE_PACKAGE), 'PLAN-PKG')->json('payment.id'))->assertOk();
        $this->approve($this->pay($owner, $this->billFor($owner, Bill::TYPE_BASE), 'PLAN-BASE')->json('payment.id'))->assertOk();
        $owner->refresh();

        $this->travelTo('2026-12-06 01:00:00');
        $this->actingAs($owner)->getJson('/api/billing')->assertOk()
            ->assertJsonPath('current_plan.label', 'Base + SMS')
            ->assertJsonPath('current_plan.sms.state', 'on')
            ->assertJsonPath('current_plan.sms.ends_at', '2027-01-05');

        $this->travelTo('2027-01-06 01:00:00');
        $owner->refresh();
        $this->actingAs($owner)->getJson('/api/billing')->assertOk()
            ->assertJsonPath('current_plan.label', 'Base')
            ->assertJsonPath('current_plan.sms.state', 'available');
    }

    public function test_plan_change_explains_adding_removing_and_renewing_sms(): void
    {
        $owner = $this->owner('active');
        $this->actingAs($owner)->getJson('/api/billing?type=package')->assertOk()
            ->assertJsonPath('plan_change.kind', 'add_sms')
            ->assertJsonPath('plan_change.message', 'Adds SMS. SMS starts on Dec 5, 2026, when your paid Base time ends. This payment covers Base + SMS from Dec 5, 2026 to Jan 4, 2027 (1 month).');
        $expired = $this->owner('expired');
        $this->actingAs($expired)->getJson('/api/billing?type=package')->assertOk()
            ->assertJsonPath('plan_change.message', 'Adds SMS. SMS starts as soon as this payment is approved. This payment covers Base + SMS from Nov 10, 2026 to Dec 9, 2026 (1 month).');
        $this->actingAs($owner)->getJson('/api/billing?type=base')->assertOk()
            ->assertJsonPath('plan_change.kind', 'renew')
            ->assertJsonPath('plan_change.message', 'Renews Base from Dec 5, 2026 to Jan 4, 2027 (1 month).');

        $this->approve($this->pay($owner, $this->billFor($owner, Bill::TYPE_PACKAGE), 'CHANGE-PKG')->json('payment.id'))->assertOk();

        $this->actingAs($owner)->getJson('/api/billing?type=base&months=3')->assertOk()
            ->assertJsonPath('plan_change.kind', 'remove_sms')
            ->assertJsonPath('plan_change.message', 'Removes SMS. SMS stays on until Jan 5, 2027, then your plan continues as Base from Jan 5, 2027 to Apr 4, 2027 (3 months).');
        $this->actingAs($owner)->getJson('/api/billing?type=package')->assertOk()
            ->assertJsonPath('plan_change.kind', 'renew');
    }

    public function test_super_admin_sees_current_plan_and_what_a_payment_changes(): void
    {
        $owner = $this->owner('active');
        $paymentId = $this->pay($owner, $this->billFor($owner, Bill::TYPE_PACKAGE), 'ADMIN-VIEW')->json('payment.id');
        $admin = $this->superAdmin();

        $this->actingAs($admin)->getJson('/api/super-admin/billing/payments/'.$paymentId)->assertOk()
            ->assertJsonPath('current_plan.label', 'Base')
            ->assertJsonPath('plan_change.kind', 'add_sms');
        $rows = collect($this->actingAs($admin)->getJson('/api/super-admin/organizations')->assertOk()->json('data'));
        $row = $rows->firstWhere('id', $owner->organization_id);
        $this->assertSame('Base', $row['current_plan']['label']);
        $this->assertSame('Base + SMS', $row['current_plan']['pending_payment']['plan_label']);
    }

    public function test_billing_without_a_type_keeps_an_open_bill_under_review(): void
    {
        $owner = $this->owner('active');
        $base = $this->billFor($owner, Bill::TYPE_BASE);

        $this->pay($owner, $base, 'DEFAULT-UNDER-REVIEW')->assertCreated();

        $this->actingAs($owner)->getJson('/api/billing')->assertOk()
            ->assertJsonPath('bill_type', Bill::TYPE_BASE)
            ->assertJsonPath('current_bill.id', $base['id']);
    }

    public function test_expired_school_can_renew_with_either_plan(): void
    {
        $owner = $this->owner('expired');

        $this->actingAs($owner)->getJson('/api/billing')->assertOk()
            ->assertJsonPath('available_types', ['base' => true, 'package' => true, 'sms_now' => false]);
        $this->pay($owner, $this->billFor($owner, Bill::TYPE_PACKAGE), 'EXPIRED-PACKAGE')->assertCreated();
    }

    public function test_legacy_include_sms_flag_maps_to_package_or_base(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->getJson('/api/billing?include_sms=1')->assertOk()
            ->assertJsonPath('bill_type', Bill::TYPE_PACKAGE);
        $this->actingAs($owner)->getJson('/api/billing?include_sms=0')->assertOk()
            ->assertJsonPath('bill_type', Bill::TYPE_BASE);
    }

    public function test_a_new_sms_agreement_updates_an_unpaid_base_plus_sms_bill(): void
    {
        $owner = $this->owner('active');
        $package = $this->billFor($owner, Bill::TYPE_PACKAGE);

        SmsPricingAgreement::create([
            'organization_id' => $owner->organization_id,
            'sms_monthly_price_minor' => 79900,
            'person_count_snapshot' => 75,
            'effective_from' => '2026-11-01',
            'status' => SmsPricingAgreement::STATUS_ACTIVE,
            'created_by' => $owner->id,
            'agreed_at' => now(),
        ]);

        $updated = $this->billFor($owner, Bill::TYPE_PACKAGE);
        $this->assertSame($package['id'], $updated['id']);
        $this->assertSame(BaseSubscriptionService::MONTHLY_PRICE_MINOR + 79900, $updated['total_minor']);
    }

    public function test_a_new_sms_agreement_does_not_update_pending_or_paid_bills(): void
    {
        $owner = $this->owner('active');
        $paid = $this->billFor($owner, Bill::TYPE_BASE);
        $this->approve($this->pay($owner, $paid, 'PRICE-PAID')->json('payment.id'))->assertOk();

        $pending = $this->billFor($owner, Bill::TYPE_PACKAGE);
        $this->pay($owner, $pending, 'PRICE-PENDING')->assertCreated();

        SmsPricingAgreement::create([
            'organization_id' => $owner->organization_id,
            'sms_monthly_price_minor' => 79900,
            'person_count_snapshot' => 75,
            'effective_from' => '2026-11-01',
            'status' => SmsPricingAgreement::STATUS_ACTIVE,
            'created_by' => $owner->id,
            'agreed_at' => now(),
        ]);

        $this->actingAs($owner)->getJson('/api/billing?type=package')->assertOk()
            ->assertJsonPath('current_bill.id', $pending['id'])
            ->assertJsonPath('current_bill.total_minor', BaseSubscriptionService::MONTHLY_PRICE_MINOR + self::SMS_PRICE_MINOR);
        $this->assertSame(BaseSubscriptionService::MONTHLY_PRICE_MINOR, Bill::findOrFail($paid['id'])->total_minor);
    }

    public function test_base_plus_sms_requires_an_agreed_sms_price(): void
    {
        $owner = $this->owner(smsPricing: false);

        $this->actingAs($owner)->getJson('/api/billing?type=package')->assertStatus(422)->assertJsonPath('message', 'SMS pricing has not been set for your organization yet. Request SMS Notifications first.');
    }

    public function test_sms_only_plan_does_not_exist(): void
    {
        $this->actingAs($this->owner('active'))->getJson('/api/billing?type=sms')->assertStatus(422)->assertJsonValidationErrors('type');
    }

    public function test_unknown_bill_type_is_a_validation_error(): void
    {
        $this->actingAs($this->owner())->getJson('/api/billing?type=premium')->assertStatus(422)->assertJsonValidationErrors('type');
    }

    public function test_approving_a_base_bill_extends_only_the_base_subscription(): void
    {
        $owner = $this->owner();
        $paymentId = $this->pay($owner, $this->billFor($owner, Bill::TYPE_BASE), 'BASE-001')->assertCreated()->json('payment.id');

        $this->approve($paymentId)->assertOk();

        $subscription = $owner->organization->baseSubscription()->firstOrFail();
        $this->assertSame('2026-11-20', $subscription->subscription_starts_at->toDateString());
        $this->assertSame('2026-12-20', $subscription->subscription_expires_at->toDateString());
        $this->assertDatabaseCount('feature_entitlements', 0);
        $this->assertSame(Bill::STATUS_PAID, Payment::findOrFail($paymentId)->bill->status);
    }

    public function test_base_approval_starts_after_trial_and_extends_one_calendar_month(): void
    {
        $owner = $this->owner();
        $paymentId = $this->pay($owner, $this->billFor($owner, Bill::TYPE_BASE), 'TRIAL-BASE')->json('payment.id');

        $this->approve($paymentId)->assertOk();

        $subscription = $owner->organization->baseSubscription()->firstOrFail();
        $this->assertSame('2026-11-20', $subscription->subscription_starts_at->toDateString());
        $this->assertSame('2026-12-20', $subscription->subscription_expires_at->toDateString());
    }

    public function test_renewing_base_plus_sms_keeps_sms_aligned_with_base(): void
    {
        Http::fake();
        $owner = $this->owner('active');
        $first = $this->pay($owner, $this->billFor($owner, Bill::TYPE_PACKAGE), 'PACKAGE-RENEW-1')->json('payment.id');
        $this->approve($first)->assertOk();

        $entitlement = $owner->organization->featureEntitlements()->where('feature', FeatureEntitlement::SMS)->firstOrFail();
        $base = $owner->organization->baseSubscription()->firstOrFail();
        $this->assertSame('2026-12-05', $entitlement->starts_at->toDateString());
        $this->assertSame($base->subscription_expires_at->toDateString(), $entitlement->expires_at->toDateString());

        $second = $this->pay($owner, $this->billFor($owner, Bill::TYPE_PACKAGE), 'PACKAGE-RENEW-2')->json('payment.id');
        $this->approve($second)->assertOk();

        $entitlement->refresh();
        $this->assertSame('2026-12-05', $entitlement->starts_at->toDateString());
        $this->assertSame('2027-02-05', $entitlement->expires_at->toDateString());
        $this->assertSame('2027-02-05', $base->fresh()->subscription_expires_at->toDateString());
        Http::assertNothingSent();
    }

    public function test_multi_month_approval_extends_base_and_sms_by_the_paid_months(): void
    {
        $owner = $this->owner('active');
        $bill = $this->actingAs($owner)->getJson('/api/billing?type=package&months=6')->json('current_bill');

        $this->approve($this->pay($owner, $bill, 'SIX-MONTHS')->json('payment.id'))->assertOk();

        $this->assertSame('2027-06-05', $owner->organization->baseSubscription()->firstOrFail()->subscription_expires_at->toDateString());
        $this->assertSame('2027-06-05', $owner->organization->featureEntitlements()->where('feature', FeatureEntitlement::SMS)->firstOrFail()->expires_at->toDateString());
    }

    public function test_downgrading_to_base_keeps_sms_until_the_paid_period_ends(): void
    {
        $owner = $this->owner('active');
        $this->approve($this->pay($owner, $this->billFor($owner, Bill::TYPE_PACKAGE), 'BEFORE-DOWNGRADE')->json('payment.id'))->assertOk();
        $this->approve($this->pay($owner, $this->billFor($owner, Bill::TYPE_BASE), 'DOWNGRADE-TO-BASE')->json('payment.id'))->assertOk();

        $features = app(FeatureEntitlementService::class);
        $this->assertSame('2027-02-05', $owner->organization->baseSubscription()->firstOrFail()->subscription_expires_at->toDateString());
        $this->travelTo('2027-01-05 01:00:00');
        $this->assertTrue($features->canUseSms($owner->organization->fresh()));
        $this->travelTo('2027-01-06 01:00:00');
        $this->assertFalse($features->canUseSms($owner->organization->fresh()));
        $this->assertTrue(app(BaseSubscriptionService::class)->canUseScanner($owner->organization->fresh()));
    }

    public function test_after_choosing_base_mid_month_the_page_shows_base_as_the_next_plan(): void
    {
        $owner = $this->owner('active');
        $this->approve($this->pay($owner, $this->billFor($owner, Bill::TYPE_PACKAGE), 'PKG-MID')->json('payment.id'))->assertOk();

        $this->actingAs($owner)->getJson('/api/billing?type=base')->assertOk()
            ->assertJsonPath('plan_change.kind', 'remove_sms')
            ->assertJsonPath('plan_change.message', 'Removes SMS. SMS stays on until Jan 5, 2027, then your plan continues as Base from Jan 5, 2027 to Feb 4, 2027 (1 month).');
        $this->approve($this->pay($owner, $this->billFor($owner, Bill::TYPE_BASE), 'BASE-MID')->json('payment.id'))->assertOk();
        $owner->refresh();

        $this->actingAs($owner)->getJson('/api/billing')->assertOk()
            ->assertJsonPath('current_plan.label', 'Base + SMS')
            ->assertJsonPath('current_plan.sms.ends_at', '2027-01-05')
            ->assertJsonPath('bill_type', Bill::TYPE_BASE)
            ->assertJsonPath('plan_change.kind', 'renew')
            ->assertJsonPath('plan_change.message', 'Renews Base from Feb 5, 2027 to Mar 4, 2027 (1 month).');
        $this->actingAs($owner)->getJson('/api/billing?type=package')->assertOk()
            ->assertJsonPath('plan_change.kind', 'add_sms');
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_adding_sms_requires_payment_before_it_turns_on(): void
    {
        $owner = $this->owner('active');
        $this->pay($owner, $this->billFor($owner, Bill::TYPE_PACKAGE), 'ADD-SMS')->assertCreated();

        $this->assertFalse(app(FeatureEntitlementService::class)->canUseSms($owner->organization->fresh()));
        $this->assertDatabaseCount('feature_entitlements', 0);
    }

    public function test_immediate_package_approval_starts_base_and_sms_today_and_approves_request(): void
    {
        Http::fake();
        $owner = $this->owner();
        $request = OrganizationFeatureRequest::create(['organization_id' => $owner->organization_id, 'feature' => OrganizationFeatureRequest::FEATURE_SMS, 'status' => OrganizationFeatureRequest::STATUS_PENDING, 'requested_by' => $owner->id, 'requested_at' => now(), 'activation_mode' => OrganizationFeatureRequest::ACTIVATION_IMMEDIATE]);
        $paymentId = $this->pay($owner, $this->billFor($owner, Bill::TYPE_PACKAGE), 'IMMEDIATE-PACKAGE')->json('payment.id');

        $this->approve($paymentId)->assertOk();

        $subscription = $owner->organization->baseSubscription()->firstOrFail();
        $entitlement = $owner->organization->featureEntitlements()->where('feature', FeatureEntitlement::SMS)->firstOrFail();
        $this->assertSame('2026-11-10', $subscription->subscription_starts_at->toDateString());
        $this->assertSame('2026-11-10', $entitlement->starts_at->toDateString());
        $this->assertSame(OrganizationFeatureRequest::STATUS_APPROVED, $request->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_approval_is_idempotent_and_amount_mismatch_is_rejected(): void
    {
        $owner = $this->owner(smsPricing: false);
        $bill = $this->billFor($owner, Bill::TYPE_BASE);
        $paymentId = $this->pay($owner, $bill, 'IDEMPOTENT')->json('payment.id');
        $this->approve($paymentId)->assertOk();
        $subscription = $owner->organization->baseSubscription()->firstOrFail();
        $expires = $subscription->subscription_expires_at->toDateString();
        $this->approve($paymentId)->assertStatus(409);
        $this->assertSame($expires, $subscription->fresh()->subscription_expires_at->toDateString());

        $owner = $this->owner(smsPricing: false);
        $bill = $this->billFor($owner, Bill::TYPE_BASE);
        $paymentId = $this->pay($owner, $bill, 'MISMATCH')->json('payment.id');
        Bill::findOrFail($bill['id'])->update(['total_minor' => $bill['total_minor'] + 1]);
        $this->approve($paymentId)->assertStatus(409);
    }

    public function test_rejection_resets_bill_and_proof_workflow_can_return_to_pending(): void
    {
        $owner = $this->owner(smsPricing: false);
        $bill = $this->billFor($owner, Bill::TYPE_BASE);
        $paymentId = $this->pay($owner, $bill, 'REJECT-ME')->json('payment.id');
        $this->actingAs($this->superAdmin())->postJson('/api/super-admin/billing/payments/'.$paymentId.'/reject')->assertStatus(422);
        $this->actingAs($this->superAdmin())->postJson('/api/super-admin/billing/payments/'.$paymentId.'/request-proof', ['reason' => 'Need a clearer receipt'])->assertOk();
        $this->approve($paymentId)->assertStatus(409);
        $this->actingAs($owner)->post('/api/billing/payments/'.$paymentId.'/corrected-proof', ['proof' => UploadedFile::fake()->create('corrected.jpg', 120, 'image/jpeg')])->assertOk();
        $this->actingAs($this->superAdmin())->postJson('/api/super-admin/billing/payments/'.$paymentId.'/reject', ['reason' => 'Still unreadable'])->assertOk();
        $this->assertSame(Bill::STATUS_UNPAID, Bill::findOrFail($bill['id'])->status);
        $this->assertSame(Payment::STATUS_REJECTED, Payment::findOrFail($paymentId)->status);
        $this->pay($owner, $bill, 'RETRY-AFTER-REJECT')->assertCreated();
    }

    public function test_non_super_admin_cannot_approve_or_reject_and_legacy_subscription_is_created(): void
    {
        $owner = $this->owner('legacy', smsPricing: false);
        $paymentId = $this->pay($owner, $this->billFor($owner, Bill::TYPE_BASE), 'LEGACY-SUPER-ADMIN')->json('payment.id');
        $this->actingAs($owner)->postJson('/api/super-admin/billing/payments/'.$paymentId.'/approve')->assertForbidden();
        $this->actingAs($owner)->postJson('/api/super-admin/billing/payments/'.$paymentId.'/reject', ['reason' => 'No'])->assertForbidden();
        $this->approve($paymentId)->assertOk();
        $this->assertSame(BaseSubscription::STATUS_ACTIVE, $owner->organization->baseSubscription()->firstOrFail()->status);
    }

    public function test_approving_a_package_extends_base_and_sms_and_approves_the_sms_request(): void
    {
        $owner = $this->owner();
        $request = OrganizationFeatureRequest::create(['organization_id' => $owner->organization_id, 'feature' => OrganizationFeatureRequest::FEATURE_SMS, 'status' => OrganizationFeatureRequest::STATUS_PENDING, 'requested_by' => $owner->id, 'requested_at' => now(), 'activation_mode' => OrganizationFeatureRequest::ACTIVATION_AFTER_TRIAL]);
        $paymentId = $this->pay($owner, $this->billFor($owner, Bill::TYPE_PACKAGE), 'PKG-001')->assertCreated()->json('payment.id');

        $this->approve($paymentId)->assertOk();

        $subscription = $owner->organization->baseSubscription()->firstOrFail();
        $entitlement = $owner->organization->featureEntitlements()->where('feature', FeatureEntitlement::SMS)->firstOrFail();
        $this->assertSame('2026-11-20', $subscription->subscription_starts_at->toDateString());
        $this->assertSame('2026-11-20', $entitlement->starts_at->toDateString());
        $this->assertSame('2026-12-20', $entitlement->expires_at->toDateString());
        $this->assertSame(OrganizationFeatureRequest::STATUS_APPROVED, $request->fresh()->status);
    }

    public function test_active_organization_can_pay_base_early_and_gets_the_next_unpaid_period(): void
    {
        $owner = $this->owner('active', smsPricing: false);
        $first = $this->billFor($owner, Bill::TYPE_BASE);
        $this->approve($this->pay($owner, $first, 'YEAR-BASE-1')->json('payment.id'))->assertOk();

        $billing = $this->actingAs($owner)->getJson('/api/billing?type=base')->assertOk();
        $billing->assertJsonPath('paid_bill.id', $first['id']);
        $next = $billing->json('current_bill');
        $this->assertSame(Bill::STATUS_UNPAID, $next['status']);
        $this->assertSame(
            Carbon::parse($first['billing_period_end'])->addDay()->toDateString(),
            substr($next['billing_period_start'], 0, 10),
        );
    }

    public function test_two_early_base_payments_extend_coverage_two_months_without_overlapping_bills(): void
    {
        $owner = $this->owner('active', smsPricing: false);
        $first = $this->billFor($owner, Bill::TYPE_BASE);
        $this->approve($this->pay($owner, $first, 'YEAR-BASE-A')->json('payment.id'))->assertOk();
        $second = $this->billFor($owner, Bill::TYPE_BASE);
        $this->approve($this->pay($owner, $second, 'YEAR-BASE-B')->json('payment.id'))->assertOk();

        $bills = Bill::where('organization_id', $owner->organization_id)->where('bill_type', Bill::TYPE_BASE)->where('status', Bill::STATUS_PAID)->orderBy('billing_period_start')->get();
        $this->assertCount(2, $bills);
        $this->assertSame($bills[0]->billing_period_end->copy()->addDay()->toDateString(), $bills[1]->billing_period_start->toDateString());
        $this->assertSame($bills[1]->billing_period_end->copy()->addDay()->toDateString(), $owner->organization->baseSubscription()->firstOrFail()->subscription_expires_at->toDateString());
    }

    public function test_expired_scanner_is_redirected_and_new_bill_starts_today(): void
    {
        $owner = $this->owner('expired', smsPricing: false);

        $this->actingAs($owner)->get('/scanner')->assertRedirect('/billing')->assertSessionHas('subscription_expired', true);
        $this->assertSame('2026-11-10', substr($this->billFor($owner, Bill::TYPE_BASE)['billing_period_start'], 0, 10));
    }

    public function test_billing_uses_the_organization_timezone_for_the_calendar_date(): void
    {
        $this->travelTo('2026-11-10 23:30:00');
        $owner = $this->owner('legacy', smsPricing: false);

        $this->assertSame('2026-11-11', substr($this->billFor($owner, Bill::TYPE_BASE)['billing_period_start'], 0, 10));
    }

    public function test_january_31_expiry_produces_a_non_leap_year_february_period(): void
    {
        $this->travelTo('2027-01-01 02:00:00');
        $owner = $this->owner('active', smsPricing: false);
        $owner->organization->baseSubscription()->update(['subscription_expires_at' => '2027-01-31', 'status' => BaseSubscription::STATUS_ACTIVE]);

        $bill = $this->billFor($owner, Bill::TYPE_BASE);
        $this->assertSame('2027-01-31', substr($bill['billing_period_start'], 0, 10));
        $this->assertSame('2027-02-27', substr($bill['billing_period_end'], 0, 10));
    }

    public function test_bill_type_migration_backfills_legacy_bills_and_reverses_cleanly(): void
    {
        $owner = $this->owner('legacy', smsPricing: false);
        $migration = require database_path('migrations/2026_11_08_222240_add_bill_type_to_bills_table.php');

        Schema::table('bills', function ($table): void {
            $table->dropUnique('bills_type_period_unique');
            $table->dropColumn('bill_type');
            $table->unique(['organization_id', 'billing_period_start', 'billing_period_end'], 'bills_period_unique');
        });

        $common = ['organization_id' => $owner->organization_id, 'billing_period_start' => '2026-12-01', 'billing_period_end' => '2026-12-31', 'currency' => 'PHP', 'subtotal_minor' => 0, 'total_minor' => 0, 'status' => Bill::STATUS_UNPAID, 'created_at' => now(), 'updated_at' => now()];
        $packageId = DB::table('bills')->insertGetId($common);
        $baseId = DB::table('bills')->insertGetId(array_merge($common, ['billing_period_start' => '2027-01-01', 'billing_period_end' => '2027-01-31']));
        DB::table('bill_items')->insert(['bill_id' => $packageId, 'type' => 'sms_addon', 'description' => 'SMS Notifications', 'quantity' => 1, 'unit_amount_minor' => 49900, 'amount_minor' => 49900, 'created_at' => now(), 'updated_at' => now()]);

        $migration->up();

        $this->assertSame('package', DB::table('bills')->where('id', $packageId)->value('bill_type'));
        $this->assertSame('base', DB::table('bills')->where('id', $baseId)->value('bill_type'));
        $indexNames = collect(Schema::getIndexes('bills'))->pluck('name');
        $this->assertTrue($indexNames->contains('bills_type_period_unique'));
        $this->assertFalse($indexNames->contains('bills_period_unique'));

        $migration->down();

        $this->assertFalse(Schema::hasColumn('bills', 'bill_type'));
        $downIndexNames = collect(Schema::getIndexes('bills'))->pluck('name');
        $this->assertTrue($downIndexNames->contains('bills_period_unique'));
        $this->assertFalse($downIndexNames->contains('bills_type_period_unique'));

        // Leave the RefreshDatabase connection in the application's current schema.
        $migration->up();
    }

    public function test_paid_bill_returns_a_clear_message_instead_of_accepting_a_second_payment(): void
    {
        $owner = $this->owner('active', smsPricing: false);
        $bill = $this->billFor($owner, Bill::TYPE_BASE);
        $this->approve($this->pay($owner, $bill, 'PAID-1')->json('payment.id'))->assertOk();

        $this->pay($owner, $bill, 'PAID-2')->assertStatus(422)->assertJsonPath('message', 'This bill has already been paid. Refresh the page to pay for the next period.');
    }

    public function test_payment_without_a_receipt_is_accepted_for_review(): void
    {
        $owner = $this->owner();

        $this->pay($owner, $this->billFor($owner, Bill::TYPE_BASE), 'NO-PROOF-1')->assertCreated()->assertJsonPath('payment.status', Payment::STATUS_PENDING);
        $this->assertNull(Payment::firstOrFail()->proof_path);
    }

    public function test_jpg_png_and_pdf_receipts_are_stored_under_payment_proofs(): void
    {
        foreach ([
            UploadedFile::fake()->create('receipt.jpg', 120, 'image/jpeg'),
            UploadedFile::fake()->create('receipt.png', 120, 'image/png'),
            UploadedFile::fake()->create('receipt.pdf', 900, 'application/pdf'),
        ] as $index => $proof) {
            $owner = $this->owner(smsPricing: false);
            $bill = $this->billFor($owner, Bill::TYPE_BASE);

            $this->pay($owner, $bill, 'VALID-PROOF-'.$index, ['proof' => $proof])->assertCreated();

            $payment = Payment::where('reference_number', 'VALID-PROOF-'.$index)->firstOrFail();
            $this->assertNotNull($payment->proof_path);
            $this->assertStringStartsWith('payment-proofs/', $payment->proof_path);
            Storage::disk('local')->assertExists($payment->proof_path);
        }
    }

    public function test_heic_webp_and_gif_receipts_are_rejected(): void
    {
        foreach ([
            UploadedFile::fake()->create('receipt.heic', 300, 'image/heic'),
            UploadedFile::fake()->create('receipt.webp', 300, 'image/webp'),
            UploadedFile::fake()->create('receipt.gif', 300, 'image/gif'),
        ] as $index => $proof) {
            $owner = $this->owner(smsPricing: false);

            $this->pay($owner, $this->billFor($owner, Bill::TYPE_BASE), 'INVALID-PROOF-'.$index, ['proof' => $proof])
                ->assertStatus(422)
                ->assertJsonPath('errors.proof.0', 'The receipt must be a JPG, PNG, or PDF file.');
        }
    }

    public function test_failed_php_upload_uses_the_plain_upload_error_message(): void
    {
        $owner = $this->owner(smsPricing: false);
        $path = tempnam(sys_get_temp_dir(), 'failed-upload-');
        file_put_contents($path, 'receipt');
        $proof = new SymfonyUploadedFile($path, 'receipt.jpg', 'image/jpeg', UPLOAD_ERR_INI_SIZE, true);

        $this->pay($owner, $this->billFor($owner, Bill::TYPE_BASE), 'FAILED-UPLOAD', ['proof' => $proof])
            ->assertStatus(422)
            ->assertJsonPath('errors.proof.0', 'The receipt could not be uploaded. Use a JPG, PNG, or PDF under 5 MB, or submit without a receipt.');
    }

    public function test_amount_must_match_the_bill_total(): void
    {
        $owner = $this->owner();

        $this->pay($owner, $this->billFor($owner, Bill::TYPE_PACKAGE), 'WRONG-AMOUNT', ['amount' => '2999.00'])
            ->assertStatus(422)->assertJsonPath('message', 'The amount must match the bill total of PHP 3,498.00. Refresh the page and try again.');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_amount_formats_are_accepted_when_the_amount_matches_the_bill_total(): void
    {
        foreach (['3498', '3498.0', '3498.00'] as $index => $amount) {
            $owner = $this->owner();
            $bill = $this->billFor($owner, Bill::TYPE_PACKAGE);

            $this->pay($owner, $bill, 'AMOUNT-FORMAT-'.$index, ['amount' => $amount])->assertCreated();
        }
    }

    public function test_a_second_payment_on_the_same_bill_is_rejected_as_under_review(): void
    {
        $owner = $this->owner(smsPricing: false);
        $bill = $this->billFor($owner, Bill::TYPE_BASE);

        $this->pay($owner, $bill, 'DUPLICATE-1')->assertCreated();
        $this->pay($owner, $bill, 'DUPLICATE-2')->assertStatus(422)
            ->assertJsonPath('message', 'A payment for this bill is already under review.');
    }

    public function test_a_payment_under_review_blocks_the_other_plan(): void
    {
        $message = 'Another payment covering the same plan is already under review. Wait for it to be reviewed before paying again.';
        $owner = $this->owner('active');
        $this->pay($owner, $this->billFor($owner, Bill::TYPE_PACKAGE), 'PACKAGE-REVIEW')->assertCreated();
        $this->pay($owner, $this->billFor($owner, Bill::TYPE_BASE), 'BASE-BLOCKED')->assertStatus(422)->assertJsonPath('message', $message);

        $owner = $this->owner('active');
        $this->pay($owner, $this->billFor($owner, Bill::TYPE_BASE), 'BASE-REVIEW')->assertCreated();
        $this->pay($owner, $this->billFor($owner, Bill::TYPE_PACKAGE), 'PACKAGE-BLOCKED')->assertStatus(422)->assertJsonPath('message', $message);
    }

    public function test_pending_or_approved_gcash_references_are_rejected_but_rejected_references_can_be_reused(): void
    {
        $owner = $this->owner(smsPricing: false);
        $firstBill = $this->billFor($owner, Bill::TYPE_BASE);
        $pending = $this->pay($owner, $firstBill, 'GCASH-PENDING')->assertCreated()->json('payment.id');
        $this->assertSame(Payment::STATUS_PENDING, Payment::findOrFail($pending)->status);

        $otherOwner = $this->owner(smsPricing: false);
        $this->pay($otherOwner, $this->billFor($otherOwner, Bill::TYPE_BASE), 'GCASH-PENDING')
            ->assertStatus(422)->assertJsonPath('message', 'This payment reference is already active or approved.');

        $this->actingAs($this->superAdmin())->postJson('/api/super-admin/billing/payments/'.$pending.'/reject', ['reason' => 'Unreadable receipt'])->assertOk();
        $this->pay($otherOwner, $this->billFor($otherOwner, Bill::TYPE_BASE), 'GCASH-PENDING')->assertCreated();
    }

    public function test_stale_bill_is_cancelled_and_cannot_be_paid(): void
    {
        $owner = $this->owner('active');
        $staleBase = $this->billFor($owner, Bill::TYPE_BASE);
        $this->approve($this->pay($owner, $this->billFor($owner, Bill::TYPE_PACKAGE), 'PKG-MOVES-BASE')->json('payment.id'))->assertOk();

        $freshBase = $this->billFor($owner, Bill::TYPE_BASE);
        $this->assertNotSame($staleBase['id'], $freshBase['id']);
        $this->assertSame(Bill::STATUS_CANCELLED, Bill::findOrFail($staleBase['id'])->status);
        $this->pay($owner, $staleBase, 'STALE-1')->assertStatus(422)->assertJsonPath('message', 'This bill is no longer current. Refresh the page and submit against the latest bill.');
    }

    public function test_owner_cannot_pay_another_organizations_bill(): void
    {
        $owner = $this->owner();
        $otherBill = $this->billFor($this->owner(), Bill::TYPE_BASE);

        $this->pay($owner, $otherBill, 'CROSS-TENANT')->assertNotFound();
    }

    public function test_operational_roles_cannot_view_or_submit_billing(): void
    {
        $owner = $this->owner();
        $scanner = User::factory()->create(['organization_id' => $owner->organization_id, 'role' => 'scanner']);

        $this->actingAs($scanner)->getJson('/api/billing')->assertForbidden();
        $this->pay($scanner, $this->billFor($owner, Bill::TYPE_BASE), 'SCANNER-1')->assertForbidden();
    }

    public function test_oversized_request_body_returns_a_json_message(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->call('POST', '/api/billing/payments', [], [], [], ['CONTENT_LENGTH' => 50 * 1024 * 1024, 'HTTP_ACCEPT' => 'application/json'])
            ->assertStatus(413)->assertJsonPath('message', 'The upload is too large. Use a receipt under 5 MB, or submit without a receipt.');
    }
}

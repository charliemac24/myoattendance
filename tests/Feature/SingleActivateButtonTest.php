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
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Super admin has one Activate button per submitted payment. The bill the organization chose when it paid
 * decides what it turns on: Base = Scan only, Base + SMS = Scan + SMS, "Add SMS" = SMS on top of Scan.
 */
class SingleActivateButtonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-10 02:00:00');
        Storage::fake('local');
        Http::fake();
    }

    private function owner(string $subscription = 'expired'): User
    {
        $organization = Organization::create(['name' => 'School '.uniqid(), 'type' => 'school', 'timezone' => 'Asia/Manila']);
        $owner = User::factory()->create(['organization_id' => $organization->id, 'role' => 'owner']);
        match ($subscription) {
            'active' => BaseSubscription::create(['organization_id' => $organization->id, 'subscription_starts_at' => now()->subDays(5), 'subscription_expires_at' => now()->addDays(25), 'status' => 'active']),
            'expired' => BaseSubscription::create(['organization_id' => $organization->id, 'trial_ends_at' => now()->subDay(), 'status' => 'expired']),
        };
        SmsPricingAgreement::create(['organization_id' => $organization->id, 'sms_monthly_price_minor' => 49900, 'person_count_snapshot' => 50, 'effective_from' => '2026-11-01', 'status' => SmsPricingAgreement::STATUS_ACTIVE, 'created_by' => $owner->id, 'agreed_at' => now()]);

        return $owner;
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['organization_id' => null, 'role' => User::ROLE_SUPER_ADMIN]);
    }

    private function submitProof(User $owner, string $type, string $reference): int
    {
        $bill = $this->actingAs($owner)->getJson('/api/billing?type='.$type)->assertOk()->json('current_bill');

        return $this->actingAs($owner)->post('/api/billing/payments', [
            'amount' => number_format($bill['total_minor'] / 100, 2, '.', ''),
            'bill_id' => $bill['id'],
            'reference_number' => $reference,
            'paid_at' => '2026-11-10',
        ], ['Accept' => 'application/json'])->assertCreated()->json('payment.id');
    }

    private function activate(int $paymentId): TestResponse
    {
        return $this->actingAs($this->superAdmin())->postJson('/api/super-admin/billing/payments/'.$paymentId.'/activate');
    }

    private function canScan(User $owner): bool
    {
        return $owner->organization->baseSubscription()->firstOrFail()->status === 'active';
    }

    private function canSms(User $owner): bool
    {
        return app(FeatureEntitlementService::class)->canUseSms($owner->organization()->firstOrFail());
    }

    public function test_scan_only_payment_is_labelled_and_activates_scan_without_sms(): void
    {
        $owner = $this->owner();
        $paymentId = $this->submitProof($owner, Bill::TYPE_BASE, 'SCAN-ONLY');

        $this->actingAs($this->superAdmin())->getJson('/api/super-admin/billing/payments?status=pending')->assertOk()
            ->assertJsonPath('data.0.activates', Payment::ACTIVATES_SCAN)
            ->assertJsonPath('data.0.activates_label', 'Scan only');
        $this->actingAs($this->superAdmin())->getJson('/api/super-admin/billing/payments/'.$paymentId)->assertOk()
            ->assertJsonPath('activates', Payment::ACTIVATES_SCAN)
            ->assertJsonPath('activates_label', 'Scan only');

        $this->activate($paymentId)->assertOk()->assertJsonPath('activated', Payment::ACTIVATES_SCAN)->assertJsonPath('payment.status', Payment::STATUS_APPROVED);

        $this->assertTrue($this->canScan($owner));
        $this->assertFalse($this->canSms($owner));
    }

    public function test_scan_plus_sms_payment_is_labelled_and_activates_both(): void
    {
        $owner = $this->owner();
        $paymentId = $this->submitProof($owner, Bill::TYPE_PACKAGE, 'SCAN-SMS');

        $this->actingAs($this->superAdmin())->getJson('/api/super-admin/billing/payments?status=pending')->assertOk()
            ->assertJsonPath('data.0.activates', Payment::ACTIVATES_SCAN_AND_SMS)
            ->assertJsonPath('data.0.activates_label', 'Scan + SMS');
        $this->actingAs($this->superAdmin())->getJson('/api/super-admin/billing/payments/'.$paymentId)->assertOk()
            ->assertJsonPath('activates_label', 'Scan + SMS');

        $this->activate($paymentId)->assertOk()->assertJsonPath('activated', Payment::ACTIVATES_SCAN_AND_SMS);

        $this->assertTrue($this->canScan($owner));
        $this->assertTrue($this->canSms($owner));
    }

    public function test_start_sms_now_payment_activates_sms_on_top_of_existing_scan(): void
    {
        $owner = $this->owner('active');
        $paymentId = $this->submitProof($owner, Bill::TYPE_SMS_NOW, 'SMS-NOW');

        $this->actingAs($this->superAdmin())->getJson('/api/super-admin/billing/payments/'.$paymentId)->assertOk()
            ->assertJsonPath('activates', Payment::ACTIVATES_SMS)
            ->assertJsonPath('activates_label', 'SMS (Scan already active)');

        $this->activate($paymentId)->assertOk()->assertJsonPath('activated', Payment::ACTIVATES_SMS);

        $this->assertTrue($this->canScan($owner));
        $this->assertTrue($this->canSms($owner));
    }

    public function test_older_payment_without_a_bill_still_activates_scan_only(): void
    {
        $owner = $this->owner();
        $payment = Payment::create(['organization_id' => $owner->organization_id, 'payment_type' => Payment::TYPE_BASE_SUBSCRIPTION, 'status' => Payment::STATUS_PENDING, 'amount_minor' => 50000, 'currency' => 'PHP', 'payment_method' => Payment::METHOD_GCASH, 'reference_number' => 'LEGACY', 'paid_at' => now(), 'submitted_by' => $owner->id]);

        $this->assertSame(Payment::ACTIVATES_SCAN, $payment->activates());
        $this->activate($payment->id)->assertOk()->assertJsonPath('activated', Payment::ACTIVATES_SCAN);

        $this->assertTrue($this->canScan($owner));
        $this->assertFalse($this->canSms($owner));
    }

    public function test_activate_works_once_and_only_for_super_admin(): void
    {
        $owner = $this->owner();
        $paymentId = $this->submitProof($owner, Bill::TYPE_PACKAGE, 'ONCE');

        $this->actingAs($owner)->postJson('/api/super-admin/billing/payments/'.$paymentId.'/activate')->assertForbidden();
        $this->activate($paymentId)->assertOk();
        $this->activate($paymentId)->assertStatus(409);
    }

    public function test_super_admin_screen_has_one_activate_button_and_no_manual_sms_activation(): void
    {
        $page = file_get_contents(resource_path('js/pages/SuperAdminPage.js'));

        $this->assertSame(1, substr_count($page, "'Activate'"));
        $this->assertStringContainsString("/activate'", $page);
        $this->assertStringNotContainsString("'Approve'", $page);
        $this->assertStringNotContainsString('Enable SMS', $page);
        $this->assertStringNotContainsString('Extend SMS access', $page);
        $this->assertStringNotContainsString('/sms/enable', $page);
    }

    public function test_paid_base_school_is_offered_add_sms_for_the_days_left_only(): void
    {
        $owner = $this->owner('active');
        $baseEnds = $owner->organization->baseSubscription()->firstOrFail()->subscription_expires_at;

        $billing = $this->actingAs($owner)->getJson('/api/billing?type=sms_now')->assertOk()->assertJsonPath('bill_type', Bill::TYPE_SMS_NOW)->assertJsonPath('sms_now.available', true);
        $this->assertSame($billing->json('sms_now.amount_minor'), $billing->json('current_bill.total_minor'));
        $this->assertLessThan(49900, $billing->json('current_bill.total_minor'));
        $this->assertSame(['sms_addon'], array_column($billing->json('current_bill.items'), 'type'));

        $paymentId = $this->submitProof($owner, Bill::TYPE_SMS_NOW, 'ADD-SMS');
        $this->activate($paymentId)->assertOk()->assertJsonPath('activated', Payment::ACTIVATES_SMS);

        $this->assertTrue($this->canSms($owner));
        $this->assertTrue($baseEnds->equalTo($owner->organization->baseSubscription()->firstOrFail()->subscription_expires_at));
    }

    public function test_paid_base_school_whose_sms_price_is_ready_lands_on_add_sms(): void
    {
        $owner = $this->owner('active');
        // The school asked for SMS before its price was set; the price is set now.
        OrganizationFeatureRequest::create(['organization_id' => $owner->organization_id, 'feature' => OrganizationFeatureRequest::FEATURE_SMS, 'status' => OrganizationFeatureRequest::STATUS_PENDING, 'requested_by' => $owner->id, 'requested_at' => now()]);

        $this->actingAs($owner)->getJson('/api/billing')->assertOk()
            ->assertJsonPath('current_plan.sms.state', 'price_ready')
            ->assertJsonPath('bill_type', Bill::TYPE_SMS_NOW);
    }

    public function test_base_plus_sms_is_still_the_choice_without_paid_base_or_in_the_last_days(): void
    {
        $expired = $this->owner('expired');
        $this->actingAs($expired)->getJson('/api/billing?type=package')->assertOk()->assertJsonPath('bill_type', Bill::TYPE_PACKAGE)->assertJsonPath('sms_now.available', false);

        $owner = $this->owner('active');
        $this->travelTo('2026-12-04 02:00:00');
        $this->actingAs($owner)->getJson('/api/billing')->assertOk()->assertJsonPath('sms_now.available', false)->assertJsonPath('sms_now.reason', 'too_few_days');
    }

    public function test_billing_page_shows_add_sms_and_hides_base_plus_sms_while_it_is_offered(): void
    {
        $script = file_get_contents(resource_path('js/billing.js'));

        $this->assertStringContainsString("button.hidden = type === 'package' && addSmsMode;", $script);
        $this->assertStringContainsString('Add SMS · ${amount}', $script);
        $this->assertStringNotContainsString('Start SMS now', $script);
    }

    public function test_recent_payments_show_the_newest_submission_first_even_on_the_same_paid_day(): void
    {
        $first = $this->owner();
        $second = $this->owner();
        $third = $this->owner();
        $firstId = $this->submitProof($first, Bill::TYPE_BASE, 'SAME-DAY-1');
        $this->travel(5)->minutes();
        $secondId = $this->submitProof($second, Bill::TYPE_PACKAGE, 'SAME-DAY-2');
        $this->travel(5)->minutes();
        $thirdId = $this->submitProof($third, Bill::TYPE_BASE, 'SAME-DAY-3');

        $this->assertSame([$thirdId, $secondId, $firstId], array_slice(array_column($this->actingAs($this->superAdmin())->getJson('/api/super-admin/dashboard')->assertOk()->json('recent_payments'), 'id'), 0, 3));
        $this->assertSame([$thirdId, $secondId, $firstId], array_column($this->actingAs($this->superAdmin())->getJson('/api/super-admin/billing/payments')->assertOk()->json('data'), 'id'));
    }

    public function test_sms_turned_off_by_admin_can_be_turned_back_on_until_the_paid_end_date(): void
    {
        $owner = $this->owner();
        $admin = $this->superAdmin();
        $this->activate($this->submitProof($owner, Bill::TYPE_PACKAGE, 'RESUME'))->assertOk();
        $paidUntil = $owner->organization->featureEntitlements()->firstOrFail()->expires_at->toDateString();
        $organizationUrl = '/api/super-admin/organizations/'.$owner->organization_id;

        $this->actingAs($admin)->postJson($organizationUrl.'/sms/disable')->assertOk();
        $this->assertFalse($this->canSms($owner));
        $this->actingAs($admin)->getJson($organizationUrl)->assertOk()
            ->assertJsonPath('organization.sms_access_status', 'disabled')
            ->assertJsonPath('organization.sms_resumable_until', $paidUntil);

        $this->actingAs($owner)->postJson($organizationUrl.'/sms/resume')->assertForbidden();
        $this->actingAs($admin)->postJson($organizationUrl.'/sms/resume')->assertOk();

        $this->assertTrue($this->canSms($owner));
        $this->assertSame($paidUntil, $owner->organization->featureEntitlements()->firstOrFail()->expires_at->toDateString());
        $this->actingAs($admin)->getJson($organizationUrl)->assertOk()
            ->assertJsonPath('organization.sms_access_status', 'active')
            ->assertJsonPath('organization.sms_resumable_until', null);
    }

    public function test_sms_cannot_be_turned_back_on_without_paid_sms_time_left(): void
    {
        $neverPaid = $this->owner();
        $this->actingAs($this->superAdmin())->postJson('/api/super-admin/organizations/'.$neverPaid->organization_id.'/sms/resume')->assertStatus(409);

        $owner = $this->owner();
        $admin = $this->superAdmin();
        $this->activate($this->submitProof($owner, Bill::TYPE_PACKAGE, 'RESUME-LATE'))->assertOk();
        $this->actingAs($admin)->postJson('/api/super-admin/organizations/'.$owner->organization_id.'/sms/disable')->assertOk();
        $this->travel(2)->months();

        $this->actingAs($admin)->postJson('/api/super-admin/organizations/'.$owner->organization_id.'/sms/resume')->assertStatus(409);
        $this->assertFalse($this->canSms($owner));
    }

    private function recordCash(User $owner, array $data): TestResponse
    {
        $plan = $data['plan'] ?? null;
        $listPrice = in_array($plan, Bill::ALL_TYPES, true)
            ? $this->actingAs($this->superAdmin())->getJson('/api/super-admin/organizations/'.$owner->organization_id.'/recorded-payments/quote?plan='.$plan.'&months='.($data['months'] ?? 1))->json('bill.total_minor')
            : null;

        return $this->actingAs($this->superAdmin())->postJson('/api/super-admin/organizations/'.$owner->organization_id.'/recorded-payments', $data + ['payment_method' => Payment::METHOD_CASH, 'received_at' => '2026-11-10', 'amount' => $listPrice ? number_format($listPrice / 100, 2, '.', '') : '100.00']);
    }

    public function test_cash_for_base_is_recorded_approved_and_turns_on_scan_only(): void
    {
        $owner = $this->owner();
        $quote = $this->actingAs($this->superAdmin())->getJson('/api/super-admin/organizations/'.$owner->organization_id.'/recorded-payments/quote?plan=base&months=3')->assertOk()
            ->assertJsonPath('activates_label', 'Scan only')->assertJsonPath('under_review', false);

        $response = $this->recordCash($owner, ['plan' => Bill::TYPE_BASE, 'months' => 3, 'notes' => 'OR 1234'])->assertCreated()
            ->assertJsonPath('activated', Payment::ACTIVATES_SCAN)
            ->assertJsonPath('payment.status', Payment::STATUS_APPROVED)
            ->assertJsonPath('payment.payment_method', Payment::METHOD_CASH)
            ->assertJsonPath('payment.amount_minor', $quote->json('bill.total_minor'));

        $this->assertTrue($this->canScan($owner));
        $this->assertFalse($this->canSms($owner));
        $this->assertSame(Bill::STATUS_PAID, Bill::findOrFail($response->json('payment.bill_id'))->status);
        $this->assertSame(3, Bill::findOrFail($response->json('payment.bill_id'))->months);

        // The school sees it as a paid Cash payment on its Billing page; super admin sees it in both lists.
        $this->actingAs($owner)->getJson('/api/billing')->assertOk()
            ->assertJsonPath('payments.0.payment_method', Payment::METHOD_CASH)
            ->assertJsonPath('payments.0.status', Payment::STATUS_APPROVED)
            ->assertJsonPath('current_plan.status', 'active');
        $this->actingAs($this->superAdmin())->getJson('/api/super-admin/organizations/'.$owner->organization_id)->assertOk()
            ->assertJsonPath('organization.payments.0.payment_method', Payment::METHOD_CASH)
            ->assertJsonPath('organization.payments.0.notes', 'OR 1234');
        $this->actingAs($this->superAdmin())->getJson('/api/super-admin/billing/payments?status=approved')->assertOk()
            ->assertJsonPath('data.0.payment_method', Payment::METHOD_CASH)
            ->assertJsonPath('data.0.recorded_by.id', $response->json('payment.reviewed_by.id'));
        $this->assertArrayNotHasKey('password', $response->json('payment.reviewed_by'));
    }

    public function test_cash_for_base_plus_sms_turns_on_scan_and_sms(): void
    {
        $owner = $this->owner();

        $this->recordCash($owner, ['plan' => Bill::TYPE_PACKAGE, 'months' => 1])->assertCreated()->assertJsonPath('activated', Payment::ACTIVATES_SCAN_AND_SMS);

        $this->assertTrue($this->canScan($owner));
        $this->assertTrue($this->canSms($owner));
    }

    public function test_cash_for_add_sms_turns_on_sms_until_base_ends(): void
    {
        $owner = $this->owner('active');
        $baseEnds = $owner->organization->baseSubscription()->firstOrFail()->subscription_expires_at;
        $this->actingAs($this->superAdmin())->getJson('/api/super-admin/organizations/'.$owner->organization_id.'/recorded-payments/quote')->assertOk()
            ->assertJsonPath('plan', Bill::TYPE_SMS_NOW);

        $this->recordCash($owner, ['plan' => Bill::TYPE_SMS_NOW])->assertCreated()->assertJsonPath('activated', Payment::ACTIVATES_SMS);

        $this->assertTrue($this->canSms($owner));
        $this->assertTrue($baseEnds->equalTo($owner->organization->baseSubscription()->firstOrFail()->subscription_expires_at));
    }

    public function test_cash_is_refused_for_unavailable_plans_pending_reviews_and_non_admins(): void
    {
        $expired = $this->owner();
        $this->recordCash($expired, ['plan' => Bill::TYPE_SMS_NOW])->assertStatus(422);
        $this->recordCash($expired, ['plan' => 'gold'])->assertStatus(422);
        $this->recordCash($expired, ['plan' => Bill::TYPE_BASE, 'received_at' => '2026-12-01'])->assertStatus(422);

        $this->submitProof($expired, Bill::TYPE_BASE, 'WAITING');
        $this->recordCash($expired, ['plan' => Bill::TYPE_BASE])->assertStatus(409);

        $this->actingAs($expired)->postJson('/api/super-admin/organizations/'.$expired->organization_id.'/recorded-payments', ['plan' => Bill::TYPE_BASE, 'received_at' => '2026-11-10'])->assertForbidden();
        $this->assertSame(1, Payment::query()->where('organization_id', $expired->organization_id)->count());
    }

    public function test_cash_records_the_amount_received_but_still_turns_on_the_full_plan(): void
    {
        $owner = $this->owner();

        $response = $this->recordCash($owner, ['plan' => Bill::TYPE_PACKAGE, 'months' => 1, 'amount' => '3000'])->assertCreated()
            ->assertJsonPath('payment.amount_minor', 300000)
            ->assertJsonPath('activated', Payment::ACTIVATES_SCAN_AND_SMS);

        $this->assertTrue($this->canScan($owner));
        $this->assertTrue($this->canSms($owner));
        $this->assertSame(349800, Bill::findOrFail($response->json('payment.bill_id'))->total_minor);
        $this->assertSame(Bill::STATUS_PAID, Bill::findOrFail($response->json('payment.bill_id'))->status);
        $this->actingAs($this->superAdmin())->getJson('/api/super-admin/organizations/'.$owner->organization_id)->assertOk()
            ->assertJsonPath('organization.payments.0.amount_minor', 300000);
    }

    public function test_cash_amount_must_be_above_zero(): void
    {
        $owner = $this->owner();

        foreach (['0', '0.00', '-5', 'abc', '10.555'] as $amount) {
            $this->recordCash($owner, ['plan' => Bill::TYPE_BASE, 'amount' => $amount])->assertStatus(422)->assertJsonValidationErrors('amount');
        }
        $this->assertFalse($this->canScan($owner));
    }

    public function test_cash_base_plus_sms_for_a_new_or_expired_school_turns_sms_on_right_away(): void
    {
        $owner = $this->owner();
        $this->actingAs($this->superAdmin())->getJson('/api/super-admin/organizations/'.$owner->organization_id.'/recorded-payments/quote?plan=package')->assertOk()
            ->assertJsonPath('sms_can_start_today', false)
            ->assertJsonPath('starts_on', '2026-11-10');

        $this->recordCash($owner, ['plan' => Bill::TYPE_PACKAGE])->assertCreated();

        $this->assertTrue($this->canSms($owner));
    }

    public function test_cash_base_plus_sms_while_base_is_running_can_start_sms_today(): void
    {
        $owner = $this->owner('active');
        $this->actingAs($this->superAdmin())->getJson('/api/super-admin/organizations/'.$owner->organization_id.'/recorded-payments/quote?plan=package')->assertOk()
            ->assertJsonPath('sms_can_start_today', true)
            ->assertJsonPath('starts_on', '2026-12-05');

        $this->recordCash($owner, ['plan' => Bill::TYPE_PACKAGE, 'start_sms_today' => true])->assertCreated();

        $this->assertTrue($this->canSms($owner));
        $entitlement = $owner->organization->featureEntitlements()->firstOrFail();
        $this->assertSame('2026-11-10', $entitlement->starts_at->toDateString());
        $this->assertSame('2027-01-05', $entitlement->expires_at->toDateString());
    }

    public function test_cash_base_plus_sms_while_base_is_running_waits_for_the_next_period_when_unticked(): void
    {
        $owner = $this->owner('active');

        $this->recordCash($owner, ['plan' => Bill::TYPE_PACKAGE, 'start_sms_today' => false])->assertCreated();

        $this->assertFalse($this->canSms($owner));
        $this->assertSame('2026-12-05', $owner->organization->featureEntitlements()->firstOrFail()->starts_at->toDateString());
    }

    public function test_recorded_cash_payment_amount_date_and_note_can_be_edited(): void
    {
        $owner = $this->owner();
        $payment = $this->recordCash($owner, ['plan' => Bill::TYPE_BASE, 'notes' => 'OR 1'])->assertCreated()->json('payment');
        $expiresBefore = $owner->organization->baseSubscription()->firstOrFail()->subscription_expires_at;

        $this->actingAs($this->superAdmin())->putJson('/api/super-admin/recorded-payments/'.$payment['id'], ['payment_method' => 'cash', 'amount' => '2500.50', 'received_at' => '2026-11-09', 'notes' => 'OR 2'])->assertOk()
            ->assertJsonPath('payment.amount_minor', 250050)
            ->assertJsonPath('payment.notes', 'OR 2');
        $this->assertSame('2026-11-09', Payment::findOrFail($payment['id'])->paid_at->toDateString());
        $this->assertTrue($expiresBefore->equalTo($owner->organization->baseSubscription()->firstOrFail()->subscription_expires_at));

        $this->actingAs($this->superAdmin())->putJson('/api/super-admin/recorded-payments/'.$payment['id'], ['payment_method' => 'cash', 'amount' => '0', 'received_at' => '2026-11-09'])->assertStatus(422);
        $this->actingAs($owner)->putJson('/api/super-admin/recorded-payments/'.$payment['id'], ['payment_method' => 'cash', 'amount' => '1', 'received_at' => '2026-11-09'])->assertForbidden();

        $gcashId = $this->submitProof($this->owner(), Bill::TYPE_BASE, 'NOT-CASH');
        $this->actingAs($this->superAdmin())->putJson('/api/super-admin/recorded-payments/'.$gcashId, ['payment_method' => 'cash', 'amount' => '1', 'received_at' => '2026-11-09'])->assertNotFound();
    }

    public function test_gcash_the_school_only_messaged_about_can_be_recorded_with_its_reference(): void
    {
        $owner = $this->owner();

        $response = $this->recordCash($owner, ['plan' => Bill::TYPE_PACKAGE, 'payment_method' => Payment::METHOD_GCASH, 'reference_number' => '9012345678901'])->assertCreated()
            ->assertJsonPath('payment.payment_method', Payment::METHOD_GCASH)
            ->assertJsonPath('payment.reference_number', '9012345678901')
            ->assertJsonPath('payment.manual_entry', true)
            ->assertJsonPath('activated', Payment::ACTIVATES_SCAN_AND_SMS);

        $this->assertTrue($this->canScan($owner));
        $this->assertTrue($this->canSms($owner));
        $this->actingAs($owner)->getJson('/api/billing')->assertOk()
            ->assertJsonPath('payments.0.payment_method', Payment::METHOD_GCASH)
            ->assertJsonPath('payments.0.manual_entry', true);
        $this->actingAs($this->superAdmin())->getJson('/api/super-admin/dashboard')->assertOk()
            ->assertJsonPath('recent_payments.0.manual_entry', true);
        $this->assertSame(Payment::STATUS_APPROVED, Payment::findOrFail($response->json('payment.id'))->status);
    }

    public function test_bank_transfer_base_plus_sms_can_be_recorded_without_a_reference_and_turns_sms_on(): void
    {
        $owner = $this->owner();
        $this->recordCash($owner, ['plan' => Bill::TYPE_BASE, 'payment_method' => 'paypal'])->assertStatus(422)->assertJsonValidationErrors('payment_method');

        $this->recordCash($owner, ['plan' => Bill::TYPE_PACKAGE, 'payment_method' => Payment::METHOD_BANK_TRANSFER])->assertCreated()
            ->assertJsonPath('payment.payment_method', Payment::METHOD_BANK_TRANSFER)
            ->assertJsonPath('payment.reference_number', null)
            ->assertJsonPath('activated', Payment::ACTIVATES_SCAN_AND_SMS);
        $this->assertTrue($this->canScan($owner));
        $this->assertTrue($this->canSms($owner));

        // The school's Billing page lists it as a paid bank transfer, and super admin's Payment history shows it too.
        $this->actingAs($owner)->getJson('/api/billing')->assertOk()
            ->assertJsonPath('payments.0.payment_method', Payment::METHOD_BANK_TRANSFER)
            ->assertJsonPath('payments.0.status', Payment::STATUS_APPROVED)
            ->assertJsonPath('payments.0.manual_entry', true)
            ->assertJsonPath('payments.0.bill.bill_type', Bill::TYPE_PACKAGE);
        $this->actingAs($this->superAdmin())->getJson('/api/super-admin/organizations/'.$owner->organization_id)->assertOk()
            ->assertJsonPath('organization.payments.0.payment_method', Payment::METHOD_BANK_TRANSFER)
            ->assertJsonPath('organization.payments.0.manual_entry', true);
    }

    public function test_a_recorded_reference_already_on_another_payment_is_refused(): void
    {
        $this->submitProof($this->owner(), Bill::TYPE_BASE, 'GCASH-DUP');
        $other = $this->owner();

        $this->recordCash($other, ['plan' => Bill::TYPE_BASE, 'payment_method' => Payment::METHOD_GCASH, 'reference_number' => 'GCASH-DUP'])->assertStatus(422);
        $this->assertFalse($this->canScan($other));
    }

    public function test_recorded_payment_method_and_reference_can_be_edited(): void
    {
        $owner = $this->owner();
        $payment = $this->recordCash($owner, ['plan' => Bill::TYPE_BASE])->assertCreated()->json('payment');

        $this->actingAs($this->superAdmin())->putJson('/api/super-admin/recorded-payments/'.$payment['id'], ['payment_method' => 'gcash', 'reference_number' => 'GC-777', 'amount' => '2999', 'received_at' => '2026-11-10'])->assertOk()
            ->assertJsonPath('payment.payment_method', Payment::METHOD_GCASH)
            ->assertJsonPath('payment.reference_number', 'GC-777')
            ->assertJsonPath('payment.manual_entry', true);
        // Editing it again keeps its own reference.
        $this->actingAs($this->superAdmin())->putJson('/api/super-admin/recorded-payments/'.$payment['id'], ['payment_method' => 'bank_transfer', 'reference_number' => 'GC-777', 'amount' => '2999', 'received_at' => '2026-11-10'])->assertOk()
            ->assertJsonPath('payment.payment_method', Payment::METHOD_BANK_TRANSFER);
        $this->actingAs($this->superAdmin())->putJson('/api/super-admin/recorded-payments/'.$payment['id'], ['payment_method' => 'card', 'amount' => '2999', 'received_at' => '2026-11-10'])->assertStatus(422);
        $this->assertTrue($this->canScan($owner));
    }
}

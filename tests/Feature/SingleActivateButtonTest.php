<?php

namespace Tests\Feature;

use App\Models\BaseSubscription;
use App\Models\Bill;
use App\Models\Organization;
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
 * decides what it turns on: Base = Scan only, Base + SMS = Scan + SMS, "Start SMS now" = SMS on top of Scan.
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
}

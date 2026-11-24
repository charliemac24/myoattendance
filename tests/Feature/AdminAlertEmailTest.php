<?php

namespace Tests\Feature;

use App\Mail\NewSignupAlert;
use App\Mail\PaymentSubmittedAlert;
use App\Mail\SmsPriceRequestedAlert;
use App\Models\BaseSubscription;
use App\Models\Bill;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\SmsPricingAgreement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Super admin gets one email per payment to review, SMS price request and new sign-up, only when ALERT_EMAILS is on.
 */
class AdminAlertEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-10 02:00:00');
        Http::fake();
        Storage::fake('local');
        Mail::fake();
        config(['services.admin_alerts.enabled' => true, 'services.admin_alerts.to' => 'alerts@myo.test, boss@myo.test']);
    }

    private function trialOwner(bool $price = false): User
    {
        $organization = Organization::create(['name' => 'Alert School', 'type' => 'school', 'timezone' => 'Asia/Manila']);
        $owner = User::factory()->create(['organization_id' => $organization->id, 'role' => 'owner']);
        BaseSubscription::create(['organization_id' => $organization->id, 'trial_starts_at' => now()->subDays(20), 'trial_ends_at' => now()->addDays(10), 'status' => 'trialing']);
        if ($price) {
            SmsPricingAgreement::create(['organization_id' => $organization->id, 'sms_monthly_price_minor' => 49900, 'person_count_snapshot' => 50, 'effective_from' => '2026-11-01', 'status' => SmsPricingAgreement::STATUS_ACTIVE, 'created_by' => $owner->id, 'agreed_at' => now()]);
        }

        return $owner;
    }

    private function submitPayment(User $owner): int
    {
        $bill = $this->actingAs($owner)->getJson('/api/billing?type='.Bill::TYPE_PACKAGE)->assertOk()->json('current_bill');

        return $this->actingAs($owner)->postJson('/api/billing/payments', [
            'amount' => number_format($bill['total_minor'] / 100, 2, '.', ''),
            'bill_id' => $bill['id'],
            'reference_number' => 'GC-ALERT-1',
            'paid_at' => '2026-11-10',
        ])->assertCreated()->json('payment.id');
    }

    private function register(array $extra = []): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'New Owner',
            'username' => 'newowner',
            'organization_name' => 'Fresh Academy',
            'organization_type' => 'school',
            'email' => 'newowner@example.com',
            'contact_number' => '09171234567',
            'password' => 'Secret-password-123',
            'password_confirmation' => 'Secret-password-123',
        ] + $extra)->assertCreated();
    }

    public function test_submitted_payment_queues_one_alert_with_amount_plan_reference_and_review_link(): void
    {
        $paymentId = $this->submitPayment($this->trialOwner(price: true));

        Mail::assertQueuedCount(1);
        Mail::assertQueued(PaymentSubmittedAlert::class, function (PaymentSubmittedAlert $mail) use ($paymentId): bool {
            return $mail->hasTo('alerts@myo.test') && $mail->hasTo('boss@myo.test')
                && $mail->details['organization'] === 'Alert School'
                && $mail->details['amount'] === 'PHP '.number_format(Payment::findOrFail($paymentId)->amount_minor / 100, 2)
                && $mail->details['plan'] === 'Scan + SMS'
                && $mail->details['reference'] === 'GC-ALERT-1'
                && str_ends_with($mail->details['url'], '/super-admin?payment='.$paymentId);
        });
    }

    public function test_custom_sms_price_request_queues_an_alert_with_the_answers_and_estimate_once(): void
    {
        $owner = $this->trialOwner();

        $this->actingAs($owner)->postJson('/api/organization/sms/access/request', ['people' => 80, 'texts' => ['time_in', 'time_out'], 'days' => 'weekdays'])->assertCreated();
        $this->actingAs($owner)->postJson('/api/organization/sms/access/request', ['people' => 80, 'texts' => ['time_in', 'time_out'], 'days' => 'weekdays'])->assertOk();

        Mail::assertQueuedCount(1);
        Mail::assertQueued(SmsPriceRequestedAlert::class, fn (SmsPriceRequestedAlert $mail): bool => $mail->details['people'] === 80
            && $mail->details['texts_per_day'] === 2
            && $mail->details['days'] === 'Weekdays'
            && $mail->details['estimate'] === 'PHP 1,800.00/month'
            && str_ends_with($mail->details['url'], '/super-admin?organization='.$owner->organization_id));
    }

    public function test_sign_up_queues_an_alert_saying_whether_sms_answers_came_with_it(): void
    {
        $this->register(['sms_estimate_people' => 80, 'sms_estimate_texts' => ['time_in', 'time_out'], 'sms_estimate_days' => 'weekdays']);
        $organization = Organization::query()->where('name', 'Fresh Academy')->firstOrFail();

        Mail::assertQueued(NewSignupAlert::class, fn (NewSignupAlert $mail): bool => $mail->details['organization'] === 'Fresh Academy'
            && $mail->details['type'] === 'School / Education'
            && $mail->details['answers'] !== null
            && $mail->details['estimate'] === 'PHP 1,800.00/month'
            && str_ends_with($mail->details['url'], '/super-admin?organization='.$organization->id));
    }

    public function test_sign_up_without_sms_answers_says_so_and_rendered_emails_read_correctly(): void
    {
        $this->register();
        $organization = Organization::query()->where('name', 'Fresh Academy')->firstOrFail();

        $mail = new NewSignupAlert($organization, $organization->users()->firstOrFail());
        $this->assertNull($mail->details['answers']);
        $mail->assertSeeInHtml('Fresh Academy')->assertSeeInText('SMS answers')->assertSeeInText('No');
    }

    public function test_alerts_go_to_super_admins_when_no_address_is_configured(): void
    {
        config(['services.admin_alerts.to' => null]);
        User::factory()->create(['organization_id' => null, 'role' => User::ROLE_SUPER_ADMIN, 'email' => 'super@myo.test']);

        $this->register();

        Mail::assertQueued(NewSignupAlert::class, fn (NewSignupAlert $mail): bool => $mail->hasTo('super@myo.test'));
    }

    public function test_nothing_is_sent_while_alert_emails_are_off(): void
    {
        config(['services.admin_alerts.enabled' => false]);

        $this->register();
        $this->submitPayment($this->trialOwner(price: true));

        Mail::assertNothingQueued();
        Mail::assertNothingSent();
    }

    public function test_a_mail_failure_does_not_break_the_payment(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('Mail server down'));

        $paymentId = $this->submitPayment($this->trialOwner(price: true));

        $this->assertSame(Payment::STATUS_PENDING, Payment::findOrFail($paymentId)->status);
    }
}

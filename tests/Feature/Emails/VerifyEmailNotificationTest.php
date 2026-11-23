<?php

namespace Tests\Feature\Emails;

use App\Models\Organization;
use App\Models\User;
use App\Services\BaseSubscriptionService;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class VerifyEmailNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_sends_verify_email_notification_to_the_correct_recipient(): void
    {
        Notification::fake();

        $this->postJson('/api/auth/register', [
            'name' => 'Jamie Rivera',
            'organization_name' => 'Verify Test Org',
            'organization_type' => 'company',
            'username' => 'jamie.rivera',
            'email' => 'jamie@example.test',
            'contact_number' => '09171234567',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertCreated();

        $user = User::where('email', 'jamie@example.test')->firstOrFail();

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_verify_email_mail_uses_myo_branding_and_the_signed_verification_url(): void
    {
        $organization = Organization::create(['name' => 'Verify Org', 'type' => 'company', 'timezone' => 'Asia/Manila']);
        app(BaseSubscriptionService::class)->createTrial($organization);
        $user = User::factory()->unverified()->create(['organization_id' => $organization->id, 'role' => 'owner', 'name' => 'Jamie Rivera']);

        $notification = new VerifyEmail;
        $mail = $notification->toMail($user);

        $this->assertSame('Verify your email address · MYO Attendance', $mail->subject);
        $this->assertSame('emails.auth.verify-email', $mail->view);
        $this->assertSame('Hi Jamie,', $mail->viewData['greeting']);

        $expectedUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(config('auth.verification.expire', 60)),
            ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())]
        );
        // Compare everything but the signature/expires timestamps, which are time-sensitive.
        $this->assertStringStartsWith(url('/email/verify/'.$user->id.'/'), $mail->viewData['url']);
        $this->assertSame(parse_url($expectedUrl, PHP_URL_PATH), parse_url($mail->viewData['url'], PHP_URL_PATH));

        $rendered = (string) $this->view($mail->view, $mail->viewData);
        $this->assertStringContainsString('Welcome to MYO Attendance', $rendered);
        $this->assertStringContainsString('Verify Email Address', $rendered);
        $this->assertStringContainsString('Your 30-day free trial', $rendered);
        $this->assertStringContainsString('₱2,999/month', $rendered);
        $this->assertStringContainsString(e($mail->viewData['url']), $rendered);
        $this->assertStringNotContainsString('MYO Attendance V2', $rendered);
    }

    public function test_verify_email_mail_falls_back_to_a_generic_greeting_when_name_is_blank(): void
    {
        $user = User::factory()->unverified()->create(['name' => '']);

        $mail = (new VerifyEmail)->toMail($user);

        $this->assertSame('Hi there,', $mail->viewData['greeting']);
    }

    public function test_resend_verification_sends_a_new_notification(): void
    {
        Notification::fake();

        $organization = Organization::create(['name' => 'Resend Org', 'type' => 'company', 'timezone' => 'Asia/Manila']);
        app(BaseSubscriptionService::class)->createTrial($organization);
        $user = User::factory()->unverified()->create(['organization_id' => $organization->id, 'role' => 'owner']);

        $this->actingAs($user)->postJson('/api/auth/verification/resend')->assertOk();

        Notification::assertSentTo($user, VerifyEmail::class);
    }
}

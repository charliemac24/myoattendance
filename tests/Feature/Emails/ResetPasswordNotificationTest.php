<?php

namespace Tests\Feature\Emails;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class ResetPasswordNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_sends_reset_notification_to_the_correct_recipient(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'jamie@example.test']);

        $this->postJson('/api/auth/forgot-password', ['email' => 'jamie@example.test'])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_password_mail_uses_myo_branding_and_the_configured_expiration(): void
    {
        $user = User::factory()->create(['name' => 'Jamie Rivera', 'email' => 'jamie@example.test']);
        $token = 'a-fake-token-for-rendering';

        $notification = new ResetPassword($token);
        $mail = $notification->toMail($user);

        $this->assertSame('Reset your password · MYO Attendance', $mail->subject);
        $this->assertSame('emails.auth.reset-password', $mail->view);
        $this->assertSame('Hi Jamie,', $mail->viewData['greeting']);

        $expectedUrl = url(route('password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()], false));
        $this->assertSame($expectedUrl, $mail->viewData['url']);

        $configuredExpire = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');
        $this->assertSame($configuredExpire, $mail->viewData['expireMinutes']);

        $rendered = (string) $this->view($mail->view, $mail->viewData);
        $this->assertStringContainsString('Reset your password', $rendered);
        $this->assertStringContainsString('Reset Password', $rendered);
        $this->assertStringContainsString("expire in {$configuredExpire} minutes", $rendered);
        $this->assertStringContainsString($expectedUrl, $rendered);
        $this->assertStringNotContainsString('MYO Attendance V2', $rendered);
    }

    public function test_reset_password_mail_falls_back_to_a_generic_greeting_when_name_is_blank(): void
    {
        $user = User::factory()->create(['name' => '']);

        $mail = (new ResetPassword('token'))->toMail($user);

        $this->assertSame('Hi there,', $mail->viewData['greeting']);
    }

    public function test_reset_password_url_can_be_used_to_actually_reset_the_password(): void
    {
        $user = User::factory()->create(['email' => 'jamie@example.test', 'password' => bcrypt('OldPass123!')]);
        $token = Password::createToken($user);

        $response = $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => 'jamie@example.test',
            'password' => 'NewPass456!',
            'password_confirmation' => 'NewPass456!',
        ]);

        $response->assertOk();
        $this->assertTrue(Hash::check('NewPass456!', $user->fresh()->password));
    }
}

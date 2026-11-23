<?php

namespace App\Providers;

use App\Models\User;
use App\Services\BaseSubscriptionService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        VerifyEmail::toMailUsing(function (User $notifiable, string $url) {
            $subscription = $notifiable->organization
                ? app(BaseSubscriptionService::class)->payload($notifiable->organization)
                : null;

            $trialEndsAt = $subscription && $subscription['status'] === 'trialing' && $subscription['trial_ends_at']
                ? $subscription['trial_ends_at']->format('M j, Y')
                : null;

            $monthlyPrice = '₱'.number_format(BaseSubscriptionService::MONTHLY_PRICE_MINOR / 100, 0);

            return (new MailMessage)
                ->subject('Verify your email address · MYO Attendance')
                ->view('emails.auth.verify-email', [
                    'url' => $url,
                    'greeting' => $this->greetingFor($notifiable),
                    'trialEndsAt' => $trialEndsAt,
                    'monthlyPrice' => $monthlyPrice,
                ]);
        });

        ResetPassword::toMailUsing(function (User $notifiable, string $token) {
            $url = url(route('password.reset', [
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ], false));

            $expireMinutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

            return (new MailMessage)
                ->subject('Reset your password · MYO Attendance')
                ->view('emails.auth.reset-password', [
                    'url' => $url,
                    'greeting' => $this->greetingFor($notifiable),
                    'expireMinutes' => $expireMinutes,
                ]);
        });
    }

    private function greetingFor(User $notifiable): string
    {
        $firstName = trim(strtok(trim((string) $notifiable->name), ' '));

        return $firstName !== '' ? "Hi {$firstName}," : 'Hi there,';
    }
}

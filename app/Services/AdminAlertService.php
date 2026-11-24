<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Emails super admin when something needs them (a payment to review, an SMS price request, a new sign-up).
 * Off unless ALERT_EMAILS=true. Mail is queued and any failure is only logged, so it never breaks the
 * payment, request or sign-up that triggered it.
 */
class AdminAlertService
{
    public function send(Mailable $alert): void
    {
        if (! config('services.admin_alerts.enabled')) {
            return;
        }

        try {
            $recipients = $this->recipients();
            if ($recipients === []) {
                return;
            }
            Mail::to($recipients)->queue($alert);
        } catch (\Throwable $exception) {
            Log::warning('Super admin alert email could not be queued.', ['alert' => $alert::class, 'error' => $exception->getMessage()]);
        }
    }

    /**
     * ALERT_EMAIL_TO (comma-separated) when set, otherwise every super admin's email.
     *
     * @return list<string>
     */
    public function recipients(): array
    {
        $configured = array_values(array_filter(array_map('trim', explode(',', (string) config('services.admin_alerts.to')))));

        return $configured !== []
            ? $configured
            : User::query()->where('role', User::ROLE_SUPER_ADMIN)->whereNotNull('email')->pluck('email')->all();
    }
}

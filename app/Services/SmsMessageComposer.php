<?php

namespace App\Services;

use App\Models\AttendanceEvent;
use App\Models\Organization;
use App\Models\Person;
use App\Models\SmsTemplate;

class SmsMessageComposer
{
    public function compose(Organization $organization, Person $person, AttendanceEvent $event): ?array
    {
        $notificationType = match ($event->event_type) {
            'check_in' => SmsTemplate::TIME_IN,
            'late_check_in' => SmsTemplate::LATE,
            'check_out' => SmsTemplate::TIME_OUT,
            default => null,
        };

        if (! $notificationType) {
            return null;
        }

        $phone = $this->normalizePhone($person->contact_number);
        if (! $phone) {
            return null;
        }

        $template = app(SmsTemplateService::class)->ensureDefaults($organization)
            ->firstWhere('template_key', $notificationType);
        if (! $template?->enabled) {
            return null;
        }

        $localDate = $event->occurred_at->setTimezone($organization->timezone);
        $name = trim($person->first_name.' '.$person->last_name);

        return [
            'notification_type' => $notificationType,
            'recipient_phone' => $phone,
            'message' => str_replace(
                ['{{name}}', '{{time}}', '{{date}}'],
                [$name, $localDate->format('g:i A'), $localDate->toFormattedDateString()],
                $template->body,
            ),
            'sender_name' => $organization->sms_sender_name ?: config('services.semaphore.sender_name'),
        ];
    }

    private function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?: '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (str_starts_with($digits, '09') && strlen($digits) === 11) {
            $digits = '63'.substr($digits, 1);
        }

        return strlen($digits) >= 10 && strlen($digits) <= 15 ? $digits : null;
    }
}

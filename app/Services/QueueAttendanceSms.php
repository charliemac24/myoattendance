<?php

namespace App\Services;

use App\Enums\SmsMessageStatus;
use App\Enums\SmsProvider;
use App\Models\AttendanceEvent;
use App\Models\Organization;
use App\Models\Person;
use App\Models\SmsMessage;
use Illuminate\Support\Facades\Log;

class QueueAttendanceSms
{
    public function __construct(
        private readonly FeatureEntitlementService $entitlements,
        private readonly SmsMessageComposer $composer,
    ) {}

    public function handle(Organization $organization, Person $person, AttendanceEvent $event): ?SmsMessage
    {
        if (! $this->entitlements->canUseSms($organization)) {
            Log::info('SMS skipped because organization is not entitled.', ['organization_id' => $organization->id, 'reason' => 'sms_skipped_not_entitled']);

            return null;
        }

        $payload = $this->composer->compose($organization, $person, $event);
        if (! $payload) {
            Log::info('SMS skipped because no eligible recipient or notification.', ['organization_id' => $organization->id, 'person_id' => $person->id, 'reason' => 'sms_skipped_no_recipient_or_disabled']);

            return null;
        }

        $message = SmsMessage::query()->createOrFirst(
            ['attendance_event_id' => $event->id, 'notification_type' => $payload['notification_type'], 'recipient_phone' => $payload['recipient_phone']],
            [
                'organization_id' => $organization->id,
                'person_id' => $person->id,
                'message' => $payload['message'],
                'status' => SmsMessageStatus::Pending,
                'provider' => SmsProvider::Semaphore,
                'sender_name' => $payload['sender_name'],
                'attempt_count' => 0,
            ],
        );

        Log::info('Attendance SMS queued.', ['organization_id' => $organization->id, 'person_id' => $person->id, 'attendance_event_id' => $event->id, 'sms_message_id' => $message->id]);

        return $message;
    }
}

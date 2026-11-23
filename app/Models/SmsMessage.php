<?php

namespace App\Models;

use App\Enums\SmsMessageStatus;
use App\Enums\SmsProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsMessage extends Model
{
    protected $fillable = [
        'organization_id', 'person_id', 'attendance_event_id', 'notification_type',
        'recipient_phone', 'message', 'status', 'provider', 'sender_name',
        'provider_message_id', 'provider_status', 'provider_response', 'attempt_count',
        'last_attempt_at', 'processing_started_at', 'next_attempt_at', 'sent_at', 'failed_at',
        'last_error_code', 'last_error_message',
    ];

    protected function casts(): array
    {
        return [
            'status' => SmsMessageStatus::class,
            'provider' => SmsProvider::class,
            'provider_response' => 'array',
            'attempt_count' => 'integer',
            'last_attempt_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function attendanceEvent(): BelongsTo
    {
        return $this->belongsTo(AttendanceEvent::class);
    }
}

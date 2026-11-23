<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceEvent extends Model
{
    /** Scans that count as attendance. Every report reads only these. */
    public const ATTENDANCE_TYPES = ['check_in', 'late_check_in', 'check_out'];

    /**
     * A scan the scanner turned away (archived person, expired membership). Saved so the daily log can show who
     * tried to come in; it never counts as attendance.
     */
    public const TYPE_REFUSED = 'refused';

    protected $fillable = ['organization_id', 'person_id', 'event_type', 'occurred_at', 'performed_by_user_id', 'meta'];

    /**
     * Keep the scanner's name on the event itself: removing a staff account clears performed_by_user_id, and this
     * way the log still shows who scanned.
     */
    protected static function booted(): void
    {
        static::creating(function (AttendanceEvent $event): void {
            $meta = $event->meta ?? [];
            if ($event->performed_by_user_id && ! isset($meta['performed_by_name'])) {
                $user = User::query()->find($event->performed_by_user_id, ['name', 'username']);
                if ($user) {
                    $event->meta = $meta + ['performed_by_name' => $user->name ?: $user->username];
                }
            }
        });
    }

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'meta' => 'array'];
    }

    /**
     * The date of the shift this scan belongs to. A night shift's 6 AM time out belongs to the night before, which
     * the scan stores in meta; older scans without it use their own local date.
     */
    public function attendanceDate(string $timezone): string
    {
        return ($this->meta ?? [])['attendance_date'] ?? $this->occurred_at->copy()->setTimezone($timezone)->toDateString();
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }

    public function smsMessages(): HasMany
    {
        return $this->hasMany(SmsMessage::class);
    }
}

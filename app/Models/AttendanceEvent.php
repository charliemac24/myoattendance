<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceEvent extends Model
{
    protected $fillable = ['organization_id', 'person_id', 'event_type', 'occurred_at', 'performed_by_user_id', 'meta'];

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

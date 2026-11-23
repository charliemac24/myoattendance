<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Person extends Model
{
    protected $table = 'persons';

    protected $hidden = ['qr_token'];

    protected $fillable = ['organization_id', 'person_number', 'first_name', 'last_name', 'group_id', 'subgroup_id', 'contact_number', 'membership_valid_until', 'qr_token', 'is_active'];

    protected static function booted(): void
    {
        static::creating(function (Person $person): void {
            $person->qr_token ??= Str::random(64);
            if ($person->is_active === false) {
                // Added as archived: never active, so never expected on any date.
                $person->archived_at ??= Carbon::create(2000, 1, 1);
            }
        });
        // Remember when someone was archived, so past reports still count the days they were active.
        static::updating(function (Person $person): void {
            if ($person->isDirty('is_active')) {
                $person->archived_at = $person->is_active ? null : now();
            }
        });
        // Group history: reports use the group a person was in on each date.
        static::created(fn (Person $person) => PersonAssignment::create(['organization_id' => $person->organization_id, 'person_id' => $person->id, 'group_id' => $person->group_id, 'subgroup_id' => $person->subgroup_id]));
        static::updated(function (Person $person): void {
            if ($person->wasChanged(['group_id', 'subgroup_id'])) {
                PersonAssignment::recordMove($person);
            }
        });
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'archived_at' => 'datetime', 'membership_valid_until' => 'date:Y-m-d'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function subgroup(): BelongsTo
    {
        return $this->belongsTo(Subgroup::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(PersonAssignment::class);
    }

    /** False from the day they were archived (in the organization's timezone). */
    public function wasActiveOn(string $date, string $timezone): bool
    {
        return $this->archived_at === null ? (bool) $this->is_active : $this->archived_at->copy()->setTimezone($timezone)->toDateString() > $date;
    }

    public function attendanceEvents(): HasMany
    {
        return $this->hasMany(AttendanceEvent::class);
    }

    public function smsMessages(): HasMany
    {
        return $this->hasMany(SmsMessage::class);
    }
}

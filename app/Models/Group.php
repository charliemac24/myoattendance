<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Group extends Model
{
    /** People check in on the group's schedules: late, absent and time out apply. */
    public const MODE_SCHEDULED = 'scheduled';

    /** People check in anytime (e.g. a gym): every scan is a visit, nobody is late or absent. */
    public const MODE_OPEN = 'open';

    protected $fillable = ['organization_id', 'name', 'attendance_mode', 'scan_out'];

    protected static function booted(): void
    {
        static::creating(function (Group $group): void {
            $group->attendance_mode ??= $group->organization?->defaultGroupMode() ?? self::MODE_SCHEDULED;
        });
    }

    protected function casts(): array
    {
        return ['scan_out' => 'boolean'];
    }

    public function isOpen(): bool
    {
        return $this->attendance_mode === self::MODE_OPEN;
    }

    /** True once anyone has scanned while in this group. Its attendance mode then decides how those past days read. */
    public function hasAttendanceEvents(): bool
    {
        return AttendanceEvent::query()->where('organization_id', $this->organization_id)->where('meta->group_id', $this->id)->whereIn('event_type', AttendanceEvent::ATTENDANCE_TYPES)->exists();
    }

    /**
     * True when past reports depend on this group: someone was assigned to it at some point, or scanned while in it.
     * Deleting it would remove or rewrite that history.
     */
    public function hasAttendanceHistory(): bool
    {
        return PersonAssignment::query()->where('organization_id', $this->organization_id)->where('group_id', $this->id)->exists()
            || $this->hasAttendanceEvents();
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function subgroups(): HasMany
    {
        return $this->hasMany(Subgroup::class);
    }

    public function persons(): HasMany
    {
        return $this->hasMany(Person::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }
}

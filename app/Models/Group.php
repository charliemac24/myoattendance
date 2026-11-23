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

    protected function casts(): array
    {
        return ['scan_out' => 'boolean'];
    }

    public function isOpen(): bool
    {
        return $this->attendance_mode === self::MODE_OPEN;
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

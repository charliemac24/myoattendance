<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The group and subgroup a person was in from starts_on to ends_on (null = open-ended). Reports read these, so
 * moving someone only changes their attendance from the day of the move.
 */
class PersonAssignment extends Model
{
    protected $fillable = ['organization_id', 'person_id', 'group_id', 'subgroup_id', 'starts_on', 'ends_on'];

    protected function casts(): array
    {
        return ['starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d'];
    }

    public function coversDate(string $date): bool
    {
        return ($this->starts_on === null || $this->starts_on->toDateString() <= $date) && ($this->ends_on === null || $this->ends_on->toDateString() >= $date);
    }

    /**
     * Record a person's move from today (in the organization's timezone): the current assignment ends yesterday
     * and a new one starts today. Moving twice on the same day just updates today's assignment.
     */
    public static function recordMove(Person $person): void
    {
        $today = now($person->organization?->timezone ?? 'UTC')->toDateString();
        $current = self::query()->where('person_id', $person->id)->whereNull('ends_on')->latest('id')->first();
        $attributes = ['group_id' => $person->group_id, 'subgroup_id' => $person->subgroup_id];
        if ($current && $current->starts_on?->toDateString() === $today) {
            $current->update($attributes);

            return;
        }
        $current?->update(['ends_on' => now($person->organization?->timezone ?? 'UTC')->subDay()->toDateString()]);
        self::create(['organization_id' => $person->organization_id, 'person_id' => $person->id, 'starts_on' => $current ? $today : null] + $attributes);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function subgroup(): BelongsTo
    {
        return $this->belongsTo(Subgroup::class);
    }
}

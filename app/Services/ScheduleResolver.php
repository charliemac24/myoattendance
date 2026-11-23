<?php

namespace App\Services;

use App\Models\Group;
use App\Models\Person;
use App\Models\PersonAssignment;
use App\Models\Schedule;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Which schedule applied to a person on a date, as it was then: the group/subgroup they were in that day (their
 * assignment history) and the schedule version in use that day. A subgroup schedule wins over the group's.
 */
class ScheduleResolver
{
    /** @var array<int, Collection<int, Group>> Groups per organization, looked up once per request. */
    private array $groups = [];

    /** @var array<int, Collection<int, PersonAssignment>> Each person's assignment history, looked up once per request. */
    private array $assignments = [];

    /** People in an open-hours group have no schedule: they can check in anytime and are never expected. */
    public function resolve(Person $person, CarbonInterface $date): ?Schedule
    {
        return $this->resolveMany(collect([$person]), $date)->get($person->id);
    }

    /** @param Collection<int, Person> $people @return Collection<int, Schedule|null> */
    public function resolveMany(Collection $people, CarbonInterface $date): Collection
    {
        if ($people->isEmpty()) {
            return collect();
        }

        $organizationId = $people->first()->organization_id;
        $dateString = $date->toDateString();
        $schedules = Schedule::query()
            ->where('organization_id', $organizationId)
            ->where('day_of_week', $date->dayOfWeekIso)
            ->where('is_active', true)
            ->effectiveOn($dateString)
            ->get();
        $overrides = $schedules->whereNotNull('subgroup_id')->keyBy(fn (Schedule $schedule) => "{$schedule->group_id}:{$schedule->subgroup_id}");
        $fallbacks = $schedules->whereNull('subgroup_id')->keyBy('group_id');

        return $people->mapWithKeys(function (Person $person) use ($overrides, $fallbacks, $dateString): array {
            [$groupId, $subgroupId] = $this->scopeOn($person, $dateString);
            if ($groupId === null || $this->group($person->organization_id, $groupId)?->isOpen()) {
                return [$person->id => null];
            }
            $schedule = $subgroupId ? $overrides->get("{$groupId}:{$subgroupId}") : null;

            return [$person->id => $schedule ?? $fallbacks->get($groupId)];
        });
    }

    /**
     * The group and subgroup a person was in on a date. Dates before their first recorded assignment use it.
     *
     * @return array{0: ?int, 1: ?int}
     */
    public function scopeOn(Person $person, string $date): array
    {
        $history = $this->assignments[$person->id] ??= PersonAssignment::query()->where('person_id', $person->id)->orderByRaw('starts_on IS NULL DESC')->orderBy('starts_on')->orderBy('id')->get();
        if ($history->isEmpty()) {
            return [$person->group_id, $person->subgroup_id];
        }
        $assignment = $history->first(fn (PersonAssignment $assignment): bool => $assignment->coversDate($date))
            ?? ($history->first()->starts_on && $history->first()->starts_on->toDateString() > $date ? $history->first() : null);

        return $assignment ? [$assignment->group_id, $assignment->subgroup_id] : [null, null];
    }

    /** The group a person was in on a date, e.g. to know whether it was an open-hours group then. */
    public function groupOn(Person $person, string $date): ?Group
    {
        $groupId = $this->scopeOn($person, $date)[0];

        return $groupId === null ? null : $this->group($person->organization_id, $groupId);
    }

    /** @param Collection<int, Person> $people */
    public function preload(Collection $people): void
    {
        $missing = $people->pluck('id')->reject(fn (int $id): bool => isset($this->assignments[$id]))->values();
        if ($missing->isEmpty()) {
            return;
        }
        $loaded = PersonAssignment::query()->whereIn('person_id', $missing)->orderByRaw('starts_on IS NULL DESC')->orderBy('starts_on')->orderBy('id')->get()->groupBy('person_id');
        foreach ($missing as $id) {
            $this->assignments[$id] = $loaded->get($id, collect());
        }
    }

    private function group(int $organizationId, int $groupId): ?Group
    {
        $this->groups[$organizationId] ??= Group::query()->where('organization_id', $organizationId)->get()->keyBy('id');

        return $this->groups[$organizationId]->get($groupId);
    }
}

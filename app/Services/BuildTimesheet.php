<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Person;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Hours worked per employee and day over a pay period, with totals, for payroll. Built on the same day-by-day
 * timeline as person history, so night shifts count on the date they start and days before someone was added are
 * left out. People in open-hours groups (visits, not shifts) are not included.
 */
class BuildTimesheet
{
    public const MAX_DAYS = 62;

    public function __construct(private readonly AttendanceTimeline $timeline) {}

    /**
     * @return array{from: string, to: string, people: array<int, array{person: array<string, mixed>, totals: array<string, int>, days: array<int, array<string, mixed>>}>}
     */
    public function handle(Organization $organization, CarbonInterface $from, CarbonInterface $to, ?int $groupId = null, ?int $subgroupId = null, ?int $personId = null): array
    {
        $rangeStart = Carbon::parse($from->toDateString(), $organization->timezone)->startOfDay();
        $rangeEnd = Carbon::parse($to->toDateString(), $organization->timezone)->addDay()->endOfDay();
        $people = $organization->persons()
            ->with(['group:id,name,attendance_mode,scan_out', 'subgroup:id,name'])
            // Filtering by group means everyone who was in it during the range; only their days in it are listed.
            ->when($groupId, fn ($query) => $query->whereHas('assignments', fn ($assignment) => $assignment->where('group_id', $groupId)
                ->when($subgroupId, fn ($query) => $query->where('subgroup_id', $subgroupId))
                ->where(fn ($starts) => $starts->whereNull('starts_on')->orWhere('starts_on', '<=', $to->toDateString()))
                ->where(fn ($ends) => $ends->whereNull('ends_on')->orWhere('ends_on', '>=', $from->toDateString()))))
            ->when($personId, fn ($query) => $query->whereKey($personId))
            // Archived people still appear for the days they worked.
            ->where(fn ($query) => $query->where('is_active', true)->orWhereHas('attendanceEvents', fn ($events) => $events->whereBetween('occurred_at', [$rangeStart->copy()->utc(), $rangeEnd->copy()->utc()])))
            ->orderBy('last_name')->orderBy('first_name')->orderBy('person_number')
            ->get();
        $timelines = $this->timeline->handle($organization, $people, $from, $to);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'people' => $people
                ->map(function (Person $person) use ($timelines, $groupId, $subgroupId): array {
                    // Open-hours visits have no hours (work is null); with a group filter, keep only days spent in it.
                    $days = collect($timelines->get($person->id, []))
                        ->filter(fn (array $day): bool => $day['work'] !== null)
                        ->filter(fn (array $day): bool => ! $groupId || ($day['group_id'] === $groupId && (! $subgroupId || $day['subgroup_id'] === $subgroupId)));

                    return $this->personRow($person, $days->values()->all());
                })
                // People who are in an open-hours group now and had no shifts in the range are left out.
                ->reject(fn (array $row): bool => $row['days'] === [] && $people->firstWhere('id', $row['person']['id'])?->group?->isOpen())
                ->values()->all(),
        ];
    }

    /** @return array{0: Carbon, 1: Carbon} The requested range, or the current pay period (1st–15th or 16th–end of month). */
    public static function range(Organization $organization, ?string $from, ?string $to): array
    {
        if ($from && $to) {
            return [Carbon::createFromFormat('!Y-m-d', $from, $organization->timezone), Carbon::createFromFormat('!Y-m-d', $to, $organization->timezone)];
        }
        $today = now($organization->timezone)->startOfDay();

        return $today->day <= 15
            ? [$today->copy()->startOfMonth(), $today->copy()->setDay(15)]
            : [$today->copy()->setDay(16), $today->copy()->endOfMonth()->startOfDay()];
    }

    /**
     * @param  array<int, array<string, mixed>>  $timeline
     * @return array{person: array<string, mixed>, totals: array<string, int>, days: array<int, array<string, mixed>>}
     */
    private function personRow(Person $person, array $timeline): array
    {
        $days = collect($timeline)->map(fn (array $day): array => $this->dayRow($day))->values();
        $sum = fn (string $key): int => (int) $days->sum($key);

        return [
            'person' => [
                'id' => $person->id,
                'person_number' => $person->person_number,
                'full_name' => trim($person->first_name.' '.$person->last_name),
                'group' => $person->group?->name,
                'subgroup' => $person->subgroup?->name,
                'is_active' => $person->is_active,
            ],
            'totals' => [
                'days_worked' => $days->where('worked', true)->count(),
                'absences' => $days->where('status', 'Absent')->count(),
                'incomplete_days' => $days->where('incomplete', true)->count(),
                'late_days' => $days->where('late_minutes', '>', 0)->count(),
                'regular_minutes' => $sum('regular_minutes'),
                'late_minutes' => $sum('late_minutes'),
                'undertime_minutes' => $sum('undertime_minutes'),
                'overtime_minutes' => $sum('overtime_minutes'),
                'rest_day_minutes' => $sum('rest_day_minutes'),
            ],
            'days' => $days->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $day
     * @return array<string, mixed>
     */
    private function dayRow(array $day): array
    {
        $work = $day['work'] ?? [];
        $status = match (true) {
            $day['holiday'] !== null && ! ($work['incomplete'] ?? false) && ! ($work['in_progress'] ?? false) => 'Holiday',
            (bool) ($work['absent'] ?? false) => 'Absent',
            (bool) ($work['in_progress'] ?? false) && ! $day['time_in'] => 'Not yet',
            (bool) ($work['in_progress'] ?? false) => 'In progress',
            (bool) ($work['incomplete'] ?? false) => 'Incomplete',
            ! $day['scheduled'] => 'Rest day',
            $day['status'] === AttendanceTimeline::LATE => 'Late',
            default => 'Present',
        };

        return [
            'date' => $day['date'],
            'day' => $day['day'],
            'shift' => $day['shift'] ?? null,
            'time_in' => $day['time_in'],
            'time_out' => $day['time_out'],
            'status' => $status,
            'holiday' => $day['holiday'] ?? null,
            'worked' => $day['time_in'] !== null || $day['time_out'] !== null,
            'incomplete' => (bool) ($work['incomplete'] ?? false),
            'no_time_in' => $day['no_time_in'],
            'no_time_out' => $day['no_time_out'],
            'scheduled_minutes' => $work['scheduled_minutes'] ?? null,
            'regular_minutes' => (int) ($work['regular_minutes'] ?? 0),
            'late_minutes' => (int) ($work['late_minutes'] ?? 0),
            'undertime_minutes' => (int) ($work['undertime_minutes'] ?? 0),
            'overtime_minutes' => (int) ($work['overtime_minutes'] ?? 0),
            'rest_day_minutes' => (int) ($work['rest_day_minutes'] ?? 0),
            'break_minutes' => (int) ($work['break_minutes'] ?? 0),
        ];
    }
}

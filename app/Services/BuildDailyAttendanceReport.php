<?php

namespace App\Services;

use App\Models\AttendanceEvent;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Schedule;
use App\Models\Subgroup;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class BuildDailyAttendanceReport
{
    /** @var Collection<int, Group>|null */
    private ?Collection $groups = null;

    /** @var Collection<int, Subgroup>|null */
    private ?Collection $subgroups = null;

    public function __construct(private readonly ScheduleResolver $scheduleResolver, private readonly ClassifyDailyAttendance $classifier, private readonly HolidayCalendar $holidays) {}

    /** @return array{date: string, holiday: ?string, summary: array<string, int>, people: array<int, array<string, mixed>>, refused: array<int, array<string, mixed>>} */
    public function handle(Organization $organization, string $date, array $filters = []): array
    {
        $localDate = Carbon::createFromFormat('!Y-m-d', $date, $organization->timezone);
        $start = $localDate->copy()->startOfDay();
        $end = $localDate->copy()->endOfDay();

        // Archived people still count for the days before they were archived.
        $people = $organization->persons()
            ->where(fn ($query) => $query->where('is_active', true)->orWhere('archived_at', '>', $start->copy()->utc()))
            ->orderBy('last_name')->orderBy('first_name')->get();
        $this->scheduleResolver->preload($people);
        $resolvedSchedules = $this->scheduleResolver->resolveMany($people, $localDate);
        // A night shift's time out lands the next morning but belongs to this date, so look one day further.
        $events = $organization->attendanceEvents()
            ->whereBetween('occurred_at', [$start->copy()->utc(), $end->copy()->addDay()->utc()])
            ->whereIn('event_type', ['check_in', 'late_check_in', 'check_out'])
            ->orderBy('occurred_at')->orderBy('id')->get()
            ->filter(fn ($event): bool => $event->attendanceDate($organization->timezone) === $localDate->toDateString())
            ->groupBy('person_id');
        // People added (and not yet scanning) after this date were not expected yet.
        $startDates = AttendanceTimeline::startDates($organization, $people);
        // People in open-hours groups are never expected; they are listed on the days they came.
        // Anyone who scanned that day is listed too (e.g. an employee working a rest day).
        $dateString = $localDate->toDateString();
        // On a holiday for their group (or everyone), people who did not scan are not expected, so nobody is absent.
        $expected = $people->filter(fn ($person) => $events->has($person->id) || ($person->wasActiveOn($dateString, $organization->timezone)
            && $resolvedSchedules->get($person->id) && $startDates->get($person->id) <= $dateString
            && ! $this->holidays->nameOn($organization->id, $this->scheduleResolver->scopeOn($person, $dateString)[0], $dateString)));

        $localNow = now($organization->timezone);
        $rows = $this->sortMostRecentFirst(
            $expected->map(fn ($person) => $this->row($person, $events->get($person->id, collect()), $organization, $resolvedSchedules->get($person->id), $localDate, $localNow))
        );
        $present = $rows->whereIn('status', ['PRESENT', 'LATE'])->count();
        $late = $rows->where('status', 'LATE')->count();

        return [
            'date' => $localDate->toDateString(),
            'holiday' => $this->holidays->organizationNameOn($organization->id, $localDate->toDateString()),
            'summary' => ['expected_total' => $rows->count(), 'present' => $present, 'late' => $late, 'absent' => $rows->where('status', 'ABSENT')->count(), 'no_time_in' => $rows->where('no_time_in', true)->count(), 'no_time_out' => $rows->where('no_time_out', true)->count()],
            'people' => $this->filterRows($rows, $filters)->all(),
            'refused' => $this->refusedScans($organization, $localDate, $filters),
        ];
    }

    /**
     * Scans the scanner turned away on this date (archived person, expired membership), newest first. They are
     * listed apart from attendance and never change the counts above.
     *
     * @return array<int, array{person: array<string, mixed>, reason: string, time: string, membership_valid_until: ?string}>
     */
    private function refusedScans(Organization $organization, CarbonInterface $localDate, array $filters): array
    {
        $date = $localDate->toDateString();
        $search = strtolower(trim((string) ($filters['search'] ?? '')));

        return $organization->attendanceEvents()
            ->where('event_type', AttendanceEvent::TYPE_REFUSED)
            ->whereBetween('occurred_at', [$localDate->copy()->startOfDay()->utc(), $localDate->copy()->endOfDay()->utc()])
            ->with('person:id,person_number,first_name,last_name')
            ->orderByDesc('occurred_at')->orderByDesc('id')->get()
            ->filter(function (AttendanceEvent $event) use ($filters, $search): bool {
                $meta = $event->meta ?? [];
                $person = $event->person;

                return $person
                    && (empty($filters['group_id']) || (string) ($meta['group_id'] ?? '') === (string) $filters['group_id'])
                    && (empty($filters['subgroup_id']) || (string) ($meta['subgroup_id'] ?? '') === (string) $filters['subgroup_id'])
                    && ($search === '' || str_contains(strtolower($person->person_number.' '.$person->first_name.' '.$person->last_name), $search));
            })
            ->filter(fn (AttendanceEvent $event): bool => $event->attendanceDate($organization->timezone) === $date)
            ->map(fn (AttendanceEvent $event): array => [
                'person' => ['id' => $event->person->id, 'person_number' => $event->person->person_number, 'first_name' => $event->person->first_name, 'last_name' => $event->person->last_name],
                'reason' => ($event->meta ?? [])['reason'] ?? 'REFUSED',
                'time' => $event->occurred_at->copy()->setTimezone($organization->timezone)->format('g:i A'),
                'membership_valid_until' => ($event->meta ?? [])['membership_valid_until'] ?? null,
            ])->values()->all();
    }

    private function row($person, Collection $events, Organization $organization, ?Schedule $schedule, CarbonInterface $localDate, CarbonInterface $localNow): array
    {
        $checkIn = $events->first(fn ($event) => in_array($event->event_type, ['check_in', 'late_check_in'], true));
        $checkOut = $checkIn
            ? $events->filter(fn ($event) => $event->event_type === 'check_out' && $event->occurred_at->greaterThanOrEqualTo($checkIn->occurred_at))->last()
            : $events->filter(fn ($event) => ClassifyDailyAttendance::isNoTimeIn($event))->last();
        $status = $this->classifier->handle($events);
        // Nobody is expected on a holiday, so anyone who came is present, never late.
        if ($status === 'LATE' && $this->holidays->nameOn($organization->id, $this->scheduleResolver->scopeOn($person, $localDate->toDateString())[0], $localDate->toDateString())) {
            $status = 'PRESENT';
        }
        $flags = $this->classifier->flags($events, $schedule, $localDate, $localNow);
        // The group and subgroup they were in on this date, not today.
        [$groupId, $subgroupId] = $this->scheduleResolver->scopeOn($person, $localDate->toDateString());
        $group = $groupId ? $this->groups($organization)->get($groupId) : null;
        $subgroup = $subgroupId ? $this->subgroups($organization)->get($subgroupId) : null;

        return [
            'person' => ['id' => $person->id, 'person_number' => $person->person_number, 'first_name' => $person->first_name, 'last_name' => $person->last_name],
            'group' => $group ? ['id' => $group->id, 'name' => $group->name] : null,
            'subgroup' => $subgroup ? ['id' => $subgroup->id, 'name' => $subgroup->name] : null,
            'status' => $status,
            'is_late' => $status === 'LATE',
            'check_in_time' => $checkIn?->occurred_at->setTimezone($organization->timezone)->format('g:i A'),
            'check_out_time' => $checkOut?->occurred_at->setTimezone($organization->timezone)->format('g:i A'),
            'no_time_in' => $flags['no_time_in'],
            'no_time_out' => $flags['no_time_out'] && (! $group?->isOpen() || $group->scan_out),
            'visits' => $events->whereIn('event_type', ['check_in', 'late_check_in'])->count(),
            'latest_event' => $checkOut ?? $checkIn,
        ];
    }

    /**
     * Most recent activity first: the time shown on each row (check-out, otherwise check-in), newest first,
     * with the event id breaking same-second ties. People with no scan go last, by name and person number.
     */
    private function sortMostRecentFirst(Collection $rows): Collection
    {
        return $rows->sort(function (array $a, array $b): int {
            $eventA = $a['latest_event'];
            $eventB = $b['latest_event'];

            if ($eventA && $eventB) {
                return [$eventB->occurred_at->getTimestamp(), $eventB->id] <=> [$eventA->occurred_at->getTimestamp(), $eventA->id];
            }
            if ($eventA || $eventB) {
                return $eventA ? -1 : 1;
            }

            return [$a['person']['last_name'], $a['person']['first_name'], $a['person']['person_number']]
                <=> [$b['person']['last_name'], $b['person']['first_name'], $b['person']['person_number']];
        })->map(function (array $row): array {
            unset($row['latest_event']);

            return $row;
        })->values();
    }

    private function filterRows(Collection $rows, array $filters): Collection
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $status = $filters['status'] ?? null;
        $flag = $filters['flag'] ?? null;
        $groupId = $filters['group_id'] ?? null;
        $subgroupId = $filters['subgroup_id'] ?? null;

        return $rows->filter(function (array $row) use ($search, $status, $flag, $groupId, $subgroupId): bool {
            $person = $row['person'];
            $matchesSearch = $search === '' || str_contains(strtolower($person['person_number'].' '.$person['first_name'].' '.$person['last_name']), strtolower($search));
            $matchesStatus = ! $status || ($status === 'PRESENT' ? in_array($row['status'], ['PRESENT', 'LATE'], true) : $row['status'] === $status);
            $matchesGroup = ! $groupId || (string) ($row['group']['id'] ?? '') === (string) $groupId;
            $matchesSubgroup = ! $subgroupId || (string) ($row['subgroup']['id'] ?? '') === (string) $subgroupId;

            $matchesFlag = ! $flag || ($flag === 'NO_TIME_IN' ? $row['no_time_in'] : $row['no_time_out']);

            return $matchesSearch && $matchesStatus && $matchesFlag && $matchesGroup && $matchesSubgroup;
        })->values();
    }

    /** @return Collection<int, Group> */
    private function groups(Organization $organization): Collection
    {
        return $this->groups ??= $organization->groups()->get(['id', 'name', 'attendance_mode', 'scan_out'])->keyBy('id');
    }

    /** @return Collection<int, Subgroup> */
    private function subgroups(Organization $organization): Collection
    {
        return $this->subgroups ??= $organization->subgroups()->get(['id', 'name'])->keyBy('id');
    }
}

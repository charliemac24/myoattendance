<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Person;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class BuildMonthlyAttendanceReport
{
    public function __construct(private readonly ScheduleResolver $scheduleResolver, private readonly ClassifyDailyAttendance $classifier, private readonly HolidayCalendar $holidays) {}

    /** @return array<string, mixed> */
    public function handle(Organization $organization, string $month, int $groupId, ?int $subgroupId = null): array
    {
        $group = $organization->groups()->findOrFail($groupId);
        $subgroup = $subgroupId === null ? null : $organization->subgroups()->where('group_id', $group->id)->findOrFail($subgroupId);
        $localMonth = Carbon::createFromFormat('!Y-m', $month, $organization->timezone);
        $start = $localMonth->copy()->startOfMonth();
        $end = $localMonth->copy()->endOfMonth();
        $today = now($organization->timezone)->startOfDay();
        // Everyone who was in this group (and subgroup) at some point in the month, archived people included for the
        // days before they were archived. Days they were elsewhere show "-".
        $people = $organization->persons()
            ->whereHas('assignments', fn ($query) => $query->where('group_id', $group->id)
                ->when($subgroup, fn ($query) => $query->where('subgroup_id', $subgroup->id))
                ->where(fn ($from) => $from->whereNull('starts_on')->orWhere('starts_on', '<=', $end->toDateString()))
                ->where(fn ($to) => $to->whereNull('ends_on')->orWhere('ends_on', '>=', $start->toDateString())))
            ->where(fn ($query) => $query->where('is_active', true)->orWhere('archived_at', '>', $start->copy()->utc()))
            ->orderBy('last_name')->orderBy('first_name')->orderBy('person_number')->get();
        $this->scheduleResolver->preload($people);
        $inScope = fn (Person $person, string $date): bool => ($scope = $this->scheduleResolver->scopeOn($person, $date))[0] === $group->id && (! $subgroup || $scope[1] === $subgroup->id);
        // Grouped by shift date: a night shift's time out the next morning belongs to the night before.
        $events = $organization->attendanceEvents()->whereIn('person_id', $people->pluck('id'))->whereBetween('occurred_at', [$start->copy()->utc(), $end->copy()->addDay()->utc()])->whereIn('event_type', ['check_in', 'late_check_in', 'check_out'])->orderBy('occurred_at')->orderBy('id')->get()->groupBy(fn ($event): string => $event->person_id.'|'.$event->attendanceDate($organization->timezone));
        $startDates = AttendanceTimeline::startDates($organization, $people);
        $schedules = collect();
        $dates = collect();
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $resolved = $this->scheduleResolver->resolveMany($people, $date);
            // Weekdays are always shown; a Saturday or Sunday is shown only when someone in this list is scheduled on it.
            // Open-hours groups show every day, since people can come in any day.
            if ($group->isOpen() || $date->isWeekday() || $resolved->filter()->isNotEmpty()) {
                $schedules->put($date->toDateString(), $resolved);
                $dates->push(['date' => $date->toDateString(), 'label' => $date->format('M j'), 'day' => $date->format('l'), 'future' => $date->gt($today)]);
            }
        }

        return [
            'month' => $month,
            'group' => ['id' => $group->id, 'name' => $group->name],
            'open_hours' => $group->isOpen(),
            'subgroup' => $subgroup ? ['id' => $subgroup->id, 'name' => $subgroup->name] : null,
            'dates' => $dates->map(fn (array $date): array => ['date' => $date['date'], 'label' => $date['label'], 'day' => $date['day']])->values()->all(),
            'persons' => $people->map(function ($person) use ($dates, $schedules, $events, $organization, $startDates, $group, $inScope): array {
                $attendance = [];
                $localNow = now($organization->timezone);
                if ($group->isOpen()) {
                    return $this->openVisitsRow($person, $dates, $events, fn (string $date): bool => $inScope($person, $date));
                }
                foreach ($dates as $date) {
                    $status = '-';
                    if (! $inScope($person, $date['date'])) {
                        $attendance[$date['date']] = $status;

                        continue;
                    }
                    $schedule = $schedules->get($date['date'])?->get($person->id);
                    $dayEvents = $events->get($person->id.'|'.$date['date'], collect());
                    $localDate = Carbon::parse($date['date'], $organization->timezone);
                    $holiday = $this->holidays->nameOn($organization->id, $group->id, $date['date']);
                    if ($holiday && $dayEvents->isEmpty()) {
                        // Nobody is expected on a holiday: shown as "Holiday", never Absent (only on days they would have had a schedule).
                        $attendance[$date['date']] = $schedule && $date['date'] >= ($startDates->get($person->id) ?? '0000-00-00') && $person->wasActiveOn($date['date'], $organization->timezone) ? 'Holiday' : '-';

                        continue;
                    }
                    $notCountedYet = $dayEvents->isEmpty() && ($date['date'] < $startDates->get($person->id) || AttendanceTimeline::dayIsOpen($schedule, $localDate, $localNow) || ! $person->wasActiveOn($date['date'], $organization->timezone));
                    if (! $date['future'] && $schedule && ! $notCountedYet) {
                        $status = match ($this->classifier->handle($dayEvents)) {
                            'PRESENT' => 'Present', 'LATE' => 'Late', default => 'Absent',
                        };
                        // Nobody is expected on a holiday, so anyone who came is present, never late.
                        if ($holiday && $status === 'Late') {
                            $status = 'Present';
                        }
                        $flags = $this->classifier->flags($dayEvents, $schedule, $localDate, $localNow);
                        if ($flags['no_time_in']) {
                            $status .= ' (No time in)';
                        } elseif ($flags['no_time_out']) {
                            $status .= ' (No time out)';
                        }
                    }
                    $attendance[$date['date']] = $status;
                }

                return ['person_number' => $person->person_number, 'full_name' => trim($person->first_name.' '.$person->last_name), 'attendance' => $attendance];
            })->values()->all(),
        ];
    }

    /**
     * An open-hours person's month: "Visited" (or "Visited (2)" for two visits) on the days they came, "-" otherwise.
     *
     * @param  Collection<int, array<string, mixed>>  $dates
     * @param  Collection<string, Collection<int, mixed>>  $events
     * @param  callable(string): bool  $inScope  whether the person was in this group on a date
     * @return array{person_number: string, full_name: string, attendance: array<string, string>, total_visits: int}
     */
    private function openVisitsRow(Person $person, Collection $dates, Collection $events, callable $inScope): array
    {
        $attendance = [];
        $total = 0;
        foreach ($dates as $date) {
            if (! $inScope($date['date'])) {
                $attendance[$date['date']] = '-';

                continue;
            }
            $visits = $events->get($person->id.'|'.$date['date'], collect())->whereIn('event_type', ['check_in', 'late_check_in'])->count();
            $total += $visits;
            $attendance[$date['date']] = match (true) {
                $visits === 0 => '-',
                $visits === 1 => 'Visited',
                default => "Visited ({$visits})",
            };
        }

        return ['person_number' => $person->person_number, 'full_name' => trim($person->first_name.' '.$person->last_name), 'attendance' => $attendance, 'total_visits' => $total];
    }
}

<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Person;
use App\Models\Schedule;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Day-by-day attendance for a set of people over a date range, shared by person history and "Needs attention".
 *
 * A day is listed for a person when they had a schedule that day or scanned that day. Days after today, and
 * scheduled days before the person was added, are left out. Today only counts as Absent once the day is over
 * (the last period's end time has passed, or midnight when the schedule has no end time); before that it is "not_yet".
 */
class AttendanceTimeline
{
    public const PRESENT = 'present';

    public const LATE = 'late';

    public const ABSENT = 'absent';

    public const NOT_YET = 'not_yet';

    /** A holiday with no scans: nobody is expected, so it is neither present nor absent. */
    public const HOLIDAY = 'holiday';

    public function __construct(private readonly ScheduleResolver $scheduleResolver, private readonly ClassifyDailyAttendance $classifier, private readonly CalculateWorkHours $workHours, private readonly HolidayCalendar $holidays) {}

    /**
     * @param  Collection<int, Person>  $people
     * @return Collection<int, array<int, array{date: string, day: string, status: string, scheduled: bool, time_in: ?string, time_out: ?string, minutes_late: ?int, no_time_in: bool, no_time_out: bool, visits: int}>>
     */
    public function handle(Organization $organization, Collection $people, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $timezone = $organization->timezone;
        $localNow = now($timezone);
        $today = $localNow->toDateString();
        $start = Carbon::parse($from->toDateString(), $timezone)->startOfDay();
        $end = Carbon::parse(min($to->toDateString(), $today), $timezone)->endOfDay();
        $timeline = $people->mapWithKeys(fn (Person $person): array => [$person->id => []]);
        if ($people->isEmpty() || $start->gt($end)) {
            return $timeline;
        }

        $events = $organization->attendanceEvents()
            ->whereIn('person_id', $people->pluck('id'))
            ->whereBetween('occurred_at', [$start->copy()->utc(), $end->copy()->addDay()->utc()])
            ->whereIn('event_type', ['check_in', 'late_check_in', 'check_out'])
            ->orderBy('occurred_at')->orderBy('id')->get()
            ->groupBy(fn ($event): string => $event->person_id.'|'.$event->attendanceDate($timezone));
        $joinedOn = self::startDates($organization, $people);
        $this->scheduleResolver->preload($people);

        $days = [];
        for ($date = $start->copy()->startOfDay(); $date->lte($end); $date->addDay()) {
            $dateString = $date->toDateString();
            $schedules = $this->scheduleResolver->resolveMany($people, $date);
            foreach ($people as $person) {
                $dayEvents = $events->get($person->id.'|'.$dateString, collect());
                // Archived people are not expected from the day they were archived.
                $schedule = $person->wasActiveOn($dateString, $timezone) ? $schedules->get($person->id) : null;
                // The group they were in on that date, so moving someone does not change their past days.
                $group = $this->scheduleResolver->groupOn($person, $dateString);
                $tracksTimeOut = ! $group?->isOpen() || $group->scan_out;
                [$groupId, $subgroupId] = $this->scheduleResolver->scopeOn($person, $dateString);
                // A holiday for their group (or everyone) on that date: nobody is expected, so no absence or lateness.
                $holiday = $group?->isOpen() ? null : $this->holidays->nameOn($organization->id, $groupId, $dateString);
                if ($holiday && $dayEvents->isEmpty()) {
                    $joined = $joinedOn->get($person->id);
                    if ($schedule && ($joined === null || $dateString >= $joined)) {
                        $days[$person->id][] = $this->holidayEntry($date, $holiday, $groupId, $subgroupId, $localNow);
                    }

                    continue;
                }
                // Employees who work a holiday are off their schedule: the hours are holiday (rest day) hours.
                $workedHoliday = $holiday !== null && $organization->tracksWorkHours();
                if ($workedHoliday) {
                    $schedule = null;
                }
                $entry = $this->day($dayEvents, $schedule, $date->copy(), $localNow, $joinedOn->get($person->id), $timezone, $tracksTimeOut);
                if ($entry !== null) {
                    $entry['holiday'] = $holiday;
                    if ($workedHoliday) {
                        $entry['status'] = self::PRESENT;
                    }
                    [$entry['group_id'], $entry['subgroup_id']] = [$groupId, $subgroupId];
                    // Hours only make sense on a schedule or rest day, not for open-hours visits.
                    $entry['shift'] = $schedule ? self::shiftLabel($schedule, $dateString, $timezone) : null;
                    $entry['work'] = $group?->isOpen() ? null : $this->workHours->handle($schedule, $dayEvents, $dateString, $localNow);
                    $days[$person->id][] = $entry;
                }
            }
        }

        return $timeline->map(fn (array $empty, int $personId): array => $days[$personId] ?? []);
    }

    /** @return array<string, mixed> */
    private function holidayEntry(CarbonInterface $date, string $holiday, ?int $groupId, ?int $subgroupId, CarbonInterface $localNow): array
    {
        $dateString = $date->toDateString();

        return [
            'date' => $dateString, 'day' => $date->format('l'), 'status' => self::HOLIDAY, 'scheduled' => false,
            'time_in' => null, 'time_out' => null, 'minutes_late' => null, 'no_time_in' => false, 'no_time_out' => false, 'visits' => 0,
            'holiday' => $holiday, 'group_id' => $groupId, 'subgroup_id' => $subgroupId, 'shift' => null,
            'work' => $this->workHours->handle(null, collect(), $dateString, $localNow),
        ];
    }

    /**
     * The local date each person starts being counted: the day they were added, or their first scan if that came
     * earlier (people imported after they had already been scanning).
     *
     * @param  Collection<int, Person>  $people
     * @return Collection<int, string|null>
     */
    public static function startDates(Organization $organization, Collection $people): Collection
    {
        $firstScans = $people->isEmpty() ? collect() : $organization->attendanceEvents()->whereIn('person_id', $people->pluck('id'))
            ->whereIn('event_type', ['check_in', 'late_check_in', 'check_out'])
            ->selectRaw('person_id, MIN(occurred_at) as first_scan_at')->groupBy('person_id')->pluck('first_scan_at', 'person_id');

        return $people->mapWithKeys(function (Person $person) use ($firstScans, $organization): array {
            $dates = array_filter([
                $person->created_at?->copy()->setTimezone($organization->timezone)->toDateString(),
                $firstScans->has($person->id) ? Carbon::parse($firstScans->get($person->id), 'UTC')->setTimezone($organization->timezone)->toDateString() : null,
            ]);

            return [$person->id => $dates === [] ? null : min($dates)];
        });
    }

    /**
     * True while a day's attendance can still change: a future day, or a shift that has not ended yet (its last
     * end time, the next morning for a night shift; or midnight when a period has no end time).
     */
    public static function dayIsOpen(?Schedule $schedule, CarbonInterface $localDate, CarbonInterface $localNow): bool
    {
        $date = $localDate->toDateString();
        $today = $localNow->toDateString();
        if ($date > $today) {
            return true;
        }
        $ends = collect($schedule?->windows($date, $localNow->getTimezone()->getName()) ?? [])->pluck('end');
        if ($ends->isEmpty() || $ends->contains(null)) {
            return $date === $today;
        }

        return $localNow->lessThan($ends->max());
    }

    /** @return array{date: string, day: string, status: string, scheduled: bool, time_in: ?string, time_out: ?string, minutes_late: ?int, no_time_in: bool, no_time_out: bool, visits: int}|null */
    private function day(Collection $events, ?Schedule $schedule, Carbon $localDate, CarbonInterface $localNow, ?string $joinedOn, string $timezone, bool $tracksTimeOut = true): ?array
    {
        $dateString = $localDate->toDateString();
        if ($events->isEmpty()) {
            if (! $schedule || ($joinedOn !== null && $dateString < $joinedOn)) {
                return null;
            }
            $status = self::dayIsOpen($schedule, $localDate, $localNow) ? self::NOT_YET : self::ABSENT;

            return ['date' => $dateString, 'day' => $localDate->format('l'), 'status' => $status, 'scheduled' => true, 'time_in' => null, 'time_out' => null, 'minutes_late' => null, 'no_time_in' => false, 'no_time_out' => false, 'visits' => 0];
        }

        $checkIn = $events->first(fn ($event): bool => in_array($event->event_type, ['check_in', 'late_check_in'], true));
        $checkOut = $checkIn
            ? $events->filter(fn ($event): bool => $event->event_type === 'check_out' && $event->occurred_at->greaterThanOrEqualTo($checkIn->occurred_at))->last()
            : $events->filter(fn ($event): bool => ClassifyDailyAttendance::isNoTimeIn($event))->last();
        $status = $this->classifier->handle($events) === 'LATE' ? self::LATE : self::PRESENT;
        $flags = $this->classifier->flags($events, $schedule, $localDate, $localNow);

        return [
            'date' => $dateString,
            'day' => $localDate->format('l'),
            'status' => $status,
            'scheduled' => $schedule !== null,
            'time_in' => $checkIn?->occurred_at->copy()->setTimezone($timezone)->format('g:i A'),
            'time_out' => $checkOut?->occurred_at->copy()->setTimezone($timezone)->format('g:i A'),
            'minutes_late' => $this->minutesLate($events, $schedule, $dateString, $timezone),
            'no_time_in' => $flags['no_time_in'],
            'no_time_out' => $tracksTimeOut && $flags['no_time_out'],
            'visits' => $events->whereIn('event_type', ['check_in', 'late_check_in'])->count(),
        ];
    }

    /** Minutes after the late-after time of the period the late time in belongs to (the latest period that had started). */
    private function minutesLate(Collection $events, ?Schedule $schedule, string $date, string $timezone): ?int
    {
        $lateCheckIn = $events->first(fn ($event): bool => $event->event_type === 'late_check_in');
        if (! $lateCheckIn || ! $schedule) {
            return null;
        }
        $scannedAt = $lateCheckIn->occurred_at->copy()->setTimezone($timezone)->startOfMinute();
        $windows = $schedule->windows($date, $timezone);
        $window = collect($windows)->filter(fn (array $window): bool => $window['start']->lessThanOrEqualTo($scannedAt))->last() ?? $windows[0];

        return max(0, (int) floor($window['late_after']->diffInMinutes($scannedAt, false)));
    }

    /** e.g. "8:00 AM – 5:00 PM", or "10:00 PM – 6:00 AM (next day)" for a night shift. */
    public static function shiftLabel(Schedule $schedule, string $date, string $timezone): string
    {
        $windows = $schedule->windows($date, $timezone);
        $first = $windows[0]['start'];
        $last = end($windows)['end'];
        if (! $last) {
            return $first->format('g:i A');
        }

        return $first->format('g:i A').' – '.$last->format('g:i A').($last->toDateString() !== $date ? ' (next day)' : '');
    }
}

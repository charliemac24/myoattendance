<?php

namespace App\Services;

use App\Models\AttendanceEvent;
use App\Models\Schedule;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Hours worked for one person on one shift date, from their time ins and outs. All amounts are whole minutes.
 *
 * Rules (the defaults agreed for employee timesheets):
 * - Scans are read as time in to time out stretches, whatever period the scanner filed them under, so one in and
 *   one out over a split schedule (7:30–12:00 and 1:00–5:00) still counts both halves.
 * - Regular time only counts inside the scheduled periods: arriving early, or working through the gap between
 *   periods, is not paid.
 * - Late: arriving after the period's "late after" (grace) time; the minutes are counted from the period start.
 * - Undertime: leaving before the period's end, counted to the end. A missed period on a day they came is undertime.
 *   Scanning out and back in during a period (e.g. lunch) is simply not worked time.
 * - Overtime: time after the last period's end, counted only when it is at least OVERTIME_MINIMUM_MINUTES.
 * - The schedule's unpaid break is taken out once, only when they worked at least BREAK_AFTER_MINUTES, less any
 *   time they already scanned out during the shift.
 * - A time in still open once the shift is over, or a time out with no time in, is incomplete: those hours are not
 *   guessed and count as 0 until someone fixes the scans.
 * - On a day with no schedule (a rest day), each time in to time out is counted as rest day time.
 */
class CalculateWorkHours
{
    public const OVERTIME_MINIMUM_MINUTES = 30;

    public const BREAK_AFTER_MINUTES = 300;

    /**
     * @param  Collection<int, AttendanceEvent>  $events  the shift's time ins and outs, in time order
     * @return array{scheduled_minutes: ?int, regular_minutes: int, late_minutes: int, undertime_minutes: int, overtime_minutes: int, rest_day_minutes: int, break_minutes: int, incomplete: bool, in_progress: bool, absent: bool}
     */
    public function handle(?Schedule $schedule, Collection $events, string $shiftDate, CarbonInterface $localNow): array
    {
        $result = ['scheduled_minutes' => $schedule?->scheduledMinutes(), 'regular_minutes' => 0, 'late_minutes' => 0, 'undertime_minutes' => 0, 'overtime_minutes' => 0, 'rest_day_minutes' => 0, 'break_minutes' => 0, 'incomplete' => false, 'in_progress' => false, 'absent' => false];
        $timezone = $localNow->getTimezone()->getName();

        if (! $schedule) {
            return $this->restDay($events, $result, $localNow);
        }
        if ($events->isEmpty()) {
            $result['absent'] = ! AttendanceTimeline::dayIsOpen($schedule, Carbon::parse($shiftDate, $timezone), $localNow);
            $result['in_progress'] = ! $result['absent'];

            return $result;
        }

        $windows = $schedule->windows($shiftDate, $timezone);
        [$intervals, $openIn, $broken] = $this->intervals($events, $timezone);
        $result['incomplete'] = $broken;
        $lastEnd = end($windows)['end'];
        $shiftIsOver = $lastEnd ? $localNow->greaterThanOrEqualTo($lastEnd) : $localNow->toDateString() > $shiftDate;
        if ($openIn) {
            $result[$shiftIsOver ? 'incomplete' : 'in_progress'] = true;
        }

        $worked = 0;
        $gaps = 0;
        foreach ($windows as $window) {
            $windowEnd = $window['end'] ?? ($openIn ? $localNow->copy()->startOfMinute() : collect($intervals)->max('out'));
            $inside = collect($intervals)
                ->map(fn (array $interval): array => ['in' => $interval['in']->max($window['start']), 'out' => $windowEnd ? $interval['out']->min($windowEnd) : $interval['out'], 'started' => $interval['in']])
                ->filter(fn (array $interval): bool => $interval['out']->greaterThan($interval['in']))
                ->values();
            // Someone still checked in counts as here from their time in, for late (not for hours).
            $openStart = $openIn && (! $window['end'] || $openIn->lessThan($window['end'])) ? $openIn->max($window['start']) : null;
            if ($inside->isEmpty() && ! $openStart) {
                // Missed this whole period on a day they came (once it is over): it is all undertime.
                if ($window['end'] && $localNow->greaterThanOrEqualTo($window['end']) && ($intervals !== [] || $openIn)) {
                    $result['undertime_minutes'] += $this->minutes($window['start'], $window['end']);
                }

                continue;
            }

            $firstIn = $inside->first()['in'] ?? $openStart;
            $arrivedAt = $inside->first()['started'] ?? $openIn;
            if ($arrivedAt->greaterThan($window['late_after']) && $firstIn->greaterThan($window['late_after'])) {
                $result['late_minutes'] += $this->minutes($window['start'], $firstIn);
            }
            foreach ($inside as $position => $interval) {
                $worked += $this->minutes($interval['in'], $interval['out']);
                if ($position > 0) {
                    // Time out and back in during the period (e.g. a lunch scanned out): not worked, not undertime.
                    $gaps += $this->minutes($inside[$position - 1]['out'], $interval['in']);
                }
            }
            $lastOut = $inside->last()['out'] ?? null;
            // Left before the end (unless they are checked back in during this period).
            if ($window['end'] && $lastOut && $lastOut->lessThan($window['end']) && ! ($openIn && $openIn->lessThan($window['end']))) {
                $result['undertime_minutes'] += $this->minutes($lastOut, $window['end']);
            }
        }

        if ($lastEnd) {
            $overtime = collect($intervals)->sum(fn (array $interval): int => $interval['out']->greaterThan($lastEnd) ? $this->minutes($interval['in']->max($lastEnd), $interval['out']) : 0);
            $result['overtime_minutes'] = $overtime >= self::OVERTIME_MINIMUM_MINUTES ? $overtime : 0;
        }

        // The unpaid break, less any time they already scanned out during the shift (e.g. a lunch they scanned).
        $break = max(0, (int) $schedule->break_minutes - $gaps);
        if ($break > 0 && $worked >= self::BREAK_AFTER_MINUTES) {
            $result['break_minutes'] = min($break, $worked);
            $worked -= $result['break_minutes'];
        }
        $result['regular_minutes'] = $worked;

        return $result;
    }

    /**
     * The person's time in to time out stretches, in order. A time out with no time in before it (including one the
     * scanner saved as "no time in") makes the day incomplete; a time in still waiting for its time out is returned
     * separately.
     *
     * @param  Collection<int, AttendanceEvent>  $events
     * @return array{0: array<int, array{in: Carbon, out: Carbon}>, 1: ?Carbon, 2: bool}
     */
    private function intervals(Collection $events, string $timezone): array
    {
        $intervals = [];
        $open = null;
        $broken = false;
        foreach ($events as $event) {
            $at = $event->occurred_at->copy()->setTimezone($timezone)->startOfMinute();
            if ($event->event_type !== 'check_out') {
                $open ??= $at;

                continue;
            }
            if ($open) {
                $intervals[] = ['in' => $open, 'out' => $at];
                $open = null;
            } else {
                $broken = true;
            }
        }

        return [$intervals, $open, $broken];
    }

    /**
     * @param  Collection<int, AttendanceEvent>  $events
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function restDay(Collection $events, array $result, CarbonInterface $localNow): array
    {
        $timezone = $localNow->getTimezone()->getName();
        $in = null;
        foreach ($events as $event) {
            $at = $event->occurred_at->copy()->setTimezone($timezone)->startOfMinute();
            if ($event->event_type !== 'check_out') {
                $in = $at;

                continue;
            }
            if ($in) {
                $result['rest_day_minutes'] += $this->minutes($in, $at);
                $in = null;
            } else {
                $result['incomplete'] = true;
            }
        }
        if ($in) {
            $result[$localNow->toDateString() > $in->toDateString() ? 'incomplete' : 'in_progress'] = true;
        }

        return $result;
    }

    private function minutes(CarbonInterface $from, CarbonInterface $to): int
    {
        return max(0, (int) $from->diffInMinutes($to, false));
    }
}

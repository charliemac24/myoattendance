<?php

namespace App\Services;

use App\Models\Schedule;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class ClassifyDailyAttendance
{
    public function handle(Collection $events): string
    {
        $checkIns = $events->whereIn('event_type', ['check_in', 'late_check_in']);
        if ($checkIns->isEmpty()) {
            // Scanned only when leaving: they were on site, but their arrival was never recorded, so count them as late.
            return $events->contains(fn ($event): bool => self::isNoTimeIn($event)) ? 'LATE' : 'ABSENT';
        }

        return $checkIns->contains(fn ($event): bool => $event->event_type === 'late_check_in') ? 'LATE' : 'PRESENT';
    }

    /**
     * Missing-scan flags for one person's day. Events must be that day's check-ins/check-outs in time order.
     *
     * - no_time_in: the person's first scan for a period came at the end of the day and was saved as a time out.
     * - no_time_out: the person's last scan is a time in, and that period is over (its end time has passed, the next
     *   morning for a night shift, or the day has passed when the schedule has no end time).
     *
     * @return array{no_time_in: bool, no_time_out: bool}
     */
    public function flags(Collection $events, ?Schedule $schedule, CarbonInterface $localDate, CarbonInterface $localNow): array
    {
        $noTimeIn = $events->contains(fn ($event): bool => self::isNoTimeIn($event));
        $last = $events->last();
        if (! $last || $last->event_type === 'check_out') {
            return ['no_time_in' => $noTimeIn, 'no_time_out' => false];
        }

        $slots = 0;
        foreach ($events as $event) {
            if ($event === $last) {
                break;
            }
            $slots += self::slotsUsed($event);
        }
        $windows = $schedule?->windows($localDate->toDateString(), $localNow->getTimezone()->getName()) ?? [];
        $end = $windows[intdiv($slots, 2)]['end'] ?? null;
        // With an end time, the period is over once that moment passes (the next morning for a night shift);
        // without one, once the day is over.
        $periodIsOver = $end
            ? $localNow->greaterThanOrEqualTo($end->copy()->startOfMinute())
            : $localNow->toDateString() > $localDate->toDateString();

        return ['no_time_in' => $noTimeIn, 'no_time_out' => (bool) $periodIsOver];
    }

    public static function isNoTimeIn($event): bool
    {
        return $event->event_type === 'check_out' && (bool) (($event->meta ?? [])['no_time_in'] ?? false);
    }

    /** A time out saved without a time in fills both the in and the out of its period. */
    public static function slotsUsed($event): int
    {
        return self::isNoTimeIn($event) ? 2 : 1;
    }
}

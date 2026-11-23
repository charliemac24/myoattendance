<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Person;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class BuildPersonAttendanceHistory
{
    public const DEFAULT_DAYS = 30;

    public const MAX_DAYS = 366;

    public function __construct(private readonly AttendanceTimeline $timeline) {}

    /**
     * One person's attendance between two local dates (inclusive), newest day first.
     *
     * The rate counts scheduled days only: attended (present or late) ÷ (attended + absent). Today is left out
     * of the rate while it is still "not yet". Scans on unscheduled days are listed but do not change the rate.
     *
     * @return array{person: array<string, mixed>, from: string, to: string, summary: array{scheduled_days: int, attended: int, late: int, absent: int, attendance_rate: ?float, visits: int, days_visited: int, last_seen: ?string, last_seen_date: ?string, last_seen_time: ?string, days_since_last_seen: ?int}, today: string, days: array<int, array<string, mixed>>}
     */
    public function handle(Organization $organization, Person $person, CarbonInterface $from, CarbonInterface $to): array
    {
        $person->loadMissing(['group:id,name,attendance_mode,scan_out', 'subgroup:id,name']);
        $days = collect($this->timeline->handle($organization, collect([$person]), $from, $to)->get($person->id, []))->reverse()->values();
        $scheduled = $days->where('scheduled', true)->where('status', '!=', AttendanceTimeline::NOT_YET);
        $attended = $scheduled->whereIn('status', [AttendanceTimeline::PRESENT, AttendanceTimeline::LATE])->count();
        $lastEvent = $organization->attendanceEvents()->where('person_id', $person->id)->whereIn('event_type', ['check_in', 'late_check_in', 'check_out'])->where('occurred_at', '<=', now())->latest('occurred_at')->first();
        $lastSeen = $lastEvent?->occurred_at->copy()->setTimezone($organization->timezone);

        return [
            'person' => [
                'id' => $person->id,
                'person_number' => $person->person_number,
                'full_name' => trim($person->first_name.' '.$person->last_name),
                'group' => $person->group?->name,
                'subgroup' => $person->subgroup?->name,
                'added_on' => $person->created_at?->copy()->setTimezone($organization->timezone)->toDateString(),
                'open_hours' => (bool) $person->group?->isOpen(),
                'membership_valid_until' => $person->membership_valid_until?->toDateString(),
            ],
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'summary' => [
                'scheduled_days' => $scheduled->count(),
                'attended' => $attended,
                'late' => $scheduled->where('status', AttendanceTimeline::LATE)->count(),
                'absent' => $scheduled->where('status', AttendanceTimeline::ABSENT)->count(),
                'attendance_rate' => $scheduled->isEmpty() ? null : round($attended / $scheduled->count() * 100, 1),
                'visits' => $days->sum('visits'),
                'days_visited' => $days->where('visits', '>', 0)->count(),
                'last_seen' => $lastSeen?->format('M j, Y g:i A'),
                'last_seen_date' => $lastSeen?->toDateString(),
                'last_seen_time' => $lastSeen?->format('g:i A'),
                'days_since_last_seen' => $lastSeen ? (int) $lastSeen->copy()->startOfDay()->diffInDays(now($organization->timezone)->startOfDay()) : null,
            ],
            'today' => now($organization->timezone)->toDateString(),
            'days' => $days->all(),
        ];
    }

    /** @return array{0: Carbon, 1: Carbon} The requested range, or the last 30 days ending today. */
    public static function range(Organization $organization, ?string $from, ?string $to): array
    {
        $end = $to ? Carbon::createFromFormat('!Y-m-d', $to, $organization->timezone) : now($organization->timezone)->startOfDay();
        $start = $from ? Carbon::createFromFormat('!Y-m-d', $from, $organization->timezone) : $end->copy()->subDays(self::DEFAULT_DAYS - 1);

        return [$start, $end];
    }
}

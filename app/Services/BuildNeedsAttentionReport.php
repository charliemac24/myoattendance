<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Person;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Active people who may have stopped coming. A person is listed for any of these reasons:
 *
 * - not_seen: no scan for at least N days (default 7) while they had scheduled days in that time (open-hours
 *   members: no scan for N days, whatever the schedule).
 * - streak: absent on their last 3 or more scheduled days in a row.
 * - rate: attended less than 75% of their scheduled days in the last 30 days (with at least 5 scheduled days).
 */
class BuildNeedsAttentionReport
{
    public const NOT_SEEN_OPTIONS = [3, 7, 14, 30];

    public const DEFAULT_NOT_SEEN_DAYS = 7;

    public const STREAK_THRESHOLD = 3;

    public const RATE_THRESHOLD = 75;

    public const RATE_MIN_DAYS = 5;

    public const WINDOW_DAYS = 30;

    public function __construct(private readonly AttendanceTimeline $timeline) {}

    /** @return array{not_seen_days: int, window_days: int, streak_threshold: int, rate_threshold: int, total: int, people: array<int, array<string, mixed>>} */
    public function handle(Organization $organization, int $notSeenDays = self::DEFAULT_NOT_SEEN_DAYS): array
    {
        $timezone = $organization->timezone;
        $today = now($timezone)->startOfDay();
        $windowStart = $today->copy()->subDays(max(self::WINDOW_DAYS, $notSeenDays) - 1);
        $people = $organization->persons()->where('is_active', true)->with(['group:id,name,attendance_mode,scan_out', 'subgroup:id,name'])->orderBy('last_name')->orderBy('first_name')->orderBy('person_number')->get();
        $timelines = $this->timeline->handle($organization, $people, $windowStart, $today);
        $lastSeen = $organization->attendanceEvents()->whereIn('person_id', $people->pluck('id'))->whereIn('event_type', ['check_in', 'late_check_in', 'check_out'])->where('occurred_at', '<=', now())
            ->selectRaw('person_id, MAX(occurred_at) as last_seen_at')->groupBy('person_id')->pluck('last_seen_at', 'person_id');

        $rows = $people->map(fn (Person $person): ?array => $this->row($person, collect($timelines->get($person->id, [])), $lastSeen->get($person->id), $today, $notSeenDays, $timezone))->filter()
            ->sort(fn (array $a, array $b): int => [$b['days_since_last_seen'] ?? PHP_INT_MAX, $b['absence_streak'], $a['full_name']] <=> [$a['days_since_last_seen'] ?? PHP_INT_MAX, $a['absence_streak'], $b['full_name']])
            ->values();

        return [
            'not_seen_days' => $notSeenDays,
            'window_days' => self::WINDOW_DAYS,
            'streak_threshold' => self::STREAK_THRESHOLD,
            'rate_threshold' => self::RATE_THRESHOLD,
            'total' => $rows->count(),
            'people' => $rows->all(),
        ];
    }

    /** @param Collection<int, array<string, mixed>> $days */
    private function row(Person $person, Collection $days, ?string $lastSeenAt, Carbon $today, int $notSeenDays, string $timezone): ?array
    {
        // Holidays are neither a day they came nor a day they missed: they are skipped, and do not break an absence streak.
        $counted = $days->filter(fn (array $day): bool => ! in_array($day['status'], [AttendanceTimeline::NOT_YET, AttendanceTimeline::HOLIDAY], true));
        $lastSeen = $lastSeenAt ? Carbon::parse($lastSeenAt, 'UTC')->setTimezone($timezone) : null;
        $daysSince = $lastSeen ? (int) $lastSeen->copy()->startOfDay()->diffInDays($today) : null;

        $notSeenSince = $today->copy()->subDays($notSeenDays - 1)->toDateString();
        $scheduledLately = $counted->contains(fn (array $day): bool => $day['scheduled'] && $day['date'] >= $notSeenSince);
        $addedOn = $person->created_at?->copy()->setTimezone($timezone)->startOfDay();
        // Open-hours members have no schedule, so they are only checked for not coming in.
        $notSeen = ($scheduledLately || $person->group?->isOpen()) && ($daysSince !== null ? $daysSince >= $notSeenDays : (! $addedOn || $addedOn->diffInDays($today) >= $notSeenDays));

        $streak = 0;
        foreach ($counted->reverse() as $day) {
            if ($day['status'] !== AttendanceTimeline::ABSENT) {
                break;
            }
            $streak++;
        }

        $rateStart = $today->copy()->subDays(self::WINDOW_DAYS - 1)->toDateString();
        $rateDays = $counted->filter(fn (array $day): bool => $day['scheduled'] && $day['date'] >= $rateStart);
        $attended = $rateDays->whereIn('status', [AttendanceTimeline::PRESENT, AttendanceTimeline::LATE])->count();
        $rate = $rateDays->isEmpty() ? null : round($attended / $rateDays->count() * 100, 1);

        $reasons = [];
        if ($notSeen) {
            $reasons[] = ['type' => 'not_seen', 'label' => $daysSince === null ? 'Never scanned' : "Not seen in {$daysSince} days"];
        }
        if ($streak >= self::STREAK_THRESHOLD) {
            $reasons[] = ['type' => 'streak', 'label' => "{$streak} absences in a row"];
        }
        if ($rate !== null && $rateDays->count() >= self::RATE_MIN_DAYS && $rate < self::RATE_THRESHOLD) {
            $reasons[] = ['type' => 'rate', 'label' => "{$rate}% attendance"];
        }
        if ($reasons === []) {
            return null;
        }

        return [
            'id' => $person->id,
            'person_number' => $person->person_number,
            'full_name' => trim($person->first_name.' '.$person->last_name),
            'group' => $person->group?->name,
            'subgroup' => $person->subgroup?->name,
            'contact_number' => $person->contact_number,
            'last_seen' => $lastSeen?->format('M j, Y g:i A'),
            'last_seen_date' => $lastSeen?->toDateString(),
            'days_since_last_seen' => $daysSince,
            'absence_streak' => $streak,
            'attendance_rate' => $rate,
            'scheduled_days' => $rateDays->count(),
            'attended_days' => $attended,
            'reasons' => $reasons,
        ];
    }
}

<?php

namespace App\Services;

use App\Models\AttendanceEvent;
use App\Models\Organization;
use Carbon\CarbonInterface;

class BuildHomeDashboard
{
    public function __construct(private readonly BuildDailyAttendanceReport $dailyReport, private readonly BuildNeedsAttentionReport $needsAttention) {}

    /** @return array<string, mixed> */
    public function handle(Organization $organization, CarbonInterface $localNow): array
    {
        $today = $localNow->toDateString();
        $todaySummary = $this->dailyReport->handle($organization, $today)['summary'];
        $recorded = $todaySummary['present'] + $todaySummary['late'];
        $recordedPercent = $todaySummary['expected_total'] > 0
            ? round($recorded / $todaySummary['expected_total'] * 100, 1)
            : 0.0;

        return [
            'today_summary' => $todaySummary,
            'recorded' => $recorded,
            'recorded_percent' => $recordedPercent,
            'recent_activity' => $this->recentEvents($organization, $localNow, 3),
            'needs_attention' => $this->needsAttentionPreview($organization),
        ];
    }

    /** @return array{total: int, not_seen_days: int, people: array<int, array<string, mixed>>} */
    private function needsAttentionPreview(Organization $organization): array
    {
        $report = $this->needsAttention->handle($organization);

        return ['total' => $report['total'], 'not_seen_days' => $report['not_seen_days'], 'people' => array_slice($report['people'], 0, 3)];
    }

    /** @return array<int, array<string, mixed>> */
    private function recentEvents(Organization $organization, CarbonInterface $localNow, int $limit): array
    {
        $start = $localNow->copy()->startOfDay()->utc();
        $end = $localNow->copy()->endOfDay()->utc();

        $events = $organization->attendanceEvents()
            ->whereBetween('occurred_at', [$start, $end])
            ->whereIn('event_type', ['check_in', 'late_check_in', 'check_out'])
            ->with('person:id,first_name,last_name,person_number')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $events->map(function (AttendanceEvent $event) use ($organization): array {
            $local = $event->occurred_at->copy()->setTimezone($organization->timezone);

            return [
                'person' => $event->person ? ['id' => $event->person->id, 'name' => trim("{$event->person->first_name} {$event->person->last_name}"), 'person_number' => $event->person->person_number] : null,
                'direction' => $event->event_type === 'check_out' ? 'OUT' : 'IN',
                'status' => $event->event_type === 'late_check_in' ? 'LATE' : ($event->event_type === 'check_out' ? null : 'PRESENT'),
                'time' => $local->format('g:i A'),
                'time_ago' => $event->occurred_at->diffForHumans(),
            ];
        })->all();
    }
}

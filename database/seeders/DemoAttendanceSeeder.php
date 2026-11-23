<?php

namespace Database\Seeders;

use App\Models\AttendanceEvent;
use App\Models\Organization;
use App\Models\Schedule;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class DemoAttendanceSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::query()->withCount('persons')->orderByDesc('persons_count')->orderByDesc('id')->firstOrFail();
        $people = $organization->persons()->orderBy('id')->get();
        $userId = $organization->users()->orderBy('id')->value('id');
        $groups = $people->pluck('group_id')->filter()->unique();

        foreach ($groups as $groupId) {
            for ($day = 1; $day <= 7; $day++) {
                Schedule::firstOrCreate(
                    ['organization_id' => $organization->id, 'group_id' => $groupId, 'subgroup_id' => null, 'day_of_week' => $day],
                    ['name' => 'Demo daily schedule', 'start_time' => '07:30', 'late_after' => '07:45', 'end_time' => '17:00', 'periods' => [['start_time' => '07:30', 'late_after' => '07:45', 'end_time' => '12:00'], ['start_time' => '13:00', 'late_after' => '13:15', 'end_time' => '17:00']], 'is_active' => true],
                );
            }
        }

        $date = Carbon::now($organization->timezone)->startOfDay();
        foreach ($people->values() as $index => $person) {
            $pattern = $index % 5;
            if ($pattern === 0) {
                continue;
            }
            $times = match ($pattern) {
                1 => [['07:35', 'check_in'], ['16:45', 'check_out']],
                2 => [['08:05', 'late_check_in'], ['16:40', 'check_out']],
                3 => [['07:32', 'check_in'], ['12:00', 'check_out'], ['13:05', 'check_in'], ['16:55', 'check_out']],
                default => [['07:40', 'check_in']],
            };
            foreach ($times as [$time, $eventType]) {
                $seedKey = "demo-{$date->toDateString()}-{$person->id}-{$time}-{$eventType}";
                if (AttendanceEvent::where('organization_id', $organization->id)->where('meta->seed_key', $seedKey)->exists()) {
                    continue;
                }
                AttendanceEvent::create(['organization_id' => $organization->id, 'person_id' => $person->id, 'event_type' => $eventType, 'occurred_at' => $date->copy()->setTimeFromTimeString($time)->utc(), 'performed_by_user_id' => $userId, 'meta' => ['seed_key' => $seedKey, 'source' => 'demo-seeder']]);
            }
        }
    }
}

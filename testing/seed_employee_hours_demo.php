<?php

/**
 * LOCAL SIMULATION ONLY. Adds a "Day Crew" (8:00 AM–5:00 PM, 60 min unpaid break) and a "Night Crew"
 * (10:00 PM–6:00 AM, 60 min unpaid break) to MYO Demo Organization, with 4 employees (EMP-01 to EMP-04) and the
 * last 10 days of scans, so the Timesheet shows overtime, late, undertime, a night shift, a missing time out and
 * rest day work. Re-running replaces those groups, employees and scans; nobody else is touched. Scans are written
 * directly, so no SMS is queued.
 *
 * Usage: docker compose exec app php artisan tinker --execute "require 'testing/seed_employee_hours_demo.php';"
 */

use App\Models\AttendanceEvent;
use App\Models\Organization;
use App\Models\Person;
use App\Models\Schedule;
use App\Models\SmsMessage;
use Illuminate\Support\Carbon;

if (app()->environment('production')) {
    echo "Refusing to run in production.\n";

    return;
}

$org = Organization::where('name', 'MYO Demo Organization')->firstOrFail();
$tz = $org->timezone;
$today = Carbon::now($tz)->startOfDay();

$old = $org->persons()->where('person_number', 'like', 'EMP-%')->pluck('id');
AttendanceEvent::whereIn('person_id', $old)->delete();
SmsMessage::query()->whereIn('person_id', $old)->delete();
Person::whereIn('id', $old)->delete();
foreach (['Day Crew', 'Night Crew'] as $name) {
    if ($group = $org->groups()->where('name', $name)->first()) {
        $group->schedules()->delete();
        $group->delete();
    }
}

$day = $org->groups()->create(['name' => 'Day Crew']);
$night = $org->groups()->create(['name' => 'Night Crew']);
foreach (range(1, 5) as $weekday) {
    Schedule::create(['organization_id' => $org->id, 'group_id' => $day->id, 'name' => 'Day Crew Schedule', 'day_of_week' => $weekday, 'start_time' => '08:00', 'late_after' => '08:15', 'end_time' => '17:00', 'periods' => [['start_time' => '08:00', 'late_after' => '08:15', 'end_time' => '17:00']], 'break_minutes' => 60]);
    Schedule::create(['organization_id' => $org->id, 'group_id' => $night->id, 'name' => 'Night Crew Schedule', 'day_of_week' => $weekday, 'start_time' => '22:00', 'late_after' => '22:15', 'end_time' => '06:00', 'periods' => [['start_time' => '22:00', 'late_after' => '22:15', 'end_time' => '06:00']], 'break_minutes' => 60]);
}

$employees = [
    ['EMP-01', 'Ana', 'Cruz', $day, 'Works overtime'],
    ['EMP-02', 'Ben', 'Lopez', $day, 'Sometimes late, left early once, forgot to time out once, worked a Saturday'],
    ['EMP-03', 'Cara', 'Diaz', $night, 'Night shift, once late after midnight'],
    ['EMP-04', 'Dan', 'Ramos', $night, 'Night shift with overtime, missed a night'],
];

$scan = function (Person $person, Carbon $at, string $type, string $shiftDate, array $extra = []) use ($org): void {
    AttendanceEvent::create(['organization_id' => $org->id, 'person_id' => $person->id, 'event_type' => $type, 'occurred_at' => $at->copy()->utc(), 'meta' => ['group_id' => $person->group_id, 'attendance_date' => $shiftDate, 'simulated' => true] + $extra]);
};

foreach ($employees as [$number, $first, $last, $group, $note]) {
    $person = $org->persons()->create(['person_number' => $number, 'first_name' => $first, 'last_name' => $last, 'group_id' => $group->id]);
    $person->forceFill(['created_at' => $today->copy()->subDays(30)])->save();

    for ($ago = 10; $ago >= 1; $ago--) {
        $date = $today->copy()->subDays($ago);
        $d = $date->toDateString();
        if ($number === 'EMP-02' && $date->isSaturday()) {
            $scan($person, $date->copy()->setTime(9, 0), 'check_in', $d, ['rest_day' => true]);
            $scan($person, $date->copy()->setTime(13, 30), 'check_out', $d, ['rest_day' => true]);
        }
        if ($date->isWeekend()) {
            continue;
        }
        match ($number) {
            'EMP-01' => [$scan($person, $date->copy()->setTime(7, 52), 'check_in', $d), $scan($person, $date->copy()->setTime(18, $ago % 2 ? 30 : 5), 'check_out', $d)],
            'EMP-02' => match ($ago % 4) {
                0 => [$scan($person, $date->copy()->setTime(8, 27), 'late_check_in', $d), $scan($person, $date->copy()->setTime(17, 2), 'check_out', $d)],
                1 => [$scan($person, $date->copy()->setTime(8, 1), 'check_in', $d), $scan($person, $date->copy()->setTime(15, 45), 'check_out', $d)],
                2 => $scan($person, $date->copy()->setTime(7, 58), 'check_in', $d),
                default => [$scan($person, $date->copy()->setTime(8, 5), 'check_in', $d), $scan($person, $date->copy()->setTime(17, 0), 'check_out', $d)],
            },
            'EMP-03' => $ago === 3
                ? [$scan($person, $date->copy()->addDay()->setTime(0, 40), 'late_check_in', $d), $scan($person, $date->copy()->addDay()->setTime(6, 3), 'check_out', $d)]
                : [$scan($person, $date->copy()->setTime(21, 50), 'check_in', $d), $scan($person, $date->copy()->addDay()->setTime(6, 8), 'check_out', $d)],
            'EMP-04' => $ago === 5
                ? null
                : [$scan($person, $date->copy()->setTime(21, 57), 'check_in', $d), $scan($person, $date->copy()->addDay()->setTime(7, 15), 'check_out', $d)],
        };
    }
    echo "{$number} {$first} {$last}: {$note}\n";
}

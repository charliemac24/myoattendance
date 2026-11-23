<?php

/**
 * LOCAL SIMULATION ONLY. Adds 8 people (person numbers NA-01 to NA-08) with 30 days of scans to MYO Demo Organization,
 * so the "Needs attention" list has one example of each reason plus normal people for contrast.
 * Re-running replaces those 8 people and their scans; nobody else is touched. Scans are written directly, so no SMS is queued.
 *
 * Usage: docker compose exec app php artisan tinker --execute "require 'testing/seed_needs_attention_demo.php';"
 */

use App\Models\AttendanceEvent;
use App\Models\Organization;
use App\Models\Person;
use App\Models\SmsMessage;
use Illuminate\Support\Carbon;

if (app()->environment('production')) {
    echo "Refusing to run in production.\n";

    return;
}

$org = Organization::where('name', 'MYO Demo Organization')->firstOrFail();
$group = $org->groups()->where('name', 'Demo Group')->firstOrFail();
$subgroup = $org->subgroups()->where('group_id', $group->id)->first();
$tz = $org->timezone;
$today = Carbon::now($tz)->startOfDay();

$old = $org->persons()->where('person_number', 'like', 'NA-%')->pluck('id');
AttendanceEvent::whereIn('person_id', $old)->delete();
SmsMessage::query()->whereIn('person_id', $old)->delete();
Person::whereIn('id', $old)->delete();

// pattern(int $daysAgo): null = no scan, 'on' = on time, 'late' = late
$people = [
    ['NA-01', 'Marco', 'Reyes', 'Stopped coming 10 days ago', fn (int $ago) => $ago >= 10 ? 'on' : null, 30],
    ['NA-02', 'Nora', 'Santos', 'Missed the last 3 days in a row', fn (int $ago) => $ago >= 4 ? 'on' : null, 30],
    ['NA-03', 'Oscar', 'Lim', 'Comes about 2 days in 3 (under 75%)', fn (int $ago) => $ago % 3 === 0 ? null : ($ago % 4 === 0 ? 'late' : 'on'), 30],
    ['NA-04', 'Pia', 'Gomez', 'Added 16 days ago, never scanned', fn (int $ago) => null, 16],
    ['NA-05', 'Rico', 'Tan', 'Came on and off, then stopped 2 weeks ago', fn (int $ago) => $ago >= 14 && $ago % 2 === 0 ? 'on' : null, 30],
    ['NA-06', 'Sara', 'Cruz', 'Normal: every day on time', fn (int $ago) => 'on', 30],
    ['NA-07', 'Tomas', 'Diaz', 'Normal: often late but always comes', fn (int $ago) => $ago % 2 === 0 ? 'late' : 'on', 30],
    ['NA-08', 'Uma', 'Flores', 'Normal: missed 2 days this month', fn (int $ago) => in_array($ago, [5, 17], true) ? null : 'on', 30],
];

foreach ($people as $index => [$number, $first, $last, $story, $pattern, $addedDaysAgo]) {
    $person = Person::create(['organization_id' => $org->id, 'person_number' => $number, 'first_name' => $first, 'last_name' => $last, 'group_id' => $group->id, 'subgroup_id' => $subgroup?->id, 'contact_number' => sprintf('0917555%04d', $index + 1)]);
    $person->forceFill(['created_at' => $today->copy()->subDays($addedDaysAgo)->setTime(6, 0)->utc()])->save();
    for ($ago = min($addedDaysAgo, 30); $ago >= 1; $ago--) {
        $kind = $pattern($ago);
        if ($kind === null) {
            continue;
        }
        $day = $today->copy()->subDays($ago);
        $meta = ['group_id' => $group->id, 'subgroup_id' => $subgroup?->id, 'attendance_date' => $day->toDateString(), 'simulated' => true];
        AttendanceEvent::create(['organization_id' => $org->id, 'person_id' => $person->id, 'event_type' => $kind === 'late' ? 'late_check_in' : 'check_in', 'occurred_at' => $day->copy()->setTime($kind === 'late' ? 8 : 7, $kind === 'late' ? 12 : 35)->utc(), 'meta' => $meta]);
        AttendanceEvent::create(['organization_id' => $org->id, 'person_id' => $person->id, 'event_type' => 'check_out', 'occurred_at' => $day->copy()->setTime(16, 45)->utc(), 'meta' => $meta]);
    }
    echo "{$number} {$first} {$last}: {$story}\n";
}

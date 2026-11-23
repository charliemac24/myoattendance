<?php

namespace Tests\Feature;

use App\Models\AttendanceEvent;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Person;
use App\Models\Schedule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Employee hours: night shifts, late, undertime, overtime, breaks, missing scans, rest days and the timesheet. */
class EmployeeHoursTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::create(['name' => 'Acme Logistics', 'type' => 'warehouse', 'timezone' => 'Asia/Manila']);
        $this->user = User::create(['organization_id' => $this->organization->id, 'name' => 'Owner', 'email' => 'owner@acme.test', 'password' => 'password', 'email_verified_at' => now(), 'role' => 'owner']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_day_shift_hours_with_early_arrival_overtime_late_undertime_half_day_and_the_unpaid_break(): void
    {
        $group = $this->groupWithShift('Day Shift', '08:00', '08:15', '17:00', 60);
        $ana = $this->person($group, 'E1', 'Ana');
        $this->scan($ana, '2026-11-02 07:50');  // early arrival is not paid
        $this->scan($ana, '2026-11-02 17:40');  // 40 min overtime
        $this->scan($ana, '2026-11-03 08:20');  // late: 20 min from the 8:00 start
        $this->scan($ana, '2026-11-03 16:30');  // 30 min undertime
        $this->scan($ana, '2026-11-04 08:00');
        $this->scan($ana, '2026-11-04 17:20');  // 20 min after the end: under the 30 min minimum, not overtime
        $this->scan($ana, '2026-11-05 08:00');
        $this->scan($ana, '2026-11-05 12:00');  // half day: no break taken out, the rest is undertime

        $days = $this->days($ana, '2026-11-02', '2026-11-06', '2026-11-07 09:00');

        $this->assertSame(['Present', 480, 0, 0, 40, 60], $this->summary($days['2026-11-02']));
        $this->assertSame(['Late', 430, 20, 30, 0, 60], $this->summary($days['2026-11-03']));
        $this->assertSame(['Present', 480, 0, 0, 0, 60], $this->summary($days['2026-11-04']));
        $this->assertSame(['Present', 240, 0, 300, 0, 0], $this->summary($days['2026-11-05']));
        $this->assertSame(['Absent', 0, 0, 0, 0, 0], $this->summary($days['2026-11-06']));
        $this->assertSame('8:00 AM – 5:00 PM', $days['2026-11-02']['shift']);
    }

    public function test_one_in_and_one_out_over_a_split_schedule_counts_both_halves(): void
    {
        // A 7:30–12:00 and 1:00–5:00 schedule, scanned only on arrival and when going home.
        $group = Group::create(['organization_id' => $this->organization->id, 'name' => 'Split']);
        Schedule::create(['organization_id' => $this->organization->id, 'group_id' => $group->id, 'name' => 'Split', 'day_of_week' => 2, 'start_time' => '07:30', 'late_after' => '07:45', 'end_time' => '12:00', 'periods' => [['start_time' => '07:30', 'late_after' => '07:45', 'end_time' => '12:00'], ['start_time' => '13:00', 'late_after' => '13:15', 'end_time' => '17:00']]]);
        $gia = $this->person($group, 'S1', 'Gia');
        $this->scan($gia, '2026-11-03 07:40');
        $this->scan($gia, '2026-11-03 16:40');

        $day = $this->days($gia, '2026-11-03', '2026-11-03', '2026-11-04 09:00')['2026-11-03'];
        $this->assertSame(['Present', 480, 0, 20, 0, 0], $this->summary($day), 'Was 4h 20m worked and 240 min undertime before the fix.');
    }

    public function test_employees_can_scan_out_and_back_in_for_lunch_on_a_one_period_shift(): void
    {
        $group = $this->groupWithShift('Day Shift', '07:30', '08:00', '17:00', 60);
        $hugo = $this->person($group, 'E5', 'Hugo');
        $this->scan($hugo, '2026-11-02 07:32')->assertJsonPath('code', 'CHECKED_IN');
        $this->scan($hugo, '2026-11-02 12:00')->assertJsonPath('code', 'CHECKED_OUT');
        $this->scan($hugo, '2026-11-02 13:05')->assertJsonPath('code', 'CHECKED_IN')->assertJsonPath('data.attendance_status', 'PRESENT');
        $this->scan($hugo, '2026-11-02 16:55')->assertJsonPath('code', 'CHECKED_OUT');

        $day = $this->days($hugo, '2026-11-02', '2026-11-02', '2026-11-03 09:00')['2026-11-02'];
        $this->assertSame(['Present', 498, 0, 5, 0, 0], $this->summary($day), '4h 28m + 3h 50m worked; the 65 min lunch already covers the 60 min break; left 5 min early.');
        $this->assertFalse($day['no_time_out']);

        $school = Organization::create(['name' => 'School', 'type' => 'school', 'timezone' => 'Asia/Manila']);
        $teacher = User::create(['organization_id' => $school->id, 'name' => 'T', 'email' => 't@school.test', 'password' => 'password', 'email_verified_at' => now(), 'role' => 'owner']);
        $grade = Group::create(['organization_id' => $school->id, 'name' => 'Grade 1']);
        Schedule::create(['organization_id' => $school->id, 'group_id' => $grade->id, 'name' => 'Class', 'day_of_week' => 2, 'start_time' => '07:30', 'late_after' => '07:45', 'end_time' => '12:00']);
        $pupil = Person::create(['organization_id' => $school->id, 'person_number' => 'P1', 'first_name' => 'Pia', 'last_name' => 'Test', 'group_id' => $grade->id]);
        foreach (['07:30', '12:00'] as $time) {
            Carbon::setTestNow(Carbon::parse("2026-11-03 {$time}", 'Asia/Manila'));
            $this->actingAs($teacher)->postJson('/api/attendance/scan', ['qr_payload' => $pupil->qr_token])->assertOk();
        }
        Carbon::setTestNow(Carbon::parse('2026-11-03 12:30', 'Asia/Manila'));
        $this->actingAs($teacher)->postJson('/api/attendance/scan', ['qr_payload' => $pupil->qr_token])->assertJsonPath('code', 'ATTENDANCE_COMPLETE', 'Schools are unchanged.');
    }

    public function test_a_missing_time_out_is_incomplete_and_its_hours_are_not_guessed(): void
    {
        $group = $this->groupWithShift('Day Shift', '08:00', '08:15', '17:00', 60);
        $ben = $this->person($group, 'E2', 'Ben');
        $this->scan($ben, '2026-11-02 08:05');

        $inProgress = $this->days($ben, '2026-11-02', '2026-11-02', '2026-11-02 12:00')['2026-11-02'];
        $this->assertSame(['In progress', 0, false], [$inProgress['status'], $inProgress['regular_minutes'], $inProgress['incomplete']]);

        $after = $this->days($ben, '2026-11-02', '2026-11-02', '2026-11-03 09:00')['2026-11-02'];
        $this->assertSame(['Incomplete', 0, true, true], [$after['status'], $after['regular_minutes'], $after['incomplete'], $after['no_time_out']]);
    }

    public function test_a_night_shift_counts_on_the_date_it_starts_including_a_late_arrival_after_midnight(): void
    {
        $group = $this->groupWithShift('Night Shift', '22:00', '22:15', '06:00', 60);
        $cara = $this->person($group, 'N1', 'Cara');

        $this->scan($cara, '2026-11-02 21:55')->assertJsonPath('code', 'CHECKED_IN')->assertJsonPath('data.attendance_status', 'PRESENT');
        $this->scan($cara, '2026-11-03 06:10')->assertJsonPath('code', 'CHECKED_OUT');
        $this->scan($cara, '2026-11-03 21:58')->assertJsonPath('code', 'CHECKED_IN', 'Tuesday night is a new shift, not a repeat of Monday.');
        $this->scan($cara, '2026-11-04 06:45')->assertJsonPath('code', 'CHECKED_OUT');
        $this->scan($cara, '2026-11-05 00:30')->assertJsonPath('code', 'CHECKED_IN')->assertJsonPath('data.attendance_status', 'LATE');
        $this->scan($cara, '2026-11-05 06:00')->assertJsonPath('code', 'CHECKED_OUT');

        $this->assertSame(['2026-11-02', '2026-11-02', '2026-11-03', '2026-11-03', '2026-11-04', '2026-11-04'], AttendanceEvent::orderBy('occurred_at')->get()->map(fn ($event) => $event->meta['attendance_date'])->all());

        $days = $this->days($cara, '2026-11-02', '2026-11-05', '2026-11-06 09:00');
        $this->assertSame(['Present', 420, 0, 0, 0, 60], $this->summary($days['2026-11-02']), '22:00 to 06:00 is 8h, minus the 1h break; 10 min over is under the overtime minimum.');
        $this->assertSame(['Present', 420, 0, 0, 45, 60], $this->summary($days['2026-11-03']));
        $this->assertSame(['Late', 270, 150, 0, 0, 60], $this->summary($days['2026-11-04']), 'Arrived 12:30 AM: 150 min late, 5.5h worked minus the break.');
        $this->assertSame(['9:55 PM', '6:10 AM', '10:00 PM – 6:00 AM (next day)'], [$days['2026-11-02']['time_in'], $days['2026-11-02']['time_out'], $days['2026-11-02']['shift']]);
        $this->assertSame('Absent', $days['2026-11-05']['status']);

        $tuesday = $this->actingAs($this->user)->getJson('/api/attendance/report?date=2026-11-03')->assertOk()->json('people.0');
        $this->assertSame(['9:58 PM', '6:45 AM', false], [$tuesday['check_in_time'], $tuesday['check_out_time'], $tuesday['no_time_out']], 'Monday night\'s 6:10 AM time out is not shown on Tuesday.');
        $monthly = $this->actingAs($this->user)->getJson("/api/attendance/monthly?month=2026-11&group_id={$group->id}")->assertOk()->json('persons.0.attendance');
        $this->assertSame(['Present', 'Present', 'Late', 'Absent'], [$monthly['2026-11-02'], $monthly['2026-11-03'], $monthly['2026-11-04'], $monthly['2026-11-05']]);
    }

    public function test_a_night_shift_still_running_is_not_absent_and_a_late_time_out_within_the_grace_closes_it(): void
    {
        $group = $this->groupWithShift('Night Shift', '22:00', '22:15', '06:00', 0);
        $dan = $this->person($group, 'N2', 'Dan');
        $this->scan($dan, '2026-11-02 22:05');

        $running = $this->days($dan, '2026-11-02', '2026-11-02', '2026-11-03 03:00')['2026-11-02'];
        $this->assertSame('In progress', $running['status']);
        $this->assertFalse($running['no_time_out']);

        $this->scan($dan, '2026-11-03 09:30')->assertJsonPath('code', 'CHECKED_OUT');
        $day = $this->days($dan, '2026-11-02', '2026-11-02', '2026-11-03 10:00')['2026-11-02'];
        $this->assertSame(['Present', 475, 0, 0, 210, 0], $this->summary($day), 'Worked 10:05 PM to 6:00 AM, plus 3.5h overtime.');
    }

    public function test_employees_can_work_a_rest_day_but_people_with_no_schedule_at_all_are_still_refused(): void
    {
        $group = $this->groupWithShift('Day Shift', '08:00', '08:15', '17:00', 60);
        $eve = $this->person($group, 'E3', 'Eve');
        $unassigned = Person::create(['organization_id' => $this->organization->id, 'person_number' => 'E4', 'first_name' => 'Fay', 'last_name' => 'Test']);

        $this->scan($eve, '2026-11-07 09:00')->assertJsonPath('code', 'CHECKED_IN')->assertJsonPath('data.rest_day', true);
        $this->scan($eve, '2026-11-07 13:30')->assertJsonPath('code', 'CHECKED_OUT');
        $this->scan($unassigned, '2026-11-07 09:00')->assertJsonPath('code', 'NO_SCHEDULE');

        $saturday = $this->days($eve, '2026-11-07', '2026-11-07', '2026-11-08 09:00')['2026-11-07'];
        $this->assertSame(['Rest day', 0, 270], [$saturday['status'], $saturday['regular_minutes'], $saturday['rest_day_minutes']]);
        $this->assertSame(1, $this->actingAs($this->user)->getJson('/api/attendance/report?date=2026-11-07')->json('summary.expected_total'));
    }

    public function test_timesheet_totals_csv_exports_archived_workers_and_open_hours_groups(): void
    {
        $group = $this->groupWithShift('Day Shift', '08:00', '08:15', '17:00', 60);
        $ana = $this->person($group, 'E1', 'Ana');
        $left = $this->person($group, 'E9', 'Leo');
        $gym = Group::create(['organization_id' => $this->organization->id, 'name' => 'Staff Gym', 'attendance_mode' => Group::MODE_OPEN]);
        $this->person($gym, 'G1', 'Gus');
        $this->scan($ana, '2026-11-02 08:20');
        $this->scan($ana, '2026-11-02 18:00');
        $this->scan($left, '2026-11-02 08:00');
        $this->scan($left, '2026-11-02 17:00');
        $left->update(['is_active' => false]);
        Carbon::setTestNow(Carbon::parse('2026-11-04 09:00', 'Asia/Manila'));

        $data = $this->actingAs($this->user)->getJson('/api/attendance/timesheet?from=2026-11-02&to=2026-11-03')->assertOk()->json();
        $rows = collect($data['people'])->keyBy('person.person_number');
        $this->assertSame(['E1', 'E9'], $rows->keys()->sort()->values()->all(), 'Archived workers keep their worked days; open-hours members are left out.');
        $this->assertSame(['days_worked' => 1, 'absences' => 1, 'incomplete_days' => 0, 'late_days' => 1, 'regular_minutes' => 460, 'late_minutes' => 20, 'undertime_minutes' => 0, 'overtime_minutes' => 60, 'rest_day_minutes' => 0], $rows['E1']['totals']);

        $summary = $this->actingAs($this->user)->get('/api/attendance/timesheet/export?from=2026-11-02&to=2026-11-03&format=summary')->assertOk()->streamedContent();
        $lines = array_map('str_getcsv', preg_split('/\r\n|\n/', trim($summary)));
        $this->assertSame(['ID Number', 'Full Name', 'Department', 'Shift', 'Days Worked', 'Absences', 'Late Days', 'Incomplete Days', 'Regular Hours', 'Late Minutes', 'Undertime Minutes', 'Overtime Hours', 'Rest Day Hours'], $lines[0]);
        $this->assertSame(['E1', 'Ana Test', 'Day Shift', '', '1', '1', '1', '0', '7.67', '20', '0', '1.00', '0.00'], $lines[1]);

        $daily = $this->actingAs($this->user)->get('/api/attendance/timesheet/export?from=2026-11-02&to=2026-11-03')->assertOk()->streamedContent();
        $this->assertStringContainsString('E1,"Ana Test",2026-11-02,Monday,"8:00 AM – 5:00 PM","8:20 AM","6:00 PM",Late,7.67,20,0,1.00,0.00,60,', $daily);
        $this->actingAs($this->user)->getJson('/api/attendance/timesheet?from=2026-09-01&to=2026-11-03')->assertUnprocessable();
    }

    public function test_schedules_accept_night_shifts_and_a_break_but_reject_impossible_ones(): void
    {
        $group = Group::create(['organization_id' => $this->organization->id, 'name' => 'Nights']);
        $base = ['name' => 'Night', 'group_id' => $group->id, 'days' => [1, 2]];

        $this->actingAs($this->user)->postJson('/api/schedules', $base + ['start_time' => '22:00', 'late_after' => '22:15', 'end_time' => '06:00', 'break_minutes' => 60])->assertCreated();
        $this->assertTrue(Schedule::first()->isOvernight());
        $this->assertSame(420, Schedule::first()->scheduledMinutes());
        $this->actingAs($this->user)->postJson('/api/schedules', ['days' => [3]] + $base + ['start_time' => '23:50', 'late_after' => '00:05', 'end_time' => '08:00'])->assertCreated();
        $this->actingAs($this->user)->postJson('/api/schedules', ['days' => [4]] + $base + ['start_time' => '08:00', 'late_after' => '08:15', 'end_time' => '17:00', 'break_minutes' => 540])->assertUnprocessable();
        $this->actingAs($this->user)->postJson('/api/schedules', ['days' => [5]] + $base + ['start_time' => '08:00', 'late_after' => '07:00'])->assertUnprocessable();
        $twoPeriods = ['days' => [6], 'start_time' => '20:00', 'late_after' => '20:10', 'periods' => [['start_time' => '20:00', 'late_after' => '20:10', 'end_time' => '01:00'], ['start_time' => '21:00', 'late_after' => '21:10', 'end_time' => '23:00']]];
        $this->actingAs($this->user)->postJson('/api/schedules', $twoPeriods + $base)->assertUnprocessable();
    }

    private function groupWithShift(string $name, string $start, string $lateAfter, string $end, int $breakMinutes): Group
    {
        $group = Group::create(['organization_id' => $this->organization->id, 'name' => $name]);
        foreach (range(1, 5) as $day) {
            Schedule::create(['organization_id' => $this->organization->id, 'group_id' => $group->id, 'name' => $name, 'day_of_week' => $day, 'start_time' => $start, 'late_after' => $lateAfter, 'end_time' => $end, 'break_minutes' => $breakMinutes]);
        }

        return $group;
    }

    private function person(Group $group, string $number, string $first): Person
    {
        $person = Person::create(['organization_id' => $this->organization->id, 'person_number' => $number, 'first_name' => $first, 'last_name' => 'Test', 'group_id' => $group->id]);
        $person->forceFill(['created_at' => '2026-10-01 00:00:00'])->save();

        return $person->fresh();
    }

    private function scan(Person $person, string $localTime)
    {
        Carbon::setTestNow(Carbon::parse($localTime, 'Asia/Manila'));

        return $this->actingAs($this->user)->postJson('/api/attendance/scan', ['qr_payload' => $person->qr_token])->assertOk();
    }

    /** @return array<string, array<string, mixed>> */
    private function days(Person $person, string $from, string $to, string $now): array
    {
        Carbon::setTestNow(Carbon::parse($now, 'Asia/Manila'));

        return collect($this->actingAs($this->user)->getJson("/api/attendance/timesheet?from={$from}&to={$to}&person_id={$person->id}")->assertOk()->json('people.0.days'))->keyBy('date')->all();
    }

    /** @param array<string, mixed> $day */
    private function summary(array $day): array
    {
        return [$day['status'], $day['regular_minutes'], $day['late_minutes'], $day['undertime_minutes'], $day['overtime_minutes'], $day['break_minutes']];
    }
}

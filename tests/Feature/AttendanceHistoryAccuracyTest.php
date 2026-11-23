<?php

namespace Tests\Feature;

use App\Models\AttendanceEvent;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Person;
use App\Models\PersonAssignment;
use App\Models\Schedule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Past attendance stays the same when people move groups, schedules are edited, or people are archived. */
class AttendanceHistoryAccuracyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organization $organization;

    private Group $morning;

    private Group $afternoon;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::create(['name' => 'Sunrise School', 'type' => 'school', 'timezone' => 'Asia/Manila']);
        $this->user = User::create(['organization_id' => $this->organization->id, 'name' => 'Owner', 'email' => 'owner@sunrise.test', 'password' => 'password', 'email_verified_at' => now(), 'role' => 'owner']);
        $this->morning = $this->groupWithWeekdays('Morning', '07:30', '07:45', '12:00');
        $this->afternoon = $this->groupWithWeekdays('Afternoon', '13:00', '13:15', '17:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_moving_mid_month_keeps_past_days_in_the_old_group_and_moving_back_works(): void
    {
        $this->now('2026-11-01 08:00');
        $kim = $this->person('K1', 'Kim', $this->morning);
        foreach (['2026-11-02', '2026-11-03', '2026-11-04'] as $date) {
            $this->scanEvent($kim, "{$date} 07:35");
        }

        $this->now('2026-11-09 06:00');
        $this->actingAs($this->user)->putJson("/api/people/{$kim->id}", ['person_number' => 'K1', 'first_name' => 'Kim', 'last_name' => 'Test', 'group_id' => $this->afternoon->id])->assertOk();
        $this->now('2026-11-09 18:00');
        $this->actingAs($this->user)->putJson("/api/people/{$kim->id}", ['person_number' => 'K1', 'first_name' => 'Kim', 'last_name' => 'Test', 'group_id' => $this->afternoon->id, 'contact_number' => '0917'])->assertOk();
        $this->assertSame(2, PersonAssignment::where('person_id', $kim->id)->count(), 'Saving without a group change adds no history.');
        $this->scanEvent($kim, '2026-11-10 13:05');
        $this->now('2026-11-16 06:00');
        $this->actingAs($this->user)->putJson("/api/people/{$kim->id}", ['person_number' => 'K1', 'first_name' => 'Kim', 'last_name' => 'Test', 'group_id' => $this->morning->id])->assertOk();
        $this->now('2026-11-20 20:00');

        $this->assertSame([[null, '2026-11-08'], ['2026-11-09', '2026-11-15'], ['2026-11-16', null]], PersonAssignment::where('person_id', $kim->id)->orderBy('id')->get()->map(fn ($row) => [$row->starts_on?->toDateString(), $row->ends_on?->toDateString()])->all());

        $morning = $this->monthly($this->morning)['2026-11'];
        $this->assertSame(['Present', 'Present', 'Present', 'Absent', '-', '-', 'Absent'], [$morning['2026-11-02'], $morning['2026-11-03'], $morning['2026-11-04'], $morning['2026-11-05'], $morning['2026-11-09'], $morning['2026-11-13'], $morning['2026-11-16']]);
        $afternoon = $this->monthly($this->afternoon)['2026-11'];
        $this->assertSame(['-', 'Absent', 'Present', '-'], [$afternoon['2026-11-06'], $afternoon['2026-11-09'], $afternoon['2026-11-10'], $afternoon['2026-11-16']]);

        $daily = $this->actingAs($this->user)->getJson('/api/attendance/report?date=2026-11-03')->assertOk()->json('people.0');
        $this->assertSame(['Morning', 'PRESENT'], [$daily['group']['name'], $daily['status']]);
        $this->assertSame('Afternoon', $this->actingAs($this->user)->getJson("/api/attendance/report?date=2026-11-10&group_id={$this->afternoon->id}")->json('people.0.group.name'));
        $this->assertSame([], $this->actingAs($this->user)->getJson("/api/attendance/report?date=2026-11-03&group_id={$this->afternoon->id}")->json('people'));
    }

    public function test_editing_or_removing_a_schedule_only_changes_attendance_from_today(): void
    {
        $this->now('2026-11-01 08:00');
        $lea = $this->person('L1', 'Lea', $this->morning);
        $this->scanEvent($lea, '2026-11-02 07:40');
        $this->scanEvent($lea, '2026-11-02 12:00', 'check_out');

        $this->now('2026-11-09 06:00');
        $wednesday = Schedule::where('group_id', $this->morning->id)->where('day_of_week', 3)->first();
        $monday = Schedule::where('group_id', $this->morning->id)->where('day_of_week', 1)->first();
        $this->actingAs($this->user)->deleteJson("/api/schedules/{$wednesday->id}")->assertOk();
        $this->actingAs($this->user)->putJson("/api/schedules/{$monday->id}", ['name' => 'Morning', 'group_id' => $this->morning->id, 'day_of_week' => 1, 'start_time' => '07:00', 'late_after' => '07:15', 'end_time' => '11:00'])->assertOk();

        $this->assertSame('2026-11-08', $monday->fresh()->effective_to->toDateString(), 'The old version is kept for the days before today.');
        $this->assertSame('2026-11-08', $wednesday->fresh()->effective_to->toDateString(), 'A deleted schedule that was in use is ended, not erased.');
        $listed = collect($this->actingAs($this->user)->getJson('/api/schedules?per_page=100')->json('data'));
        $this->assertSame([1, 2, 4, 5], $listed->where('group_id', $this->morning->id)->pluck('day_of_week')->sort()->values()->all());
        $this->assertSame('07:00', substr($listed->firstWhere('day_of_week', 1)['start_time'], 0, 5));

        $this->now('2026-11-20 20:00');
        $days = $this->monthly($this->morning)['2026-11'];
        $this->assertSame(['Present', 'Absent', '-', 'Absent'], [$days['2026-11-02'], $days['2026-11-04'], $days['2026-11-11'] ?? '-', $days['2026-11-09']]);
        $history = collect($this->actingAs($this->user)->getJson("/api/people/{$lea->id}/attendance?from=2026-11-02&to=2026-11-09")->json('days'))->keyBy('date');
        $this->assertSame(['7:30 AM – 12:00 PM', '7:00 AM – 11:00 AM'], [$history['2026-11-02']['shift'], $history['2026-11-09']['shift']]);

        // Editing a version that only started today just updates it.
        $this->now('2026-11-09 07:00');
        $current = Schedule::where('group_id', $this->morning->id)->where('day_of_week', 1)->whereNull('effective_to')->first();
        $this->actingAs($this->user)->putJson("/api/schedules/{$current->id}", ['name' => 'Morning', 'group_id' => $this->morning->id, 'day_of_week' => 1, 'start_time' => '07:10', 'late_after' => '07:20', 'end_time' => '11:00'])->assertOk();
        $this->assertSame(2, Schedule::where('group_id', $this->morning->id)->where('day_of_week', 1)->count());
    }

    public function test_archived_people_stay_in_past_reports_for_the_days_they_were_active(): void
    {
        $this->now('2026-11-01 08:00');
        $max = $this->person('M1', 'Max', $this->morning);
        $this->scanEvent($max, '2026-11-02 07:35');

        $this->now('2026-11-05 15:00');
        $this->actingAs($this->user)->postJson("/api/people/{$max->id}/archive")->assertOk();
        $this->assertNotNull($max->fresh()->archived_at);
        $this->now('2026-11-20 20:00');

        $this->assertSame('PRESENT', $this->actingAs($this->user)->getJson('/api/attendance/report?date=2026-11-02')->json('people.0.status'));
        $this->assertSame('ABSENT', $this->actingAs($this->user)->getJson('/api/attendance/report?date=2026-11-04')->json('people.0.status'));
        $this->assertSame([], $this->actingAs($this->user)->getJson('/api/attendance/report?date=2026-11-05')->json('people'), 'Not expected from the day they were archived.');

        $days = $this->monthly($this->morning)['2026-11'];
        $this->assertSame(['Present', 'Absent', '-', '-'], [$days['2026-11-02'], $days['2026-11-04'], $days['2026-11-05'], $days['2026-11-16']]);
        $history = collect($this->actingAs($this->user)->getJson("/api/people/{$max->id}/attendance?from=2026-11-01&to=2026-11-20")->json('days'));
        $this->assertSame('2026-11-04', $history->first()['date'], 'History stops at the archive date instead of piling up absences.');
        $this->assertSame([], $this->actingAs($this->user)->getJson('/api/attendance/needs-attention')->json('people'));

        // Reactivating counts them again from then on.
        $this->now('2026-11-23 06:00');
        $this->actingAs($this->user)->putJson("/api/people/{$max->id}", ['person_number' => 'M1', 'first_name' => 'Max', 'last_name' => 'Test', 'group_id' => $this->morning->id, 'is_active' => true])->assertOk();
        $this->assertNull($max->fresh()->archived_at);
    }

    public function test_a_group_with_only_ended_schedule_versions_can_be_deleted(): void
    {
        $this->now('2026-11-01 08:00');
        $empty = $this->groupWithWeekdays('Old Class', '08:00', '08:15', '10:00');
        $this->now('2026-11-09 08:00');
        foreach (Schedule::where('group_id', $empty->id)->get() as $schedule) {
            $this->actingAs($this->user)->deleteJson("/api/schedules/{$schedule->id}")->assertOk();
        }
        $this->actingAs($this->user)->deleteJson("/api/groups/{$empty->id}")->assertOk();
        $this->assertSame(0, Schedule::where('group_id', $empty->id)->count());
    }

    public function test_existing_people_get_their_past_moves_back_from_the_group_saved_on_each_scan(): void
    {
        $this->now('2026-11-20 08:00');
        $nia = $this->person('N1', 'Nia', $this->morning);
        $this->scanEvent($nia, '2026-11-02 07:35');
        $this->scanEvent($nia, '2026-11-03 07:35');
        // Moved before this release: the scans remember Morning, the person is now in Afternoon.
        Person::withoutEvents(fn () => $nia->update(['group_id' => $this->afternoon->id]));
        $this->scanEvent($nia, '2026-11-12 13:05');
        $steady = $this->person('S1', 'Sam', $this->morning);
        PersonAssignment::query()->delete();

        $migration = require database_path('migrations/2026_11_11_070522_add_attendance_history_tables.php');
        (fn () => $this->backfillAssignments())->call($migration);

        $rows = PersonAssignment::where('person_id', $nia->id)->orderBy('id')->get()->map(fn ($row) => [$row->group_id, $row->starts_on?->toDateString(), $row->ends_on?->toDateString()])->all();
        $this->assertSame([[$this->morning->id, null, '2026-11-11'], [$this->afternoon->id, '2026-11-12', null]], $rows);
        $this->assertSame([[$this->morning->id, null, null]], PersonAssignment::where('person_id', $steady->id)->get()->map(fn ($row) => [$row->group_id, $row->starts_on, $row->ends_on])->all());
    }

    private function groupWithWeekdays(string $name, string $start, string $late, string $end): Group
    {
        $group = Group::create(['organization_id' => $this->organization->id, 'name' => $name]);
        foreach (range(1, 5) as $day) {
            Schedule::create(['organization_id' => $this->organization->id, 'group_id' => $group->id, 'name' => $name, 'day_of_week' => $day, 'start_time' => $start, 'late_after' => $late, 'end_time' => $end]);
        }

        return $group;
    }

    private function person(string $number, string $first, Group $group): Person
    {
        $person = Person::create(['organization_id' => $this->organization->id, 'person_number' => $number, 'first_name' => $first, 'last_name' => 'Test', 'group_id' => $group->id]);
        $person->forceFill(['created_at' => '2026-10-01 00:00:00'])->save();

        return $person->fresh();
    }

    private function scanEvent(Person $person, string $localTime, string $type = 'check_in'): void
    {
        $at = Carbon::parse($localTime, 'Asia/Manila');
        AttendanceEvent::create(['organization_id' => $this->organization->id, 'person_id' => $person->id, 'event_type' => $type, 'occurred_at' => $at->copy()->utc(), 'meta' => ['group_id' => $person->fresh()->group_id, 'attendance_date' => $at->toDateString()]]);
    }

    private function now(string $localTime): void
    {
        Carbon::setTestNow(Carbon::parse($localTime, 'Asia/Manila'));
    }

    /** @return array<string, array<string, string>> */
    private function monthly(Group $group): array
    {
        $attendance = $this->actingAs($this->user)->getJson("/api/attendance/monthly?month=2026-11&group_id={$group->id}")->assertOk()->json('persons.0.attendance') ?? [];

        // Statuses without the missing-scan note ("Present (No time out)" -> "Present").
        return ['2026-11' => array_map(fn (string $status): string => explode(' (', $status)[0], $attendance)];
    }
}

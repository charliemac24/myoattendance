<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Holiday;
use App\Models\Organization;
use App\Models\Person;
use App\Models\Schedule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Holidays: nobody is absent or late, reports and the timesheet say "Holiday", employees who work get holiday hours. */
class HolidaysTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organization $organization;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_owners_and_admins_manage_the_list_and_staff_cannot(): void
    {
        Http::fake();
        $this->tenant('school');
        $group = Group::create(['organization_id' => $this->organization->id, 'name' => 'Grade 1']);
        $this->now('2026-11-02 08:00');

        $id = $this->actingAs($this->user)->postJson('/api/holidays', ['name' => ' Christmas Day ', 'starts_on' => '2026-12-25'])->assertCreated()
            ->assertJsonPath('holiday.name', 'Christmas Day')->assertJsonPath('holiday.ends_on', '2026-12-25')->json('holiday.id');
        $this->actingAs($this->user)->postJson('/api/holidays', ['name' => 'Typhoon closure', 'starts_on' => '2026-11-10', 'ends_on' => '2026-11-12', 'group_id' => $group->id])->assertCreated()->assertJsonPath('holiday.group', 'Grade 1');
        $this->actingAs($this->user)->postJson('/api/holidays', ['name' => 'Christmas Day', 'starts_on' => '2026-12-25'])->assertUnprocessable()->assertJsonPath('message', 'That holiday is already on the list.');
        $this->actingAs($this->user)->postJson('/api/holidays', ['name' => 'Bad range', 'starts_on' => '2026-11-10', 'ends_on' => '2026-11-09'])->assertUnprocessable();
        $this->actingAs($this->user)->postJson('/api/holidays', ['name' => 'Too long', 'starts_on' => '2026-01-01', 'ends_on' => '2026-06-01'])->assertUnprocessable();
        $this->actingAs($this->user)->postJson('/api/holidays', ['name' => '', 'starts_on' => '2026-11-10'])->assertUnprocessable();
        $this->assertSame(['2026-11-10', '2026-12-25'], $this->actingAs($this->user)->getJson('/api/holidays')->assertOk()->json('holidays.*.starts_on'));
        $this->actingAs($this->user)->get('/settings/holidays')->assertOk()->assertSee('Add common holidays');

        $admin = User::create(['organization_id' => $this->organization->id, 'name' => 'Admin', 'username' => 'adm', 'password' => 'password', 'email_verified_at' => now(), 'role' => 'admin']);
        $staff = User::create(['organization_id' => $this->organization->id, 'name' => 'Guard', 'username' => 'guard', 'password' => 'password', 'email_verified_at' => now(), 'role' => 'staff']);
        $this->actingAs($admin)->postJson('/api/holidays', ['name' => 'Founders Day', 'starts_on' => '2026-11-20'])->assertCreated();
        $this->actingAs($staff)->getJson('/api/holidays')->assertForbidden();
        $this->actingAs($staff)->get('/settings/holidays')->assertRedirect('/scanner');

        [$otherUser] = $this->otherTenant();
        $this->actingAs($otherUser)->deleteJson("/api/holidays/{$id}")->assertNotFound();
        $this->actingAs($otherUser)->postJson('/api/holidays', ['name' => 'X', 'starts_on' => '2026-11-10', 'group_id' => $group->id])->assertNotFound();
        $this->assertSame([], $this->actingAs($otherUser)->getJson('/api/holidays')->json('holidays'));
        $this->actingAs($this->user)->deleteJson("/api/holidays/{$id}")->assertOk();
        $this->assertDatabaseMissing('holidays', ['id' => $id]);
        Http::assertNothingSent();
    }

    public function test_common_philippine_holidays_are_added_once_with_the_right_dates(): void
    {
        Http::fake();
        $this->tenant('school');

        $this->actingAs($this->user)->postJson('/api/holidays/common', ['year' => 2026])->assertOk()->assertJsonPath('added', 11);
        $this->actingAs($this->user)->postJson('/api/holidays/common', ['year' => 2026])->assertOk()->assertJsonPath('added', 0)->assertJsonPath('message', 'Those holidays are already on your list.');
        $dates = Holiday::where('organization_id', $this->organization->id)->pluck('starts_on', 'name')->map->toDateString();
        $this->assertSame('2026-08-31', $dates['National Heroes Day'], 'Last Monday of August 2026.');
        $this->assertSame(['2026-12-25', '2026-06-12', '2026-05-01'], [$dates['Christmas Day'], $dates['Independence Day'], $dates['Labor Day']]);
        $this->actingAs($this->user)->postJson('/api/holidays/common', ['year' => 2027])->assertOk()->assertJsonPath('added', 11);
        $this->assertSame('2027-08-30', Holiday::where('name', 'National Heroes Day')->where('starts_on', '2027-08-30')->first()?->starts_on->toDateString());
        $this->actingAs($this->user)->postJson('/api/holidays/common', ['year' => 1999])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_a_school_holiday_in_the_middle_of_a_range_is_not_absent_late_or_a_streak_breaker(): void
    {
        Http::fake();
        $this->tenant('school');
        $group = $this->weekdayGroup('Grade 1', '08:00', '08:15', '15:00');
        $kim = $this->person($group, 'K1', 'Kim');
        $this->actingAs($this->user)->postJson('/api/holidays', ['name' => 'Founders Day', 'starts_on' => '2026-11-04'])->assertCreated();
        $this->scanOn($kim, '2026-11-02 08:00');
        $this->now('2026-11-09 20:00');

        $daily = $this->actingAs($this->user)->getJson('/api/attendance/report?date=2026-11-04')->assertOk()->json();
        $this->assertSame(['Founders Day', 0, 0], [$daily['holiday'], $daily['summary']['expected_total'], $daily['summary']['absent']]);
        $this->assertSame([], $daily['people']);
        $this->assertNull($this->actingAs($this->user)->getJson('/api/attendance/report?date=2026-11-03')->json('holiday'));

        $month = $this->actingAs($this->user)->getJson("/api/attendance/monthly?month=2026-11&group_id={$group->id}")->json('persons.0.attendance');
        $this->assertSame(['Present (No time out)', 'Absent', 'Holiday', 'Absent'], [$month['2026-11-02'], $month['2026-11-03'], $month['2026-11-04'], $month['2026-11-05']]);

        $history = $this->actingAs($this->user)->getJson("/api/people/{$kim->id}/attendance?from=2026-11-02&to=2026-11-06")->json();
        $byDate = collect($history['days'])->keyBy('date');
        $this->assertSame(['holiday', 'Founders Day', false], [$byDate['2026-11-04']['status'], $byDate['2026-11-04']['holiday'], $byDate['2026-11-04']['scheduled']]);
        $this->assertSame([4, 1, 3], [$history['summary']['scheduled_days'], $history['summary']['attended'], $history['summary']['absent']], 'The holiday is not a scheduled day, so it is out of the rate.');
        $csv = $this->actingAs($this->user)->get("/api/people/{$kim->id}/attendance/export?from=2026-11-02&to=2026-11-06")->streamedContent();
        $this->assertStringContainsString('Holiday,,,,"Holiday: Founders Day"', $csv);

        // Absent Nov 3, holiday Nov 4, absent Nov 5, 6 and 9: the holiday does not break the streak (4, not 2).
        $flagged = $this->actingAs($this->user)->getJson('/api/attendance/needs-attention')->json('people.0');
        // The 30-day rate counts 20 scheduled weekdays (21 minus the holiday): 1 of 20 = 5%, not 1 of 21.
        $this->assertSame(['Not seen in 7 days', '4 absences in a row', '5% attendance'], array_column($flagged['reasons'], 'label'));
        $this->assertSame(20, $flagged['scheduled_days']);
        Http::assertNothingSent();
    }

    public function test_a_group_holiday_only_affects_that_group_and_deleting_it_brings_absences_back(): void
    {
        Http::fake();
        $this->tenant('school');
        $morning = $this->weekdayGroup('Morning', '07:30', '07:45', '12:00');
        $afternoon = $this->weekdayGroup('Afternoon', '13:00', '13:15', '17:00');
        $mia = $this->person($morning, 'M1', 'Mia');
        $ned = $this->person($afternoon, 'A1', 'Ned');
        $holidayId = $this->actingAs($this->user)->postJson('/api/holidays', ['name' => 'Morning class retreat', 'starts_on' => '2026-11-03', 'ends_on' => '2026-11-04', 'group_id' => $morning->id])->json('holiday.id');
        $this->now('2026-11-09 20:00');

        $daily = $this->actingAs($this->user)->getJson('/api/attendance/report?date=2026-11-03')->json();
        $this->assertNull($daily['holiday'], 'A group holiday is not a banner for the whole organization.');
        $this->assertSame(['A1'], array_column(array_column($daily['people'], 'person'), 'person_number'));
        $this->assertSame('Holiday', $this->monthlyOf($morning)['2026-11-04']);
        $this->assertSame('Absent', $this->monthlyOf($afternoon)['2026-11-04']);

        $this->actingAs($this->user)->deleteJson("/api/holidays/{$holidayId}")->assertOk();
        $this->assertSame('Absent', $this->monthlyOf($morning)['2026-11-04']);
        $this->assertCount(2, $this->actingAs($this->user)->getJson('/api/attendance/report?date=2026-11-03')->json('people'));

        // A group that people were in keeps its history and cannot be deleted; an unused one can, with its holidays.
        $ned->update(['group_id' => null, 'subgroup_id' => null]);
        Schedule::where('group_id', $afternoon->id)->delete();
        $this->actingAs($this->user)->deleteJson("/api/groups/{$afternoon->id}")->assertStatus(409);
        $unused = Group::create(['organization_id' => $this->organization->id, 'name' => 'Evening']);
        $this->actingAs($this->user)->postJson('/api/holidays', ['name' => 'Gone with group', 'starts_on' => '2026-11-05', 'group_id' => $unused->id])->assertCreated();
        $this->actingAs($this->user)->deleteJson("/api/groups/{$unused->id}")->assertOk();
        $this->assertSame(0, Holiday::where('name', 'Gone with group')->count(), 'Group holidays go with their group.');
        $this->assertNotNull($mia->fresh());
        Http::assertNothingSent();
    }

    public function test_employees_who_work_a_holiday_get_holiday_hours_and_are_never_late(): void
    {
        Http::fake();
        $this->tenant('warehouse');
        $group = $this->weekdayGroup('Day Crew', '08:00', '08:15', '17:00', 60);
        $ana = $this->person($group, 'E1', 'Ana');
        $ben = $this->person($group, 'E2', 'Ben');
        $this->actingAs($this->user)->postJson('/api/holidays', ['name' => 'Founders Day', 'starts_on' => '2026-11-04'])->assertCreated();

        $this->scanOn($ana, '2026-11-04 09:30')->assertJsonPath('code', 'CHECKED_IN')->assertJsonPath('data.holiday', 'Founders Day')->assertJsonPath('data.attendance_status', 'PRESENT')->assertJsonPath('message', 'Checked in on Founders Day. Counted as holiday hours.');
        $this->scanOn($ana, '2026-11-04 15:30')->assertJsonPath('code', 'CHECKED_OUT');
        $this->now('2026-11-05 09:00');

        $ts = $this->actingAs($this->user)->getJson('/api/attendance/timesheet?from=2026-11-04&to=2026-11-04')->assertOk()->json('people');
        $rows = collect($ts)->keyBy('person.person_number');
        $anaDay = $rows['E1']['days'][0];
        $this->assertSame(['Holiday', 'Founders Day', 360, 0, 0, 0], [$anaDay['status'], $anaDay['holiday'], $anaDay['rest_day_minutes'], $anaDay['regular_minutes'], $anaDay['late_minutes'], $anaDay['undertime_minutes']], '9:30 AM to 3:30 PM = 6h of holiday hours, not late for a 8:15 grace.');
        $benDay = $rows['E2']['days'][0];
        $this->assertSame(['Holiday', 0, 0], [$benDay['status'], $benDay['rest_day_minutes'], $benDay['undertime_minutes']]);
        $this->assertSame(['absences' => 0, 'days_worked' => 1], ['absences' => $rows['E2']['totals']['absences'] + $rows['E1']['totals']['absences'], 'days_worked' => $rows['E1']['totals']['days_worked']]);
        $csv = $this->actingAs($this->user)->get('/api/attendance/timesheet/export?from=2026-11-04&to=2026-11-04')->streamedContent();
        $this->assertStringContainsString('E1,"Ana Test",2026-11-04,Wednesday,,"9:30 AM","3:30 PM",Holiday,0.00,0,0,0.00,6.00,0,"Holiday: Founders Day"', $csv);

        $daily = $this->actingAs($this->user)->getJson('/api/attendance/report?date=2026-11-04')->json();
        $this->assertSame(['E1'], array_column(array_column($daily['people'], 'person'), 'person_number'), 'Only the person who scanned is listed.');
        $this->assertSame('PRESENT', $daily['people'][0]['status']);
        $this->assertSame('Present', explode(' (', $this->monthlyOf($group)['2026-11-04'])[0], 'A holiday scan is never Late in the monthly grid.');
        Http::assertNothingSent();
    }

    public function test_a_holiday_on_the_date_a_night_shift_starts_counts_that_whole_shift_as_holiday_hours(): void
    {
        Http::fake();
        $this->tenant('warehouse');
        $group = $this->weekdayGroup('Night Crew', '22:00', '22:15', '06:00', 60);
        $cara = $this->person($group, 'N1', 'Cara');
        $this->actingAs($this->user)->postJson('/api/holidays', ['name' => 'Founders Day', 'starts_on' => '2026-11-03'])->assertCreated();

        // Mon night is a normal shift; its 6:10 AM time out falls on the holiday date but belongs to Monday.
        $this->scanOn($cara, '2026-11-02 21:55')->assertJsonPath('code', 'CHECKED_IN')->assertJsonPath('data.attendance_status', 'PRESENT');
        $this->scanOn($cara, '2026-11-03 06:10')->assertJsonPath('code', 'CHECKED_OUT')->assertJsonMissingPath('data.holiday');
        // Tue night starts on the holiday: holiday hours.
        $this->scanOn($cara, '2026-11-03 21:55')->assertJsonPath('code', 'CHECKED_IN')->assertJsonPath('data.holiday', 'Founders Day');
        $this->scanOn($cara, '2026-11-04 06:10')->assertJsonPath('code', 'CHECKED_OUT')->assertJsonPath('data.holiday', 'Founders Day');
        // Wed night is normal again.
        $this->scanOn($cara, '2026-11-04 21:58')->assertJsonPath('code', 'CHECKED_IN')->assertJsonMissingPath('data.holiday');
        $this->scanOn($cara, '2026-11-05 06:03')->assertJsonPath('code', 'CHECKED_OUT');
        $this->now('2026-11-06 09:00');

        $days = collect($this->actingAs($this->user)->getJson("/api/attendance/timesheet?from=2026-11-02&to=2026-11-04&person_id={$cara->id}")->json('people.0.days'))->keyBy('date');
        $this->assertSame(['Present', 420, 0], [$days['2026-11-02']['status'], $days['2026-11-02']['regular_minutes'], $days['2026-11-02']['rest_day_minutes']]);
        $this->assertSame(['Holiday', 0, 495, 'Founders Day'], [$days['2026-11-03']['status'], $days['2026-11-03']['regular_minutes'], $days['2026-11-03']['rest_day_minutes'], $days['2026-11-03']['holiday']], '9:55 PM to 6:10 AM = 8h 15m of holiday hours.');
        $this->assertSame(['Present', 420], [$days['2026-11-04']['status'], $days['2026-11-04']['regular_minutes']]);
        Http::assertNothingSent();
    }

    public function test_schools_keep_recording_scans_on_a_holiday_as_before(): void
    {
        Http::fake();
        $this->tenant('school');
        $group = $this->weekdayGroup('Grade 1', '08:00', '08:15', '15:00');
        $kim = $this->person($group, 'K1', 'Kim');
        $this->actingAs($this->user)->postJson('/api/holidays', ['name' => 'Founders Day', 'starts_on' => '2026-11-04'])->assertCreated();

        $this->scanOn($kim, '2026-11-04 08:30')->assertJsonPath('code', 'CHECKED_IN')->assertJsonPath('data.attendance_status', 'LATE')->assertJsonMissingPath('data.holiday');
        $daily = $this->actingAs($this->user)->getJson('/api/attendance/report?date=2026-11-04')->json();
        $this->assertSame(['Founders Day', 'LATE'], [$daily['holiday'], $daily['people'][0]['status']], 'A school scan is still shown as it was scanned.');
        Http::assertNothingSent();
    }

    public function test_a_holiday_does_not_hide_days_before_a_person_was_added_or_after_archiving_and_skips_open_hours_groups(): void
    {
        Http::fake();
        $this->tenant('school');
        $group = $this->weekdayGroup('Grade 1', '08:00', '08:15', '15:00');
        $gym = Group::create(['organization_id' => $this->organization->id, 'name' => 'Open Gym', 'attendance_mode' => Group::MODE_OPEN]);
        $this->now('2026-11-03 08:00');
        $late = $this->person($group, 'L1', 'Late Joiner', '2026-11-05 00:00:00');
        $member = $this->person($gym, 'G1', 'Gina', '2026-10-01 00:00:00');
        $this->actingAs($this->user)->postJson('/api/holidays', ['name' => 'Founders Day', 'starts_on' => '2026-11-02', 'ends_on' => '2026-11-03'])->assertCreated();
        $this->scanOn($member, '2026-11-03 07:00')->assertJsonPath('code', 'CHECKED_IN');
        $this->now('2026-11-09 20:00');

        $this->assertSame('-', $this->monthlyOf($group)['2026-11-03'], 'Not yet added: neither Absent nor Holiday.');
        $gymMonth = $this->actingAs($this->user)->getJson("/api/attendance/monthly?month=2026-11&group_id={$gym->id}")->json('persons.0.attendance');
        $this->assertSame(['Visited', '-'], [$gymMonth['2026-11-03'], $gymMonth['2026-11-02']], 'Open hours keep counting visits on a holiday.');
        $this->assertNotNull($late);
        Http::assertNothingSent();
    }

    private function tenant(string $type): void
    {
        $this->organization = Organization::create(['name' => 'Test Org', 'type' => $type, 'timezone' => 'Asia/Manila']);
        $this->user = User::create(['organization_id' => $this->organization->id, 'name' => 'Owner', 'username' => 'owner', 'email' => 'owner@test.test', 'password' => 'password', 'email_verified_at' => now(), 'role' => 'owner']);
    }

    /** @return array{0: User, 1: Organization} */
    private function otherTenant(): array
    {
        $organization = Organization::create(['name' => 'Other Org', 'type' => 'school', 'timezone' => 'Asia/Manila']);

        return [User::create(['organization_id' => $organization->id, 'name' => 'Other', 'username' => 'other', 'email' => 'other@test.test', 'password' => 'password', 'email_verified_at' => now(), 'role' => 'owner']), $organization];
    }

    private function weekdayGroup(string $name, string $start, string $late, string $end, int $break = 0): Group
    {
        $group = Group::create(['organization_id' => $this->organization->id, 'name' => $name]);
        foreach (range(1, 5) as $day) {
            Schedule::create(['organization_id' => $this->organization->id, 'group_id' => $group->id, 'name' => $name, 'day_of_week' => $day, 'start_time' => $start, 'late_after' => $late, 'end_time' => $end, 'break_minutes' => $break]);
        }

        return $group;
    }

    private function person(Group $group, string $number, string $first, string $createdAt = '2026-10-01 00:00:00'): Person
    {
        $person = Person::create(['organization_id' => $this->organization->id, 'person_number' => $number, 'first_name' => $first, 'last_name' => 'Test', 'group_id' => $group->id]);
        $person->forceFill(['created_at' => $createdAt])->save();

        return $person->fresh();
    }

    private function scanOn(Person $person, string $localTime)
    {
        $this->now($localTime);

        return $this->actingAs($this->user)->postJson('/api/attendance/scan', ['qr_payload' => $person->qr_token])->assertOk();
    }

    private function now(string $localTime): void
    {
        Carbon::setTestNow(Carbon::parse($localTime, 'Asia/Manila'));
    }

    /** @return array<string, string> */
    private function monthlyOf(Group $group): array
    {
        return $this->actingAs($this->user)->getJson("/api/attendance/monthly?month=2026-11&group_id={$group->id}")->json('persons.0.attendance');
    }
}

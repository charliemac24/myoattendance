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
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MissingScanFlagsTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-11-09';

    private const TUESDAY = '2026-11-10';

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_first_scan_near_end_time_is_saved_as_time_out_with_no_time_in(): void
    {
        [$user, , $group, $person] = $this->tenant();
        $this->schedule($group, '16:00');

        $this->scan($user, $person, self::MONDAY.' 15:45:00')
            ->assertJsonPath('code', 'CHECKED_OUT')
            ->assertJsonPath('data.direction', 'OUT')
            ->assertJsonPath('data.attendance_status', 'LATE')
            ->assertJsonPath('data.no_time_in', true)
            ->assertJsonPath('data.event_type', 'check_out');
        $this->assertTrue(AttendanceEvent::firstOrFail()->meta['no_time_in']);

        $this->scan($user, $person, self::MONDAY.' 16:05:00')->assertJsonPath('code', 'ATTENDANCE_COMPLETE');
        $this->assertSame(1, AttendanceEvent::count());

        $report = $this->report($user, self::MONDAY, self::MONDAY.' 17:00:00');
        $report->assertJsonPath('summary.present', 1)->assertJsonPath('summary.late', 1)->assertJsonPath('summary.absent', 0)
            ->assertJsonPath('summary.no_time_in', 1)->assertJsonPath('summary.no_time_out', 0)
            ->assertJsonPath('people.0.status', 'LATE')
            ->assertJsonPath('people.0.no_time_in', true)
            ->assertJsonPath('people.0.no_time_out', false)
            ->assertJsonPath('people.0.check_in_time', null)
            ->assertJsonPath('people.0.check_out_time', '3:45 PM');
    }

    public function test_exit_window_starts_thirty_minutes_before_end_time(): void
    {
        [$user, , $group, $person] = $this->tenant();
        $this->schedule($group, '16:00');

        $this->scan($user, $person, self::MONDAY.' 15:29:59')
            ->assertJsonPath('code', 'CHECKED_IN')
            ->assertJsonPath('data.attendance_status', 'LATE')
            ->assertJsonPath('data.no_time_in', false);
    }

    public function test_schedule_without_end_time_keeps_first_scan_as_time_in(): void
    {
        [$user, , $group, $person] = $this->tenant();
        $this->schedule($group, null);

        $this->scan($user, $person, self::MONDAY.' 17:00:00')->assertJsonPath('code', 'CHECKED_IN')->assertJsonPath('data.attendance_status', 'LATE');
    }

    public function test_no_time_out_appears_once_end_time_passes_and_next_day_starts_fresh(): void
    {
        [$user, , $group, $person] = $this->tenant();
        $this->schedule($group, '16:00');
        $this->scan($user, $person, self::MONDAY.' 07:55:00')->assertJsonPath('code', 'CHECKED_IN');

        $this->report($user, self::MONDAY, self::MONDAY.' 15:59:00')->assertJsonPath('people.0.no_time_out', false)->assertJsonPath('people.0.status', 'PRESENT');
        $this->report($user, self::MONDAY, self::MONDAY.' 16:00:00')->assertJsonPath('people.0.no_time_out', true)->assertJsonPath('summary.no_time_out', 1)->assertJsonPath('people.0.status', 'PRESENT');

        $this->scan($user, $person, self::TUESDAY.' 07:50:00')->assertJsonPath('code', 'CHECKED_IN')->assertJsonPath('data.attendance_status', 'PRESENT');
        $this->report($user, self::MONDAY, self::TUESDAY.' 08:00:00')->assertJsonPath('people.0.no_time_out', true)->assertJsonPath('people.0.check_in_time', '7:55 AM');
        $this->report($user, self::TUESDAY, self::TUESDAY.' 08:00:00')->assertJsonPath('people.0.no_time_out', false);
    }

    public function test_no_time_out_without_end_time_appears_the_next_day(): void
    {
        [$user, , $group, $person] = $this->tenant();
        $this->schedule($group, null);
        $this->scan($user, $person, self::MONDAY.' 07:55:00');

        $this->report($user, self::MONDAY, self::MONDAY.' 23:59:00')->assertJsonPath('people.0.no_time_out', false);
        $this->report($user, self::MONDAY, self::TUESDAY.' 00:01:00')->assertJsonPath('people.0.no_time_out', true);
    }

    public function test_checked_out_person_has_no_flags(): void
    {
        [$user, , $group, $person] = $this->tenant();
        $this->schedule($group, '16:00');
        $this->scan($user, $person, self::MONDAY.' 07:55:00');
        $this->scan($user, $person, self::MONDAY.' 16:02:00')->assertJsonPath('code', 'CHECKED_OUT')->assertJsonPath('data.no_time_in', false);

        $this->report($user, self::MONDAY, self::MONDAY.' 17:00:00')
            ->assertJsonPath('people.0.no_time_in', false)->assertJsonPath('people.0.no_time_out', false)
            ->assertJsonPath('people.0.check_in_time', '7:55 AM')->assertJsonPath('people.0.check_out_time', '4:02 PM');
    }

    public function test_flag_filter_lists_only_matching_people(): void
    {
        [$user, $organization, $group, $exitOnly] = $this->tenant();
        $this->schedule($group, '16:00');
        $forgotOut = Person::create(['organization_id' => $organization->id, 'person_number' => 'P-002', 'first_name' => 'Forgot', 'last_name' => 'Out', 'group_id' => $group->id]);
        Person::create(['organization_id' => $organization->id, 'person_number' => 'P-003', 'first_name' => 'Never', 'last_name' => 'Came', 'group_id' => $group->id]);
        $this->scan($user, $forgotOut, self::MONDAY.' 07:58:00');
        $this->scan($user, $exitOnly, self::MONDAY.' 15:50:00');

        Carbon::setTestNow(Carbon::parse(self::MONDAY.' 17:00:00', 'Asia/Manila'));
        $this->actingAs($user)->getJson('/api/attendance/report?date='.self::MONDAY.'&flag=NO_TIME_IN')
            ->assertOk()->assertJsonCount(1, 'people')->assertJsonPath('people.0.person.first_name', 'Exit');
        $this->actingAs($user)->getJson('/api/attendance/report?date='.self::MONDAY.'&flag=NO_TIME_OUT')
            ->assertOk()->assertJsonCount(1, 'people')->assertJsonPath('people.0.person.first_name', 'Forgot');
        $this->actingAs($user)->getJson('/api/attendance/report?date='.self::MONDAY.'&flag=NOPE')->assertUnprocessable();
    }

    public function test_multi_period_exit_scan_only_fills_that_period(): void
    {
        [$user, , $group, $person] = $this->tenant();
        Schedule::create(['organization_id' => $group->organization_id, 'group_id' => $group->id, 'name' => 'Split', 'day_of_week' => 1, 'start_time' => '07:00', 'late_after' => '07:15', 'end_time' => '12:00', 'periods' => [
            ['start_time' => '07:00', 'late_after' => '07:15', 'end_time' => '12:00'],
            ['start_time' => '13:00', 'late_after' => '13:15', 'end_time' => '17:00'],
        ]]);

        $this->scan($user, $person, self::MONDAY.' 11:45:00')->assertJsonPath('code', 'CHECKED_OUT')->assertJsonPath('data.no_time_in', true);
        $this->scan($user, $person, self::MONDAY.' 13:05:00')->assertJsonPath('code', 'CHECKED_IN')->assertJsonPath('data.attendance_status', 'PRESENT');
        $this->scan($user, $person, self::MONDAY.' 17:01:00')->assertJsonPath('code', 'CHECKED_OUT')->assertJsonPath('data.no_time_in', false);
        $this->scan($user, $person, self::MONDAY.' 17:10:00')->assertJsonPath('code', 'ATTENDANCE_COMPLETE');
    }

    public function test_monthly_report_marks_missing_scans(): void
    {
        [$user, $organization, $group, $exitOnly] = $this->tenant();
        $this->schedule($group, '16:00');
        $forgotOut = Person::create(['organization_id' => $organization->id, 'person_number' => 'P-002', 'first_name' => 'Forgot', 'last_name' => 'Out', 'group_id' => $group->id]);
        $this->scan($user, $forgotOut, self::MONDAY.' 07:58:00');
        $this->scan($user, $exitOnly, self::MONDAY.' 15:50:00');

        Carbon::setTestNow(Carbon::parse(self::TUESDAY.' 09:00:00', 'Asia/Manila'));
        $persons = collect($this->actingAs($user)->getJson("/api/attendance/monthly?month=2026-11&group_id={$group->id}")->assertOk()->json('persons'))->keyBy('person_number');

        $this->assertSame('Late (No time in)', $persons['P-001']['attendance'][self::MONDAY]);
        $this->assertSame('Present (No time out)', $persons['P-002']['attendance'][self::MONDAY]);
    }

    private function tenant(): array
    {
        $organization = Organization::create(['name' => 'Missing Scan Org', 'type' => 'school', 'timezone' => 'Asia/Manila']);
        $user = User::create(['organization_id' => $organization->id, 'name' => 'Admin', 'email' => 'missing-scan@example.test', 'password' => 'password', 'email_verified_at' => now(), 'role' => 'owner']);
        $group = Group::create(['organization_id' => $organization->id, 'name' => 'Grade 1']);
        $person = Person::create(['organization_id' => $organization->id, 'person_number' => 'P-001', 'first_name' => 'Exit', 'last_name' => 'Only', 'group_id' => $group->id]);

        return [$user, $organization, $group, $person];
    }

    private function schedule(Group $group, ?string $endTime): void
    {
        foreach ([1, 2] as $day) {
            Schedule::create(['organization_id' => $group->organization_id, 'group_id' => $group->id, 'name' => 'Daily', 'day_of_week' => $day, 'start_time' => '08:00', 'late_after' => '08:15', 'end_time' => $endTime]);
        }
    }

    private function scan(User $user, Person $person, string $localTime)
    {
        Carbon::setTestNow(Carbon::parse($localTime, 'Asia/Manila'));

        return $this->actingAs($user)->postJson('/api/attendance/scan', ['qr_payload' => $person->qr_token])->assertOk();
    }

    private function report(User $user, string $date, string $localNow)
    {
        Carbon::setTestNow(Carbon::parse($localNow, 'Asia/Manila'));

        return $this->actingAs($user)->getJson('/api/attendance/report?date='.$date)->assertOk();
    }
}

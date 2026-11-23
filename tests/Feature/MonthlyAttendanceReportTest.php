<?php

namespace Tests\Feature;

use App\Models\AttendanceEvent;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Person;
use App\Models\Schedule;
use App\Models\Subgroup;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonthlyAttendanceReportTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_monthly_matrix_excludes_weekends_preserves_expected_statuses_and_neutral_future_days(): void
    {
        [$user, $organization, $group, $subgroup] = $this->tenant();
        $present = $this->person($organization, $group, $subgroup, '000001', 'Alice', 'Cruz');
        $late = $this->person($organization, $group, $subgroup, '000002', 'Ben', 'Reyes');
        $absent = $this->person($organization, $group, $subgroup, '000003', 'Carla', 'Santos');
        Schedule::create(['organization_id' => $organization->id, 'group_id' => $group->id, 'subgroup_id' => $subgroup->id, 'name' => 'Monday', 'day_of_week' => 1, 'start_time' => '07:30', 'late_after' => '08:00']);
        AttendanceEvent::create(['organization_id' => $organization->id, 'person_id' => $present->id, 'event_type' => 'check_in', 'occurred_at' => '2026-09-14 00:00:00']);
        AttendanceEvent::create(['organization_id' => $organization->id, 'person_id' => $late->id, 'event_type' => 'late_check_in', 'occurred_at' => '2026-09-14 01:00:00']);
        AttendanceEvent::create(['organization_id' => $organization->id, 'person_id' => $present->id, 'event_type' => 'check_out', 'occurred_at' => '2026-09-14 08:00:00']);
        AttendanceEvent::create(['organization_id' => $organization->id, 'person_id' => $late->id, 'event_type' => 'check_out', 'occurred_at' => '2026-09-14 08:00:00']);
        Carbon::setTestNow(Carbon::parse('2026-09-16 02:00:00', 'UTC'));

        $response = $this->actingAs($user)->getJson("/api/attendance/monthly?month=2026-09&group_id={$group->id}&subgroup_id={$subgroup->id}");
        $data = $response->assertOk()->json();

        $this->assertCount(22, $data['dates']);
        $this->assertSame('2026-09-01', $data['dates'][0]['date']);
        $this->assertSame('2026-09-30', $data['dates'][21]['date']);
        $this->assertTrue(collect($data['dates'])->every(fn (array $date): bool => ! in_array($date['day'], ['Saturday', 'Sunday'], true)));
        $rows = collect($data['persons'])->keyBy('person_number');
        $this->assertSame('Present', $rows['000001']['attendance']['2026-09-14']);
        $this->assertSame('Late', $rows['000002']['attendance']['2026-09-14']);
        $this->assertSame('Absent', $rows['000003']['attendance']['2026-09-14']);
        $this->assertSame('-', $rows['000001']['attendance']['2026-09-15']);
        $this->assertSame('-', $rows['000001']['attendance']['2026-09-17']);
    }

    public function test_monthly_report_uses_subgroup_scope_and_rejects_cross_tenant_relationships(): void
    {
        [$user, $organization, $group, $subgroup] = $this->tenant();
        $otherSubgroup = Subgroup::create(['organization_id' => $organization->id, 'group_id' => $group->id, 'name' => 'Section B']);
        $this->person($organization, $group, $subgroup, '001', 'Section', 'A');
        $this->person($organization, $group, $otherSubgroup, '002', 'Section', 'B');
        [$otherUser, $otherOrganization, $otherGroup] = $this->tenant('Other');
        $otherSubgroupForTenant = Subgroup::create(['organization_id' => $otherOrganization->id, 'group_id' => $otherGroup->id, 'name' => 'Other Section']);

        $this->actingAs($user)->getJson("/api/attendance/monthly?month=2026-09&group_id={$group->id}&subgroup_id={$subgroup->id}")
            ->assertOk()->assertJsonCount(1, 'persons')->assertJsonPath('persons.0.person_number', '001');
        $this->actingAs($user)->getJson('/api/attendance/monthly?month=2026-09&group_id='.$otherGroup->id)->assertNotFound();
        $this->actingAs($user)->getJson('/api/attendance/monthly?month=2026-09&group_id='.$group->id.'&subgroup_id='.$otherSubgroupForTenant->id)->assertNotFound();
        $this->actingAs($user)->getJson("/api/attendance/monthly?month=2026-13&group_id={$group->id}")->assertUnprocessable();
    }

    public function test_monthly_csv_uses_same_matrix_and_preserves_person_numbers_and_escaped_names(): void
    {
        [$user, $organization, $group, $subgroup] = $this->tenant();
        $person = $this->person($organization, $group, $subgroup, '000123', 'Juan', 'Dela Cruz, Jr.');
        Schedule::create(['organization_id' => $organization->id, 'group_id' => $group->id, 'subgroup_id' => $subgroup->id, 'name' => 'Monday', 'day_of_week' => 1, 'start_time' => '07:30', 'late_after' => '08:00']);
        AttendanceEvent::create(['organization_id' => $organization->id, 'person_id' => $person->id, 'event_type' => 'check_in', 'occurred_at' => '2026-09-14 00:00:00']);
        AttendanceEvent::create(['organization_id' => $organization->id, 'person_id' => $person->id, 'event_type' => 'check_out', 'occurred_at' => '2026-09-14 08:00:00']);
        Carbon::setTestNow(Carbon::parse('2026-09-16 02:00:00', 'UTC'));

        $response = $this->actingAs($user)->get("/api/attendance/monthly/export?month=2026-09&group_id={$group->id}&subgroup_id={$subgroup->id}");
        $csv = $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->streamedContent();

        $this->assertSame(['ID Number', 'Full Name', 'Sep 1', 'Sep 2', 'Sep 3', 'Sep 4'], array_slice(str_getcsv(strtok($csv, "\n")), 0, 6));
        $this->assertStringContainsString('000123,"Juan Dela Cruz, Jr."', $csv);
        $this->assertStringContainsString(',Present,', str_replace(["\r\n", "\n"], ',', $csv));
        $this->assertStringNotContainsString('Sep 5', $csv);
        $this->assertStringNotContainsString('Sep 6', $csv);
    }

    public function test_monthly_matrix_matches_daily_report_for_same_person_and_date(): void
    {
        [$user, $organization, $group, $subgroup] = $this->tenant();
        $person = $this->person($organization, $group, $subgroup, '001', 'Late', 'Person');
        Schedule::create(['organization_id' => $organization->id, 'group_id' => $group->id, 'subgroup_id' => $subgroup->id, 'name' => 'Monday', 'day_of_week' => 1, 'start_time' => '07:30', 'late_after' => '08:00']);
        AttendanceEvent::create(['organization_id' => $organization->id, 'person_id' => $person->id, 'event_type' => 'late_check_in', 'occurred_at' => '2026-09-14 01:00:00']);
        AttendanceEvent::create(['organization_id' => $organization->id, 'person_id' => $person->id, 'event_type' => 'check_out', 'occurred_at' => '2026-09-14 08:00:00']);
        Carbon::setTestNow(Carbon::parse('2026-09-16 02:00:00', 'UTC'));

        $daily = $this->actingAs($user)->getJson('/api/attendance/report?date=2026-09-14')->assertOk()->json('people.0.status');
        $monthly = $this->actingAs($user)->getJson("/api/attendance/monthly?month=2026-09&group_id={$group->id}&subgroup_id={$subgroup->id}")->assertOk()->json('persons.0.attendance.2026-09-14');

        $this->assertSame('LATE', $daily);
        $this->assertSame('Late', $monthly);
    }

    public function test_monthly_matrix_shows_scheduled_weekends_and_skips_days_before_a_person_was_added(): void
    {
        [$user, $organization, $group, $subgroup] = $this->tenant();
        $longTime = $this->person($organization, $group, $subgroup, '000001', 'Alice', 'Cruz');
        $newcomer = $this->person($organization, $group, $subgroup, '000002', 'Ben', 'Reyes', '2026-09-10 01:00:00');
        Schedule::create(['organization_id' => $organization->id, 'group_id' => $group->id, 'subgroup_id' => $subgroup->id, 'name' => 'Monday', 'day_of_week' => 1, 'start_time' => '07:30', 'late_after' => '08:00']);
        Schedule::create(['organization_id' => $organization->id, 'group_id' => $group->id, 'subgroup_id' => $subgroup->id, 'name' => 'Saturday', 'day_of_week' => 6, 'start_time' => '07:30', 'late_after' => '08:00']);
        Carbon::setTestNow(Carbon::parse('2026-09-30 12:00:00', 'UTC'));

        $data = $this->actingAs($user)->getJson("/api/attendance/monthly?month=2026-09&group_id={$group->id}&subgroup_id={$subgroup->id}")->assertOk()->json();

        $days = collect($data['dates'])->pluck('day', 'date');
        $this->assertSame('Saturday', $days['2026-09-12']);
        $this->assertFalse($days->has('2026-09-13'), 'An unscheduled Sunday stays hidden.');
        $this->assertCount(26, $data['dates']);
        $rows = collect($data['persons'])->keyBy('person_number');
        $this->assertSame('Absent', $rows['000001']['attendance']['2026-09-07']);
        $this->assertSame('Absent', $rows['000001']['attendance']['2026-09-12']);
        $this->assertSame('-', $rows['000002']['attendance']['2026-09-07'], 'Not counted before the person was added.');
        $this->assertSame('Absent', $rows['000002']['attendance']['2026-09-12']);
    }

    public function test_monthly_matrix_does_not_mark_today_absent_until_the_day_is_over(): void
    {
        [$user, $organization, $group, $subgroup] = $this->tenant();
        $this->person($organization, $group, $subgroup, '000001', 'Alice', 'Cruz');
        Schedule::create(['organization_id' => $organization->id, 'group_id' => $group->id, 'subgroup_id' => $subgroup->id, 'name' => 'Monday', 'day_of_week' => 1, 'start_time' => '07:30', 'late_after' => '08:00', 'end_time' => '16:00']);
        $url = "/api/attendance/monthly?month=2026-09&group_id={$group->id}&subgroup_id={$subgroup->id}";

        Carbon::setTestNow(Carbon::parse('2026-09-14 09:00', 'Asia/Manila'));
        $this->assertSame('-', $this->actingAs($user)->getJson($url)->assertOk()->json('persons.0.attendance.2026-09-14'));

        Carbon::setTestNow(Carbon::parse('2026-09-14 16:30', 'Asia/Manila'));
        $this->assertSame('Absent', $this->actingAs($user)->getJson($url)->assertOk()->json('persons.0.attendance.2026-09-14'));
    }

    public function test_daily_report_does_not_expect_people_before_they_were_added(): void
    {
        [$user, $organization, $group, $subgroup] = $this->tenant();
        $this->person($organization, $group, $subgroup, '000001', 'Alice', 'Cruz');
        $this->person($organization, $group, $subgroup, '000002', 'Ben', 'Reyes', '2026-09-16 01:00:00');
        Schedule::create(['organization_id' => $organization->id, 'group_id' => $group->id, 'subgroup_id' => $subgroup->id, 'name' => 'Monday', 'day_of_week' => 1, 'start_time' => '07:30', 'late_after' => '08:00']);
        Carbon::setTestNow(Carbon::parse('2026-09-30 12:00:00', 'UTC'));

        $this->actingAs($user)->getJson('/api/attendance/report?date=2026-09-14')->assertOk()->assertJsonPath('summary.expected_total', 1)->assertJsonPath('people.0.person.person_number', '000001');
        $this->actingAs($user)->getJson('/api/attendance/report?date=2026-09-21')->assertOk()->assertJsonPath('summary.expected_total', 2);
    }

    private function tenant(string $suffix = ''): array
    {
        $organization = Organization::create(['name' => 'Organization '.$suffix, 'type' => 'company', 'timezone' => 'Asia/Manila']);
        $user = User::create(['organization_id' => $organization->id, 'name' => 'Admin '.$suffix, 'email' => 'admin'.strtolower($suffix ?: 'main').'@example.test', 'password' => 'password', 'email_verified_at' => now(), 'role' => 'owner']);
        $group = Group::create(['organization_id' => $organization->id, 'name' => 'Grade 6']);
        $subgroup = Subgroup::create(['organization_id' => $organization->id, 'group_id' => $group->id, 'name' => 'Section A']);

        return [$user, $organization, $group, $subgroup];
    }

    private function person(Organization $organization, Group $group, Subgroup $subgroup, string $number, string $first, string $last, string $addedAt = '2026-08-01 00:00:00'): Person
    {
        $person = Person::create(['organization_id' => $organization->id, 'person_number' => $number, 'first_name' => $first, 'last_name' => $last, 'group_id' => $group->id, 'subgroup_id' => $subgroup->id]);
        $person->forceFill(['created_at' => $addedAt])->save();

        return $person;
    }
}

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

class PhaseFourAttendanceReportTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_actual_scanner_events_flow_into_the_daily_report(): void
    {
        [$user, $organization, $group, $subgroup] = $this->tenantWithMondaySchedule();
        $onTime = $this->person($organization, $group, $subgroup, '000001', 'Juan', 'On Time');
        $late = $this->person($organization, $group, $subgroup, '000002', 'Maria', 'Late');
        $absent = $this->person($organization, $group, $subgroup, 'EMP-003', 'Pedro', 'Absent');

        Carbon::setTestNow(Carbon::parse('2026-09-13 23:50:00', 'UTC'));
        $this->actingAs($user)->postJson('/api/attendance/scan', ['qr_payload' => $onTime->qr_token])->assertJsonPath('code', 'CHECKED_IN')->assertJsonPath('data.attendance_status', 'PRESENT');
        Carbon::setTestNow(Carbon::parse('2026-09-14 00:20:00', 'UTC'));
        $this->actingAs($user)->postJson('/api/attendance/scan', ['qr_payload' => $late->qr_token])->assertJsonPath('code', 'CHECKED_IN')->assertJsonPath('data.attendance_status', 'LATE');

        $this->actingAs($user)->getJson('/api/attendance/report?date=2026-09-14')->assertOk()
            ->assertJsonPath('summary.expected_total', 3)
            ->assertJsonPath('summary.present', 2)
            ->assertJsonPath('summary.late', 1)
            ->assertJsonPath('summary.absent', 1);
        $this->assertDatabaseHas('persons', ['id' => $absent->id, 'person_number' => 'EMP-003']);
    }

    public function test_report_uses_local_date_schedule_resolution_and_late_subset_semantics(): void
    {
        [$user, $organization, $group, $subgroup] = $this->tenantWithMondaySchedule();
        $present = $this->person($organization, $group, $subgroup, '001', 'Juan', 'Present');
        $late = $this->person($organization, $group, $subgroup, '002', 'Maria', 'Late');
        $absent = $this->person($organization, $group, $subgroup, '003', 'Ana', 'Absent');
        $inactive = $this->person($organization, $group, $subgroup, '004', 'Inactive', 'Person', false);
        $offGroup = Group::create(['organization_id' => $organization->id, 'name' => 'Off Day Group']);
        $offSubgroup = Subgroup::create(['organization_id' => $organization->id, 'group_id' => $offGroup->id, 'name' => 'Off Day Section']);
        $offDay = $this->person($organization, $offGroup, $offSubgroup, '005', 'Off', 'Day');

        AttendanceEvent::create(['organization_id' => $organization->id, 'person_id' => $present->id, 'event_type' => 'check_in', 'occurred_at' => '2026-09-13 23:42:00']);
        AttendanceEvent::create(['organization_id' => $organization->id, 'person_id' => $present->id, 'event_type' => 'check_out', 'occurred_at' => '2026-09-14 09:04:00']);
        AttendanceEvent::create(['organization_id' => $organization->id, 'person_id' => $late->id, 'event_type' => 'late_check_in', 'occurred_at' => '2026-09-14 00:19:00']);
        AttendanceEvent::create(['organization_id' => $organization->id, 'person_id' => $offDay->id, 'event_type' => 'check_in', 'occurred_at' => '2026-09-15 00:00:00']);

        $response = $this->actingAs($user)->getJson('/api/attendance/report?date=2026-09-14');

        $response->assertOk()
            ->assertJsonPath('date', '2026-09-14')
            ->assertJsonPath('summary.expected_total', 3)
            ->assertJsonPath('summary.present', 2)
            ->assertJsonPath('summary.late', 1)
            ->assertJsonPath('summary.absent', 1)
            ->assertJsonCount(3, 'people')
            ->assertJsonPath('people.2.status', 'ABSENT');
        $this->assertStringContainsString('7:42 AM', $response->json('people.0.check_in_time'));
        $this->assertSame('5:04 PM', $response->json('people.0.check_out_time'));
        $this->assertSame('LATE', $response->json('people.1.status'));
    }

    public function test_report_filters_are_server_side_and_present_includes_late(): void
    {
        [$user, $organization, $group, $subgroup] = $this->tenantWithMondaySchedule();
        $person = $this->person($organization, $group, $subgroup, '001', 'Maria', 'Santos');
        AttendanceEvent::create(['organization_id' => $organization->id, 'person_id' => $person->id, 'event_type' => 'late_check_in', 'occurred_at' => '2026-09-14 00:19:00']);

        $this->actingAs($user)->getJson('/api/attendance/report?date=2026-09-14&status=PRESENT&search=maria')
            ->assertOk()->assertJsonCount(1, 'people')->assertJsonPath('people.0.status', 'LATE');
        $this->actingAs($user)->getJson('/api/attendance/report?date=2026-09-14&status=ABSENT')
            ->assertOk()->assertJsonCount(0, 'people');
    }

    private function tenantWithMondaySchedule(): array
    {
        $organization = Organization::create(['name' => 'Organization', 'type' => 'company', 'timezone' => 'Asia/Manila']);
        $user = User::create(['organization_id' => $organization->id, 'name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password', 'email_verified_at' => now(), 'role' => 'owner']);
        $group = Group::create(['organization_id' => $organization->id, 'name' => 'Grade 6']);
        $subgroup = Subgroup::create(['organization_id' => $organization->id, 'group_id' => $group->id, 'name' => 'Section A']);
        Schedule::create(['organization_id' => $organization->id, 'group_id' => $group->id, 'name' => 'Monday', 'day_of_week' => 1, 'start_time' => '07:30', 'late_after' => '08:00']);

        return [$user, $organization, $group, $subgroup];
    }

    private function person(Organization $organization, Group $group, Subgroup $subgroup, string $number, string $first, string $last, bool $active = true): Person
    {
        $person = Person::create(['organization_id' => $organization->id, 'person_number' => $number, 'first_name' => $first, 'last_name' => $last, 'group_id' => $group->id, 'subgroup_id' => $subgroup->id, 'is_active' => $active]);
        $person->forceFill(['created_at' => '2026-08-01 00:00:00'])->save();

        return $person;
    }
}

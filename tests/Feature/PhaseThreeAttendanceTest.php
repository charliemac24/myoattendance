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

class PhaseThreeAttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_on_time_late_checkout_and_complete_flow_are_server_authoritative(): void
    {
        [$user, $organization, $person] = $this->personWithSchedule();
        Carbon::setTestNow(Carbon::parse('2026-09-14 00:00:00', 'UTC'));

        Carbon::setTestNow(Carbon::parse('2026-09-14 00:15:00', 'UTC'));
        $this->actingAs($user)->postJson('/api/attendance/scan', ['qr_payload' => $person->qr_token, 'scan_request_id' => '11111111-1111-4111-8111-111111111111'])
            ->assertOk()->assertJsonPath('code', 'CHECKED_IN')->assertJsonPath('data.direction', 'IN')->assertJsonPath('data.attendance_status', 'PRESENT')->assertJsonPath('data.event_type', 'check_in');
        $this->assertDatabaseHas('attendance_events', ['organization_id' => $organization->id, 'person_id' => $person->id, 'event_type' => 'check_in']);

        Carbon::setTestNow(Carbon::parse('2026-09-14 00:15:01', 'UTC'));
        $this->actingAs($user)->postJson('/api/attendance/scan', ['qr_payload' => $person->qr_token])->assertOk()->assertJsonPath('code', 'DUPLICATE_SCAN');
        $this->assertSame(1, AttendanceEvent::count());

        Carbon::setTestNow(Carbon::parse('2026-09-14 00:21:00', 'UTC'));
        $this->actingAs($user)->postJson('/api/attendance/scan', ['qr_payload' => $person->qr_token])->assertOk()->assertJsonPath('code', 'CHECKED_OUT')->assertJsonPath('data.direction', 'OUT');
        $this->assertSame(2, AttendanceEvent::count());

        Carbon::setTestNow(Carbon::parse('2026-09-14 00:30:00', 'UTC'));
        // Schools stop at the last period; employers can scan back in (lunch), see EmployeeHoursTest.
        $user->organization->update(['type' => 'school']);
        $this->actingAs($user)->postJson('/api/attendance/scan', ['qr_payload' => $person->qr_token])->assertOk()->assertJsonPath('code', 'ATTENDANCE_COMPLETE');
        $this->assertSame(2, AttendanceEvent::count());
    }

    public function test_late_threshold_is_inclusive_and_one_second_later_is_late(): void
    {
        [$user, , $person] = $this->personWithSchedule();
        Carbon::setTestNow(Carbon::parse('2026-09-14 00:15:00', 'UTC'));
        $this->actingAs($user)->postJson('/api/attendance/scan', ['qr_payload' => $person->qr_token])->assertJsonPath('data.attendance_status', 'PRESENT');

        [$lateUser, , $latePerson] = $this->personWithSchedule('Late');
        Carbon::setTestNow(Carbon::parse('2026-09-14 00:15:01', 'UTC'));
        $this->actingAs($lateUser)->postJson('/api/attendance/scan', ['qr_payload' => $latePerson->qr_token])->assertJsonPath('data.attendance_status', 'LATE')->assertJsonPath('data.event_type', 'late_check_in');
    }

    public function test_qr_security_and_invalid_person_states_do_not_create_events(): void
    {
        [$userA] = $this->tenant('A', 'Asia/Manila', true);
        [$userB, $organizationB, $personB] = $this->personWithSchedule('B');
        $response = $this->actingAs($userA)->postJson('/api/attendance/scan', ['qr_payload' => $personB->qr_token]);
        $response->assertOk()->assertJsonPath('code', 'INVALID_QR')->assertJsonMissing(['first_name' => $personB->first_name]);
        $this->assertSame(0, AttendanceEvent::where('organization_id', $organizationB->id)->count());

        $personB->update(['is_active' => false]);
        $this->actingAs($userB)->postJson('/api/attendance/scan', ['qr_payload' => $personB->qr_token])->assertJsonPath('code', 'PERSON_INACTIVE');
        $this->actingAs($userB)->postJson('/api/attendance/scan', ['qr_payload' => 'malformed'])->assertJsonPath('code', 'INVALID_QR');
    }

    public function test_schedule_override_fallback_and_no_schedule_are_applied_through_scan(): void
    {
        [$user, $organization, $person] = $this->personWithSchedule();
        $override = Schedule::create(['organization_id' => $organization->id, 'group_id' => $person->group_id, 'subgroup_id' => $person->subgroup_id, 'name' => 'Override', 'day_of_week' => 1, 'start_time' => '09:00', 'late_after' => '09:15']);
        Carbon::setTestNow(Carbon::parse('2026-09-14 01:00:00', 'UTC'));
        $this->actingAs($user)->postJson('/api/attendance/scan', ['qr_payload' => $person->qr_token])->assertJsonPath('data.schedule.name', 'Override');

        $override->update(['is_active' => false]);
        $person->update(['qr_token' => 'new-token']);
        Carbon::setTestNow(Carbon::parse('2026-09-15 01:00:00', 'UTC'));
        // Employers record rest day work instead (see EmployeeHoursTest); other organizations refuse the scan.
        $user->organization->update(['type' => 'school']);
        $this->actingAs($user)->postJson('/api/attendance/scan', ['qr_payload' => 'new-token'])->assertJsonPath('code', 'NO_SCHEDULE');
    }

    public function test_request_idempotency_creates_one_event(): void
    {
        [$user, , $person] = $this->personWithSchedule();
        Carbon::setTestNow(Carbon::parse('2026-09-14 00:01:00', 'UTC'));
        $payload = ['qr_payload' => $person->qr_token, 'scan_request_id' => '22222222-2222-4222-8222-222222222222'];
        $this->actingAs($user)->postJson('/api/attendance/scan', $payload)->assertJsonPath('code', 'CHECKED_IN');
        $this->actingAs($user)->postJson('/api/attendance/scan', $payload)->assertJsonPath('code', 'CHECKED_IN')->assertJsonPath('idempotent_replay', true);
        $this->assertSame(1, AttendanceEvent::count());
    }

    public function test_multiple_schedule_periods_allow_two_in_out_cycles(): void
    {
        [$user, $organization, $person] = $this->personWithSchedule('Split');
        Schedule::firstOrFail()->update(['periods' => [
            ['start_time' => '07:00', 'late_after' => '07:15', 'end_time' => '12:00'],
            ['start_time' => '13:00', 'late_after' => '13:15', 'end_time' => '17:00'],
        ]]);

        foreach ([
            ['time' => '2026-09-13 23:00:00', 'code' => 'CHECKED_IN', 'direction' => 'IN'],
            ['time' => '2026-09-14 04:00:00', 'code' => 'CHECKED_OUT', 'direction' => 'OUT'],
            ['time' => '2026-09-14 05:00:00', 'code' => 'CHECKED_IN', 'direction' => 'IN'],
            ['time' => '2026-09-14 09:00:00', 'code' => 'CHECKED_OUT', 'direction' => 'OUT'],
        ] as $scan) {
            Carbon::setTestNow(Carbon::parse($scan['time'], 'UTC'));
            $this->actingAs($user)->postJson('/api/attendance/scan', ['qr_payload' => $person->qr_token])
                ->assertJsonPath('code', $scan['code'])->assertJsonPath('data.direction', $scan['direction']);
        }

        Carbon::setTestNow(Carbon::parse('2026-09-14 10:00:00', 'UTC'));
        $user->organization->update(['type' => 'school']);
        $this->actingAs($user)->postJson('/api/attendance/scan', ['qr_payload' => $person->qr_token])->assertJsonPath('code', 'ATTENDANCE_COMPLETE');
        $this->assertSame(4, AttendanceEvent::where('organization_id', $organization->id)->count());
    }

    public function test_organization_timezone_controls_attendance_day_and_schedule(): void
    {
        [$user, $organization, $person] = $this->personWithSchedule('Timezone', 'America/New_York');
        Carbon::setTestNow(Carbon::parse('2026-09-15 03:30:00', 'UTC'));
        $this->actingAs($user)->postJson('/api/attendance/scan', ['qr_payload' => $person->qr_token])->assertJsonPath('code', 'CHECKED_IN')->assertJsonPath('data.schedule.name', 'Daily');
        $this->assertDatabaseHas('attendance_events', ['organization_id' => $organization->id, 'person_id' => $person->id]);
        $this->assertSame('2026-09-14', AttendanceEvent::firstOrFail()->meta['attendance_date']);
    }

    public function test_scan_requires_verified_authentication(): void
    {
        $this->postJson('/api/attendance/scan', ['qr_payload' => 'token'])->assertUnauthorized();
        [$user] = $this->tenant('Unverified');
        $this->actingAs($user)->postJson('/api/attendance/scan', ['qr_payload' => 'token'])->assertForbidden();
    }

    public function test_scan_validation_has_a_stable_machine_code(): void
    {
        [$user] = $this->tenant('Validation', 'Asia/Manila', true);

        $this->actingAs($user)->postJson('/api/attendance/scan', ['qr_payload' => ''])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    private function personWithSchedule(string $label = 'A', string $timezone = 'Asia/Manila'): array
    {
        [$user, $organization] = $this->tenant($label, $timezone, true);
        $group = Group::create(['organization_id' => $organization->id, 'name' => "Group {$label}"]);
        $subgroup = Subgroup::create(['organization_id' => $organization->id, 'group_id' => $group->id, 'name' => "Subgroup {$label}"]);
        $person = Person::create(['organization_id' => $organization->id, 'person_number' => "{$label}-001", 'first_name' => 'Test', 'last_name' => 'Person', 'group_id' => $group->id, 'subgroup_id' => $subgroup->id]);
        Schedule::create(['organization_id' => $organization->id, 'group_id' => $group->id, 'name' => 'Daily', 'day_of_week' => 1, 'start_time' => '08:00', 'late_after' => '08:15']);

        return [$user, $organization, $person];
    }

    private function tenant(string $label, string $timezone = 'Asia/Manila', bool $verified = false): array
    {
        $organization = Organization::create(['name' => "Organization {$label}", 'type' => 'company', 'timezone' => $timezone]);
        $user = User::create(['organization_id' => $organization->id, 'name' => "Admin {$label}", 'email' => strtolower("admin{$label}@example.test"), 'password' => 'password', 'email_verified_at' => $verified ? now() : null, 'role' => 'owner']);

        return [$user, $organization];
    }
}

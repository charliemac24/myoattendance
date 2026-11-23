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
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class OpenHoursAndMembershipTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_open_hours_members_check_in_anytime_and_each_visit_is_counted(): void
    {
        [$user, $organization] = $this->tenant();
        $gym = $this->group($organization, 'Gym Floor', Group::MODE_OPEN);
        $member = $this->person($organization, $gym, 'M1');

        // Saturday morning, no schedule at all.
        $this->scanAt($user, $member, '2026-11-07 06:30')->assertJsonPath('code', 'CHECKED_IN')->assertJsonPath('data.attendance_status', 'PRESENT');
        $this->scanAt($user, $member, '2026-11-07 06:45')->assertJsonPath('code', 'DUPLICATE_SCAN')->assertJsonPath('message', 'Already checked in at 6:30 AM.');
        $this->scanAt($user, $member, '2026-11-07 18:00')->assertJsonPath('code', 'CHECKED_IN')->assertJsonPath('data.visits_today', 2);
        $this->assertSame(2, AttendanceEvent::where('person_id', $member->id)->where('event_type', 'check_in')->count());
        $this->assertSame(0, AttendanceEvent::where('person_id', $member->id)->where('event_type', 'check_out')->count());
    }

    public function test_open_hours_groups_can_also_scan_people_out(): void
    {
        [$user, $organization] = $this->tenant();
        $gym = $this->group($organization, 'Gym Floor', Group::MODE_OPEN, true);
        $member = $this->person($organization, $gym, 'M1');

        $this->scanAt($user, $member, '2026-11-09 06:30')->assertJsonPath('code', 'CHECKED_IN');
        $this->scanAt($user, $member, '2026-11-09 06:40')->assertJsonPath('code', 'CHECKED_OUT');
        $this->scanAt($user, $member, '2026-11-09 17:00')->assertJsonPath('code', 'CHECKED_IN')->assertJsonPath('data.visits_today', 2);
    }

    public function test_reports_show_visits_and_never_mark_open_hours_members_absent_or_missing_a_time_out(): void
    {
        [$user, $organization] = $this->tenant();
        $gym = $this->group($organization, 'Gym Floor', Group::MODE_OPEN);
        $regular = $this->person($organization, $gym, 'M1', 'Rita');
        $away = $this->person($organization, $gym, 'M2', 'Andy');
        $this->scanAt($user, $regular, '2026-11-07 06:30');
        $this->scanAt($user, $regular, '2026-11-07 18:00');
        $this->scanAt($user, $regular, '2026-11-09 07:00');
        Carbon::setTestNow(Carbon::parse('2026-11-20 09:00', 'Asia/Manila'));

        $daily = $this->actingAs($user)->getJson('/api/attendance/report?date=2026-11-07')->assertOk()->json();
        $this->assertSame(['M1'], array_column(array_column($daily['people'], 'person'), 'person_number'), 'Members who did not come are not listed as absent.');
        $this->assertSame([2, 'PRESENT', false], [$daily['people'][0]['visits'], $daily['people'][0]['status'], $daily['people'][0]['no_time_out']]);
        $this->assertSame(0, $daily['summary']['absent']);

        $monthly = $this->actingAs($user)->getJson("/api/attendance/monthly?month=2026-11&group_id={$gym->id}")->assertOk()->json();
        $this->assertTrue($monthly['open_hours']);
        $this->assertCount(30, $monthly['dates'], 'Every day of the month, weekends included.');
        $rita = collect($monthly['persons'])->firstWhere('person_number', 'M1');
        $this->assertSame(['Visited (2)', '-', 'Visited', 3], [$rita['attendance']['2026-11-07'], $rita['attendance']['2026-11-08'], $rita['attendance']['2026-11-09'], $rita['total_visits']]);
        $csv = $this->actingAs($user)->get("/api/attendance/monthly/export?month=2026-11&group_id={$gym->id}")->assertOk()->streamedContent();
        $this->assertStringContainsString('Total Visits', strtok($csv, "\n"));

        $history = $this->actingAs($user)->getJson("/api/people/{$regular->id}/attendance?from=2026-11-01&to=2026-11-20")->assertOk()->json();
        $this->assertTrue($history['person']['open_hours']);
        $this->assertSame([3, 2, 0, null], [$history['summary']['visits'], $history['summary']['days_visited'], $history['summary']['absent'], $history['summary']['attendance_rate']]);

        $flagged = collect($this->actingAs($user)->getJson('/api/attendance/needs-attention')->assertOk()->json('people'))->keyBy('person_number');
        $this->assertSame(['not_seen'], array_column($flagged['M1']['reasons'], 'type'), 'Not seen in 11 days, but no absence streak or rate.');
        $this->assertSame('Never scanned', $flagged['M2']['reasons'][0]['label']);
    }

    public function test_expired_memberships_are_turned_away_and_ending_soon_is_shown_on_the_scan(): void
    {
        [$user, $organization] = $this->tenant();
        $gym = $this->group($organization, 'Gym Floor', Group::MODE_OPEN);
        $classes = $this->group($organization, 'Kids Class', Group::MODE_SCHEDULED);
        Schedule::create(['organization_id' => $organization->id, 'group_id' => $classes->id, 'name' => 'Class', 'day_of_week' => 1, 'start_time' => '08:00', 'late_after' => '08:15', 'end_time' => '10:00']);
        $expired = $this->person($organization, $gym, 'M1', 'Ed', '2026-11-08');
        $endsToday = $this->person($organization, $gym, 'M2', 'Tia', '2026-11-09');
        $student = $this->person($organization, $classes, 'K1', 'Kim', '2026-11-01');

        $this->scanAt($user, $expired, '2026-11-09 07:00')->assertJsonPath('code', 'MEMBERSHIP_EXPIRED')->assertJsonPath('message', 'Membership expired on Nov 8, 2026.');
        $this->assertSame(0, AttendanceEvent::where('person_id', $expired->id)->count());
        $this->scanAt($user, $endsToday, '2026-11-09 07:00')->assertJsonPath('code', 'CHECKED_IN')->assertJsonPath('data.membership', ['valid_until' => '2026-11-09', 'days_left' => 0]);
        $this->scanAt($user, $student, '2026-11-09 08:00')->assertJsonPath('code', 'MEMBERSHIP_EXPIRED');
    }

    public function test_group_mode_and_membership_dates_are_saved_from_forms_and_csv(): void
    {
        [$user, $organization] = $this->tenant();

        $groupId = $this->actingAs($user)->postJson('/api/groups', ['name' => 'Open Gym', 'attendance_mode' => 'open', 'scan_out' => true])->assertCreated()->json('group.id');
        $this->assertSame(['open', true], [Group::find($groupId)->attendance_mode, Group::find($groupId)->scan_out]);
        $this->actingAs($user)->putJson("/api/groups/{$groupId}", ['name' => 'Open Gym', 'attendance_mode' => 'scheduled', 'scan_out' => true])->assertOk();
        $this->assertSame(['scheduled', false], [Group::find($groupId)->attendance_mode, Group::find($groupId)->scan_out], 'Scanning out only applies to open hours.');
        $this->actingAs($user)->putJson("/api/groups/{$groupId}", ['name' => 'Renamed'])->assertOk();
        $this->assertSame('scheduled', Group::find($groupId)->attendance_mode, 'Saving without the mode keeps it.');

        $personId = $this->actingAs($user)->postJson('/api/people', ['person_number' => 'M9', 'first_name' => 'Mia', 'last_name' => 'Cruz', 'membership_valid_until' => '2026-12-31'])->assertCreated()->json('person.id');
        $this->assertSame('2026-12-31', Person::find($personId)->membership_valid_until->toDateString());
        $this->actingAs($user)->putJson("/api/people/{$personId}", ['person_number' => 'M9', 'first_name' => 'Mia', 'last_name' => 'Cruz'])->assertOk();
        $this->assertSame('2026-12-31', Person::find($personId)->membership_valid_until->toDateString(), 'Forms without the field keep the date.');

        $file = UploadedFile::fake()->createWithContent('members.csv', "person_number,first_name,last_name,membership_valid_until\nM10,Leo,Santos,2027-01-15\nM9,Mia,Cruz,\n");
        $this->actingAs($user)->post('/api/people/import', ['file' => $file, 'upsert' => '1'], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame('2027-01-15', Person::where('person_number', 'M10')->first()->membership_valid_until->toDateString());
        $this->assertNull(Person::find($personId)->membership_valid_until);
    }

    private function scanAt(User $user, Person $person, string $localTime)
    {
        Carbon::setTestNow(Carbon::parse($localTime, 'Asia/Manila'));

        return $this->actingAs($user)->postJson('/api/attendance/scan', ['qr_payload' => $person->qr_token])->assertOk();
    }

    /** @return array{0: User, 1: Organization} */
    private function tenant(): array
    {
        $organization = Organization::create(['name' => 'Iron Gym', 'type' => 'sports_academy', 'timezone' => 'Asia/Manila']);
        $user = User::create(['organization_id' => $organization->id, 'name' => 'Owner', 'email' => 'owner@gym.test', 'password' => 'password', 'email_verified_at' => now(), 'role' => 'owner']);

        return [$user, $organization];
    }

    private function group(Organization $organization, string $name, string $mode, bool $scanOut = false): Group
    {
        return Group::create(['organization_id' => $organization->id, 'name' => $name, 'attendance_mode' => $mode, 'scan_out' => $scanOut]);
    }

    private function person(Organization $organization, Group $group, string $number, string $first = 'Member', ?string $validUntil = null): Person
    {
        $person = Person::create(['organization_id' => $organization->id, 'person_number' => $number, 'first_name' => $first, 'last_name' => 'Test', 'group_id' => $group->id, 'membership_valid_until' => $validUntil]);
        $person->forceFill(['created_at' => '2026-10-01 00:00:00'])->save();

        return $person->fresh();
    }
}

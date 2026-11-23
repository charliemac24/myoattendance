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

class PersonAttendanceHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_history_lists_each_day_with_times_minutes_late_and_a_summary(): void
    {
        [$user, $organization, $group] = $this->tenant();
        $person = $this->person($organization, $group, '2026-09-01 00:00:00');
        $this->weekdaySchedule($organization, $group);
        $this->scan($organization, $person, 'check_in', '2026-09-14 07:50');
        $this->scan($organization, $person, 'check_out', '2026-09-14 16:05');
        $this->scan($organization, $person, 'late_check_in', '2026-09-15 08:12');
        $this->scan($organization, $person, 'check_in', '2026-09-19 09:00');
        Carbon::setTestNow(Carbon::parse('2026-09-18 09:00', 'Asia/Manila'));

        $data = $this->actingAs($user)->getJson("/api/people/{$person->id}/attendance?from=2026-09-14&to=2026-09-20")->assertOk()->json();

        $days = collect($data['days'])->keyBy('date');
        $this->assertSame(['2026-09-18', '2026-09-17', '2026-09-16', '2026-09-15', '2026-09-14'], array_column($data['days'], 'date'), 'Newest first; future days left out.');
        $this->assertSame(['present', '7:50 AM', '4:05 PM', null], [$days['2026-09-14']['status'], $days['2026-09-14']['time_in'], $days['2026-09-14']['time_out'], $days['2026-09-14']['minutes_late']]);
        $this->assertSame(['late', 12], [$days['2026-09-15']['status'], $days['2026-09-15']['minutes_late']]);
        $this->assertSame('absent', $days['2026-09-16']['status']);
        $this->assertSame('not_yet', $days['2026-09-18']['status'], 'Today is not Absent while the day is open.');
        $this->assertSame(['scheduled_days' => 4, 'attended' => 2, 'late' => 1, 'absent' => 2, 'attendance_rate' => 50], array_intersect_key($data['summary'], array_flip(['scheduled_days', 'attended', 'late', 'absent', 'attendance_rate'])));
        $this->assertSame('2026-09-15', $data['summary']['last_seen_date'], 'The future-dated scan is outside the range but last seen uses all scans up to now.');
    }

    public function test_history_defaults_to_the_last_30_days_and_skips_days_before_the_person_was_added(): void
    {
        [$user, $organization, $group] = $this->tenant();
        $person = $this->person($organization, $group, '2026-09-16 02:00:00');
        $this->weekdaySchedule($organization, $group);
        Carbon::setTestNow(Carbon::parse('2026-09-18 20:00', 'Asia/Manila'));

        $data = $this->actingAs($user)->getJson("/api/people/{$person->id}/attendance")->assertOk()->json();

        $this->assertSame(['2026-08-20', '2026-09-18'], [$data['from'], $data['to']]);
        $this->assertSame(['2026-09-18', '2026-09-17', '2026-09-16'], array_column($data['days'], 'date'));
        $this->assertSame('absent', $data['days'][0]['status'], 'Today counts once the day is over.');
        $this->assertNull($data['summary']['last_seen']);
    }

    public function test_people_added_after_they_started_scanning_count_from_their_first_scan(): void
    {
        [$user, $organization, $group] = $this->tenant();
        $person = $this->person($organization, $group, '2026-09-20 00:00:00');
        $this->weekdaySchedule($organization, $group);
        $this->scan($organization, $person, 'check_in', '2026-09-15 07:50');
        Carbon::setTestNow(Carbon::parse('2026-09-18 20:00', 'Asia/Manila'));

        $data = $this->actingAs($user)->getJson("/api/people/{$person->id}/attendance?from=2026-09-14&to=2026-09-18")->assertOk()->json();

        $this->assertSame(['absent', 'absent', 'absent', 'present'], array_column($data['days'], 'status'));
        $this->assertSame('2026-09-15', end($data['days'])['date']);
    }

    public function test_history_csv_export_and_tenant_isolation(): void
    {
        [$user, $organization, $group] = $this->tenant();
        $person = $this->person($organization, $group, '2026-09-01 00:00:00');
        $this->weekdaySchedule($organization, $group);
        $this->scan($organization, $person, 'late_check_in', '2026-09-15 08:12');
        Carbon::setTestNow(Carbon::parse('2026-09-16 20:00', 'Asia/Manila'));

        $csv = $this->actingAs($user)->get("/api/people/{$person->id}/attendance/export?from=2026-09-15&to=2026-09-16")->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=attendance-000001-ana-cruz-2026-09-15-to-2026-09-16.csv')
            ->streamedContent();
        $lines = array_map('str_getcsv', preg_split('/\r\n|\n|\r/', trim($csv)));
        $this->assertSame(['ID Number', 'Full Name', 'Date', 'Day', 'Status', 'Time In', 'Time Out', 'Minutes Late', 'Note'], $lines[0]);
        $this->assertSame(['000001', 'Ana Cruz', '2026-09-16', 'Wednesday', 'Absent', '', '', '', ''], $lines[1]);
        $this->assertSame(['000001', 'Ana Cruz', '2026-09-15', 'Tuesday', 'Late', '8:12 AM', '', '12', 'No time out'], $lines[2]);

        [$otherUser] = $this->tenant('Other');
        $this->actingAs($otherUser)->getJson("/api/people/{$person->id}/attendance")->assertNotFound();
        $this->actingAs($user)->getJson("/api/people/{$person->id}/attendance?from=2026-09-20&to=2026-09-10")->assertUnprocessable()->assertJsonValidationErrors('from');
    }

    private function tenant(string $suffix = 'Main'): array
    {
        $organization = Organization::create(['name' => 'Organization '.$suffix, 'type' => 'company', 'timezone' => 'Asia/Manila']);
        $user = User::create(['organization_id' => $organization->id, 'name' => 'Admin '.$suffix, 'email' => 'admin'.strtolower($suffix).'@example.test', 'password' => 'password', 'email_verified_at' => now(), 'role' => 'owner']);
        $group = Group::create(['organization_id' => $organization->id, 'name' => 'Grade 6']);

        return [$user, $organization, $group];
    }

    private function person(Organization $organization, Group $group, string $addedAt): Person
    {
        $person = Person::create(['organization_id' => $organization->id, 'person_number' => '000001', 'first_name' => 'Ana', 'last_name' => 'Cruz', 'group_id' => $group->id]);
        $person->forceFill(['created_at' => $addedAt])->save();

        return $person;
    }

    private function weekdaySchedule(Organization $organization, Group $group): void
    {
        foreach (range(1, 5) as $day) {
            Schedule::create(['organization_id' => $organization->id, 'group_id' => $group->id, 'name' => 'Weekday', 'day_of_week' => $day, 'start_time' => '07:30', 'late_after' => '08:00', 'end_time' => '17:00']);
        }
    }

    private function scan(Organization $organization, Person $person, string $type, string $localTime): void
    {
        AttendanceEvent::create(['organization_id' => $organization->id, 'person_id' => $person->id, 'event_type' => $type, 'occurred_at' => Carbon::parse($localTime, 'Asia/Manila')->utc()]);
    }
}

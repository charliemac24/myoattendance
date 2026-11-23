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

class NeedsAttentionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_lists_people_not_seen_absent_in_a_row_or_below_the_rate_and_leaves_regulars_out(): void
    {
        [$user, $organization, $group] = $this->tenant();
        $regular = $this->person($organization, $group, 'P1', 'Rita', 'Regular');
        $stopped = $this->person($organization, $group, 'P2', 'Sam', 'Stopped', '0917000002');
        $never = $this->person($organization, $group, 'P3', 'Nina', 'Never');
        $sporadic = $this->person($organization, $group, 'P4', 'Paolo', 'Sporadic');
        $newcomer = $this->person($organization, $group, 'P5', 'Nico', 'New', null, '2026-09-29 00:00:00');
        $this->weekdaySchedule($organization, $group);
        // Weekdays from Sep 1 to Sep 30, 2026 (22 days).
        $weekdays = collect(range(1, 30))->map(fn (int $day): Carbon => Carbon::create(2026, 9, $day, 7, 50, 0, 'Asia/Manila'))->filter->isWeekday()->values();
        foreach ($weekdays as $index => $day) {
            $this->scan($organization, $regular, $day);
            if ($day->day <= 18) {
                $this->scan($organization, $stopped, $day);
            }
            if ($index % 2 === 0) {
                $this->scan($organization, $sporadic, $day);
            }
        }
        Carbon::setTestNow(Carbon::parse('2026-09-30 20:00', 'Asia/Manila'));

        $data = $this->actingAs($user)->getJson('/api/attendance/needs-attention')->assertOk()->json();

        $rows = collect($data['people'])->keyBy('person_number');
        $this->assertSame(['P3', 'P2', 'P4'], array_column($data['people'], 'person_number'), 'Never seen first, then longest since last seen.');
        $this->assertSame(['Never scanned', '22 absences in a row', '0% attendance'], array_column($rows['P3']['reasons'], 'label'));
        $this->assertSame(['Not seen in 12 days', '8 absences in a row', '63.6% attendance'], array_column($rows['P2']['reasons'], 'label'));
        $this->assertSame(['0917000002', '2026-09-18', 8], [$rows['P2']['contact_number'], $rows['P2']['last_seen_date'], $rows['P2']['absence_streak']]);
        $this->assertSame(['rate'], array_column($rows['P4']['reasons'], 'type'));
        $this->assertFalse($rows->has('P1'));
        $this->assertFalse($rows->has('P5'), 'Added 2 days ago: 2 absences are not yet a streak and 7 days have not passed.');

        $longer = $this->actingAs($user)->getJson('/api/attendance/needs-attention?not_seen_days=14')->assertOk()->json('people');
        $this->assertSame(['8 absences in a row', '63.6% attendance'], array_column(collect($longer)->firstWhere('person_number', 'P2')['reasons'], 'label'));
        $this->actingAs($user)->getJson('/api/attendance/needs-attention?not_seen_days=5')->assertUnprocessable();
    }

    public function test_csv_export_lists_the_same_people_and_is_tenant_scoped(): void
    {
        [$user, $organization, $group] = $this->tenant();
        $this->person($organization, $group, 'P1', 'Sam', 'Stopped', '0917000002');
        $this->weekdaySchedule($organization, $group);
        [, $otherOrganization, $otherGroup] = $this->tenant('Other');
        $this->person($otherOrganization, $otherGroup, 'X1', 'Other', 'Tenant');
        $this->weekdaySchedule($otherOrganization, $otherGroup);
        Carbon::setTestNow(Carbon::parse('2026-09-30 20:00', 'Asia/Manila'));

        $csv = $this->actingAs($user)->get('/api/attendance/needs-attention/export')->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=needs-attention-2026-09-30.csv')->streamedContent();

        $lines = array_map('str_getcsv', preg_split('/\r\n|\n|\r/', trim($csv)));
        $this->assertCount(2, $lines);
        $this->assertSame(['P1', 'Sam Stopped', 'Grade 6', '', '0917000002', 'Never'], array_slice($lines[1], 0, 6));
        $this->assertStringNotContainsString('Other Tenant', $csv);
    }

    public function test_home_shows_the_needs_attention_preview(): void
    {
        [$user, $organization, $group] = $this->tenant();
        $this->person($organization, $group, 'P1', 'Sam', 'Stopped');
        $this->weekdaySchedule($organization, $group);
        Carbon::setTestNow(Carbon::parse('2026-09-30 20:00', 'Asia/Manila'));

        $this->actingAs($user)->get('/app')->assertOk()->assertSee('Needs attention')->assertSee('Sam Stopped')->assertSee('Never scanned');
    }

    public function test_home_list_shows_reasons_group_last_seen_links_and_industry_words(): void
    {
        [$user, $organization, $group] = $this->tenant();
        $stopped = $this->person($organization, $group, 'P2', 'Sam', 'Stopped');
        $this->person($organization, $group, 'P1', 'Nina', 'Never');
        $this->weekdaySchedule($organization, $group);
        foreach ([1, 2, 3, 4, 7, 8] as $day) {
            $this->scan($organization, $stopped, Carbon::create(2026, 9, $day, 7, 50, 0, 'Asia/Manila'));
        }
        Carbon::setTestNow(Carbon::parse('2026-09-30 20:00', 'Asia/Manila'));

        $html = $this->actingAs($user)->get('/app')->assertOk()->getContent();

        $this->assertStringContainsString('home-attn-count">2<', $html);
        $this->assertStringContainsString('Employees who may have stopped coming', $html, 'Uses the industry wording.');
        $this->assertStringContainsString('View all (2)', $html);
        $this->assertStringContainsString('href="/attendance/needs-attention"', $html);
        $this->assertStringContainsString('href="/people/'.$stopped->id.'"', $html);
        $this->assertStringContainsString('Not seen in 22 days · 16 absences in a row', $html, 'The reasons are one plain line.');
        $this->assertStringContainsString('#P2 &middot; Grade 6', $html);
        $this->assertStringContainsString('>22 days ago</span>', $html);
        $this->assertStringContainsString('home-attn-dot home-attn-dot--high', $html, 'Long absences are marked as most urgent.');
        $this->assertStringContainsString('Never scanned', $html);
        $this->assertStringNotContainsString('home-attn-card', $html, 'No boxed cards or chips.');
        $this->assertStringNotContainsString('home-attn-chip', $html);
    }

    public function test_home_list_shows_a_friendly_all_clear_when_nobody_needs_attention(): void
    {
        [$user, $organization] = $this->tenant();
        Carbon::setTestNow(Carbon::parse('2026-09-30 20:00', 'Asia/Manila'));

        $html = $this->actingAs($user)->get('/app')->assertOk()->getContent();

        $this->assertStringContainsString('No one needs attention right now', $html);
        $this->assertStringContainsString('Everyone is coming as expected.', $html);
        $this->assertStringNotContainsString('home-attn-count', $html);
        $this->assertStringContainsString('>Open</a>', $html);
        $this->assertStringNotContainsString('home-attn-row', $html);
    }

    private function tenant(string $suffix = 'Main'): array
    {
        $organization = Organization::create(['name' => 'Organization '.$suffix, 'type' => 'company', 'timezone' => 'Asia/Manila']);
        $user = User::create(['organization_id' => $organization->id, 'name' => 'Admin '.$suffix, 'email' => 'admin'.strtolower($suffix).'@example.test', 'password' => 'password', 'email_verified_at' => now(), 'role' => 'owner']);
        $group = Group::create(['organization_id' => $organization->id, 'name' => 'Grade 6']);

        return [$user, $organization, $group];
    }

    private function person(Organization $organization, Group $group, string $number, string $first, string $last, ?string $contact = null, string $addedAt = '2026-08-01 00:00:00'): Person
    {
        $person = Person::create(['organization_id' => $organization->id, 'person_number' => $number, 'first_name' => $first, 'last_name' => $last, 'group_id' => $group->id, 'contact_number' => $contact]);
        $person->forceFill(['created_at' => $addedAt])->save();

        return $person;
    }

    private function weekdaySchedule(Organization $organization, Group $group): void
    {
        foreach (range(1, 5) as $day) {
            Schedule::create(['organization_id' => $organization->id, 'group_id' => $group->id, 'name' => 'Weekday', 'day_of_week' => $day, 'start_time' => '07:30', 'late_after' => '08:00', 'end_time' => '17:00']);
        }
    }

    private function scan(Organization $organization, Person $person, Carbon $localTime): void
    {
        AttendanceEvent::create(['organization_id' => $organization->id, 'person_id' => $person->id, 'event_type' => 'check_in', 'occurred_at' => $localTime->copy()->utc()]);
    }
}

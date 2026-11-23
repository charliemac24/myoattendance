<?php

namespace Tests\Feature;

use App\Models\AttendanceEvent;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Person;
use App\Models\PersonAssignment;
use App\Models\Subgroup;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The safety fixes and gym features from the non-school pilot assessment: archived people stay archived, used
 * groups keep their history, industry and check-in mode cannot silently rewrite past reports, CSV exports are safe
 * to open in a spreadsheet, the Fitness / Gym type, the membership filter, and turned-away scans.
 */
class ReadinessFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_saving_an_archived_persons_profile_keeps_them_archived(): void
    {
        [$owner, $organization] = $this->tenant('school');
        $group = $this->group($organization, 'Grade 1');
        $person = $this->person($organization, $group, 'S1');
        Carbon::setTestNow(Carbon::parse('2026-11-10 09:00', 'Asia/Manila'));
        $this->actingAs($owner)->postJson("/api/people/{$person->id}/archive")->assertOk()->assertJsonPath('person.is_active', false);
        $archivedAt = $person->fresh()->archived_at;

        Carbon::setTestNow(Carbon::parse('2026-11-20 09:00', 'Asia/Manila'));
        $this->actingAs($owner)->putJson("/api/people/{$person->id}", ['person_number' => 'S1', 'first_name' => 'Renamed', 'last_name' => 'Test', 'group_id' => $group->id])
            ->assertOk()->assertJsonPath('person.is_active', false)->assertJsonPath('person.first_name', 'Renamed');
        $this->assertEquals($archivedAt, $person->fresh()->archived_at, 'The archive date must not move, or past reports change.');

        $this->actingAs($owner)->putJson("/api/people/{$person->id}", ['person_number' => 'S1', 'first_name' => 'Renamed', 'last_name' => 'Test', 'is_active' => true])
            ->assertOk()->assertJsonPath('person.is_active', true);
        $this->actingAs($owner)->postJson('/api/people', ['person_number' => 'S2', 'first_name' => 'New', 'last_name' => 'Test'])
            ->assertCreated()->assertJsonPath('person.is_active', true);
    }

    public function test_a_group_or_subgroup_people_were_in_cannot_be_deleted_but_an_unused_one_can(): void
    {
        [$owner, $organization] = $this->tenant('school');
        $old = $this->group($organization, 'Old Grade');
        $section = Subgroup::create(['organization_id' => $organization->id, 'group_id' => $old->id, 'name' => 'A']);
        $new = $this->group($organization, 'New Grade');
        $person = $this->person($organization, $old, 'S1', $section);
        Carbon::setTestNow(Carbon::parse('2026-11-20 09:00', 'Asia/Manila'));
        $person->update(['group_id' => $new->id, 'subgroup_id' => null]);

        $this->actingAs($owner)->deleteJson("/api/subgroups/{$section->id}")->assertStatus(409)->assertJsonPath('message', fn (string $message) => str_contains($message, 'Rename it instead'));
        $section->delete();
        $this->actingAs($owner)->deleteJson("/api/groups/{$old->id}")->assertStatus(409);
        $this->assertSame(2, PersonAssignment::where('person_id', $person->id)->count(), 'The old assignment is still there for past reports.');

        $unused = $this->group($organization, 'Never Used');
        $unusedSection = Subgroup::create(['organization_id' => $organization->id, 'group_id' => $new->id, 'name' => 'Empty']);
        $this->actingAs($owner)->deleteJson("/api/subgroups/{$unusedSection->id}")->assertOk();
        $this->actingAs($owner)->deleteJson("/api/groups/{$unused->id}")->assertOk();
    }

    public function test_only_the_owner_can_switch_the_industry(): void
    {
        [$owner, $organization] = $this->tenant('school');
        $admin = User::create(['organization_id' => $organization->id, 'name' => 'Office Admin', 'username' => 'office', 'password' => 'password', 'email_verified_at' => now(), 'role' => 'admin']);

        $this->actingAs($admin)->putJson('/api/organization/industry', ['type' => 'company'])->assertForbidden();
        $this->assertSame('school', $organization->fresh()->type);
        $this->actingAs($admin)->get('/settings/terminology')->assertOk()->assertSee('Only the owner can change the industry.');

        $this->actingAs($owner)->putJson('/api/organization/industry', ['type' => 'company'])->assertOk()->assertJsonPath('type', 'company');
    }

    public function test_a_groups_check_in_mode_is_locked_once_people_have_scanned_in_it(): void
    {
        [$owner, $organization] = $this->tenant('fitness');
        $gym = $this->group($organization, 'Monthly', Group::MODE_OPEN);
        $member = $this->person($organization, $gym, 'M1');

        $this->actingAs($owner)->putJson("/api/groups/{$gym->id}", ['name' => 'Monthly', 'attendance_mode' => 'scheduled'])->assertOk();
        $this->actingAs($owner)->putJson("/api/groups/{$gym->id}", ['name' => 'Monthly', 'attendance_mode' => 'open'])->assertOk();
        $this->scanAt($owner, $member, '2026-11-20 07:00')->assertJsonPath('code', 'CHECKED_IN');

        $this->actingAs($owner)->putJson("/api/groups/{$gym->id}", ['name' => 'Monthly', 'attendance_mode' => 'scheduled'])->assertUnprocessable()->assertJsonValidationErrors('attendance_mode');
        $this->assertSame('open', $gym->fresh()->attendance_mode);
        $this->actingAs($owner)->putJson("/api/groups/{$gym->id}", ['name' => 'Monthly Unlimited', 'attendance_mode' => 'open', 'scan_out' => true])->assertOk()->assertJsonPath('group.name', 'Monthly Unlimited');
        $this->actingAs($owner)->get("/groups/{$gym->id}/edit")->assertOk()->assertSee('can no longer change');
    }

    public function test_csv_exports_escape_formula_characters_and_the_people_csv_still_imports_back(): void
    {
        [$owner, $organization] = $this->tenant('fitness');
        $plan = $this->group($organization, '+Plan');
        $person = Person::create(['organization_id' => $organization->id, 'person_number' => '-5', 'first_name' => '=HYPERLINK("http://x.test")', 'last_name' => '@Smith', 'group_id' => $plan->id]);
        $person->forceFill(['created_at' => '2026-10-01 00:00:00'])->save();

        $csv = $this->actingAs($owner)->get('/api/people/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('"\'=HYPERLINK(""http://x.test"")"', $csv);
        $this->assertStringContainsString("'@Smith", $csv);
        $this->assertStringContainsString("'+Plan", $csv);
        $this->assertStringContainsString("\n-5,", $csv, 'Negative numbers are left alone.');

        Carbon::setTestNow(Carbon::parse('2026-11-20 09:00', 'Asia/Manila'));
        $monthly = $this->actingAs($owner)->get("/api/attendance/monthly/export?month=2026-11&group_id={$plan->id}")->assertOk()->streamedContent();
        $this->assertStringContainsString("\n-5,\"'=HYPERLINK", $monthly, 'Report exports are escaped too.');

        $person->update(['first_name' => 'Changed']);
        $this->actingAs($owner)->post('/api/people/import', ['file' => UploadedFile::fake()->createWithContent('people.csv', $csv), 'upsert' => '1'])->assertOk()->assertJsonPath('updated', 1);
        $this->assertSame('=HYPERLINK("http://x.test")', $person->fresh()->first_name, 'Re-importing our own export restores the original text.');
        $this->assertSame('@Smith', $person->fresh()->last_name);
    }

    public function test_scans_keep_the_scanners_name_after_their_account_is_removed(): void
    {
        [$owner, $organization] = $this->tenant('fitness');
        $staff = User::create(['organization_id' => $organization->id, 'name' => 'Front Desk Ana', 'username' => 'ana', 'password' => 'password', 'email_verified_at' => now(), 'role' => 'staff']);
        $member = $this->person($organization, $this->group($organization, 'Monthly', Group::MODE_OPEN), 'M1');

        $this->scanAt($staff, $member, '2026-11-20 07:00')->assertJsonPath('code', 'CHECKED_IN');
        $this->actingAs($owner)->deleteJson("/api/team/{$staff->id}")->assertOk();

        $event = AttendanceEvent::where('person_id', $member->id)->sole();
        $this->assertNull($event->performed_by_user_id);
        $this->assertSame('Front Desk Ana', $event->meta['performed_by_name']);
    }

    public function test_fitness_sign_up_uses_member_plan_class_labels_and_new_plans_default_to_open_hours(): void
    {
        $this->get('/register')->assertOk()->assertSee('Fitness / Gym')->assertDontSee('Sports Academy');

        $this->postJson('/api/auth/register', [
            'name' => 'Gym Owner', 'username' => 'gym.owner', 'organization_name' => 'Iron Studio', 'organization_type' => 'fitness',
            'email' => 'gym@example.test', 'contact_number' => '09171234567', 'password' => 'password', 'password_confirmation' => 'password',
        ])->assertCreated();
        $owner = User::where('username', 'gym.owner')->sole();
        $owner->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($owner)->getJson('/api/organization/terminology')->assertOk()
            ->assertJsonPath('terminology.person_singular', 'Member')->assertJsonPath('terminology.group_singular', 'Plan')->assertJsonPath('terminology.subgroup_singular', 'Class');

        $this->actingAs($owner)->postJson('/api/groups', ['name' => 'Monthly'])->assertCreated()->assertJsonPath('group.attendance_mode', 'open');
        $this->actingAs($owner)->postJson('/api/groups', ['name' => 'Kids Class', 'attendance_mode' => 'scheduled'])->assertCreated()->assertJsonPath('group.attendance_mode', 'scheduled');
        $csv = "person_number,first_name,last_name,group\nM1,Rita,Cruz,Annual\n";
        $this->actingAs($owner)->post('/api/people/import', ['file' => UploadedFile::fake()->createWithContent('people.csv', $csv)])->assertOk();
        $this->assertSame('open', $owner->organization->groups()->where('name', 'Annual')->value('attendance_mode'));

        [$school] = $this->tenant('school', 'school@test.test');
        $this->actingAs($school)->postJson('/api/groups', ['name' => 'Grade 1'])->assertCreated()->assertJsonPath('group.attendance_mode', 'scheduled');
    }

    public function test_existing_sports_academies_keep_their_type_while_it_is_hidden_from_new_sign_ups(): void
    {
        [$owner, $organization] = $this->tenant('sports_academy');

        $this->actingAs($owner)->get('/settings/terminology')->assertOk()->assertSee('Sports Academy');
        $this->actingAs($owner)->getJson('/api/organization/terminology')->assertJsonPath('terminology.person_singular', 'Athlete');
        $this->assertSame('sports_academy', $organization->fresh()->type);
    }

    public function test_the_people_list_and_csv_can_show_expiring_and_expired_members(): void
    {
        [$owner, $organization] = $this->tenant('fitness');
        $plan = $this->group($organization, 'Monthly', Group::MODE_OPEN);
        $this->person($organization, $plan, 'EXP', null, '2026-11-19');
        $this->person($organization, $plan, 'D0', null, '2026-11-20');
        $this->person($organization, $plan, 'D7', null, '2026-11-27');
        $this->person($organization, $plan, 'D14', null, '2026-12-04');
        $this->person($organization, $plan, 'D30', null, '2026-12-20');
        $this->person($organization, $plan, 'NONE');
        $this->person($organization, $plan, 'ARCH', null, '2026-11-01')->update(['is_active' => false]);
        Carbon::setTestNow(Carbon::parse('2026-11-20 09:00', 'Asia/Manila'));

        $numbers = fn (string $filter): array => array_column($this->actingAs($owner)->getJson("/api/people?membership={$filter}")->assertOk()->json('data'), 'person_number');
        $this->assertSame(['EXP'], $numbers('expired'), 'Archived people are not listed for renewal.');
        $this->assertSame(['D0', 'D7'], $numbers('expiring_7'));
        $this->assertSame(['D0', 'D7', 'D14'], $numbers('expiring_14'));
        $this->assertCount(7, $this->actingAs($owner)->getJson('/api/people')->json('data'));
        $this->actingAs($owner)->getJson('/api/people?membership=soon')->assertUnprocessable();

        $response = $this->actingAs($owner)->get('/api/people/export?membership=expired')->assertOk();
        $this->assertStringContainsString('persons-expired-2026-11-20.csv', $response->headers->get('Content-Disposition'));
        $lines = array_filter(explode("\n", $response->streamedContent()));
        $this->assertCount(2, $lines);
        $this->assertStringStartsWith('EXP,', $lines[1]);
    }

    public function test_turned_away_scans_are_logged_for_the_daily_log_but_never_count_as_attendance(): void
    {
        [$owner, $organization] = $this->tenant('fitness');
        $plan = $this->group($organization, 'Monthly', Group::MODE_OPEN);
        $expired = $this->person($organization, $plan, 'M1', null, '2026-11-19');
        $archived = $this->person($organization, $plan, 'M2');
        $archived->update(['is_active' => false]);

        $this->scanAt($owner, $expired, '2026-11-20 07:00')->assertJsonPath('code', 'MEMBERSHIP_EXPIRED');
        $this->scanAt($owner, $expired, '2026-11-20 07:00:30')->assertJsonPath('code', 'MEMBERSHIP_EXPIRED');
        $this->scanAt($owner, $archived, '2026-11-20 07:05')->assertJsonPath('code', 'PERSON_INACTIVE');
        $this->assertSame(1, AttendanceEvent::where('person_id', $expired->id)->where('event_type', 'refused')->count(), 'A repeat within a minute is not saved twice.');

        Carbon::setTestNow(Carbon::parse('2026-11-20 20:00', 'Asia/Manila'));
        $daily = $this->actingAs($owner)->getJson('/api/attendance/report?date=2026-11-20')->assertOk()->json();
        $this->assertSame([], $daily['people']);
        $this->assertSame(0, $daily['summary']['present']);
        $this->assertSame([['M2', 'PERSON_INACTIVE', '7:05 AM'], ['M1', 'MEMBERSHIP_EXPIRED', '7:00 AM']], array_map(fn (array $row): array => [$row['person']['person_number'], $row['reason'], $row['time']], $daily['refused']));
        $this->assertSame('2026-11-19', $daily['refused'][1]['membership_valid_until']);
        $this->assertSame([], $this->actingAs($owner)->getJson('/api/attendance/report?date=2026-11-20&search=nobody')->json('refused'));

        $monthly = $this->actingAs($owner)->getJson("/api/attendance/monthly?month=2026-11&group_id={$plan->id}")->assertOk()->json();
        $this->assertSame(0, collect($monthly['persons'])->firstWhere('person_number', 'M1')['total_visits']);
        $history = $this->actingAs($owner)->getJson("/api/people/{$expired->id}/attendance?from=2026-11-01&to=2026-11-20")->assertOk()->json();
        $this->assertNull($history['summary']['last_seen'] ?? null);
        $this->assertFalse($plan->fresh()->hasAttendanceEvents(), 'Turned-away scans do not lock the check-in mode.');
    }

    private function scanAt(User $user, Person $person, string $localTime)
    {
        Carbon::setTestNow(Carbon::parse($localTime, 'Asia/Manila'));

        return $this->actingAs($user)->postJson('/api/attendance/scan', ['qr_payload' => $person->qr_token])->assertOk();
    }

    /** @return array{0: User, 1: Organization} */
    private function tenant(string $type, string $email = 'owner@readiness.test'): array
    {
        $organization = Organization::create(['name' => 'Readiness '.$type, 'type' => $type, 'timezone' => 'Asia/Manila']);
        $user = User::create(['organization_id' => $organization->id, 'name' => 'Owner', 'email' => $email, 'password' => 'password', 'email_verified_at' => now(), 'role' => 'owner']);

        return [$user, $organization];
    }

    private function group(Organization $organization, string $name, string $mode = Group::MODE_SCHEDULED): Group
    {
        return Group::create(['organization_id' => $organization->id, 'name' => $name, 'attendance_mode' => $mode]);
    }

    private function person(Organization $organization, Group $group, string $number, ?Subgroup $subgroup = null, ?string $validUntil = null): Person
    {
        $person = Person::create(['organization_id' => $organization->id, 'person_number' => $number, 'first_name' => 'Member', 'last_name' => $number, 'group_id' => $group->id, 'subgroup_id' => $subgroup?->id, 'membership_valid_until' => $validUntil]);
        $person->forceFill(['created_at' => '2026-10-01 00:00:00'])->save();

        return $person->fresh();
    }
}

<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Organization;
use App\Models\Person;
use App\Models\Schedule;
use App\Models\Subgroup;
use App\Models\User;
use App\Services\ScheduleResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class PhaseTwoTest extends TestCase
{
    use RefreshDatabase;

    public function test_groups_and_subgroups_are_managed_with_safe_deletion(): void
    {
        [$user, $organization] = $this->tenant('A');
        $this->actingAs($user)->postJson('/api/groups', ['name' => 'Operations'])->assertCreated();
        $group = $organization->groups()->first();
        $this->actingAs($user)->postJson('/api/subgroups', ['name' => 'Team A', 'group_id' => $group->id])->assertCreated();
        $this->actingAs($user)->getJson('/api/groups?search=Oper')->assertOk()->assertJsonPath('data.0.name', 'Operations');
        $this->actingAs($user)->deleteJson("/api/groups/{$group->id}")->assertStatus(409);
        $this->actingAs($user)->putJson("/api/subgroups/{$group->subgroups()->first()->id}", ['name' => 'Team B', 'group_id' => $group->id])->assertOk()->assertJsonPath('subgroup.name', 'Team B');
    }

    public function test_schedules_validate_relationships_and_resolve_override_then_fallback(): void
    {
        [$user, $organization] = $this->tenant('A');
        $group = Group::create(['organization_id' => $organization->id, 'name' => 'Grade 6']);
        $subgroup = Subgroup::create(['organization_id' => $organization->id, 'group_id' => $group->id, 'name' => 'Section A']);
        $person = Person::create(['organization_id' => $organization->id, 'person_number' => '000123', 'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'group_id' => $group->id, 'subgroup_id' => $subgroup->id]);
        $this->actingAs($user)->postJson('/api/schedules', ['name' => 'Grade 6 Monday', 'group_id' => $group->id, 'day_of_week' => 1, 'start_time' => '07:30', 'late_after' => '07:45'])->assertCreated();
        $this->actingAs($user)->postJson('/api/schedules', ['name' => 'Bad', 'group_id' => $group->id, 'subgroup_id' => $subgroup->id, 'day_of_week' => 2, 'start_time' => '08:00', 'late_after' => '07:00'])->assertUnprocessable();
        $this->assertSame('Grade 6 Monday', app(ScheduleResolver::class)->resolve($person, Carbon::parse('2026-09-14'))->name);
        $override = Schedule::create(['organization_id' => $organization->id, 'group_id' => $group->id, 'subgroup_id' => $subgroup->id, 'name' => 'Section A Monday', 'day_of_week' => 1, 'start_time' => '08:00', 'late_after' => '08:15']);
        $this->assertSame($override->id, app(ScheduleResolver::class)->resolve($person->fresh(), Carbon::parse('2026-09-14'))->id);
        $override->update(['is_active' => false]);
        $this->assertSame('Grade 6 Monday', app(ScheduleResolver::class)->resolve($person->fresh(), Carbon::parse('2026-09-14'))->name);
        $this->assertNull(app(ScheduleResolver::class)->resolve($person, Carbon::parse('2026-09-15')));
    }

    public function test_schedule_can_be_created_for_multiple_named_days(): void
    {
        [$user, $organization] = $this->tenant('Multi Day');
        $group = Group::create(['organization_id' => $organization->id, 'name' => 'Grade 6']);

        $response = $this->actingAs($user)->postJson('/api/schedules', [
            'name' => 'Grade 6 Weekdays',
            'group_id' => $group->id,
            'days' => [1, 2, 3, 4, 5],
            'start_time' => '07:00',
            'late_after' => '07:15',
            'periods' => [
                ['start_time' => '07:00', 'late_after' => '07:15', 'end_time' => '12:00'],
                ['start_time' => '13:00', 'late_after' => '13:15', 'end_time' => '17:00'],
            ],
        ]);

        $response->assertCreated()->assertJsonCount(5, 'schedules');
        $this->assertSame([1, 2, 3, 4, 5], $organization->schedules()->orderBy('day_of_week')->pluck('day_of_week')->all());
        $this->assertCount(2, $organization->schedules()->firstOrFail()->periods);
    }

    public function test_people_preserve_string_numbers_validate_relationships_and_manage_qr(): void
    {
        [$user, $organization] = $this->tenant('A');
        $group = Group::create(['organization_id' => $organization->id, 'name' => 'Grade 6']);
        $subgroup = Subgroup::create(['organization_id' => $organization->id, 'group_id' => $group->id, 'name' => 'Section A']);
        $otherGroup = Group::create(['organization_id' => $organization->id, 'name' => 'Grade 7']);
        $response = $this->actingAs($user)->postJson('/api/people', ['person_number' => '000123', 'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'group_id' => $group->id, 'subgroup_id' => $subgroup->id]);
        $response->assertCreated()->assertJsonPath('person.person_number', '000123');
        $person = Person::firstOrFail();
        $oldToken = $person->qr_token;
        $this->actingAs($user)->postJson('/api/people', ['person_number' => 'EMP-001', 'first_name' => 'Bad', 'last_name' => 'Pair', 'group_id' => $otherGroup->id, 'subgroup_id' => $subgroup->id])->assertUnprocessable();
        $this->actingAs($user)->getJson("/api/people/{$person->id}/qr")->assertOk()->assertJsonPath('qr_payload', $oldToken);
        $this->actingAs($user)->getJson("/api/people/{$person->id}")->assertOk()->assertJsonMissingPath('person.qr_token');
        $this->actingAs($user)->postJson("/api/people/{$person->id}/regenerate-qr")->assertOk();
        $this->assertNotSame($oldToken, $person->fresh()->qr_token);
        $this->actingAs($user)->postJson("/api/people/{$person->id}/archive")->assertOk()->assertJsonPath('person.is_active', false);
    }

    public function test_csv_import_reuses_case_insensitive_groups_and_reports_duplicates_and_errors(): void
    {
        [$user, $organization] = $this->tenant('A');
        $group = Group::create(['organization_id' => $organization->id, 'name' => 'Grade 6']);
        Subgroup::create(['organization_id' => $organization->id, 'group_id' => $group->id, 'name' => 'Section A']);
        Person::create(['organization_id' => $organization->id, 'person_number' => '000123', 'first_name' => 'Existing', 'last_name' => 'Person']);
        $csv = "person_number,first_name,last_name,group,subgroup,contact_number\n000123,Duplicate,Person,grade 6,section a,0917\n000124,Maria,Santos,GRADE 6,SECTION A,0918\nEMP-001,Pedro,Reyes,Operations,Warehouse Team,0919\n,Missing,Number,Grade 6,Section A,0920\n";
        $response = $this->actingAs($user)->post('/api/people/import', ['file' => UploadedFile::fake()->createWithContent('people.csv', $csv)]);
        $response->assertOk()->assertJsonPath('imported', 2)->assertJsonPath('skipped_duplicates', 1)->assertJsonPath('failed', 1);
        $this->assertSame('000124', Person::where('first_name', 'Maria')->firstOrFail()->person_number);
        $this->assertSame(2, $organization->groups()->count());
        $this->assertSame(2, $organization->subgroups()->count());
    }

    public function test_csv_import_accepts_optional_qr_tokens_and_generates_missing_tokens(): void
    {
        [$user, $organization] = $this->tenant('QR CSV');
        $csv = "person_number,first_name,last_name,qr_token\n000123,Maria,Santos,imported-token-123\n000124,Pedro,Reyes,\n000125,Juan,Dela Cruz\n";

        $response = $this->actingAs($user)->post('/api/people/import', [
            'file' => UploadedFile::fake()->createWithContent('people.csv', $csv),
        ]);

        $response->assertOk()->assertJsonPath('imported', 3)->assertJsonPath('failed', 0);
        $this->assertSame('imported-token-123', $organization->persons()->where('person_number', '000123')->value('qr_token'));
        $this->assertNotSame('', $organization->persons()->where('person_number', '000124')->value('qr_token'));
        $this->assertSame(64, strlen($organization->persons()->where('person_number', '000125')->value('qr_token')));
    }

    public function test_csv_import_rejects_duplicate_qr_tokens_without_exposing_database_errors(): void
    {
        [$user, $organization] = $this->tenant('Duplicate QR CSV');
        Person::create(['organization_id' => $organization->id, 'person_number' => 'EXISTING', 'first_name' => 'Existing', 'last_name' => 'Person', 'qr_token' => 'already-used']);
        $csv = "person_number,first_name,last_name,qr_token\n000123,Maria,Santos,already-used\n";

        $response = $this->actingAs($user)->post('/api/people/import', [
            'file' => UploadedFile::fake()->createWithContent('people.csv', $csv),
        ]);

        $response->assertOk()->assertJsonPath('imported', 0)->assertJsonPath('failed', 1)
            ->assertJsonPath('errors.0.message', 'The row contains invalid person data.')
            ->assertJsonMissing(['SQLSTATE']);
    }

    public function test_csv_import_can_upsert_existing_people_and_preserves_omitted_optional_fields(): void
    {
        [$user, $organization] = $this->tenant('Upsert CSV');
        $group = Group::create(['organization_id' => $organization->id, 'name' => 'Grade 6']);
        $person = Person::create([
            'organization_id' => $organization->id,
            'person_number' => '000123',
            'first_name' => 'Old',
            'last_name' => 'Name',
            'group_id' => $group->id,
            'contact_number' => '0917000000',
            'qr_token' => 'existing-token',
        ]);
        $csv = "person_number,first_name,last_name,qr_token\n000123,Updated,Person,new-token\n000124,New,Person,\n";

        $response = $this->actingAs($user)->post('/api/people/import', [
            'file' => UploadedFile::fake()->createWithContent('people.csv', $csv),
            'upsert' => '1',
        ]);

        $response->assertOk()->assertJsonPath('imported', 1)->assertJsonPath('updated', 1)->assertJsonPath('skipped_duplicates', 0);
        $person->refresh();
        $this->assertSame('Updated', $person->first_name);
        $this->assertSame('Person', $person->last_name);
        $this->assertSame('0917000000', $person->contact_number);
        $this->assertSame('new-token', $person->qr_token);
        $this->assertSame(2, $organization->persons()->count());
    }

    public function test_csv_upsert_matches_person_number_only_within_the_authenticated_organization(): void
    {
        [$userA, $organizationA] = $this->tenant('Upsert Tenant A');
        [, $organizationB] = $this->tenant('Upsert Tenant B');
        Person::create([
            'organization_id' => $organizationA->id,
            'person_number' => '000123',
            'first_name' => 'Tenant A',
            'last_name' => 'Original',
        ]);
        $personB = Person::create([
            'organization_id' => $organizationB->id,
            'person_number' => '000123',
            'first_name' => 'Tenant B',
            'last_name' => 'Original',
        ]);
        $csv = "person_number,first_name,last_name\n000123,Tenant A,Updated\n000124,New,Person\n";

        $response = $this->actingAs($userA)->post('/api/people/import', [
            'file' => UploadedFile::fake()->createWithContent('people.csv', $csv),
            'upsert' => '1',
        ]);

        $response->assertOk()->assertJsonPath('imported', 1)->assertJsonPath('updated', 1);
        $this->assertSame('Updated', $organizationA->persons()->where('person_number', '000123')->value('last_name'));
        $this->assertSame('Original', $personB->fresh()->last_name);
        $this->assertSame(1, $organizationB->persons()->count());
    }

    public function test_person_csv_export_is_import_ready_and_tenant_scoped(): void
    {
        [$user, $organization] = $this->tenant('Export A');
        [, $organizationB] = $this->tenant('Export B');
        $group = Group::create(['organization_id' => $organization->id, 'name' => 'Grade 6']);
        $subgroup = Subgroup::create(['organization_id' => $organization->id, 'group_id' => $group->id, 'name' => 'Section A']);
        Person::create([
            'organization_id' => $organization->id,
            'person_number' => '000123',
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'group_id' => $group->id,
            'subgroup_id' => $subgroup->id,
            'contact_number' => '0917000000',
            'qr_token' => 'tenant-a-qr',
        ]);
        Person::create([
            'organization_id' => $organizationB->id,
            'person_number' => 'B-001',
            'first_name' => 'Private',
            'last_name' => 'Person',
            'qr_token' => 'tenant-b-qr',
        ]);

        $response = $this->actingAs($user)->get('/api/people/export');

        $csv = $response->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('content-disposition', 'attachment; filename=persons.csv')
            ->streamedContent();
        $lines = preg_split('/\r\n|\n|\r/', trim($csv));
        $this->assertSame(['person_number', 'first_name', 'last_name', 'group', 'subgroup', 'contact_number', 'membership_valid_until'], str_getcsv($lines[0]));
        $this->assertSame(['000123', 'Juan', 'Dela Cruz', 'Grade 6', 'Section A', '0917000000', ''], str_getcsv($lines[1]));
        $this->assertStringNotContainsString('tenant-a-qr', $csv);
        $this->assertStringNotContainsString('Private', $csv);
        $this->assertStringNotContainsString('tenant-b-qr', $csv);
    }

    public function test_csv_import_does_not_return_raw_database_errors_for_invalid_rows(): void
    {
        [$user] = $this->tenant('Safe CSV');
        $csv = "person_number,first_name,last_name,contact_number\nA-001,".str_repeat('x', 101).",Person,0917\n";

        $response = $this->actingAs($user)->post('/api/people/import', [
            'file' => UploadedFile::fake()->createWithContent('people.csv', $csv),
        ]);

        $response->assertOk()->assertJsonPath('failed', 1)
            ->assertJsonPath('errors.0.message', 'The row contains invalid person data.')
            ->assertJsonMissing(['SQLSTATE']);
    }

    public function test_phase_two_resources_are_tenant_isolated_and_relationship_ids_are_rejected(): void
    {
        [$userA, $organizationA] = $this->tenant('A');
        [$userB, $organizationB] = $this->tenant('B');
        $groupB = Group::create(['organization_id' => $organizationB->id, 'name' => 'Private']);
        $subgroupB = Subgroup::create(['organization_id' => $organizationB->id, 'group_id' => $groupB->id, 'name' => 'Private Team']);
        $personB = Person::create(['organization_id' => $organizationB->id, 'person_number' => 'B-1', 'first_name' => 'Private', 'last_name' => 'Person', 'group_id' => $groupB->id, 'subgroup_id' => $subgroupB->id]);
        foreach (["/api/groups/{$groupB->id}", "/api/subgroups/{$subgroupB->id}", "/api/people/{$personB->id}"] as $url) {
            $this->actingAs($userA)->getJson($url)->assertNotFound();
        }
        $this->actingAs($userA)->getJson('/api/groups')->assertJsonMissing(['name' => 'Private']);
        $this->actingAs($userA)->getJson('/api/groups/options')->assertOk()->assertJsonMissing(['name' => 'Private']);
        $this->actingAs($userA)->getJson('/api/subgroups/options')->assertOk()->assertJsonMissing(['name' => 'Private Team']);
        $this->actingAs($userA)->putJson("/api/people/{$personB->id}", ['person_number' => 'A-1', 'first_name' => 'No', 'last_name' => 'Access'])->assertNotFound();
        $this->actingAs($userA)->postJson('/api/people', ['person_number' => 'A-1', 'first_name' => 'No', 'last_name' => 'Access', 'group_id' => $groupB->id])->assertNotFound();
        $this->actingAs($userA)->postJson('/api/schedules', ['name' => 'No Access', 'group_id' => $groupB->id, 'day_of_week' => 1, 'start_time' => '07:30', 'late_after' => '07:45'])->assertNotFound();
    }

    private function tenant(string $label): array
    {
        $organization = Organization::create(['name' => "Organization {$label}", 'type' => 'company', 'timezone' => 'Asia/Manila']);
        $user = User::create(['organization_id' => $organization->id, 'name' => "Admin {$label}", 'email' => strtolower("admin{$label}@example.test"), 'password' => 'password', 'email_verified_at' => now(), 'role' => 'owner']);

        return [$user, $organization];
    }
}

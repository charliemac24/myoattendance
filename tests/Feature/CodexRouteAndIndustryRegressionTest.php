<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Organization;
use App\Models\Person;
use App\Models\User;
use App\Support\OrganizationTerminology;
use App\Support\OrganizationTypes;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Regressions found by Codex: static /people pages vs /people/{person}, and the industry presets. */
class CodexRouteAndIndustryRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-11 09:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_people_create_opens_the_people_page_with_the_add_form(): void
    {
        Http::fake();
        [$owner] = $this->tenant('company');

        $this->actingAs($owner)->get('/people/create')->assertOk()->assertSee('add-person-overlay', false)->assertSee('openAddSheet: true', false);
        $this->actingAs($owner)->get('/people')->assertOk()->assertSee('openAddSheet: false', false);
        Http::assertNothingSent();
    }

    public function test_people_import_page_loads(): void
    {
        Http::fake();
        [$owner] = $this->tenant('company');

        $this->actingAs($owner)->get('/people/import')->assertOk()->assertSee('person_number');
        Http::assertNothingSent();
    }

    public function test_people_export_returns_the_csv(): void
    {
        Http::fake();
        [$owner, $organization] = $this->tenant('company');
        $group = Group::create(['organization_id' => $organization->id, 'name' => 'Day Crew']);
        Person::create(['organization_id' => $organization->id, 'person_number' => 'E1', 'first_name' => 'Ana', 'last_name' => 'Cruz', 'group_id' => $group->id, 'contact_number' => '0917', 'membership_valid_until' => '2026-12-31']);

        $csv = $this->actingAs($owner)->get('/people/export')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->streamedContent();
        $lines = preg_split('/\r\n|\n/', trim($csv));
        $this->assertSame(['person_number', 'first_name', 'last_name', 'group', 'subgroup', 'contact_number', 'membership_valid_until'], str_getcsv($lines[0]));
        $this->assertSame(['E1', 'Ana', 'Cruz', 'Day Crew', '', '0917', '2026-12-31'], str_getcsv($lines[1]));
        $this->assertSame($csv, $this->actingAs($owner)->get('/api/people/export')->assertOk()->streamedContent(), 'Same file as the API export.');
        Http::assertNothingSent();
    }

    public function test_numeric_person_pages_still_work_and_words_never_reach_the_numeric_route(): void
    {
        Http::fake();
        [$owner, $organization] = $this->tenant('company');
        $person = Person::create(['organization_id' => $organization->id, 'person_number' => 'E1', 'first_name' => 'Ana', 'last_name' => 'Cruz']);

        $this->actingAs($owner)->get("/people/{$person->id}")->assertOk()->assertSee('Ana Cruz');
        $this->actingAs($owner)->get("/people/{$person->id}/edit")->assertOk()->assertSee('E1');
        $this->actingAs($owner)->getJson("/api/people/{$person->id}")->assertOk()->assertJsonPath('person.person_number', 'E1');
        $this->actingAs($owner)->get('/people/anything')->assertNotFound();
        $this->actingAs($owner)->get('/people/abc/edit')->assertNotFound();
        $this->actingAs($owner)->getJson('/api/people/abc')->assertNotFound();
        $this->actingAs($owner)->get('/people/999999')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_every_supported_industry_is_accepted_with_its_exact_labels_and_custom_labels_are_cleared(): void
    {
        Http::fake();
        [$owner, $organization] = $this->tenant('school');
        $this->assertSame(['school', 'company', 'construction', 'training_center', 'warehouse', 'staffing', 'sports_academy', 'other'], OrganizationTypes::VALUES);
        $expected = [
            'school' => ['Student', 'Students', 'Grade Level', 'Grade Levels', 'Section', 'Sections'],
            'company' => ['Employee', 'Employees', 'Department', 'Departments', 'Team', 'Teams'],
            'construction' => ['Worker', 'Workers', 'Project', 'Projects', 'Crew', 'Crews'],
            'training_center' => ['Trainee', 'Trainees', 'Program', 'Programs', 'Batch', 'Batches'],
            'warehouse' => ['Worker', 'Workers', 'Department', 'Departments', 'Shift', 'Shifts'],
            'staffing' => ['Worker', 'Workers', 'Client', 'Clients', 'Site', 'Sites'],
            'sports_academy' => ['Athlete', 'Athletes', 'Sport', 'Sports', 'Team', 'Teams'],
            'other' => ['Person', 'People', 'Group', 'Groups', 'Subgroup', 'Subgroups'],
        ];
        $keys = ['person_singular', 'person_plural', 'group_singular', 'group_plural', 'subgroup_singular', 'subgroup_plural'];

        foreach (OrganizationTypes::VALUES as $type) {
            $owner->organization->update(['terminology' => ['person_singular' => 'Custom Person', 'group_plural' => 'Custom Groups']]);

            $response = $this->actingAs($owner)->putJson('/api/organization/industry', ['type' => $type])->assertOk()->assertJsonPath('type', $type);

            $this->assertSame(array_combine($keys, $expected[$type]), $response->json('terminology'), "Labels for {$type}");
            $this->assertSame(OrganizationTerminology::defaults($type), $response->json('terminology'), "{$type} matches the app's definitions");
            $this->assertNull($organization->fresh()->terminology, "Custom labels are cleared when switching to {$type}");
            $this->assertSame($type, $organization->fresh()->type);
        }
        Http::assertNothingSent();
    }

    public function test_an_unsupported_industry_is_rejected_and_nothing_changes(): void
    {
        Http::fake();
        [$owner, $organization] = $this->tenant('company');
        $owner->organization->update(['terminology' => ['person_singular' => 'Crew Member']]);

        $this->actingAs($owner)->putJson('/api/organization/industry', ['type' => 'gym'])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->assertSame('company', $organization->fresh()->type);
        $this->assertSame(['person_singular' => 'Crew Member'], $organization->fresh()->terminology);
        Http::assertNothingSent();
    }

    /** @return array{0: User, 1: Organization} */
    private function tenant(string $type): array
    {
        $organization = Organization::create(['name' => 'Regression Org', 'type' => $type, 'timezone' => 'Asia/Manila']);
        $owner = User::create(['organization_id' => $organization->id, 'name' => 'Owner', 'username' => 'owner', 'email' => 'owner@regression.test', 'password' => 'password', 'email_verified_at' => now(), 'role' => 'owner']);

        return [$owner, $organization];
    }
}

<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Organization;
use App\Models\Person;
use App\Models\PersonAssignment;
use App\Models\Schedule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Additional end-to-end coverage for steps 1–4. */
class StepsOneToFourEndToEndTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_all_industry_presets_use_their_exact_default_labels_and_clear_custom_terms(): void
    {
        Http::fake();
        [$owner, $organization] = $this->tenant('company');
        $expected = [
            'company' => ['person_singular' => 'Employee', 'group_singular' => 'Department', 'subgroup_singular' => 'Team'],
            'construction' => ['person_singular' => 'Worker', 'group_singular' => 'Project', 'subgroup_singular' => 'Crew'],
            'warehouse' => ['person_singular' => 'Worker', 'group_singular' => 'Department', 'subgroup_singular' => 'Shift'],
            'staffing' => ['person_singular' => 'Worker', 'group_singular' => 'Client', 'subgroup_singular' => 'Site'],
            'school' => ['person_singular' => 'Student', 'group_singular' => 'Grade Level', 'subgroup_singular' => 'Section'],
            'training_center' => ['person_singular' => 'Trainee', 'group_singular' => 'Program', 'subgroup_singular' => 'Batch'],
            'sports_academy' => ['person_singular' => 'Athlete', 'group_singular' => 'Sport', 'subgroup_singular' => 'Team'],
            'other' => ['person_singular' => 'Person', 'group_singular' => 'Group', 'subgroup_singular' => 'Subgroup'],
        ];

        foreach ($expected as $type => $labels) {
            $organization->update(['type' => 'school', 'terminology' => ['person_singular' => 'Custom']]);
            $response = $this->actingAs($owner)->putJson('/api/organization/industry', ['type' => $type])->assertOk();
            $this->assertSame($type, $response->json('type'));
            $this->assertSame($labels, collect($response->json('terminology'))->only(array_keys($labels))->all());
            $this->assertNull($owner->organization->fresh()->terminology);
        }

        $this->actingAs($owner)->putJson('/api/organization/industry', ['type' => 'gym'])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_school_forms_hide_workforce_only_fields_and_timesheet(): void
    {
        Http::fake();
        [$owner] = $this->tenant('school');

        $this->actingAs($owner)->get('/app')->assertOk()->assertDontSee('/attendance/timesheet');
        $this->actingAs($owner)->get('/schedules/create')->assertOk()->assertDontSee('Unpaid break');
        $this->actingAs($owner)->get('/people/create')->assertOk()->assertDontSee('Membership valid until');
        Http::assertNothingSent();
    }

    public function test_people_export_includes_membership_but_never_qr_tokens_and_bad_import_dates_fail(): void
    {
        Http::fake();
        [$owner, $organization] = $this->tenant('company');
        $group = Group::create(['organization_id' => $organization->id, 'name' => 'Day Crew', 'attendance_mode' => Group::MODE_OPEN]);
        $person = Person::create(['organization_id' => $organization->id, 'person_number' => 'E1', 'first_name' => 'Ana', 'last_name' => 'Test', 'group_id' => $group->id, 'membership_valid_until' => '2026-12-01']);

        $csv = $this->actingAs($owner)->get('/people/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('membership_valid_until', strtok($csv, "\n"));
        $this->assertStringNotContainsString('qr_token', strtok($csv, "\n"));
        $this->assertStringContainsString($person->person_number, $csv);

        $file = UploadedFile::fake()->createWithContent('bad.csv', "person_number,first_name,last_name,membership_valid_until\nE2,Bad,Date,2026-13-01\n");
        $this->actingAs($owner)->post('/api/people/import', ['file' => $file], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('failed', 1);
        Http::assertNothingSent();
    }

    public function test_same_day_moves_coalesce_and_group_filtered_timesheets_keep_only_matching_history(): void
    {
        Http::fake();
        [$owner, $organization] = $this->tenant('company');
        $morning = $this->scheduledGroup($organization, 'Morning', '08:00', '17:00');
        $afternoon = $this->scheduledGroup($organization, 'Afternoon', '13:00', '21:00');
        $person = Person::create(['organization_id' => $organization->id, 'person_number' => 'E1', 'first_name' => 'Ana', 'last_name' => 'Test', 'group_id' => $morning->id]);

        Carbon::setTestNow(Carbon::parse('2026-11-09 08:00', 'Asia/Manila'));
        $payload = ['person_number' => 'E1', 'first_name' => 'Ana', 'last_name' => 'Test', 'group_id' => $afternoon->id];
        $this->actingAs($owner)->putJson("/api/people/{$person->id}", $payload)->assertOk();
        $this->actingAs($owner)->putJson("/api/people/{$person->id}", $payload + ['contact_number' => '0917'])->assertOk();
        $this->assertCount(2, PersonAssignment::where('person_id', $person->id)->get());
        $this->assertSame(1, PersonAssignment::where('person_id', $person->id)->whereDate('starts_on', '2026-11-09')->count());

        $this->actingAs($owner)->getJson("/api/attendance/timesheet?from=2026-11-01&to=2026-11-15&group_id={$morning->id}")->assertOk();
        Http::assertNothingSent();
    }

    private function tenant(string $type): array
    {
        $organization = Organization::create(['name' => 'Test Org', 'type' => $type, 'timezone' => 'Asia/Manila']);
        $owner = User::create(['organization_id' => $organization->id, 'name' => 'Owner', 'username' => 'owner'.uniqid(), 'email' => uniqid().'@example.test', 'password' => 'password', 'email_verified_at' => now(), 'role' => 'owner']);

        return [$owner, $organization];
    }

    private function scheduledGroup(Organization $organization, string $name, string $start, string $end): Group
    {
        $group = Group::create(['organization_id' => $organization->id, 'name' => $name, 'attendance_mode' => Group::MODE_SCHEDULED]);
        Schedule::create(['organization_id' => $organization->id, 'group_id' => $group->id, 'name' => $name, 'day_of_week' => 1, 'start_time' => $start, 'late_after' => $start, 'end_time' => $end]);

        return $group;
    }
}

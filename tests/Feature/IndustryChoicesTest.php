<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationTerminology;
use App\Support\OrganizationTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * While the focus is School and Fitness / Gym, no other industry is offered for new choices,
 * but organizations already on a hidden industry keep working and still see it.
 */
class IndustryChoicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_school_and_gym_are_offered_for_new_choices(): void
    {
        $this->assertSame(['school', 'fitness'], array_keys(OrganizationTypes::selectableLabels()));
    }

    public function test_register_page_lists_only_school_and_gym(): void
    {
        $this->get('/register')->assertOk()
            ->assertSee('School / Education')->assertSee('Fitness / Gym')
            ->assertDontSee('Company')->assertDontSee('Sports Academy');
    }

    public function test_existing_company_organization_keeps_its_type_in_settings(): void
    {
        $organization = Organization::create(['name' => 'Old Co', 'type' => 'company', 'timezone' => 'Asia/Manila']);
        $owner = User::create(['organization_id' => $organization->id, 'name' => 'Owner', 'email' => 'owner@oldco.test', 'password' => 'password', 'email_verified_at' => now(), 'role' => 'owner']);

        $this->assertArrayHasKey('company', OrganizationTypes::selectableLabels('company'));
        $this->actingAs($owner)->get('/settings/terminology')->assertOk()->assertSee('Company');
        $this->assertSame('company', $organization->fresh()->type);
    }

    public function test_labels_follow_the_industry_when_it_changes_and_only_real_custom_labels_are_kept(): void
    {
        $organization = Organization::create(['name' => 'Switcher', 'type' => 'school', 'timezone' => 'Asia/Manila']);
        $owner = User::create(['organization_id' => $organization->id, 'name' => 'Owner', 'email' => 'owner@switch.test', 'password' => 'password', 'email_verified_at' => now(), 'role' => 'owner']);

        // Saving the form untouched must not freeze the school words.
        $this->actingAs($owner)->putJson('/api/organization/terminology', OrganizationTerminology::defaults('school'))->assertOk();
        $this->assertNull($organization->fresh()->terminology);

        $this->actingAs($owner)->putJson('/api/organization/industry', ['type' => 'fitness'])->assertOk();
        $this->actingAs($owner)->get('/people')->assertOk()->assertSee('Members')->assertDontSee('Students');
        $this->actingAs($owner)->get('/groups')->assertOk()->assertSee('Plans')->assertDontSee('Grade Levels');
        $this->actingAs($owner)->get('/subgroups')->assertOk()->assertSee('Classes')->assertDontSee('Sections');

        // A real custom label is kept, the rest still follow the industry.
        $words = OrganizationTerminology::defaults('fitness');
        $words['person_plural'] = 'Clients';
        $this->actingAs($owner)->putJson('/api/organization/terminology', $words)->assertOk();
        $this->assertSame(['person_plural' => 'Clients'], $organization->fresh()->terminology);
    }

    public function test_switching_gym_back_to_school_shows_school_labels_even_if_an_old_page_is_saved(): void
    {
        $organization = Organization::create(['name' => 'Back to school', 'type' => 'fitness', 'timezone' => 'Asia/Manila']);
        $owner = User::create(['organization_id' => $organization->id, 'name' => 'Owner', 'email' => 'owner@back.test', 'password' => 'password', 'email_verified_at' => now(), 'role' => 'owner']);
        $gymWords = OrganizationTerminology::defaults('fitness');

        $this->actingAs($owner)->putJson('/api/organization/industry', ['type' => 'school'])->assertOk()
            ->assertJsonPath('terminology.person_plural', 'Students');

        // A Labels page still open from before the switch can't pin the gym words.
        $this->actingAs($owner)->putJson('/api/organization/terminology', $gymWords + ['type' => 'fitness', 'edited' => []])->assertStatus(409);
        // Boxes that still hold old words but weren't typed into are not saved.
        $this->actingAs($owner)->putJson('/api/organization/terminology', $gymWords + ['type' => 'school', 'edited' => []])->assertOk();
        $this->assertNull($organization->fresh()->terminology);
        $this->actingAs($owner)->get('/people')->assertOk()->assertSee('Students')->assertDontSee('Members');
        $this->actingAs($owner)->get('/settings/terminology')->assertOk()->assertSee('value="Students"', false)->assertSee('value="Grade Levels"', false)->assertSee('value="Sections"', false);

        // A label the owner really typed is kept; the rest stay the school words.
        $this->actingAs($owner)->putJson('/api/organization/terminology', $gymWords + ['type' => 'school', 'edited' => ['subgroup_singular', 'subgroup_plural']])->assertOk()
            ->assertJsonPath('terminology.person_plural', 'Students')
            ->assertJsonPath('terminology.subgroup_plural', 'Classes');
        $this->assertSame(['subgroup_singular' => 'Class', 'subgroup_plural' => 'Classes'], $organization->fresh()->terminology);
    }

    public function test_labels_left_over_from_another_industry_are_cleared(): void
    {
        $stale = Organization::create(['name' => 'Stale', 'type' => 'school', 'timezone' => 'Asia/Manila', 'terminology' => OrganizationTerminology::defaults('fitness')]);
        $custom = Organization::create(['name' => 'Custom', 'type' => 'school', 'timezone' => 'Asia/Manila', 'terminology' => ['subgroup_singular' => 'Class', 'subgroup_plural' => 'Classes']]);

        (require database_path('migrations/2026_11_24_000002_clear_labels_left_over_from_another_industry.php'))->up();

        $this->assertNull($stale->fresh()->terminology);
        $this->assertSame(['subgroup_singular' => 'Class', 'subgroup_plural' => 'Classes'], $custom->fresh()->terminology);
    }

    public function test_add_plan_and_add_class_pages_show_gym_examples_for_a_gym_and_school_examples_otherwise(): void
    {
        foreach (['fitness' => ['Monthly Unlimited', 'Morning Yoga'], 'school' => ['Grade 9', 'Section A']] as $type => [$groupExample, $subgroupExample]) {
            $organization = Organization::create(['name' => 'Example '.$type, 'type' => $type, 'timezone' => 'Asia/Manila']);
            $owner = User::create(['organization_id' => $organization->id, 'name' => 'Owner', 'email' => $type.'@example.test', 'password' => 'password', 'email_verified_at' => now(), 'role' => 'owner']);

            $this->actingAs($owner)->get('/groups/create')->assertOk()->assertSee($groupExample);
            $this->actingAs($owner)->get('/subgroups/create')->assertOk()->assertSee($subgroupExample);
        }
    }

    public function test_schedule_pages_explain_that_open_hours_groups_do_not_use_schedules(): void
    {
        $organization = Organization::create(['name' => 'Gym', 'type' => 'fitness', 'timezone' => 'Asia/Manila']);
        $owner = User::create(['organization_id' => $organization->id, 'name' => 'Owner', 'email' => 'gym@schedules.test', 'password' => 'password', 'email_verified_at' => now(), 'role' => 'owner']);
        $organization->groups()->create(['name' => 'Monthly Unlimited', 'attendance_mode' => 'open']);

        $this->actingAs($owner)->get('/schedules')->assertOk()->assertSee('do not use schedules');
        $this->actingAs($owner)->get('/schedules/create')->assertOk()->assertSee('do not use schedules');
        // The picker on Add Schedule hides these groups by reading their check-in mode from this list.
        $this->actingAs($owner)->getJson('/api/groups?per_page=100')->assertOk()->assertJsonPath('data.0.attendance_mode', 'open');
    }
}

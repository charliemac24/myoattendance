<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
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
}

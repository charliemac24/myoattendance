<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PeopleDetailsModalTest extends TestCase
{
    use RefreshDatabase;

    public function test_people_list_includes_the_details_modal_so_viewing_a_person_stays_on_the_list(): void
    {
        $user = $this->organizationAdmin();

        $this->actingAs($user)->get('/people?search=cruz&person=1')
            ->assertOk()
            ->assertSee('id="person-details-overlay"', false)
            ->assertSee('id="pd-edit"', false)
            ->assertSee('id="qr-overlay"', false)
            ->assertSee('List</button>', false);
    }

    public function test_details_modal_loads_person_data_only_for_the_admins_organization(): void
    {
        $user = $this->organizationAdmin();
        $person = Person::create(['organization_id' => $user->organization_id, 'person_number' => '000123', 'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'contact_number' => '09170000001']);
        $otherOrganization = Organization::create(['name' => 'Other', 'type' => 'company', 'timezone' => 'Asia/Manila']);
        $otherPerson = Person::create(['organization_id' => $otherOrganization->id, 'person_number' => '000999', 'first_name' => 'Hidden', 'last_name' => 'Person']);

        $this->actingAs($user)->getJson("/api/people/{$person->id}")
            ->assertOk()
            ->assertJsonPath('person.first_name', 'Juan')
            ->assertJsonPath('person.contact_number', '09170000001');
        $this->actingAs($user)->getJson("/api/people/{$otherPerson->id}")->assertNotFound();
    }

    private function organizationAdmin(): User
    {
        $organization = Organization::create(['name' => 'Organization A', 'type' => 'company', 'timezone' => 'Asia/Manila']);

        return User::create(['organization_id' => $organization->id, 'name' => 'Admin A', 'email' => 'admin-a@example.test', 'password' => 'password', 'email_verified_at' => now(), 'role' => 'owner']);
    }
}

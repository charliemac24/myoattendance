<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamAndIndustryTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_adds_a_staff_member_who_signs_in_with_a_username_and_can_only_scan(): void
    {
        [$owner] = $this->tenant();

        $this->actingAs($owner)->postJson('/api/team', ['name' => 'Gate Guard', 'username' => ' Guard1 ', 'password' => 'secret-pass', 'role' => 'staff'])
            ->assertCreated()
            ->assertJsonPath('member.username', 'guard1')
            ->assertJsonPath('member.email', null)
            ->assertJsonPath('member.role_label', 'Staff');
        auth()->logout();

        $this->postJson('/api/auth/login', ['identifier' => 'guard1', 'password' => 'secret-pass'])->assertOk();
        $this->get('/app')->assertRedirect('/scanner');
        $this->get('/attendance')->assertRedirect('/scanner');
        $this->get('/settings/team')->assertRedirect('/scanner');
        $this->get('/scanner')->assertOk()->assertSee('Log Out');
        $this->getJson('/api/people')->assertForbidden();
        $this->getJson('/api/attendance/report?date=2026-11-10')->assertForbidden();
        $this->getJson('/api/team')->assertForbidden();
        $this->postJson('/api/attendance/scan', ['qr_payload' => 'unknown'])->assertOk()->assertJsonPath('code', 'INVALID_QR');
    }

    public function test_only_the_owner_adds_or_changes_admins_and_nobody_changes_the_owner_or_themselves(): void
    {
        [$owner, $organization] = $this->tenant();
        $admin = User::create(['organization_id' => $organization->id, 'name' => 'Office Admin', 'username' => 'office', 'password' => 'password', 'email_verified_at' => now(), 'role' => 'admin']);

        $this->actingAs($admin)->postJson('/api/team', ['name' => 'Second Admin', 'username' => 'admin2', 'password' => 'secret-pass', 'role' => 'admin'])->assertUnprocessable();
        $staffId = $this->actingAs($admin)->postJson('/api/team', ['name' => 'Coach', 'username' => 'coach', 'password' => 'secret-pass', 'role' => 'staff'])->assertCreated()->json('member.id');
        $this->actingAs($admin)->putJson("/api/team/{$owner->id}", ['name' => 'Owner', 'role' => 'staff'])->assertForbidden();
        $this->actingAs($admin)->deleteJson("/api/team/{$admin->id}")->assertForbidden();
        $this->actingAs($admin)->putJson("/api/team/{$staffId}", ['name' => 'Coach Ana', 'role' => 'staff', 'password' => 'new-secret'])->assertOk()->assertJsonPath('member.name', 'Coach Ana');

        $this->actingAs($owner)->putJson("/api/team/{$staffId}", ['name' => 'Coach Ana', 'role' => 'admin'])->assertOk()->assertJsonPath('member.role', 'admin');
        $this->actingAs($admin)->deleteJson("/api/team/{$staffId}")->assertForbidden();
        $this->actingAs($owner)->deleteJson("/api/team/{$staffId}")->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $staffId]);
    }

    public function test_team_members_stay_inside_their_own_organization(): void
    {
        [$owner] = $this->tenant();
        [$otherOwner, $otherOrganization] = $this->tenant('Other');
        $otherStaff = User::create(['organization_id' => $otherOrganization->id, 'name' => 'Other Guard', 'username' => 'otherguard', 'password' => 'password', 'email_verified_at' => now(), 'role' => 'staff']);

        $names = collect($this->actingAs($owner)->getJson('/api/team')->assertOk()->json('members'))->pluck('name');
        $this->assertSame(['Owner Main'], $names->all());
        $this->actingAs($owner)->deleteJson("/api/team/{$otherStaff->id}")->assertNotFound();
    }

    public function test_switching_industry_changes_the_labels_and_workforce_types_have_their_own_words(): void
    {
        [$owner, $organization] = $this->tenant();
        $organization->update(['type' => 'school', 'terminology' => ['person_singular' => 'Learner']]);

        $this->actingAs($owner)->putJson('/api/organization/industry', ['type' => 'staffing'])
            ->assertOk()
            ->assertJsonPath('type', 'staffing')
            ->assertJsonPath('terminology.person_singular', 'Worker')
            ->assertJsonPath('terminology.group_singular', 'Client')
            ->assertJsonPath('terminology.subgroup_singular', 'Site');
        $this->assertNull($organization->fresh()->terminology);

        $this->actingAs($owner)->putJson('/api/organization/industry', ['type' => 'construction'])->assertJsonPath('terminology.group_singular', 'Project');
        $this->actingAs($owner)->putJson('/api/organization/industry', ['type' => 'warehouse'])->assertJsonPath('terminology.subgroup_singular', 'Shift');
        $this->actingAs($owner)->putJson('/api/organization/industry', ['type' => 'gym'])->assertUnprocessable();
    }

    /** @return array{0: User, 1: Organization} */
    private function tenant(string $suffix = 'Main'): array
    {
        $organization = Organization::create(['name' => 'Organization '.$suffix, 'type' => 'school', 'timezone' => 'Asia/Manila']);
        $owner = User::create(['organization_id' => $organization->id, 'name' => 'Owner '.$suffix, 'username' => 'owner'.strtolower($suffix), 'email' => 'owner'.strtolower($suffix).'@example.test', 'password' => 'password', 'email_verified_at' => now(), 'role' => 'owner']);

        return [$owner, $organization];
    }
}

<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationTerminology;
use App\Support\OrganizationTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The side menu, bottom bar and "More" sheet use the organization's own words. */
class SidebarTerminologyTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_industry_shows_its_own_people_group_and_subgroup_words_in_the_menu(): void
    {
        Http::fake();
        foreach (OrganizationTypes::VALUES as $type) {
            [$owner] = $this->tenant($type);
            $terms = OrganizationTerminology::defaults($type);

            $html = $this->actingAs($owner)->get('/people')->assertOk()->getContent();

            foreach (['person_plural', 'group_plural', 'subgroup_plural'] as $key) {
                // Once in the side menu, and once in the bottom bar or the More sheet.
                $this->assertGreaterThanOrEqual(2, substr_count($html, '<span>'.$terms[$key].'</span>'), "{$type}: menu shows {$terms[$key]}");
            }
            $this->assertStringContainsString('<span>Users</span>', $html);
            $this->assertStringNotContainsString('<span>Team</span>', $html, 'Company and Sports Academy already use "Teams", so the sign-in accounts page is "Users".');
            if ($type !== 'other') {
                $this->assertStringNotContainsString('<span>People</span>', $html, "{$type} no longer shows the generic People");
                $this->assertStringNotContainsString('<span>Subgroups</span>', $html);
            }
        }
        Http::assertNothingSent();
    }

    public function test_the_menu_follows_custom_labels_and_changes_when_the_industry_changes(): void
    {
        Http::fake();
        [$owner, $organization] = $this->tenant('school');
        $organization->update(['terminology' => ['person_plural' => 'Learners', 'group_plural' => 'Year Levels']]);

        $html = $this->actingAs($owner)->get('/attendance')->assertOk()->getContent();
        $this->assertStringContainsString('<span>Learners</span>', $html);
        $this->assertStringContainsString('<span>Year Levels</span>', $html);
        $this->assertStringContainsString('<span>Sections</span>', $html, 'Labels left alone keep the industry words.');
        $this->assertStringNotContainsString('<span>Students</span>', $html);

        $this->actingAs($owner)->putJson('/api/organization/industry', ['type' => 'construction'])->assertOk();
        $html = $this->actingAs($owner)->get('/attendance')->assertOk()->getContent();
        $this->assertStringContainsString('<span>Workers</span>', $html);
        $this->assertStringContainsString('<span>Projects</span>', $html);
        $this->assertStringContainsString('<span>Crews</span>', $html);
        $this->assertStringNotContainsString('<span>Learners</span>', $html, 'Switching industry clears custom labels.');
        Http::assertNothingSent();
    }

    public function test_menu_links_and_other_items_keep_working(): void
    {
        Http::fake();
        [$owner] = $this->tenant('warehouse');

        $html = $this->actingAs($owner)->get('/app')->assertOk()->getContent();
        foreach (['/people', '/groups', '/subgroups', '/schedules', '/settings/holidays', '/settings/team', '/attendance/timesheet', '/attendance/needs-attention'] as $href) {
            $this->assertStringContainsString('href="'.$href.'"', $html);
        }
        foreach (['Daily Logs', 'Monthly Report', 'Needs Attention', 'Timesheet', 'Manage Labels', 'Holidays', 'Billing'] as $fixed) {
            $this->assertStringContainsString($fixed, $html);
        }
        Http::assertNothingSent();
    }

    /** @return array{0: User, 1: Organization} */
    private function tenant(string $type): array
    {
        $organization = Organization::create(['name' => 'Menu Org '.$type, 'type' => $type, 'timezone' => 'Asia/Manila']);
        $owner = User::create(['organization_id' => $organization->id, 'name' => 'Owner', 'username' => 'owner-'.$type, 'email' => $type.'@menu.test', 'password' => 'password', 'email_verified_at' => now(), 'role' => 'owner']);

        return [$owner, $organization];
    }
}

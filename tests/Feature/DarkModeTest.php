<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Organization;
use App\Models\Person;
use App\Models\Subgroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The light / dark switch: a toggle in the top bar, and the saved choice applied before the page paints. */
class DarkModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_app_page_loads_the_theme_script_before_the_body_and_the_dark_styles(): void
    {
        Http::fake();
        $organization = Organization::create(['name' => 'Dark Org', 'type' => 'company', 'timezone' => 'Asia/Manila']);
        $owner = User::create(['organization_id' => $organization->id, 'name' => 'Owner', 'username' => 'owner', 'email' => 'owner@dark.test', 'password' => 'password', 'email_verified_at' => now(), 'role' => 'owner']);
        $group = Group::create(['organization_id' => $organization->id, 'name' => 'Day Crew']);
        $subgroup = Subgroup::create(['organization_id' => $organization->id, 'group_id' => $group->id, 'name' => 'Crew A']);
        $person = Person::create(['organization_id' => $organization->id, 'person_number' => 'E1', 'first_name' => 'Ana', 'last_name' => 'Cruz', 'group_id' => $group->id]);

        $pages = ['/app', '/people', "/people/{$person->id}", '/attendance', '/attendance/monthly', '/attendance/needs-attention', '/attendance/timesheet', '/groups', '/subgroups', '/schedules', '/settings/holidays', '/settings/team', '/settings/terminology', '/billing',
            // Standalone forms, without the app header:
            '/groups/create', "/groups/{$group->id}/edit", '/subgroups/create', "/subgroups/{$subgroup->id}/edit", '/schedules/create', "/people/{$person->id}/edit", '/people/import'];
        foreach ($pages as $page) {
            $html = $this->actingAs($owner)->get($page)->assertOk()->getContent();
            $script = strpos($html, 'js/theme.js');
            $this->assertNotFalse($script, "{$page} loads the theme script");
            $this->assertLessThan(strpos($html, '<body'), $script, "{$page}: the script runs in <head>, so there is no flash of the wrong theme");
            $this->assertStringContainsString('css/dark-mode.css', $html, $page);
        }
        Http::assertNothingSent();
    }

    public function test_the_top_bar_has_an_accessible_switch_with_sun_and_moon_icons(): void
    {
        Http::fake();
        $organization = Organization::create(['name' => 'Dark Org', 'type' => 'school', 'timezone' => 'Asia/Manila']);
        $owner = User::create(['organization_id' => $organization->id, 'name' => 'Owner', 'username' => 'owner', 'email' => 'owner@dark.test', 'password' => 'password', 'email_verified_at' => now(), 'role' => 'owner']);

        $html = $this->actingAs($owner)->get('/app')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'id="theme-toggle"'));
        $this->assertStringContainsString('aria-label="Switch to dark mode"', $html);
        $this->assertStringContainsString('aria-pressed="false"', $html);
        $this->assertStringContainsString('theme-icon-moon', $html);
        $this->assertStringContainsString('theme-icon-sun', $html);
        $this->assertLessThan(strpos($html, 'class="app-avatar"'), strpos($html, 'id="theme-toggle"'), 'The switch sits next to the avatar, at the top right.');
        Http::assertNothingSent();
    }

    public function test_the_theme_script_remembers_the_choice_follows_the_device_and_the_styles_cover_print(): void
    {
        $script = file_get_contents(public_path('js/theme.js'));
        $this->assertStringContainsString("'myo-theme'", $script, 'The choice is saved under one key.');
        $this->assertStringContainsString('prefers-color-scheme: dark', $script, 'With no saved choice it follows the device.');
        $this->assertStringContainsString('localStorage', $script);
        $this->assertStringContainsString("setAttribute('data-theme'", $script);
        $this->assertStringContainsString('catch (error)', $script, 'Storage that is blocked (private mode) does not break the page.');

        $css = file_get_contents(public_path('css/dark-mode.css'));
        $this->assertStringContainsString('html[data-theme="dark"]', $css);
        $this->assertStringContainsString('invert(0.92) hue-rotate(180deg)', $css);
        $this->assertStringContainsString('.qr-image', $css, 'QR codes keep their normal look.');
        $this->assertMatchesRegularExpression('/@media print\s*\{\s*html\[data-theme="dark"\]/', $css, 'Printed reports stay light.');
        $this->assertStringNotContainsString('data-theme="light"', $css, 'Light mode has no overrides, so it stays exactly as before.');
    }

    public function test_the_scanner_and_sign_in_pages_are_left_as_they_were(): void
    {
        Http::fake();
        $organization = Organization::create(['name' => 'Dark Org', 'type' => 'school', 'timezone' => 'Asia/Manila']);
        $owner = User::create(['organization_id' => $organization->id, 'name' => 'Owner', 'username' => 'owner', 'email' => 'owner@dark.test', 'password' => 'password', 'email_verified_at' => now(), 'role' => 'owner']);

        $this->get('/login')->assertOk()->assertDontSee('theme-toggle')->assertDontSee('dark-mode.css');
        $this->actingAs($owner)->get('/scanner')->assertOk()->assertDontSee('dark-mode.css');
        Http::assertNothingSent();
    }
}

<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The product is called "MYO Attendance" everywhere people see it (page titles, emails), never "MYO Attendance V2".
 */
class AppNameTest extends TestCase
{
    public function test_app_name_defaults_to_myo_attendance_without_v2(): void
    {
        $this->assertStringContainsString("env('APP_NAME', 'MYO Attendance')", file_get_contents(config_path('app.php')));
        $this->assertStringContainsString('APP_NAME="MYO Attendance"'."\n", file_get_contents(base_path('.env.example')));
    }

    public function test_sign_in_page_title_uses_the_app_name(): void
    {
        config(['app.name' => 'MYO Attendance']);

        $this->get('/login')->assertOk()->assertSee('Sign in · MYO Attendance', false)->assertDontSee('V2');
    }
}

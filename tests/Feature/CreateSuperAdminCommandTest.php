<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateSuperAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_a_verified_platform_super_admin(): void
    {
        $this->artisan('superadmin:create', [
            '--email' => 'staging-admin@example.test',
            '--name' => 'Staging Admin',
        ])
            ->expectsQuestion('Password', 'secret123!')
            ->expectsQuestion('Confirm password', 'secret123!')
            ->assertExitCode(0);

        $user = User::query()->where('email', 'staging-admin@example.test')->firstOrFail();

        $this->assertSame(User::ROLE_SUPER_ADMIN, $user->role);
        $this->assertNull($user->organization_id);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($user->isSuperAdmin());
        $this->assertTrue(Hash::check('secret123!', $user->password));
    }

    public function test_command_promotes_an_existing_user_and_can_keep_its_password(): void
    {
        $organization = Organization::query()->create([
            'name' => 'Existing Organization',
            'type' => 'company',
            'timezone' => 'Asia/Manila',
        ]);
        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'email' => 'existing@example.test',
            'password' => 'old-password',
            'role' => 'owner',
            'email_verified_at' => null,
        ]);

        $this->artisan('superadmin:create', [
            '--email' => $user->email,
            '--name' => 'Platform Admin',
            '--force' => true,
        ])
            ->expectsQuestion('Password (leave blank to keep the existing password)', '')
            ->assertExitCode(0);

        $user->refresh();

        $this->assertTrue($user->isSuperAdmin());
        $this->assertSame('Platform Admin', $user->name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('old-password', $user->password));
    }
}

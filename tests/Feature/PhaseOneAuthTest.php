<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class PhaseOneAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_rejects_an_invalid_turnstile_token(): void
    {
        $this->withoutMiddleware();
        config(['services.turnstile.secret_key' => 'test-secret']);
        Http::fake([config('services.turnstile.verify_url') => Http::response(['success' => false], 200)]);

        $this->postJson('/api/auth/register', [
            'name' => 'Blocked Bot', 'organization_name' => 'Blocked Org', 'organization_type' => 'company',
            'username' => 'blocked.bot', 'email' => 'blocked@example.test', 'contact_number' => '+63 917 123 4567', 'password' => 'password', 'password_confirmation' => 'password',
            'cf-turnstile-response' => 'invalid-token',
        ])->assertUnprocessable()->assertJsonPath('message', 'Please complete the security check and try again.');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_atomically_creates_owner_and_sends_verification(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Juan Dela Cruz',
            'organization_name' => 'ABC Company',
            'organization_type' => 'company',
            'username' => 'juan.delacruz',
            'email' => 'JUAN@example.test',
            'contact_number' => '+63 917 123 4567',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.organization.name', 'ABC Company')
            ->assertJsonPath('user.contact_number', '+63 917 123 4567');
        $user = User::firstOrFail();
        $this->assertSame('juan@example.test', $user->email);
        $this->assertSame('owner', $user->role);
        $this->assertFalse($user->hasVerifiedEmail());
        $this->assertTrue(Hash::check('password', $user->password));
        $this->assertSame($user->organization_id, Organization::firstOrFail()->id);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_registered_username_can_log_in_and_is_returned_in_the_account_payload(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Username Owner', 'username' => 'username.owner', 'organization_name' => 'Username Org',
            'organization_type' => 'company', 'email' => 'username@example.test', 'contact_number' => '09171234567',
            'password' => 'password', 'password_confirmation' => 'password',
        ])->assertCreated()->assertJsonPath('user.username', 'username.owner');

        $this->postJson('/api/auth/logout')->assertOk();
        $this->postJson('/api/auth/login', ['identifier' => 'username.owner', 'password' => 'password'])
            ->assertOk()->assertJsonPath('user.username', 'username.owner');
        $this->getJson('/api/auth/user')->assertOk()->assertJsonPath('user.username', 'username.owner');
    }

    public function test_registration_rejects_duplicate_usernames(): void
    {
        User::factory()->create(['username' => 'taken.name']);

        $this->postJson('/api/auth/register', [
            'name' => 'Another Owner', 'username' => 'TAKEN.NAME', 'organization_name' => 'Another Org',
            'organization_type' => 'company', 'email' => 'another@example.test', 'contact_number' => '09171234567',
            'password' => 'password', 'password_confirmation' => 'password',
        ])->assertUnprocessable()->assertJsonValidationErrors('username');
    }

    public function test_legacy_user_uses_email_until_username_is_saved_in_account_settings(): void
    {
        $user = User::factory()->create(['email' => 'legacy@example.test', 'username' => null, 'password' => 'password']);

        $this->actingAs($user)->getJson('/api/auth/user')->assertOk()->assertJsonPath('user.username', 'legacy@example.test');
        $this->actingAs($user)->putJson('/api/auth/account', ['name' => $user->name, 'username' => 'legacy.owner'])
            ->assertOk()->assertJsonPath('user.username', 'legacy.owner');
        $this->assertSame('legacy.owner', $user->fresh()->username);
    }

    public function test_registration_rejects_bad_type_and_duplicate_email(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'A', 'organization_name' => 'Org', 'organization_type' => 'invalid',
            'username' => 'person.one', 'email' => 'person@example.test', 'contact_number' => '09171234567', 'password' => 'password', 'password_confirmation' => 'password',
        ])->assertUnprocessable()->assertJsonValidationErrors('organization_type');

        User::factory()->create(['email' => 'person@example.test']);
        $this->postJson('/api/auth/register', [
            'name' => 'A', 'organization_name' => 'Org', 'organization_type' => 'company',
            'username' => 'person.two', 'email' => 'person@example.test', 'contact_number' => '09171234567', 'password' => 'password', 'password_confirmation' => 'password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_unverified_users_are_blocked_and_verified_users_can_enter_app(): void
    {
        $user = User::factory()->unverified()->create();
        $this->actingAs($user)->getJson('/api/app')->assertForbidden();

        $user->markEmailAsVerified();
        $this->actingAs($user)->getJson('/api/app')->assertOk()->assertJsonPath('message', 'Phase 1 authentication is working.');
    }

    public function test_verification_link_and_resend_work(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $this->actingAs($user)->postJson('/api/auth/verification/resend')->assertOk();
        Notification::assertSentTo($user, VerifyEmail::class);

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(10), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->get($url)->assertRedirect('/app');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->getJson('/api/app')->assertOk();
    }

    public function test_login_persists_session_and_logout_invalidates_it(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret-password'])->assertOk()->assertJsonPath('user.email', $user->email);
        $this->getJson('/api/auth/user')->assertOk()->assertJsonMissingPath('user.password');
        $this->postJson('/api/auth/logout')->assertOk();
        $this->getJson('/api/auth/user')->assertUnauthorized();
    }

    public function test_password_reset_changes_password_and_uses_generic_request_message(): void
    {
        Notification::fake();
        $user = User::factory()->create(['password' => 'old-password']);
        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk()->assertJsonPath('message', 'If an account exists for that email, a password reset link has been sent.');
        Notification::assertSentTo($user, ResetPassword::class);

        $token = Password::createToken($user);
        $this->postJson('/api/auth/reset-password', [
            'token' => $token, 'email' => $user->email, 'password' => 'new-password', 'password_confirmation' => 'new-password',
        ])->assertOk();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'old-password'])->assertUnprocessable();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'new-password'])->assertOk();
    }

    public function test_account_settings_updates_name_and_password_with_current_password_check(): void
    {
        $user = User::factory()->create(['name' => 'Old Name', 'password' => 'old-password']);

        $this->actingAs($user)->putJson('/api/auth/account', ['name' => 'New Name'])
            ->assertOk()->assertJsonPath('user.name', 'New Name');
        $this->actingAs($user)->putJson('/api/auth/password', [
            'current_password' => 'wrong-password', 'password' => 'new-password', 'password_confirmation' => 'new-password',
        ])->assertUnprocessable();
        $this->actingAs($user)->putJson('/api/auth/password', [
            'current_password' => 'old-password', 'password' => 'new-password', 'password_confirmation' => 'new-password',
        ])->assertOk();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'new-password'])->assertOk();
    }

    public function test_authenticated_tenant_context_is_taken_from_user_relationship(): void
    {
        $user = User::factory()->create();
        $otherOrganization = Organization::create(['name' => 'Other', 'type' => 'other']);

        $this->actingAs($user)->getJson('/api/auth/user')->assertOk()
            ->assertJsonPath('user.organization.id', $user->organization_id)
            ->assertJsonMissingPath('user.organization_id');
        $this->assertNotSame($otherOrganization->id, $user->organization_id);
    }

    public function test_school_registration_exposes_school_friendly_default_labels(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'School Admin', 'organization_name' => 'MYO School', 'organization_type' => 'school',
            'username' => 'school.admin', 'email' => 'school@example.test', 'contact_number' => '09171234567', 'password' => 'password', 'password_confirmation' => 'password',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.organization.terminology.person_plural', 'Students')
            ->assertJsonPath('user.organization.terminology.group_plural', 'Grade Levels')
            ->assertJsonPath('user.organization.terminology.subgroup_plural', 'Sections');
    }

    public function test_organization_labels_can_be_updated_reset_and_are_tenant_scoped(): void
    {
        $organization = Organization::create(['name' => 'Terminology Org', 'type' => 'other']);
        $user = User::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($user)->getJson('/api/organization/terminology')->assertOk()->assertJsonPath('terminology.person_plural', 'People');
        $this->actingAs($user)->putJson('/api/organization/terminology', [
            'person_singular' => 'Student', 'person_plural' => 'Students',
            'group_singular' => 'Grade Level', 'group_plural' => 'Grade Levels',
            'subgroup_singular' => 'Section', 'subgroup_plural' => 'Sections',
        ])->assertOk()->assertJsonPath('terminology.group_plural', 'Grade Levels');
        $this->assertSame('Students', $user->organization->fresh()->terminology['person_plural']);

        $this->actingAs($user)->postJson('/api/organization/terminology/reset')->assertOk()->assertJsonPath('terminology.person_plural', 'People');
        $this->actingAs($user)->putJson('/api/organization/terminology', ['person_plural' => 'Students'])->assertUnprocessable();
    }
}

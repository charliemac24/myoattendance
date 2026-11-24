<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use App\Services\BaseSubscriptionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * The only local test logins. Safe to run any number of times, and it never resets anything:
 * `php artisan db:seed --class=LocalTestAccountsSeeder`.
 *
 * - Super admin: super / password
 * - Test school owner: demo / password ("Demo School", starts on a fresh 30-day free trial)
 *
 * To put Demo School back to "just signed up", run ActivationDemoSeeder.
 */
class LocalTestAccountsSeeder extends Seeder
{
    public const DEMO_SCHOOL_NAME = 'Demo School';

    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        $this->account(['super', 'super@myo.test', 'superadmin@myo.test'], [
            'organization_id' => null,
            'name' => 'Super Admin',
            'role' => User::ROLE_SUPER_ADMIN,
        ]);

        $school = Organization::query()->firstOrCreate(
            ['name' => self::DEMO_SCHOOL_NAME],
            ['type' => 'school', 'timezone' => 'Asia/Manila'],
        );
        if (! $school->baseSubscription()->exists()) {
            app(BaseSubscriptionService::class)->createTrial($school);
        }
        $this->account(['demo', 'demo@myo.test'], [
            'organization_id' => $school->id,
            'name' => 'Demo School Owner',
            'role' => 'owner',
        ]);
    }

    /**
     * @param  array{0: string, 1: string, 2?: string}  $identity  username, email, and an optional older email to adopt
     * @param  array<string, mixed>  $attributes
     */
    private function account(array $identity, array $attributes): void
    {
        [$username, $email] = $identity;
        $user = User::query()->where('username', $username)->orWhereIn('email', array_slice($identity, 1))->orderBy('id')->first() ?? new User;

        $user->forceFill($attributes + [
            'username' => $username,
            'email' => $email,
            'password' => Hash::make('password'),
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();
    }
}

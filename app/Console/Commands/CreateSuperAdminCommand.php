<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CreateSuperAdminCommand extends Command
{
    protected $signature = 'superadmin:create
        {--email= : Email address for the Super Admin}
        {--name= : Display name for the Super Admin}
        {--force : Update an existing account without confirmation}';

    protected $description = 'Create or promote a user as a platform Super Admin';

    public function handle(): int
    {
        $email = $this->email();
        $existing = User::query()->where('email', $email)->first();

        if ($existing && ! $this->option('force') && ! $this->confirm(
            "An account already exists for {$email}. Promote it to Super Admin?",
            true,
        )) {
            $this->info('No changes made.');

            return self::SUCCESS;
        }

        $name = trim((string) ($this->option('name') ?: ($existing?->name ?: $this->ask('Name'))));
        if ($name === '') {
            $this->error('A name is required.');

            return self::FAILURE;
        }

        $password = $this->password($existing !== null);

        DB::transaction(function () use ($existing, $name, $email, $password): void {
            $attributes = [
                'name' => $name,
                'email' => $email,
                'role' => User::ROLE_SUPER_ADMIN,
                'organization_id' => null,
                'email_verified_at' => now(),
            ];

            if ($password !== null) {
                $attributes['password'] = Hash::make($password);
            }

            if ($existing) {
                $existing->forceFill($attributes)->save();
            } else {
                User::query()->create($attributes + ['password' => Hash::make($password)]);
            }
        });

        $this->info($existing
            ? "Super Admin access granted to {$email}."
            : "Super Admin account created for {$email}.");

        return self::SUCCESS;
    }

    private function email(): string
    {
        $email = strtolower(trim((string) ($this->option('email') ?: $this->ask('Email address'))));

        while (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Enter a valid email address.');
            $email = strtolower(trim((string) $this->ask('Email address')));
        }

        return $email;
    }

    private function password(bool $optional): ?string
    {
        do {
            $password = $this->secret($optional
                ? 'Password (leave blank to keep the existing password)'
                : 'Password');

            if ($optional && ($password === null || $password === '')) {
                return null;
            }

            if (is_string($password) && strlen($password) >= 8) {
                $confirmation = $this->secret('Confirm password');
                if ($password === $confirmation) {
                    return $password;
                }
            }

            $this->error('Passwords must match and contain at least 8 characters.');
        } while (true);
    }
}

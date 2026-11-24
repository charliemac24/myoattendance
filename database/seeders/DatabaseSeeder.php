<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with the local test logins only: super / password and demo / password.
     * SamplePeopleSeeder and DemoAttendanceSeeder can still be run on their own to add sample people and attendance.
     */
    public function run(): void
    {
        $this->call(LocalTestAccountsSeeder::class);
    }
}

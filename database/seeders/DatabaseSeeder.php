<?php

namespace Database\Seeders;

use App\Models\Group;
use App\Models\Organization;
use App\Models\Person;
use App\Models\Schedule;
use App\Models\Subgroup;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $organization = Organization::create(['name' => 'MYO Demo Organization', 'type' => 'training_center', 'timezone' => 'Asia/Manila']);
        User::create(['organization_id' => $organization->id, 'name' => 'Phase 0 Admin', 'email' => 'admin@myo.test', 'password' => Hash::make('password'), 'role' => 'admin']);
        $group = Group::create(['organization_id' => $organization->id, 'name' => 'Demo Group']);
        $subgroup = Subgroup::create(['organization_id' => $organization->id, 'group_id' => $group->id, 'name' => 'Demo Subgroup']);
        Person::create(['organization_id' => $organization->id, 'person_number' => '000001', 'first_name' => 'Demo', 'last_name' => 'Person', 'group_id' => $group->id, 'subgroup_id' => $subgroup->id]);
        Schedule::create(['organization_id' => $organization->id, 'group_id' => $group->id, 'name' => 'Default weekday schedule', 'day_of_week' => 1, 'start_time' => '07:30', 'late_after' => '08:00', 'end_time' => '17:00']);
        $this->call(SamplePeopleSeeder::class);
        $this->call(DemoAttendanceSeeder::class);
        $this->call(LocalTestAccountsSeeder::class);
    }
}

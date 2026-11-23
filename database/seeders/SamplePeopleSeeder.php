<?php

namespace Database\Seeders;

use App\Models\Group;
use App\Models\Organization;
use App\Models\Person;
use App\Models\Subgroup;
use Illuminate\Database\Seeder;

class SamplePeopleSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::query()->latest('id')->firstOrFail();
        $groups = $organization->groups()->orderBy('name')->get();

        if ($groups->isEmpty()) {
            $groups = collect(['Grade 1', 'Grade 2', 'Grade 3', 'Grade 4'])->map(fn (string $name) => Group::create([
                'organization_id' => $organization->id,
                'name' => $name,
            ]));
        }

        $names = [
            ['Amelia', 'Santos'], ['Benjamin', 'Cruz'], ['Camille', 'Reyes'], ['Daniel', 'Garcia'],
            ['Eliana', 'Mendoza'], ['Felix', 'Torres'], ['Gabriela', 'Navarro'], ['Hugo', 'Castillo'],
            ['Isabella', 'Ramos'], ['Joaquin', 'Flores'], ['Katrina', 'Villanueva'], ['Liam', 'Rivera'],
            ['Mia', 'Dela Cruz'], ['Nathan', 'Aquino'], ['Olivia', 'Bautista'], ['Paolo', 'Fernandez'],
            ['Queenie', 'Lim'], ['Rafael', 'Domingo'], ['Sofia', 'Pascual'], ['Theo', 'Mercado'],
        ];

        foreach ($names as $index => [$firstName, $lastName]) {
            $group = $groups[$index % $groups->count()];
            $subgroup = $organization->subgroups()->where('group_id', $group->id)->orderBy('id')->first();
            if (! $subgroup) {
                $subgroup = Subgroup::create(['organization_id' => $organization->id, 'group_id' => $group->id, 'name' => 'Section A']);
            }

            Person::firstOrCreate(
                ['organization_id' => $organization->id, 'person_number' => sprintf('SAMPLE-%03d', $index + 1)],
                ['first_name' => $firstName, 'last_name' => $lastName, 'group_id' => $group->id, 'subgroup_id' => $subgroup->id, 'contact_number' => sprintf('09%09d', 170000000 + $index)],
            );
        }
    }
}

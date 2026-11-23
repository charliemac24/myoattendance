<?php

namespace Tests\Feature;

use App\Models\AttendanceEvent;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Person;
use App\Models\Schedule;
use App\Models\Subgroup;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PhaseZeroTest extends TestCase
{
    use RefreshDatabase;

    public function test_phase_zero_schema_and_relationships_exist(): void
    {
        foreach (['organizations', 'groups', 'subgroups', 'persons', 'schedules', 'attendance_events'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        $organization = Organization::create(['name' => 'Org A', 'type' => 'company', 'timezone' => 'Asia/Manila']);
        $user = User::create(['organization_id' => $organization->id, 'name' => 'Admin', 'email' => 'a@example.test', 'password' => 'password']);
        $group = Group::create(['organization_id' => $organization->id, 'name' => 'Operations']);
        $subgroup = Subgroup::create(['organization_id' => $organization->id, 'group_id' => $group->id, 'name' => 'Team A']);
        $person = Person::create(['organization_id' => $organization->id, 'person_number' => '000184', 'first_name' => 'A', 'last_name' => 'Person', 'group_id' => $group->id, 'subgroup_id' => $subgroup->id]);
        $schedule = Schedule::create(['organization_id' => $organization->id, 'group_id' => $group->id, 'name' => 'Default', 'day_of_week' => 1, 'start_time' => '07:30', 'late_after' => '08:00']);
        $event = AttendanceEvent::create(['organization_id' => $organization->id, 'person_id' => $person->id, 'event_type' => 'check_in', 'performed_by_user_id' => $user->id]);

        $this->assertSame($organization->id, $user->organization->id);
        $this->assertSame($organization->id, $group->organization->id);
        $this->assertSame($group->id, $subgroup->group->id);
        $this->assertSame($organization->id, $subgroup->organization->id);
        $this->assertSame($organization->id, $person->organization->id);
        $this->assertSame($group->id, $person->group->id);
        $this->assertSame($subgroup->id, $person->subgroup->id);
        $this->assertSame($group->id, $schedule->group->id);
        $this->assertSame($person->id, $event->person->id);
        $this->assertSame(64, strlen($person->qr_token));
        $this->assertSame('000184', $person->person_number);
    }

    public function test_qr_tokens_are_unique_and_person_numbers_are_tenant_aware(): void
    {
        $a = Organization::create(['name' => 'A', 'type' => 'other']);
        $b = Organization::create(['name' => 'B', 'type' => 'other']);
        $attributes = ['person_number' => '000001', 'first_name' => 'Same', 'last_name' => 'Number'];
        $one = Person::create($attributes + ['organization_id' => $a->id]);
        $two = Person::create($attributes + ['organization_id' => $b->id]);
        $this->assertNotSame($one->qr_token, $two->qr_token);
        $this->expectException(UniqueConstraintViolationException::class);
        Person::create($attributes + ['organization_id' => $a->id]);
    }

    public function test_cross_tenant_group_assignment_is_rejected_by_database(): void
    {
        $a = Organization::create(['name' => 'A', 'type' => 'other']);
        $b = Organization::create(['name' => 'B', 'type' => 'other']);
        $group = Group::create(['organization_id' => $b->id, 'name' => 'Private']);
        $this->expectException(QueryException::class);
        Person::create(['organization_id' => $a->id, 'person_number' => 'A-1', 'first_name' => 'Cross', 'last_name' => 'Tenant', 'group_id' => $group->id]);
    }
}

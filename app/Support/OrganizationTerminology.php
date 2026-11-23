<?php

namespace App\Support;

class OrganizationTerminology
{
    public static function defaults(?string $type): array
    {
        return match ($type) {
            'school' => ['person_singular' => 'Student', 'person_plural' => 'Students', 'group_singular' => 'Grade Level', 'group_plural' => 'Grade Levels', 'subgroup_singular' => 'Section', 'subgroup_plural' => 'Sections'],
            'company' => ['person_singular' => 'Employee', 'person_plural' => 'Employees', 'group_singular' => 'Department', 'group_plural' => 'Departments', 'subgroup_singular' => 'Team', 'subgroup_plural' => 'Teams'],
            'training_center' => ['person_singular' => 'Trainee', 'person_plural' => 'Trainees', 'group_singular' => 'Program', 'group_plural' => 'Programs', 'subgroup_singular' => 'Batch', 'subgroup_plural' => 'Batches'],
            'construction' => ['person_singular' => 'Worker', 'person_plural' => 'Workers', 'group_singular' => 'Project', 'group_plural' => 'Projects', 'subgroup_singular' => 'Crew', 'subgroup_plural' => 'Crews'],
            'warehouse' => ['person_singular' => 'Worker', 'person_plural' => 'Workers', 'group_singular' => 'Department', 'group_plural' => 'Departments', 'subgroup_singular' => 'Shift', 'subgroup_plural' => 'Shifts'],
            'staffing' => ['person_singular' => 'Worker', 'person_plural' => 'Workers', 'group_singular' => 'Client', 'group_plural' => 'Clients', 'subgroup_singular' => 'Site', 'subgroup_plural' => 'Sites'],
            'sports_academy' => ['person_singular' => 'Athlete', 'person_plural' => 'Athletes', 'group_singular' => 'Sport', 'group_plural' => 'Sports', 'subgroup_singular' => 'Team', 'subgroup_plural' => 'Teams'],
            default => ['person_singular' => 'Person', 'person_plural' => 'People', 'group_singular' => 'Group', 'group_plural' => 'Groups', 'subgroup_singular' => 'Subgroup', 'subgroup_plural' => 'Subgroups'],
        };
    }

    public static function forOrganization(object $organization): array
    {
        return array_replace(self::defaults($organization->type), array_filter($organization->terminology ?? [], 'is_string'));
    }
}

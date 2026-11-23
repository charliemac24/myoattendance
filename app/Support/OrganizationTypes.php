<?php

namespace App\Support;

final class OrganizationTypes
{
    public const VALUES = [
        'school', 'company', 'construction', 'training_center',
        'warehouse', 'staffing', 'sports_academy', 'fitness', 'other',
    ];

    /** Employers: these track hours worked, overtime and rest day work, and get the Timesheet. */
    public const WORKFORCE = ['company', 'construction', 'warehouse', 'staffing'];

    /** Members come and go anytime (a gym or studio): new groups check in with open hours instead of a schedule. */
    public const OPEN_HOURS_BY_DEFAULT = ['fitness'];

    /**
     * Types not offered in the industry dropdowns. Company and Other are paused while the focus is School and Fitness / Gym.
     * Construction, Warehouse and Staffing behave exactly like Company;
     * Training Center needs dated courses, and Sports Academy needs athletes in several teams, which are not supported.
     * Organizations already on one keep it.
     */
    public const HIDDEN = ['company', 'construction', 'warehouse', 'staffing', 'training_center', 'sports_academy', 'other'];

    public const LABELS = [
        'school' => 'School / Education',
        'company' => 'Company',
        'construction' => 'Construction',
        'training_center' => 'Training Center',
        'warehouse' => 'Warehouse',
        'staffing' => 'Staffing',
        'sports_academy' => 'Sports Academy',
        'fitness' => 'Fitness / Gym',
        'other' => 'Other',
    ];

    /** Example text for the name box when adding a group (a gym calls it a Plan) or a subgroup (a Class). */
    public static function nameExample(?string $type, string $level): string
    {
        return match ([$type, $level]) {
            ['fitness', 'group'] => 'e.g. Monthly Unlimited, Student Plan, 10-Session Pass',
            ['fitness', 'subgroup'] => 'e.g. Morning Yoga, Evening Zumba, Boxing Basics',
            default => $level === 'group' ? 'e.g. Grade 9, Evening Shift' : 'e.g. Section A, Evening Shift',
        };
    }

    /** Labels for a dropdown: every supported type, plus the organization's current type even when it is hidden. */
    public static function selectableLabels(?string $current = null): array
    {
        return array_filter(self::LABELS, fn (string $type): bool => ! in_array($type, self::HIDDEN, true) || $type === $current, ARRAY_FILTER_USE_KEY);
    }
}

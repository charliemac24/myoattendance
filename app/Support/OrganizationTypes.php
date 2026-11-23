<?php

namespace App\Support;

final class OrganizationTypes
{
    public const VALUES = [
        'school', 'company', 'construction', 'training_center',
        'warehouse', 'staffing', 'sports_academy', 'other',
    ];

    /** Employers: these track hours worked, overtime and rest day work, and get the Timesheet. */
    public const WORKFORCE = ['company', 'construction', 'warehouse', 'staffing'];

    /**
     * Types not offered in the industry dropdowns. Construction, Warehouse and Staffing behave exactly like Company;
     * Training Center needs dated courses, which are not supported. Organizations already on one keep it.
     */
    public const HIDDEN = ['construction', 'warehouse', 'staffing', 'training_center'];

    public const LABELS = [
        'school' => 'School / Education',
        'company' => 'Company',
        'construction' => 'Construction',
        'training_center' => 'Training Center',
        'warehouse' => 'Warehouse',
        'staffing' => 'Staffing',
        'sports_academy' => 'Sports Academy',
        'other' => 'Other',
    ];

    /** Labels for a dropdown: every supported type, plus the organization's current type even when it is hidden. */
    public static function selectableLabels(?string $current = null): array
    {
        return array_filter(self::LABELS, fn (string $type): bool => ! in_array($type, self::HIDDEN, true) || $type === $current, ARRAY_FILTER_USE_KEY);
    }
}

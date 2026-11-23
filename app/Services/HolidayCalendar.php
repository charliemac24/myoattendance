<?php

namespace App\Services;

use App\Models\Holiday;
use Carbon\Carbon;

/**
 * Looks up the holiday that applies to a group on a date. An organization's holidays are read once per instance.
 */
class HolidayCalendar
{
    /** @var array<int, array<string, array<int, array{group_id: ?int, name: string}>>> Holidays by organization, then by date. */
    private array $byOrganization = [];

    /** The holiday name for a group (null = no group) on a date, or null when it is a normal day. */
    public function nameOn(int $organizationId, ?int $groupId, string $date): ?string
    {
        $matches = $this->byOrganization[$organizationId] ??= $this->load($organizationId);
        $names = array_filter($matches[$date] ?? [], fn (array $holiday): bool => $holiday['group_id'] === null || $holiday['group_id'] === $groupId);
        // An organization-wide holiday wins over a group one for the label.
        usort($names, fn (array $a, array $b): int => ($a['group_id'] === null ? 0 : 1) <=> ($b['group_id'] === null ? 0 : 1));

        return $names[0]['name'] ?? null;
    }

    /** The organization-wide holiday on a date, ignoring group holidays (e.g. for a banner above a whole report). */
    public function organizationNameOn(int $organizationId, string $date): ?string
    {
        $matches = $this->byOrganization[$organizationId] ??= $this->load($organizationId);
        foreach ($matches[$date] ?? [] as $holiday) {
            if ($holiday['group_id'] === null) {
                return $holiday['name'];
            }
        }

        return null;
    }

    /** @return array<string, array<int, array{group_id: ?int, name: string}>> */
    private function load(int $organizationId): array
    {
        $byDate = [];
        foreach (Holiday::query()->where('organization_id', $organizationId)->orderBy('id')->get() as $holiday) {
            for ($date = Carbon::parse($holiday->starts_on->toDateString()); $date->lte($holiday->ends_on); $date->addDay()) {
                $byDate[$date->toDateString()][] = ['group_id' => $holiday->group_id, 'name' => $holiday->name];
            }
        }

        return $byDate;
    }
}

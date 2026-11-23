<?php

namespace App\Http\Controllers;

use App\Models\Holiday;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The organization's holiday list. Owners and admins add single days or ranges (for everyone or one group), or
 * add the common Philippine holidays for a year in one tap.
 */
class HolidayController extends Controller
{
    public const MAX_RANGE_DAYS = 60;

    public function index(Request $request): JsonResponse
    {
        $holidays = $request->user()->organization->holidays()->with('group:id,name')->orderBy('starts_on')->orderBy('id')->get();

        return response()->json(['holidays' => $holidays->map(fn (Holiday $holiday): array => $this->payload($holiday))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $organization = $request->user()->organization;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'group_id' => ['nullable', 'integer'],
        ]);
        $groupId = isset($data['group_id']) ? $organization->groups()->findOrFail($data['group_id'])->id : null;
        $endsOn = $data['ends_on'] ?? $data['starts_on'];
        if (Carbon::parse($data['starts_on'])->diffInDays(Carbon::parse($endsOn)) + 1 > self::MAX_RANGE_DAYS) {
            return response()->json(['message' => 'A holiday can cover up to '.self::MAX_RANGE_DAYS.' days.', 'errors' => ['ends_on' => ['A holiday can cover up to '.self::MAX_RANGE_DAYS.' days.']]], 422);
        }
        $name = trim($data['name']);
        if ($organization->holidays()->where('group_id', $groupId)->where('name', $name)->whereDate('starts_on', $data['starts_on'])->whereDate('ends_on', $endsOn)->exists()) {
            return response()->json(['message' => 'That holiday is already on the list.', 'errors' => ['name' => ['That holiday is already on the list.']]], 422);
        }

        $holiday = $organization->holidays()->create(['group_id' => $groupId, 'name' => $name, 'starts_on' => $data['starts_on'], 'ends_on' => $endsOn]);

        return response()->json(['holiday' => $this->payload($holiday->load('group:id,name'))], 201);
    }

    public function destroy(Request $request, int $holiday): JsonResponse
    {
        $request->user()->organization->holidays()->findOrFail($holiday)->delete();

        return response()->json(['message' => 'Holiday removed.']);
    }

    /** Adds the common Philippine holidays for a year to the whole organization, skipping any already listed. */
    public function addCommon(Request $request): JsonResponse
    {
        $data = $request->validate(['year' => ['required', 'integer', 'between:2020,2100']]);
        $organization = $request->user()->organization;
        $added = 0;
        foreach (self::commonHolidays((int) $data['year']) as [$name, $date]) {
            $created = $organization->holidays()->firstOrCreate(['group_id' => null, 'name' => $name, 'starts_on' => $date, 'ends_on' => $date]);
            $added += $created->wasRecentlyCreated ? 1 : 0;
        }

        return response()->json(['added' => $added, 'message' => $added === 0 ? 'Those holidays are already on your list.' : "Added {$added} holidays."]);
    }

    /**
     * Fixed-date Philippine holidays plus National Heroes Day (last Monday of August). Holy Week and Islamic holidays
     * move every year, so they are added by hand.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public static function commonHolidays(int $year): array
    {
        return [
            ["New Year's Day", "{$year}-01-01"],
            ['Araw ng Kagitingan', "{$year}-04-09"],
            ['Labor Day', "{$year}-05-01"],
            ['Independence Day', "{$year}-06-12"],
            ['National Heroes Day', self::lastMonday($year, 8)],
            ["All Saints' Day", "{$year}-11-01"],
            ['Bonifacio Day', "{$year}-11-30"],
            ['Christmas Eve', "{$year}-12-24"],
            ['Christmas Day', "{$year}-12-25"],
            ['Rizal Day', "{$year}-12-30"],
            ["New Year's Eve", "{$year}-12-31"],
        ];
    }

    private static function lastMonday(int $year, int $month): string
    {
        $date = Carbon::create($year, $month, 1)->endOfMonth()->startOfDay();
        while ($date->dayOfWeekIso !== 1) {
            $date->subDay();
        }

        return $date->toDateString();
    }

    /** @return array<string, mixed> */
    private function payload(Holiday $holiday): array
    {
        return [
            'id' => $holiday->id,
            'name' => $holiday->name,
            'starts_on' => $holiday->starts_on->toDateString(),
            'ends_on' => $holiday->ends_on->toDateString(),
            'group_id' => $holiday->group_id,
            'group' => $holiday->group?->name,
        ];
    }
}

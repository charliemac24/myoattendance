<?php

namespace App\Http\Controllers;

use App\Models\Schedule;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScheduleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $schedules = $request->user()->organization->schedules()->current($this->today($request))->with(['group:id,name', 'subgroup:id,name'])
            ->when($request->string('search')->trim()->value(), function ($query, $search): void {
                $query->where(fn ($nested) => $nested->where('name', 'like', "%{$search}%")
                    ->orWhereHas('group', fn ($group) => $group->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('subgroup', fn ($subgroup) => $subgroup->where('name', 'like', "%{$search}%")));
            })
            ->orderBy('day_of_week')->orderBy('start_time')->paginate(min($request->integer('per_page', 20), 100))->withQueryString();

        return response()->json($schedules);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $days = $data['days'] ?? [$data['day_of_week']];
        unset($data['days']);
        $schedules = DB::transaction(function () use ($request, $data, $days) {
            return collect($days)->map(function (int $day) use ($request, $data) {
                return $request->user()->organization->schedules()->create([...$data, 'day_of_week' => $day]);
            });
        });
        $schedules->each->load(['group:id,name', 'subgroup:id,name']);

        return response()->json(['schedule' => $schedules->first(), 'schedules' => $schedules->values()], 201);
    }

    public function show(Request $request, int $schedule): JsonResponse
    {
        return response()->json(['schedule' => $this->owned($request, $schedule)->load(['group:id,name', 'subgroup:id,name'])]);
    }

    /**
     * Editing a schedule that was already in use keeps the old version for the days before today and starts a new
     * version today, so past attendance is not recalculated with the new times. A schedule that only started
     * today (or later) is simply updated.
     */
    public function update(Request $request, int $schedule): JsonResponse
    {
        $owned = $this->owned($request, $schedule);
        $data = $this->validated($request, $owned->id);
        $today = $this->today($request);
        $updated = DB::transaction(function () use ($owned, $data, $today): Schedule {
            $owned->fill($data);
            if (! $owned->isDirty() || ($owned->effective_from && $owned->effective_from->toDateString() >= $today)) {
                $owned->save();

                return $owned;
            }
            $next = $owned->replicate();
            $next->effective_from = $today;
            $next->effective_to = null;
            $next->save();
            $owned->discardChanges();
            $owned->update(['effective_to' => Carbon::parse($today)->subDay()->toDateString()]);

            return $next;
        });

        return response()->json(['schedule' => $updated->fresh()->load(['group:id,name', 'subgroup:id,name'])]);
    }

    /** A schedule that was in use is ended yesterday (kept for past reports); one that never was is deleted. */
    public function destroy(Request $request, int $schedule): JsonResponse
    {
        $owned = $this->owned($request, $schedule);
        $today = $this->today($request);
        if ($owned->effective_from && $owned->effective_from->toDateString() >= $today) {
            $owned->delete();
        } else {
            $owned->update(['effective_to' => Carbon::parse($today)->subDay()->toDateString()]);
        }

        return response()->json(['message' => 'Schedule deleted.']);
    }

    private function today(Request $request): string
    {
        return now($request->user()->organization->timezone)->toDateString();
    }

    private function validated(Request $request, ?int $ignore = null): array
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'group_id' => ['required', 'integer'], 'subgroup_id' => ['nullable', 'integer'], 'day_of_week' => ['required_without:days', 'nullable', 'integer', 'between:1,7'], 'days' => ['required_without:day_of_week', 'nullable', 'array', 'min:1', 'max:7'], 'days.*' => ['integer', 'between:1,7', 'distinct'], 'start_time' => ['required', 'date_format:H:i'], 'late_after' => ['required', 'date_format:H:i'], 'end_time' => ['nullable', 'date_format:H:i'], 'periods' => ['nullable', 'array', 'min:1', 'max:8'], 'periods.*.start_time' => ['required_with:periods', 'date_format:H:i'], 'periods.*.late_after' => ['required_with:periods', 'date_format:H:i'], 'periods.*.end_time' => ['nullable', 'date_format:H:i'], 'break_minutes' => ['sometimes', 'integer', 'min:0', 'max:480'], 'is_active' => ['sometimes', 'boolean']]);
        $data['days'] = isset($data['days']) ? array_values($data['days']) : null;
        $data['day_of_week'] = $data['day_of_week'] ?? $data['days'][0];
        $organization = $request->user()->organization;
        $group = $organization->groups()->findOrFail($data['group_id']);
        $subgroup = ! empty($data['subgroup_id']) ? $organization->subgroups()->whereKey($data['subgroup_id'])->where('group_id', $group->id)->firstOrFail() : null;
        $periods = $data['periods'] ?? [['start_time' => $data['start_time'], 'late_after' => $data['late_after'], 'end_time' => $data['end_time'] ?? null]];
        usort($periods, static fn (array $left, array $right): int => strcmp($left['start_time'], $right['start_time']));
        $this->validatePeriods($periods, (int) ($data['break_minutes'] ?? 0));
        $duplicate = $organization->schedules()->current($this->today($request))->where('group_id', $group->id)->whereIn('day_of_week', $data['days'] ?? [$data['day_of_week']])->when($subgroup, fn ($query) => $query->where('subgroup_id', $subgroup->id), fn ($query) => $query->whereNull('subgroup_id'))->when($ignore, fn ($query) => $query->whereKeyNot($ignore))->exists();
        if ($duplicate) {
            return response()->json(['message' => 'A schedule already exists for this group/subgroup and day.', 'errors' => ['day_of_week' => ['A schedule already exists for this scope and day.']]], 422)->throwResponse();
        }
        $data['name'] = trim($data['name']);
        $data['periods'] = $periods;
        $data['start_time'] = $periods[0]['start_time'];
        $data['late_after'] = $periods[0]['late_after'];
        $data['end_time'] = $periods[0]['end_time'] ?? null;
        $data['subgroup_id'] = $subgroup?->id;
        $data['is_active'] = $data['is_active'] ?? true;

        return $data;
    }

    /**
     * Periods run in order and may not overlap. Only the last period may end after midnight (a night shift such as
     * 10:00 PM to 6:00 AM): its late-after and end times are then read as the next day. A shift lasts under 24 hours,
     * and the unpaid break must be shorter than it.
     *
     * @param  array<int, array{start_time: string, late_after: string, end_time: ?string}>  $periods
     */
    private function validatePeriods(array $periods, int $breakMinutes): void
    {
        $fail = fn (string $field, string $message) => response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422)->throwResponse();
        $minutes = fn (string $time): int => (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
        $lastIndex = count($periods) - 1;
        $firstStart = $minutes($periods[0]['start_time']);
        $previousEnd = null;
        foreach ($periods as $index => $period) {
            $start = $minutes($period['start_time']);
            $late = $minutes($period['late_after']);
            $end = empty($period['end_time']) ? null : $minutes($period['end_time']);
            if ($index === $lastIndex) {
                // A night shift (its end is earlier than its start): times earlier than the start are the next morning.
                if ($end !== null && $end <= $start) {
                    $end += 1440;
                    if ($late < $start) {
                        $late += 1440;
                    }
                }
            }
            if ($previousEnd !== null && $start < $previousEnd) {
                $fail("periods.$index.start_time", 'Schedule periods cannot overlap.');
            }
            if ($late < $start) {
                $fail("periods.$index.late_after", 'Late threshold must be at or after the period start.');
            }
            if ($end !== null && ($end <= $start || $end - $firstStart >= 1440)) {
                $fail("periods.$index.end_time", 'End time must be after the period start, and a shift must be shorter than 24 hours.');
            }
            if ($end !== null && $late > $end) {
                $fail("periods.$index.late_after", 'Late threshold must be before the period end.');
            }
            if ($end !== null && $end >= 1440 && $index !== $lastIndex) {
                $fail("periods.$index.end_time", 'Only the last period can end after midnight.');
            }
            $previousEnd = $end;
        }
        $length = $previousEnd !== null ? $previousEnd - $firstStart : null;
        if ($breakMinutes > 0 && $length !== null && $breakMinutes >= $length) {
            $fail('break_minutes', 'The unpaid break must be shorter than the shift.');
        }
    }

    private function owned(Request $request, int $id): Schedule
    {
        return $request->user()->organization->schedules()->findOrFail($id);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GroupController extends Controller
{
    public function options(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->organization->groups()->orderBy('name')->get(['id', 'name', 'attendance_mode'])]);
    }

    public function index(Request $request): JsonResponse
    {
        $groups = $request->user()->organization->groups()->when($request->string('search')->trim()->value(), fn ($query, $search) => $query->where('name', 'like', "%{$search}%"))->withCount(['subgroups', 'persons', 'schedules' => fn ($query) => $query->current(now($request->user()->organization->timezone)->toDateString())])->orderBy('name')->paginate(min($request->integer('per_page', 20), 100))->withQueryString();

        return response()->json($groups);
    }

    public function store(Request $request): JsonResponse
    {
        $organization = $request->user()->organization;
        $data = $request->validate(['name' => ['required', 'string', 'max:255', Rule::unique('groups')->where('organization_id', $organization->id)], ...$this->modeRules()]);
        if ($organization->groups()->whereRaw('lower(name) = ?', [strtolower(trim($data['name']))])->exists()) {
            return response()->json(['message' => 'That group name already exists in this organization.', 'errors' => ['name' => ['That group name already exists in this organization.']]], 422);
        }

        return response()->json(['group' => $organization->groups()->create(['name' => trim($data['name']), ...$this->mode($data)])], 201);
    }

    public function show(Request $request, int $group): JsonResponse
    {
        return response()->json(['group' => $this->owned($request, $group)->loadCount(['subgroups', 'persons', 'schedules'])]);
    }

    public function update(Request $request, int $group): JsonResponse
    {
        $owned = $this->owned($request, $group);
        $data = $request->validate(['name' => ['required', 'string', 'max:255', Rule::unique('groups')->where('organization_id', $owned->organization_id)->ignore($owned->id)], ...$this->modeRules()]);
        if ($request->user()->organization->groups()->whereRaw('lower(name) = ?', [strtolower(trim($data['name']))])->whereKeyNot($owned->id)->exists()) {
            return response()->json(['message' => 'That group name already exists in this organization.', 'errors' => ['name' => ['That group name already exists in this organization.']]], 422);
        }
        $owned->update(['name' => trim($data['name']), ...$this->mode($data)]);

        return response()->json(['group' => $owned->fresh()]);
    }

    public function destroy(Request $request, int $group): JsonResponse
    {
        $owned = $this->owned($request, $group);
        // Ended schedule versions are only kept for past reports and do not block deleting the group.
        if ($owned->subgroups()->exists() || $owned->persons()->exists() || $owned->schedules()->current(now($request->user()->organization->timezone)->toDateString())->exists()) {
            return response()->json(['message' => 'This group cannot be deleted while it has subgroups, people, or schedules.'], 409);
        }
        $owned->delete();

        return response()->json(['message' => 'Group deleted.']);
    }

    /** @return array<string, array<int, mixed>> */
    private function modeRules(): array
    {
        return ['attendance_mode' => ['sometimes', Rule::in([Group::MODE_SCHEDULED, Group::MODE_OPEN])], 'scan_out' => ['sometimes', 'boolean']];
    }

    /**
     * How people in the group check in, when the form sent it. Scanning out only applies to open-hours groups.
     *
     * @return array{attendance_mode?: string, scan_out?: bool}
     */
    private function mode(array $data): array
    {
        if (! isset($data['attendance_mode'])) {
            return [];
        }

        return ['attendance_mode' => $data['attendance_mode'], 'scan_out' => $data['attendance_mode'] === Group::MODE_OPEN && ($data['scan_out'] ?? false)];
    }

    private function owned(Request $request, int $id): Group
    {
        return $request->user()->organization->groups()->findOrFail($id);
    }
}

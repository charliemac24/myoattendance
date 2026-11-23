<?php

namespace App\Http\Controllers;

use App\Models\Subgroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubgroupController extends Controller
{
    public function options(Request $request): JsonResponse
    {
        $subgroups = $request->user()->organization->subgroups()->when($request->filled('group_id'), fn ($query) => $query->where('group_id', $request->integer('group_id')))->orderBy('name')->get(['id', 'group_id', 'name']);

        return response()->json(['data' => $subgroups]);
    }

    public function index(Request $request): JsonResponse
    {
        $subgroups = $request->user()->organization->subgroups()->with('group:id,name')->withCount('persons')
            ->when($request->string('search')->trim()->value(), fn ($query, $search) => $query->where('name', 'like', "%{$search}%"))
            ->when($request->filled('group_id'), fn ($query) => $query->where('group_id', $request->integer('group_id')))
            ->orderBy('name')->paginate(min($request->integer('per_page', 20), 100))->withQueryString();

        return response()->json($subgroups);
    }

    public function store(Request $request): JsonResponse
    {
        $organization = $request->user()->organization;
        $data = $request->validate(['group_id' => ['required', 'integer'], 'name' => ['required', 'string', 'max:255']]);
        $group = $organization->groups()->findOrFail($data['group_id']);
        if ($organization->subgroups()->where('group_id', $group->id)->whereRaw('lower(name) = ?', [strtolower(trim($data['name']))])->exists()) {
            return response()->json(['message' => 'That subgroup name already exists in this group.', 'errors' => ['name' => ['That subgroup name already exists in this group.']]], 422);
        }

        return response()->json(['subgroup' => $organization->subgroups()->create(['group_id' => $group->id, 'name' => trim($data['name'])])->load('group:id,name')], 201);
    }

    public function show(Request $request, int $subgroup): JsonResponse
    {
        return response()->json(['subgroup' => $this->owned($request, $subgroup)->load('group:id,name')]);
    }

    public function update(Request $request, int $subgroup): JsonResponse
    {
        $owned = $this->owned($request, $subgroup);
        $data = $request->validate(['group_id' => ['required', 'integer'], 'name' => ['required', 'string', 'max:255']]);
        $group = $request->user()->organization->groups()->findOrFail($data['group_id']);
        $duplicate = $request->user()->organization->subgroups()->where('group_id', $group->id)->whereRaw('lower(name) = ?', [strtolower(trim($data['name']))])->whereKeyNot($owned->id)->exists();
        if ($duplicate) {
            return response()->json(['message' => 'That subgroup name already exists in this group.', 'errors' => ['name' => ['That subgroup name already exists in this group.']]], 422);
        }
        $owned->update(['group_id' => $group->id, 'name' => trim($data['name'])]);

        return response()->json(['subgroup' => $owned->fresh()->load('group:id,name')]);
    }

    public function destroy(Request $request, int $subgroup): JsonResponse
    {
        $owned = $this->owned($request, $subgroup);
        $today = now($request->user()->organization->timezone)->toDateString();
        if ($owned->persons()->exists() || $owned->schedules()->current($today)->exists()) {
            return response()->json(['message' => 'This subgroup cannot be deleted while it has people or schedules.'], 409);
        }
        if ($owned->hasAttendanceHistory()) {
            return response()->json(['message' => 'This subgroup has past attendance, so deleting it would change old reports. Rename it instead.'], 409);
        }
        // Ended schedule versions were only kept for past reports; they go with the subgroup.
        $owned->schedules()->delete();
        $owned->delete();

        return response()->json(['message' => 'Subgroup deleted.']);
    }

    private function owned(Request $request, int $id): Subgroup
    {
        return $request->user()->organization->subgroups()->findOrFail($id);
    }
}

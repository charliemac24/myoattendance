<?php

namespace App\Http\Controllers;

use App\Support\OrganizationTerminology;
use App\Support\OrganizationTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrganizationTerminologyController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['terminology' => OrganizationTerminology::forOrganization($request->user()->organization)]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'person_singular' => ['required', 'string', 'max:40'], 'person_plural' => ['required', 'string', 'max:40'],
            'group_singular' => ['required', 'string', 'max:40'], 'group_plural' => ['required', 'string', 'max:40'],
            'subgroup_singular' => ['required', 'string', 'max:40'], 'subgroup_plural' => ['required', 'string', 'max:40'],
        ]);
        $data = array_map(static fn (string $value): string => trim($value), $data);
        $organization = $request->user()->organization;
        $organization->update(['terminology' => $data]);

        return response()->json(['terminology' => OrganizationTerminology::forOrganization($organization->fresh())]);
    }

    /**
     * Switch the organization's industry. Its labels switch to that industry's wording (custom labels are cleared).
     * Only the owner can do it: the industry also decides how past reports read (late, holidays, rest days).
     */
    public function updateIndustry(Request $request): JsonResponse
    {
        if (! $request->user()->isOwner()) {
            return response()->json(['message' => 'Only the owner can change the industry.'], 403);
        }
        $data = $request->validate(['type' => ['required', 'string', Rule::in(OrganizationTypes::VALUES)]]);
        $organization = $request->user()->organization;
        $organization->update(['type' => $data['type'], 'terminology' => null]);

        return response()->json(['type' => $organization->type, 'terminology' => OrganizationTerminology::forOrganization($organization->fresh())]);
    }

    public function reset(Request $request): JsonResponse
    {
        $organization = $request->user()->organization;
        $organization->update(['terminology' => null]);

        return response()->json(['terminology' => OrganizationTerminology::forOrganization($organization->fresh())]);
    }
}

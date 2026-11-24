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
        $page = $request->validate([
            // The industry the Labels page was showing, and which labels the person actually typed into.
            'type' => ['nullable', 'string'],
            'edited' => ['nullable', 'array'],
            'edited.*' => ['string', Rule::in(array_keys($data))],
        ]);
        $data = array_map(static fn (string $value): string => trim($value), $data);
        $organization = $request->user()->organization;
        // A page opened before an industry switch still holds the old industry's words; saving it would pin them.
        if (isset($page['type']) && $page['type'] !== $organization->type) {
            return response()->json(['message' => 'The industry was changed since this page opened. Reload the page and try again.'], 409);
        }
        if (array_key_exists('edited', $page)) {
            $data = array_replace(OrganizationTerminology::forOrganization($organization), array_intersect_key($data, array_flip($page['edited'] ?? [])));
        }
        // Keep only the labels that differ from the industry's own words, so the rest follow the industry when it changes.
        $custom = array_diff_assoc($data, OrganizationTerminology::defaults($organization->type));
        $organization->update(['terminology' => $custom === [] ? null : $custom]);

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

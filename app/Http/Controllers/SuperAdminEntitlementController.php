<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\OrganizationFeatureRequest;
use App\Services\FeatureEntitlementService;
use App\Services\PaymentActivationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SuperAdminEntitlementController extends Controller
{
    public function enable(Request $request, Organization $organization, FeatureEntitlementService $service): JsonResponse
    {
        abort_if($organization->featureRequests()
            ->where('feature', OrganizationFeatureRequest::FEATURE_SMS)
            ->where('status', OrganizationFeatureRequest::STATUS_PENDING)
            ->whereHas('organization.smsPricingAgreements', fn ($query) => $query->where('status', 'active'))
            ->exists(), 409, 'This SMS request has a quoted price. Wait for the owner payment, then approve the payment to activate SMS.');
        $data = $request->validate(['starts_at' => ['nullable', 'date'], 'expires_at' => ['nullable', 'date', 'after_or_equal:starts_at']]);

        $entitlement = $service->enableSms($organization, $request->user(), $data['starts_at'] ?? null, $data['expires_at'] ?? null);
        OrganizationFeatureRequest::query()
            ->where('organization_id', $organization->id)
            ->where('feature', OrganizationFeatureRequest::FEATURE_SMS)
            ->where('status', OrganizationFeatureRequest::STATUS_PENDING)
            ->update(['status' => OrganizationFeatureRequest::STATUS_APPROVED, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);

        return response()->json(['entitlement' => $entitlement]);
    }

    public function extend(Request $request, Organization $organization, FeatureEntitlementService $service): JsonResponse
    {
        $data = $request->validate(['expires_at' => ['required', 'date', 'after_or_equal:today']]);

        return response()->json(['entitlement' => $service->extendSms($organization, $request->user(), $data['expires_at'])]);
    }

    public function resume(Request $request, Organization $organization, FeatureEntitlementService $service): JsonResponse
    {
        return response()->json(['entitlement' => $service->resumeSms($organization, $request->user())]);
    }

    /**
     * Starts a paid plan that was waiting for the free trial to end today instead (Scan, and SMS when it was paid with it).
     */
    public function startNow(Request $request, Organization $organization, PaymentActivationService $service): JsonResponse
    {
        return response()->json(['subscription' => $service->startScheduledPlanNow($organization, $request->user())]);
    }

    public function disable(Request $request, Organization $organization, FeatureEntitlementService $service): JsonResponse
    {
        return response()->json(['entitlement' => $service->disableSms($organization, $request->user())]);
    }
}

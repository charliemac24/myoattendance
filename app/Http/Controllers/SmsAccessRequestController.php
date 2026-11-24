<?php

namespace App\Http\Controllers;

use App\Mail\SmsPriceRequestedAlert;
use App\Models\OrganizationFeatureRequest;
use App\Services\AdminAlertService;
use App\Services\BaseSubscriptionService;
use App\Services\BillingService;
use App\Services\FeatureEntitlementService;
use App\Services\SmsAccessRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SmsAccessRequestController extends Controller
{
    public function show(Request $request, FeatureEntitlementService $features, BaseSubscriptionService $subscriptions, BillingService $billing): JsonResponse
    {
        $organization = $request->user()->organization;
        abort_unless($organization, 403);
        $latest = $organization->featureRequests()->where('feature', OrganizationFeatureRequest::FEATURE_SMS)->latest('requested_at')->first();
        $entitlement = $organization->featureEntitlements()->where('feature', 'sms')->first();
        $access = $features->canUseSms($organization) ? 'active' : ($entitlement?->enabled && $entitlement->starts_at?->toDateString() > today()->toDateString() ? 'scheduled' : ($entitlement?->enabled && $entitlement->expires_at?->toDateString() < today()->toDateString() ? 'expired' : 'inactive'));

        return response()->json(['subscription' => $subscriptions->payload($organization), 'sms' => ['access' => $access, 'starts_at' => $entitlement?->starts_at, 'expires_at' => $entitlement?->expires_at, 'request_status' => $latest?->status, 'requested_at' => $latest?->requested_at, 'quote_sent_at' => $latest?->quote_sent_at, 'activation_mode' => $latest?->activation_mode, 'request_reason' => $latest?->status === OrganizationFeatureRequest::STATUS_REJECTED ? $latest->notes : null, 'pricing' => $organization->smsPricingAgreements()->where('status', 'active')->latest('effective_from')->first(), 'active_people_count' => $organization->persons()->where('is_active', true)->count(), 'in_trial' => ($plan = $billing->currentPlan($organization))['in_trial'], 'next_step' => $billing->smsNextStep($plan)]]);
    }

    public function store(Request $request, SmsAccessRequestService $service, AdminAlertService $alerts): JsonResponse
    {
        abort_unless($request->user()->organization, 403);
        abort_unless(in_array($request->user()->role, ['owner', 'admin'], true), 403);
        $data = $request->validate([
            'activation_mode' => ['nullable', 'string', 'in:after_trial,immediate'],
            'people' => ['nullable', 'required_with:texts,days', 'integer', 'min:1', 'max:100000'],
            'texts' => ['nullable', 'required_with:people,days', 'array', 'min:1'],
            'texts.*' => ['string', 'in:time_in,time_out,late'],
            'days' => ['nullable', 'required_with:people,texts', 'string', 'in:weekdays,mon_sat,every_day'],
        ]);
        $answers = isset($data['people']) ? ['people' => (int) $data['people'], 'texts' => $data['texts'], 'days' => $data['days']] : null;

        try {
            $featureRequest = $service->request($request->user()->organization, $request->user(), $data['activation_mode'] ?? null, $answers);
        } catch (\DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        if ($featureRequest->wasRecentlyCreated) {
            $alerts->send(new SmsPriceRequestedAlert($request->user()->organization->fresh(), $request->user()->name));
        }

        return response()->json(['request' => $featureRequest], $featureRequest->wasRecentlyCreated ? 201 : 200);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\OrganizationFeatureRequest;
use App\Services\BaseSubscriptionService;
use App\Services\BillingService;
use App\Services\SmsAccessRequestService;
use App\Services\SmsPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SuperAdminSmsRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $requests = OrganizationFeatureRequest::with(['organization:id,name,type', 'requestedBy:id,name,email', 'reviewedBy:id,name'])
            ->where('feature', OrganizationFeatureRequest::FEATURE_SMS)
            ->where('status', OrganizationFeatureRequest::STATUS_PENDING)
            ->latest('requested_at')->paginate(min($request->integer('per_page', 20), 100))->withQueryString();

        return response()->json($requests);
    }

    public function show(OrganizationFeatureRequest $featureRequest, BaseSubscriptionService $subscriptions): JsonResponse
    {
        abort_unless($featureRequest->feature === OrganizationFeatureRequest::FEATURE_SMS, 404);
        $featureRequest->load(['organization.owner:id,organization_id,name,email', 'requestedBy:id,name,email', 'reviewedBy:id,name', 'organization.featureEntitlements', 'organization.payments' => fn ($q) => $q->latest('paid_at')->limit(10)]);

        $organization = $featureRequest->organization;

        return response()->json(['request' => $featureRequest, 'organization_context' => ['active_people_count' => $organization->persons()->where('is_active', true)->count(), 'subscription' => $subscriptions->payload($organization), 'sms_pricing' => $organization->smsPricingAgreements()->where('status', 'active')->latest('effective_from')->first(), 'sms_access' => $organization->featureEntitlements->firstWhere('feature', 'sms')]]);
    }

    public function setPricing(Request $request, OrganizationFeatureRequest $featureRequest, BillingService $billing, SmsPricingService $pricing): JsonResponse
    {
        abort_unless($featureRequest->feature === OrganizationFeatureRequest::FEATURE_SMS, 404);
        $data = $request->validate(['sms_monthly_price' => ['required', 'string', 'regex:/^\d+(?:\.\d{1,2})?$/'], 'effective_from' => ['required', 'date'], 'notes' => ['nullable', 'string', 'max:5000']]);
        $minor = self::toMinor($data['sms_monthly_price']);
        abort_if($minor < 1 || $minor > 999999999999, 422, 'The SMS price is outside the allowed range.');
        $organization = $featureRequest->organization;
        $agreement = $pricing->setPrice($organization, $request->user(), $minor, $data['effective_from'], $data['notes'] ?? null);
        $bill = $billing->currentBill($organization);

        return response()->json(['agreement' => $agreement, 'bill' => $bill, 'monthly_total_minor' => BaseSubscriptionService::MONTHLY_PRICE_MINOR + $minor]);
    }

    public function sendQuote(Request $request, OrganizationFeatureRequest $featureRequest): JsonResponse
    {
        abort_unless($featureRequest->feature === OrganizationFeatureRequest::FEATURE_SMS, 404);
        abort_if(! $featureRequest->organization->smsPricingAgreements()->where('status', 'active')->exists(), 422, 'Set the SMS price before sending payment instructions.');

        $featureRequest->update(['quote_sent_at' => now()]);

        return response()->json(['message' => 'Payment instructions are now available to the organization owner.', 'request' => $featureRequest->fresh()]);
    }

    private static function toMinor(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    public function approve(Request $request, OrganizationFeatureRequest $featureRequest, SmsAccessRequestService $service): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
            'reference_number' => ['nullable', 'string', 'max:128'],
            'paid_at' => ['required', 'date'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'coverage_start' => ['nullable', 'date'],
            'coverage_end' => ['nullable', 'date', 'after_or_equal:coverage_start'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $data['amount_minor'] = (int) round(((float) $data['amount']) * 100);
        unset($data['amount']);

        try {
            return response()->json(['request' => $service->approve($featureRequest, $request->user(), $data)]);
        } catch (\DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }

    public function reject(Request $request, OrganizationFeatureRequest $featureRequest, SmsAccessRequestService $service): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);

        try {
            return response()->json(['request' => $service->reject($featureRequest, $request->user(), $data['reason'])]);
        } catch (\DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }
}

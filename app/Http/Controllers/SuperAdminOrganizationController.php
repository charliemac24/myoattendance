<?php

namespace App\Http\Controllers;

use App\Models\FeatureEntitlement;
use App\Models\Organization;
use App\Models\OrganizationFeatureRequest;
use App\Models\Payment;
use App\Models\SmsPricingAgreement;
use App\Services\BaseSubscriptionService;
use App\Services\BillingService;
use App\Services\FeatureEntitlementService;
use App\Services\SmsPriceEstimator;
use App\Services\SmsPricingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SuperAdminOrganizationController extends Controller
{
    public function index(Request $request, FeatureEntitlementService $features, BaseSubscriptionService $subscriptions): JsonResponse
    {
        $search = $request->string('search')->trim()->value();
        $organizations = Organization::query()->with(['owner:id,organization_id,name,email', 'featureEntitlements', 'baseSubscription'])
            ->withCount(['users', 'persons', 'persons as active_people_count' => fn ($query) => $query->where('is_active', true)])
            ->when($search, fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhereHas('owner', fn ($owner) => $owner->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))))
            ->when(in_array($request->input('filter'), ['sms_enabled', 'sms_expired', 'sms_disabled'], true), function ($query) use ($request): void {
                $filter = $request->input('filter');
                $query->whereHas('featureEntitlements', function ($entitlement) use ($filter): void {
                    $entitlement->where('feature', FeatureEntitlement::SMS);
                    if ($filter === 'sms_enabled') {
                        $entitlement->where('enabled', true)->where(fn ($q) => $q->whereNull('starts_at')->orWhereDate('starts_at', '<=', today()))
                            ->where(fn ($q) => $q->whereNull('expires_at')->orWhereDate('expires_at', '>=', today()));
                    } elseif ($filter === 'sms_expired') {
                        $entitlement->where('enabled', true)->whereDate('expires_at', '<', today());
                    } else {
                        $entitlement->where('enabled', false);
                    }
                });
            })
            ->when(in_array($request->input('type'), ['school', 'fitness'], true), fn ($query) => $query->where('type', $request->input('type')))
            ->when($request->input('type') === 'other', fn ($query) => $query->whereNotIn('type', ['school', 'fitness']))
            ->orderBy('name');
        $perPage = min($request->integer('per_page', 20), 100);
        $plan = $request->input('plan');
        $sms = $request->input('sms');
        $planFilter = in_array($plan, ['trialing', 'active', 'expired', 'under_review', 'expiring_soon', 'expired_with_sms', 'expired_base_only'], true);
        $smsFilter = in_array($sms, ['on', 'scheduled', 'off', 'price_requested'], true);

        if (! $planFilter && ! $smsFilter) {
            $organizations = $organizations->paginate($perPage)->withQueryString();
            $organizations->getCollection()->transform(fn (Organization $organization) => $this->summary($organization, $features, $subscriptions));

            return response()->json($organizations);
        }

        // Plan and SMS status come from the same billing rules the panel uses, so filter on the computed summary.
        $matches = $organizations->get()
            ->map(fn (Organization $organization) => $this->summary($organization, $features, $subscriptions))
            ->filter(function (array $row) use ($plan, $sms, $planFilter, $smsFilter): bool {
                $current = $row['current_plan'];
                $planOk = ! $planFilter || match ($plan) {
                    'under_review' => ! empty($current['pending_payment']),
                    'expiring_soon' => self::expiringSoon($row),
                    'expired_with_sms' => self::expiredWithSms($row),
                    'expired_base_only' => self::expiredBaseOnly($row),
                    default => $current['status'] === $plan,
                };
                $smsState = in_array($current['sms']['state'], ['on', 'scheduled'], true) ? $current['sms']['state'] : 'off';

                return $planOk && (! $smsFilter || ($sms === 'price_requested' ? self::smsPriceRequested($row) : $smsState === $sms));
            })->values();
        $page = max(1, $request->integer('page', 1));

        return response()->json((new LengthAwarePaginator($matches->forPage($page, $perPage)->values(), $matches->count(), $perPage, $page, ['path' => $request->url()]))->withQueryString());
    }

    public function show(Organization $organization, FeatureEntitlementService $features, BaseSubscriptionService $subscriptions): JsonResponse
    {
        $organization->load(['owner:id,organization_id,name,email', 'featureEntitlements.enabledBy:id,name', 'featureRequests.requestedBy:id,name,email', 'featureRequests.reviewedBy:id,name', 'baseSubscription', 'smsPricingAgreements.createdBy:id,name', 'payments' => fn ($q) => $q->with('recordedBy:id,name')->latest('paid_at')]);
        $entitlement = $organization->featureEntitlements->firstWhere('feature', FeatureEntitlement::SMS);

        return response()->json(['organization' => array_merge($this->summary($organization, $features, $subscriptions), [
            'sms_sender_name' => $organization->sms_sender_name,
            'sms_sender_name_effective' => $organization->sms_sender_name ?: config('services.semaphore.sender_name'),
            'payments' => $organization->payments->map(fn (Payment $payment) => $this->payment($payment))->values(),
            'sms_entitlement' => $entitlement,
            'sms_pricing' => $organization->smsPricingAgreements()->where('status', SmsPricingAgreement::STATUS_ACTIVE)->latest('effective_from')->first(),
            'active_people_count' => $organization->active_people_count ?? $organization->persons()->where('is_active', true)->count(),
            'sms_requests' => $organization->featureRequests()->where('feature', 'sms')->latest('requested_at')->get(),
            'sms_pricing_history' => app(SmsPricingService::class)->history($organization),
            'sms_estimate' => self::estimateAnswers($organization),
        ])]);
    }

    /**
     * Change a school's monthly SMS price at any time. It applies to bills that start on or after
     * the effective date (today by default); the current paid period is never touched.
     */
    public function updateSmsPricing(Request $request, Organization $organization, SmsPricingService $pricing): JsonResponse
    {
        $data = $request->validate([
            'sms_monthly_price' => ['required', 'string', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'effective_from' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        [$whole, $fraction] = array_pad(explode('.', $data['sms_monthly_price'], 2), 2, '');
        $minor = ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
        abort_if($minor < 1 || $minor > 999999999999, 422, 'The SMS price is outside the allowed range.');

        $agreement = $pricing->setPrice($organization, $request->user(), $minor, $data['effective_from'] ?? null, $data['notes'] ?? null);

        return response()->json(['agreement' => $agreement, 'history' => $pricing->history($organization)]);
    }

    public function updateSmsSenderName(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate([
            'sender_name' => ['nullable', 'string', 'max:11', 'regex:/^[A-Za-z0-9]+$/'],
        ]);

        $organization->update(['sms_sender_name' => $data['sender_name'] ?: null]);

        return response()->json([
            'message' => 'SMS sender name updated.',
            'organization' => [
                'id' => $organization->id,
                'sms_sender_name' => $organization->sms_sender_name,
                'sms_sender_name_effective' => $organization->sms_sender_name ?: config('services.semaphore.sender_name'),
            ],
        ]);
    }

    public function deleteCurrentDayAttendance(Organization $organization): JsonResponse
    {
        $dayStart = Carbon::now($organization->timezone)->startOfDay()->utc();
        $nextDayStart = $dayStart->copy()->addDay();

        $deleted = DB::transaction(function () use ($organization, $dayStart, $nextDayStart): array {
            $eventQuery = $organization->attendanceEvents()
                ->where('occurred_at', '>=', $dayStart)
                ->where('occurred_at', '<', $nextDayStart);
            $eventIds = $eventQuery->pluck('id');
            $smsMessages = $eventIds->isEmpty() ? 0 : $organization->smsMessages()->whereIn('attendance_event_id', $eventIds)->count();
            $events = $eventIds->count();
            $eventQuery->delete();

            $scanIdempotencies = $organization->scanIdempotencies()
                ->where('processed_at', '>=', $dayStart)
                ->where('processed_at', '<', $nextDayStart)
                ->delete();

            return ['events' => $events, 'sms_messages' => $smsMessages, 'scan_idempotencies' => $scanIdempotencies];
        });

        return response()->json([
            'message' => 'Current-day attendance records deleted.',
            'organization_id' => $organization->id,
            'local_date' => $dayStart->copy()->setTimezone($organization->timezone)->toDateString(),
            'deleted' => $deleted,
        ]);
    }

    public function dashboard(FeatureEntitlementService $features, BaseSubscriptionService $subscriptions): JsonResponse
    {
        $organizations = Organization::with(['featureEntitlements', 'baseSubscription'])->get();
        $rows = $organizations->map(fn (Organization $organization) => $this->summary($organization, $features, $subscriptions));
        $active = $organizations->filter(fn ($organization) => $features->canUseSms($organization))->count();
        $expired = $organizations->filter(fn ($organization) => ($e = $organization->featureEntitlements->firstWhere('feature', FeatureEntitlement::SMS))?->enabled && $e->expires_at?->toDateString() < today()->toDateString())->count();

        return response()->json(['total_organizations' => $organizations->count(),
            'expiring_soon' => $rows->filter(fn (array $row) => self::expiringSoon($row))->count(),
            'expired_with_sms' => $rows->filter(fn (array $row) => self::expiredWithSms($row))->count(),
            'expired_base_only' => $rows->filter(fn (array $row) => self::expiredBaseOnly($row))->count(),
            'sms_price_requests' => $rows->filter(fn (array $row) => self::smsPriceRequested($row))->count(), 'organizations_on_trial' => $organizations->filter(fn ($o) => $subscriptions->statusFor($o) === 'trialing')->count(), 'active_subscribers' => $organizations->filter(fn ($o) => $subscriptions->statusFor($o) === 'active')->count(), 'expired_subscriptions' => $organizations->filter(fn ($o) => $subscriptions->statusFor($o) === 'expired')->count(), 'active_sms_subscribers' => $active, 'expired_sms_subscribers' => $expired, 'pending_sms_requests' => OrganizationFeatureRequest::where('feature', 'sms')->where('status', 'pending')->count(), 'pending_payment_reviews' => Payment::where('status', Payment::STATUS_PENDING)->count(), 'recent_payments' => Payment::with(['organization:id,name', 'recordedBy:id,name'])->latest()->latest('id')->limit(10)->get()->map(fn ($p) => $this->payment($p))]);
    }

    /**
     * The school's own SMS answers, for showing next to an estimated price.
     *
     * @return array{people: int, texts: list<string>, texts_per_day: int, days: ?string, amount_minor: ?int}|null
     */
    public static function estimateAnswers(Organization $organization): ?array
    {
        if (! $organization->sms_estimate_people) {
            return null;
        }
        $texts = $organization->sms_estimate_texts ?? [];

        return [
            'people' => $organization->sms_estimate_people,
            'texts' => $texts,
            'texts_per_day' => SmsPriceEstimator::textsPerDay($texts),
            'days' => $organization->sms_estimate_days,
            'amount_minor' => SmsPriceEstimator::hasAnswers($organization)
                ? app(SmsPriceEstimator::class)->estimateMinor($organization->sms_estimate_people, $texts, $organization->sms_estimate_days)
                : null,
        ];
    }

    /** Days ahead that count as "expiring soon" on the dashboard. */
    public const EXPIRING_SOON_DAYS = 7;

    /**
     * Expiring soon: the Scan plan (paid Base or free trial) or SMS is still running and ends within the next 7 days.
     *
     * @param  array<string, mixed>  $row
     */
    private static function expiringSoon(array $row): bool
    {
        $plan = $row['current_plan'];
        $today = today()->toDateString();
        $limit = today()->addDays(self::EXPIRING_SOON_DAYS)->toDateString();
        $within = fn ($date): bool => $date !== null && ($day = Carbon::parse($date)->toDateString()) >= $today && $day <= $limit;
        $scanEnds = match ($plan['status']) {
            'active' => $plan['base_paid_through'],
            'trialing' => $plan['base_paid_through'] ?? $plan['trial_ends_at'],
            default => null,
        };

        return $within($scanEnds) || ($plan['sms']['state'] === 'on' && $within($plan['sms']['ends_at']));
    }

    /**
     * The school pressed "Request SMS" on Billing (custom price) and the request is still open.
     *
     * @param  array<string, mixed>  $row
     */
    private static function smsPriceRequested(array $row): bool
    {
        return in_array($row['current_plan']['sms']['state'], ['requested', 'price_ready'], true);
    }

    /**
     * Expired Base + SMS: the Scan plan has expired and the organization had SMS at some point (it has an SMS record).
     *
     * @param  array<string, mixed>  $row
     */
    private static function expiredWithSms(array $row): bool
    {
        return $row['current_plan']['status'] === 'expired' && $row['had_sms'];
    }

    /**
     * Expired Base only: the Scan plan has expired and the organization never had SMS.
     *
     * @param  array<string, mixed>  $row
     */
    private static function expiredBaseOnly(array $row): bool
    {
        return $row['current_plan']['status'] === 'expired' && ! $row['had_sms'];
    }

    private function summary(Organization $organization, FeatureEntitlementService $features, BaseSubscriptionService $subscriptions): array
    {
        $entitlement = $organization->featureEntitlements->firstWhere('feature', FeatureEntitlement::SMS) ?? $organization->featureEntitlements()->where('feature', FeatureEntitlement::SMS)->first();
        $status = $features->canUseSms($organization) ? 'active' : ($entitlement?->enabled && $entitlement->starts_at?->toDateString() > today()->toDateString() ? 'scheduled' : ($entitlement?->enabled && $entitlement->expires_at?->toDateString() < today()->toDateString() ? 'expired' : 'disabled'));

        return ['id' => $organization->id, 'name' => $organization->name, 'type' => $organization->type, 'created_at' => $organization->created_at, 'owner' => $organization->owner, 'users_count' => $organization->users_count ?? $organization->users()->count(), 'people_count' => $organization->persons_count ?? $organization->persons()->count(), 'active_people_count' => $organization->active_people_count ?? $organization->persons()->where('is_active', true)->count(), 'base_subscription' => $subscriptions->payload($organization), 'sms_access_status' => $status, 'sms_resumable_until' => $features->resumableSmsUntil($organization)?->toDateString(), 'had_sms' => $entitlement !== null, 'sms_starts_at' => $entitlement?->starts_at, 'sms_expires_at' => $entitlement?->expires_at, 'sms_pricing' => $organization->smsPricingAgreements()->where('status', SmsPricingAgreement::STATUS_ACTIVE)->latest('effective_from')->first(), 'current_plan' => app(BillingService::class)->currentPlan($organization)];
    }

    private function payment(Payment $payment): array
    {
        return ['id' => $payment->id, 'organization' => $payment->organization, 'payment_type' => $payment->payment_type, 'status' => $payment->status, 'amount_minor' => $payment->amount_minor, 'currency' => $payment->currency, 'payment_method' => $payment->payment_method, 'manual_entry' => $payment->manual_entry, 'reference_number' => $payment->reference_number, 'paid_at' => $payment->paid_at, 'coverage_start' => $payment->coverage_start, 'coverage_end' => $payment->coverage_end, 'notes' => $payment->notes, 'recorded_by' => $payment->recordedBy];
    }
}

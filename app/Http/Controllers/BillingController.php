<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\FeatureEntitlement;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\SmsPricingAgreement;
use App\Services\BaseSubscriptionService;
use App\Services\BillingService;
use App\Services\SmsPriceEstimator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class BillingController extends Controller
{
    /**
     * @var array<string, string>
     */
    private const PROOF_MESSAGES = [
        'proof.uploaded' => 'The receipt could not be uploaded. Use a JPG, PNG, or PDF under 5 MB, or submit without a receipt.',
        'proof.max' => 'The receipt must be 5 MB or smaller.',
        'proof.mimes' => 'The receipt must be a JPG, PNG, or PDF file.',
    ];

    private function authorizeManager(Request $request): void
    {
        abort_unless(in_array($request->user()->role, ['owner', 'admin'], true), 403);
    }

    public function show(Request $request, BaseSubscriptionService $subscriptions, BillingService $billing, SmsPriceEstimator $estimator): JsonResponse
    {
        $this->authorizeManager($request);
        $organization = $request->user()->organization;
        $request->validate(['type' => ['nullable', Rule::in(Bill::ALL_TYPES)], 'months' => ['nullable', 'integer', Rule::in(Bill::MONTH_OPTIONS)]]);
        $type = $request->input('type') ?? ($request->has('include_sms') ? $request->boolean('include_sms') : null);

        try {
            $bill = $billing->currentBill($organization, $type, $request->filled('months') ? $request->integer('months') : null);
        } catch (\DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $pricing = $organization->smsPricingAgreements()->where('status', 'active')->latest('effective_from')->first();
        $availableTypes = $billing->availableTypes($organization);
        $smsEntitlement = $organization->featureEntitlements()->where('feature', FeatureEntitlement::SMS)->first();
        $payments = $organization->payments()->with('bill')->latest()->latest('id')->get();
        // The school's most recent payment of any kind, so a rejection shows even after switching plans.
        $latestPayment = $organization->payments()->with('bill')->whereNotNull('bill_id')->latest()->latest('id')->first();

        return response()->json([
            'subscription' => $subscriptions->payload($organization),
            'current_plan' => $currentPlan = $billing->currentPlan($organization),
            'sms_estimate' => $this->estimatePayload($organization, $estimator),
            'sms_now' => $smsNow = $billing->smsNowQuote($organization),
            'plan_change' => $billing->describeChange($currentPlan + ['sms_now_available' => $smsNow['available']], $bill),
            'current_bill' => $bill,
            'next_period_start' => $billing->nextPeriodStart($organization)->toDateString(),
            'bill_type' => $bill->bill_type,
            'months' => $bill->months,
            'month_options' => Bill::MONTH_OPTIONS,
            'available_types' => $availableTypes,
            'paid_bill' => $billing->latestUnexpiredPaidBill($organization, $bill->bill_type),
            'paid_through' => ['base' => $subscriptions->payload($organization)['subscription_expires_at'], 'sms' => $smsEntitlement?->enabled ? $smsEntitlement->expires_at?->toDateString() : null],
            'payment_under_review' => $billing->hasPaymentUnderReview($organization, $bill->bill_type),
            'latest_payment' => $latestPayment?->makeHidden('proof_path'),
            'sms_pricing' => $pricing,
            'renewal_options' => ['sms_available' => $availableTypes[Bill::TYPE_PACKAGE], 'sms_included' => $bill->coversSms()],
            'payments' => $payments->makeHidden('proof_path'),
        ]);
    }

    /**
     * A trial school chooses when its paid plan starts: after the free trial, or as soon as its payment is approved.
     */
    public function startChoice(Request $request, BillingService $billing): JsonResponse
    {
        $this->authorizeManager($request);
        $data = $request->validate(['choice' => ['required', Rule::in(['after_trial', 'immediate'])]]);

        try {
            $billing->setTrialStartChoice($request->user()->organization, $data['choice']);
        } catch (\DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['start_choice' => $data['choice']]);
    }

    /**
     * Save the school's SMS answers. Unless Super Admin set the price, the estimate becomes the school's SMS price.
     */
    public function smsEstimate(Request $request, SmsPriceEstimator $estimator): JsonResponse
    {
        $this->authorizeManager($request);
        $data = $request->validate([
            'people' => ['required', 'integer', 'min:1', 'max:100000'],
            'texts' => ['required', 'array', 'min:1'],
            'texts.*' => ['string', 'in:time_in,time_out,late'],
            'days' => ['required', 'string', 'in:weekdays,mon_sat,every_day'],
        ]);
        $result = $estimator->saveAnswers($request->user()->organization, $request->user(), (int) $data['people'], $data['texts'], $data['days']);

        return response()->json($result + ['message' => $result['applied'] ? 'Your SMS price is ready.' : 'Saved. Your SMS price is set by MYO Attendance.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function estimatePayload(Organization $organization, SmsPriceEstimator $estimator): array
    {
        $active = $organization->smsPricingAgreements()->where('status', SmsPricingAgreement::STATUS_ACTIVE)->latest('effective_from')->first();
        $texts = $organization->sms_estimate_texts ?? [];

        return [
            'people' => $organization->sms_estimate_people,
            'texts' => $texts,
            'days' => $organization->sms_estimate_days,
            'amount_minor' => $organization->sms_estimate_people && $organization->sms_estimate_days && SmsPriceEstimator::textsPerDay($texts) > 0
                ? $estimator->estimateMinor($organization->sms_estimate_people, $texts, $organization->sms_estimate_days)
                : null,
            'price_source' => $active?->source,
            'settings' => $estimator->settings(),
        ];
    }

    public function submit(Request $request, BillingService $billing): JsonResponse
    {
        $this->authorizeManager($request);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
            'bill_id' => ['required', 'integer'],
            'reference_number' => ['required', 'string', 'max:128'],
            'paid_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'payment_time' => ['nullable', 'date_format:H:i'],
            'proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ], self::PROOF_MESSAGES);
        $organization = $request->user()->organization;
        $path = ($data['proof'] ?? null)?->store('payment-proofs');
        $payment = DB::transaction(function () use ($data, $organization, $path, $request, $billing): Payment {
            $bill = $organization->bills()->lockForUpdate()->whereKey($data['bill_id'])->firstOrFail();
            abort_if($bill->status === Bill::STATUS_PAID, 422, 'This bill has already been paid. Refresh the page to pay for the next period.');
            abort_if($bill->status === Bill::STATUS_CANCELLED, 422, 'This bill is no longer current. Refresh the page and submit against the latest bill.');
            abort_if($bill->payments()->whereIn('status', [Payment::STATUS_PENDING, Payment::STATUS_PROOF_REQUIRED])->exists(), 422, 'A payment for this bill is already under review.');
            abort_if($billing->hasPaymentUnderReview($organization, $bill->bill_type, $bill->id), 422, 'Another payment covering the same plan is already under review. Wait for it to be reviewed before paying again.');
            if ($bill->bill_type === Bill::TYPE_SMS_NOW && $bill->status === Bill::STATUS_UNPAID) {
                $quote = $billing->smsNowQuote($organization);
                abort_unless($quote['available'], 422, 'Add SMS is no longer available. Refresh the page to see your options.');
                abort_if($bill->billing_period_start->toDateString() !== $quote['start'], 422, 'This SMS amount was for an earlier day. Refresh the page to see today\'s amount.');
            }
            abort_if(self::toMinor($data['amount']) !== (int) $bill->total_minor, 422, 'The amount must match the bill total of PHP '.number_format($bill->total_minor / 100, 2).'. Refresh the page and try again.');
            $referenceInUse = Payment::query()->where('reference_number', $data['reference_number'])->whereIn('status', [Payment::STATUS_PENDING, Payment::STATUS_APPROVED])->exists();
            abort_if($referenceInUse, 422, 'This payment reference is already active or approved.');
            $payment = $organization->payments()->create([
                'bill_id' => $bill->id, 'payment_type' => Payment::TYPE_MONTHLY_BILL, 'status' => Payment::STATUS_PENDING,
                'amount_minor' => self::toMinor($data['amount']), 'currency' => 'PHP', 'payment_method' => Payment::METHOD_GCASH,
                'reference_number' => $data['reference_number'], 'proof_path' => $path, 'paid_at' => $data['paid_at'], 'payment_time' => $data['payment_time'] ?? null, 'notes' => $data['notes'] ?? null, 'submitted_by' => $request->user()->id,
            ]);
            if ($path) {
                $payment->proofs()->create(['path' => $path, 'submitted_by' => $request->user()->id, 'submitted_at' => now()]);
            }
            $bill->update(['status' => Bill::STATUS_PAYMENT_PENDING]);

            return $payment;
        });

        return response()->json(['message' => 'Payment submitted for review.', 'payment' => $payment->makeHidden('proof_path')], 201);
    }

    private static function toMinor(string $amount): int
    {
        $amount = ltrim(trim($amount), '+');
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    public function proof(Request $request, Payment $payment)
    {
        abort_unless($payment->organization_id === $request->user()->organization_id && in_array($request->user()->role, ['owner', 'admin'], true), 403);
        abort_unless($payment->proof_path, 404);

        return Storage::download($payment->proof_path);
    }

    public function correctedProof(Request $request, Payment $payment): JsonResponse
    {
        abort_unless($payment->organization_id === $request->user()->organization_id && in_array($request->user()->role, ['owner', 'admin'], true), 403);
        abort_unless($payment->status === Payment::STATUS_PROOF_REQUIRED, 409, 'This payment is not waiting for corrected proof.');
        $data = $request->validate(['proof' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120']], self::PROOF_MESSAGES);
        $path = $data['proof']->store('payment-proofs');
        $payment->update(['status' => Payment::STATUS_PENDING, 'proof_path' => $path, 'proof_unavailable' => false]);
        $payment->proofs()->create(['path' => $path, 'submitted_by' => $request->user()->id, 'submitted_at' => now()]);

        return response()->json(['message' => 'Corrected proof submitted for review.', 'payment' => $payment->fresh()->makeHidden('proof_path')]);
    }

    public function proofUnavailable(Request $request, Payment $payment): JsonResponse
    {
        abort_unless($payment->organization_id === $request->user()->organization_id && in_array($request->user()->role, ['owner', 'admin'], true), 403);
        abort_unless($payment->status === Payment::STATUS_PROOF_REQUIRED, 409, 'This payment is not waiting for a proof response.');
        $payment->update(['status' => Payment::STATUS_PENDING, 'proof_unavailable' => true]);

        return response()->json(['message' => 'Your payment is back under review.', 'payment' => $payment->fresh()->makeHidden('proof_path')]);
    }
}

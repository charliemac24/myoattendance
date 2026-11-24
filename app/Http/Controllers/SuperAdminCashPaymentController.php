<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\FeatureEntitlement;
use App\Models\Organization;
use App\Models\Payment;
use App\Services\BillingService;
use App\Services\PaymentActivationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Cash paid to the admin outside the app: the admin picks the plan, the amount comes from the school's real bill,
 * and saving records the payment as Cash and runs the same Activate step as a submitted payment.
 */
class SuperAdminCashPaymentController extends Controller
{
    public function quote(Request $request, Organization $organization, BillingService $billing): JsonResponse
    {
        $data = $this->validatePlan($request, required: false);
        $available = $billing->availableTypes($organization);
        $plan = $data['plan'] ?? ($available[Bill::TYPE_SMS_NOW] ? Bill::TYPE_SMS_NOW : Bill::TYPE_BASE);

        try {
            $bill = $billing->currentBill($organization, $plan, $plan === Bill::TYPE_SMS_NOW ? null : (int) ($data['months'] ?? 1));
        } catch (\DomainException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'available_plans' => $available], 422);
        }

        return response()->json([
            'plan' => $plan,
            'available_plans' => $available,
            'bill' => $bill,
            'activates' => $activates = $this->activatesFor($bill),
            'activates_label' => Payment::activatesLabel($activates),
            'under_review' => $bill->status === Bill::STATUS_PAYMENT_PENDING || $billing->hasPaymentUnderReview($organization, $bill->bill_type, $bill->id),
            'starts_on' => $bill->billing_period_start->toDateString(),
            'sms_can_start_today' => $this->smsCanStartToday($organization, $bill, $billing),
        ]);
    }

    public function store(Request $request, Organization $organization, BillingService $billing, PaymentActivationService $activation): JsonResponse
    {
        $data = $this->validatePlan($request, required: true) + $request->validate([
            'received_at' => ['required', 'date', 'before_or_equal:today'],
            // What the school actually handed over; it can differ from the list price (discount, rounding). Coverage is always the full plan.
            'amount' => ['required', 'string', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/', 'not_regex:/^0+(?:\.0+)?$/'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'start_sms_today' => ['nullable', 'boolean'],
        ]);

        try {
            $bill = $billing->currentBill($organization, $data['plan'], $data['plan'] === Bill::TYPE_SMS_NOW ? null : (int) ($data['months'] ?? 1));
        } catch (\DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        abort_if($bill->status === Bill::STATUS_PAYMENT_PENDING || $billing->hasPaymentUnderReview($organization, $bill->bill_type, $bill->id), 409, 'This school already sent a payment for this plan that is waiting for review. Activate or reject it in Payments first.');

        // Base + SMS for a school whose current Base is still running starts with the next period; the admin can
        // start SMS today as well, free for the days left in the current Base period.
        $startSmsToday = ($data['start_sms_today'] ?? false) && $this->smsCanStartToday($organization, $bill, $billing);

        $payment = DB::transaction(function () use ($organization, $bill, $data, $request, $activation, $startSmsToday, $billing): Payment {
            $payment = $organization->payments()->create([
                'bill_id' => $bill->id, 'payment_type' => Payment::TYPE_MONTHLY_BILL, 'status' => Payment::STATUS_PENDING,
                'amount_minor' => self::toMinor($data['amount']), 'currency' => 'PHP', 'payment_method' => Payment::METHOD_CASH,
                'reference_number' => 'CASH-'.now()->format('ymd-His'), 'paid_at' => $data['received_at'], 'notes' => $data['notes'] ?? null,
                'submitted_by' => $request->user()->id, 'recorded_by' => $request->user()->id,
            ]);
            $bill->update(['status' => Bill::STATUS_PAYMENT_PENDING]);

            $payment = $activation->activate($payment, $request->user());
            if ($startSmsToday) {
                $organization->featureEntitlements()->where('feature', FeatureEntitlement::SMS)->firstOrFail()
                    ->update(['starts_at' => $billing->todayFor($organization)->toDateString()]);
            }

            return $payment;
        });

        return response()->json(['payment' => $payment, 'activated' => $activated = $payment->activates(), 'activated_label' => Payment::activatesLabel($activated)], 201);
    }

    /**
     * Fixes a recorded cash payment's amount, date received or note. The plan, months and coverage it turned on stay as they are.
     */
    public function update(Request $request, Payment $payment): JsonResponse
    {
        abort_unless($payment->payment_method === Payment::METHOD_CASH, 404);
        $data = $request->validate([
            'amount' => ['required', 'string', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/', 'not_regex:/^0+(?:\.0+)?$/'],
            'received_at' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $payment->update(['amount_minor' => self::toMinor($data['amount']), 'paid_at' => $data['received_at'], 'notes' => $data['notes'] ?? null]);

        return response()->json(['payment' => $payment->fresh(['organization:id,name', 'recordedBy:id,name', 'bill.items'])]);
    }

    /**
     * @return array{plan?: string, months?: int}
     */
    private function validatePlan(Request $request, bool $required): array
    {
        return $request->validate([
            'plan' => [$required ? 'required' : 'nullable', Rule::in(Bill::ALL_TYPES)],
            'months' => ['nullable', 'integer', Rule::in(Bill::MONTH_OPTIONS)],
        ]);
    }

    private function smsCanStartToday(Organization $organization, Bill $bill, BillingService $billing): bool
    {
        return $bill->bill_type === Bill::TYPE_PACKAGE
            && $bill->billing_period_start->toDateString() > $billing->todayFor($organization)->toDateString()
            && ! in_array($billing->currentPlan($organization)['sms']['state'], ['on', 'scheduled'], true)
            && ! $billing->currentPlan($organization)['in_trial'];
    }

    private static function toMinor(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private function activatesFor(Bill $bill): string
    {
        return (new Payment)->setRelation('bill', $bill->loadMissing('items'))->activates();
    }
}

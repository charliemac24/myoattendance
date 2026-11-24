<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\OrganizationFeatureRequest;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The single Activate step: approves a pending payment and turns on what its bill paid for
 * (Scan only, Scan + SMS, or SMS on top of Scan). Used for submitted payments and for cash recorded by an admin.
 */
class PaymentActivationService
{
    public function __construct(
        private readonly BaseSubscriptionService $subscriptions,
        private readonly FeatureEntitlementService $features,
    ) {}

    public function activate(Payment $payment, User $admin): Payment
    {
        return DB::transaction(function () use ($payment, $admin): Payment {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->status !== Payment::STATUS_PENDING || ! in_array($payment->payment_type, [Payment::TYPE_BASE_SUBSCRIPTION, Payment::TYPE_MONTHLY_BILL], true)) {
                abort(409, 'This payment cannot be approved.');
            }
            $bill = $payment->bill_id ? Bill::query()->with('items')->lockForUpdate()->findOrFail($payment->bill_id) : null;
            if ($bill && ! in_array($bill->bill_type, Bill::ALL_TYPES, true)) {
                abort(409, 'This payment is for an old plan and cannot be activated here.');
            }
            if ($bill && ($bill->organization_id !== $payment->organization_id || $bill->status === Bill::STATUS_PAID || ($payment->payment_method !== Payment::METHOD_CASH && $payment->amount_minor !== $bill->total_minor))) {
                abort(409, 'The payment does not match the bill or the bill is already paid.');
            }
            $payment->update(['status' => Payment::STATUS_APPROVED, 'reviewed_by' => $admin->id, 'reviewed_at' => now(), 'recorded_by' => $admin->id]);
            // One Activate button: the bill the organization paid decides Scan only, Scan + SMS, or SMS on top of Scan.
            $payment->setRelation('bill', $bill);
            $coversSms = $payment->activates() !== Payment::ACTIVATES_SCAN;
            $smsRequest = $coversSms
                ? OrganizationFeatureRequest::query()->where('organization_id', $payment->organization_id)->where('feature', OrganizationFeatureRequest::FEATURE_SMS)->where('status', OrganizationFeatureRequest::STATUS_PENDING)->latest('requested_at')->lockForUpdate()->first()
                : null;

            if ($bill?->bill_type === Bill::TYPE_SMS_NOW) {
                // "Add SMS": SMS from today (or the bill's first day) until the Base period ends. Base is untouched.
                $smsStart = collect([now()->startOfDay(), Carbon::parse($bill->billing_period_start->toDateString())])->max();
                $smsEnd = Carbon::parse($bill->billing_period_end->toDateString())->addDay();
                $this->features->coverSmsThrough($payment->organization, $admin, $smsStart, $smsEnd);
                $smsRequest?->update(['status' => OrganizationFeatureRequest::STATUS_APPROVED, 'reviewed_by' => $admin->id, 'reviewed_at' => now()]);
                $payment->update(['coverage_start' => $smsStart->toDateString(), 'coverage_end' => $smsEnd->toDateString()]);
                $bill->update(['status' => Bill::STATUS_PAID]);

                return $payment->fresh(['organization', 'submittedBy', 'reviewedBy', 'bill.items']);
            }

            $months = $bill?->months ?? 1;
            // Approval follows the bill the school paid: a bill dated today during the free trial ("Start right away")
            // starts paid coverage now and ends the trial; a bill dated at the trial end ("Start after my free trial") waits for it.
            $today = now($payment->organization->timezone ?: config('app.timezone'))->toDateString();
            $startImmediately = $bill ? $bill->billing_period_start->toDateString() <= $today : $smsRequest?->activation_mode === OrganizationFeatureRequest::ACTIVATION_IMMEDIATE;
            $subscription = $this->subscriptions->activateOneMonth($payment->organization, $admin, null, $startImmediately, $months);
            $coverage = [$subscription->subscription_starts_at?->toDateString(), $subscription->subscription_expires_at?->toDateString()];
            if ($coversSms) {
                // SMS is an add-on: it covers exactly the Base period this Base + SMS payment bought.
                $this->features->coverSmsThrough($payment->organization, $admin, Carbon::parse($coverage[0]), Carbon::parse($coverage[1]));
                $smsRequest?->update(['status' => OrganizationFeatureRequest::STATUS_APPROVED, 'reviewed_by' => $admin->id, 'reviewed_at' => now()]);
            }
            $payment->update(['coverage_start' => $coverage[0] ?? null, 'coverage_end' => $coverage[1] ?? null]);
            $bill?->update(['status' => Bill::STATUS_PAID]);

            return $payment->fresh(['organization', 'submittedBy', 'reviewedBy', 'bill.items']);
        });
    }
}

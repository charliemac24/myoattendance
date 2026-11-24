<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\OrganizationFeatureRequest;
use App\Models\Payment;
use App\Models\SmsPricingAgreement;
use App\Models\User;

/**
 * In-app notices for a school's or gym's owner and admins when MYO Attendance has answered them on billing:
 * an SMS price is ready, a payment was approved, or a payment was rejected.
 *
 * Each notice has a key. A notice goes away when its key is dismissed, or by itself once it is acted on:
 * paying for SMS clears "SMS price ready", opening Billing clears "Payment approved", and sending a new
 * payment clears "Payment rejected".
 */
class BillingNoticeService
{
    /** Days an approved payment stays on Home. */
    public const APPROVED_NOTICE_DAYS = 14;

    public function __construct(private readonly BillingService $billing) {}

    /**
     * @return list<array{key: string, tone: string, title: string, message: string, action: string}>
     */
    public function forUser(?User $user): array
    {
        if (! $user || ! $user->organization || ! in_array($user->role, ['owner', 'admin'], true)) {
            return [];
        }

        // Home and the menu both ask during one page load, so work the notices out once per request.
        $cacheKey = 'billing_notices.'.$user->id;
        if (! request()->attributes->has($cacheKey)) {
            request()->attributes->set($cacheKey, $this->notices($user));
        }

        return array_values(array_filter(
            request()->attributes->get($cacheKey),
            fn (array $notice) => ! in_array($notice['key'], $user->dismissed_notices ?? [], true),
        ));
    }

    public function dismiss(User $user, string $key): void
    {
        $user->forceFill(['dismissed_notices' => array_values(array_unique([...($user->dismissed_notices ?? []), $key]))])->save();
    }

    /**
     * Opening Billing counts as having seen an approved payment.
     */
    public function markBillingSeen(User $user): void
    {
        foreach ($this->forUser($user) as $notice) {
            if (str_starts_with($notice['key'], 'payment-approved-')) {
                $this->dismiss($user, $notice['key']);
            }
        }
    }

    /**
     * @return list<array{key: string, tone: string, title: string, message: string, action: string}>
     */
    private function notices(User $user): array
    {
        $organization = $user->organization;
        $plan = $this->billing->currentPlan($organization);
        $notices = [];

        $latest = $organization->payments()->with('bill')->whereNotNull('bill_id')->latest()->latest('id')->first();
        $smsPaymentWaiting = $latest && in_array($latest->status, [Payment::STATUS_PENDING, Payment::STATUS_PROOF_REQUIRED], true) && $latest->bill?->bill_type !== Bill::TYPE_BASE;

        // Only a price super admin set counts as an answer; the school's own estimate does not.
        $agreement = $plan['sms']['state'] === 'price_ready' && ! $smsPaymentWaiting
            ? $organization->smsPricingAgreements()->where('status', SmsPricingAgreement::STATUS_ACTIVE)->latest('effective_from')->first()
            : null;
        // It must also be newer than the request: after asking for a new price, the old one is not the answer.
        $requestedAt = $agreement ? $organization->featureRequests()->where('feature', OrganizationFeatureRequest::FEATURE_SMS)->where('status', OrganizationFeatureRequest::STATUS_PENDING)->latest('requested_at')->value('requested_at') : null;
        if ($agreement?->source === SmsPricingAgreement::SOURCE_ADMIN && $requestedAt && $agreement->created_at->gte($requestedAt)) {
            $notices[] = [
                'key' => 'sms-price-'.$agreement->id,
                'tone' => 'info',
                'title' => 'Your SMS price is ready',
                'message' => 'SMS Notifications are ₱'.number_format($agreement->sms_monthly_price_minor / 100, 2).'/month. Choose Base + SMS on Billing to pay and turn them on.',
                'action' => 'Go to Billing',
            ];
        }

        if ($latest?->status === Payment::STATUS_APPROVED && $latest->reviewed_at?->gt(now()->subDays(self::APPROVED_NOTICE_DAYS))) {
            $until = $latest->coverage_end ? ' until '.$latest->coverage_end->format('M j, Y') : '';
            $notices[] = [
                'key' => 'payment-approved-'.$latest->id,
                'tone' => 'success',
                'title' => 'Payment approved',
                'message' => Bill::labelFor($latest->bill?->bill_type ?? Bill::TYPE_BASE).' is active'.$until.'. Thank you!',
                'action' => 'View Billing',
            ];
        }

        if ($latest?->status === Payment::STATUS_REJECTED) {
            $notices[] = [
                'key' => 'payment-rejected-'.$latest->id,
                'tone' => 'danger',
                'title' => 'Payment not accepted',
                'message' => ($latest->rejection_reason ? 'Reason: '.rtrim($latest->rejection_reason, '. ').'. ' : '').'Please check the details and send your payment again on Billing.',
                'action' => 'Go to Billing',
            ];
        }

        return $notices;
    }
}

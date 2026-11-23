<?php

namespace App\Services;

use App\Models\BaseSubscription;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class BaseSubscriptionService
{
    public const MONTHLY_PRICE_MINOR = 299900;

    public const CURRENCY = 'PHP';

    public const TRIAL_DAYS = 30;

    public function createTrial(Organization $organization, ?CarbonInterface $startsAt = null): BaseSubscription
    {
        $start = ($startsAt ? Carbon::instance($startsAt) : now())->utc();

        return $organization->baseSubscription()->create([
            'trial_starts_at' => $start,
            'trial_ends_at' => $start->copy()->addDays(self::TRIAL_DAYS),
            'status' => BaseSubscription::STATUS_TRIALING,
        ]);
    }

    public function statusFor(Organization $organization, ?CarbonInterface $at = null): string
    {
        $subscription = $organization->baseSubscription ?: $organization->baseSubscription()->first();
        // Organizations created before billing was introduced retain their existing access.
        if (! $subscription) {
            return BaseSubscription::STATUS_ACTIVE;
        }
        $now = $at ? Carbon::instance($at) : now();
        if ($subscription->subscription_expires_at && $now->lt($subscription->subscription_expires_at)) {
            return BaseSubscription::STATUS_ACTIVE;
        }
        if ($subscription->trial_ends_at && $now->lt($subscription->trial_ends_at)) {
            return BaseSubscription::STATUS_TRIALING;
        }

        return BaseSubscription::STATUS_EXPIRED;
    }

    public function canUseScanner(Organization $organization, ?CarbonInterface $at = null): bool
    {
        return in_array($this->statusFor($organization, $at), [BaseSubscription::STATUS_TRIALING, BaseSubscription::STATUS_ACTIVE], true);
    }

    public function daysRemaining(Organization $organization, ?CarbonInterface $at = null): int
    {
        $subscription = $organization->baseSubscription ?: $organization->baseSubscription()->first();
        if (! $subscription) {
            return 0;
        }
        $now = $at ? Carbon::instance($at) : now();
        $end = $this->statusFor($organization, $at) === BaseSubscription::STATUS_TRIALING ? $subscription->trial_ends_at : $subscription->subscription_expires_at;

        return $end && $now->lt($end) ? max(1, (int) $now->diffInDays($end)) : 0;
    }

    public function activateOneMonth(Organization $organization, User $actor, ?CarbonInterface $at = null, bool $startImmediately = false, int $months = 1): BaseSubscription
    {
        $now = ($at ? Carbon::instance($at) : now())->utc();
        // Legacy organizations without a subscription row get one the first time they pay.
        $subscription = $organization->baseSubscription()->lockForUpdate()->first()
            ?? $organization->baseSubscription()->create(['status' => BaseSubscription::STATUS_ACTIVE]);
        $currentEnd = $subscription->subscription_expires_at;
        $trialEnd = $subscription->trial_ends_at;
        $boundaries = collect($startImmediately ? [$now, $currentEnd] : [$now, $currentEnd, $trialEnd])->filter(fn ($date) => $date && $date->gt($now));
        $start = $boundaries->sortBy(fn ($date) => $date->getTimestamp())->last() ?: $now;
        $subscription->update(['subscription_starts_at' => $start, 'subscription_expires_at' => $start->copy()->addMonthsNoOverflow($months), 'status' => BaseSubscription::STATUS_ACTIVE, 'activated_by' => $actor->id]);

        return $subscription->fresh();
    }

    public function payload(Organization $organization): array
    {
        $subscription = $organization->baseSubscription ?: $organization->baseSubscription()->first();

        return ['status' => $this->statusFor($organization), 'trial_starts_at' => $subscription?->trial_starts_at, 'trial_ends_at' => $subscription?->trial_ends_at, 'subscription_starts_at' => $subscription?->subscription_starts_at, 'subscription_expires_at' => $subscription?->subscription_expires_at, 'days_remaining' => $this->daysRemaining($organization), 'monthly_price_minor' => self::MONTHLY_PRICE_MINOR, 'currency' => self::CURRENCY];
    }
}

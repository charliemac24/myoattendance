<?php

/**
 * LOCAL TESTING ONLY. Resets one seeded test school so a billing scenario can start clean.
 *
 * Usage (from the repo root):
 *   docker compose exec app php artisan tinker --execute "\$school='Trial Demo School'; \$state='trial'; require 'testing/reset_test_school.php';"
 *
 * $school: 'Trial Demo School' (login trial), 'Base Demo School' (login base) or 'MYO Demo Organization' (login admin).
 * $state:
 *   trial         free trial with 20 days left, no start choice
 *   trial_ending  free trial ending in 1 hour
 *   trial_ended   free trial ended yesterday, never paid (expired)
 *   base_active   paid Base, 20 days left, SMS off
 *   sms_active    paid Base + SMS, 20 days left, SMS on (sets a ₱500/month SMS price)
 *   base_expired  paid Base ended yesterday, SMS off
 *   sms_expired   paid Base + SMS ended yesterday (keeps a ₱500/month SMS price)
 *
 * It deletes that school's bills, payments, receipts, SMS requests, SMS prices, SMS access and SMS estimate answers first.
 * It never touches users, people or attendance, and never runs in production.
 */

use App\Models\Organization;
use App\Models\PaymentProof;
use App\Models\SmsPricingAgreement;
use App\Models\User;

if (app()->environment('production')) {
    echo "Refusing to run in production.\n";

    return;
}

$school ??= 'Trial Demo School';
$state ??= 'trial';
$organization = Organization::query()->where('name', $school)->firstOrFail();
$super = User::query()->where('username', 'super')->firstOrFail();

PaymentProof::query()->whereIn('payment_id', $organization->payments()->pluck('id'))->delete();
$organization->payments()->delete();
foreach ($organization->bills()->get() as $bill) {
    $bill->items()->delete();
    $bill->delete();
}
$organization->featureRequests()->delete();
$organization->smsPricingAgreements()->delete();
$organization->featureEntitlements()->delete();
$organization->update(['sms_estimate_people' => null, 'sms_estimate_texts' => null, 'sms_estimate_days' => null]);

$subscription = $organization->baseSubscription()->firstOrCreate([], ['status' => 'trialing']);
$trial = fn ($endsAt) => ['trial_starts_at' => $endsAt->copy()->subDays(30), 'trial_ends_at' => $endsAt, 'subscription_starts_at' => null, 'subscription_expires_at' => null, 'status' => 'trialing', 'start_choice' => null, 'activated_by' => null];
$paid = fn ($startsAt, $endsAt) => ['trial_starts_at' => $startsAt->copy()->subDays(30), 'trial_ends_at' => $startsAt, 'subscription_starts_at' => $startsAt, 'subscription_expires_at' => $endsAt, 'status' => 'active', 'start_choice' => null, 'activated_by' => $super->id];
$withSms = function ($startsAt, $endsAt) use ($organization, $super): void {
    $organization->smsPricingAgreements()->create(['sms_monthly_price_minor' => 50000, 'currency' => 'PHP', 'person_count_snapshot' => $organization->persons()->where('is_active', true)->count(), 'effective_from' => $startsAt->copy()->subMonth()->toDateString(), 'status' => SmsPricingAgreement::STATUS_ACTIVE, 'created_by' => $super->id, 'agreed_at' => now()]);
    $organization->featureEntitlements()->create(['feature' => 'sms', 'enabled' => true, 'starts_at' => $startsAt->toDateString(), 'expires_at' => $endsAt->toDateString(), 'enabled_by' => $super->id]);
};

match ($state) {
    'trial' => $subscription->update($trial(now()->addDays(20))),
    'trial_ending' => $subscription->update($trial(now()->addHour())),
    'trial_ended' => $subscription->update(['status' => 'expired'] + $trial(now()->subDay())),
    'base_active' => $subscription->update($paid(now()->subDays(10), now()->addDays(20))),
    'sms_active' => [$subscription->update($paid(now()->subDays(10), now()->addDays(20))), $withSms(now()->subDays(10), now()->addDays(20))],
    'base_expired' => $subscription->update(['status' => 'expired'] + $paid(now()->subMonth()->subDay(), now()->subDay())),
    'sms_expired' => [$subscription->update(['status' => 'expired'] + $paid(now()->subMonth()->subDay(), now()->subDay())), $withSms(now()->subMonth()->subDay(), now()->subDay())],
    default => throw new InvalidArgumentException("Unknown state [{$state}]."),
};

echo "Reset {$school} to {$state}.\n";

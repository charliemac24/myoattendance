<?php

namespace Database\Seeders;

use App\Models\Bill;
use App\Models\Organization;
use App\Models\Payment;
use App\Services\BaseSubscriptionService;
use Illuminate\Database\Seeder;

/**
 * Puts the one local test school back to "just signed up", so the whole workflow can be walked again:
 * `php artisan db:seed --class=ActivationDemoSeeder`.
 *
 * Demo School (demo / password) gets a fresh 30-day free trial with no payments, bills, SMS price,
 * SMS answers, SMS requests or SMS access. Its people, groups and attendance are kept.
 * Super admin is super / password.
 */
class ActivationDemoSeeder extends Seeder
{
    public function run(BaseSubscriptionService $subscriptions): void
    {
        if (app()->environment('production')) {
            return;
        }

        $this->call(LocalTestAccountsSeeder::class);
        $school = Organization::query()->where('name', LocalTestAccountsSeeder::DEMO_SCHOOL_NAME)->firstOrFail();

        $school->update(['sms_estimate_people' => null, 'sms_estimate_texts' => null, 'sms_estimate_days' => null]);
        $school->payments()->each(function (Payment $payment): void {
            $payment->proofs()->delete();
            $payment->delete();
        });
        $school->bills()->each(function (Bill $bill): void {
            $bill->items()->delete();
            $bill->delete();
        });
        $school->featureEntitlements()->delete();
        $school->featureRequests()->delete();
        $school->smsPricingAgreements()->delete();
        $school->baseSubscription()->delete();
        $subscriptions->createTrial($school);
    }
}

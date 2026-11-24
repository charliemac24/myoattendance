<?php

namespace Database\Seeders;

use App\Models\BaseSubscription;
use App\Models\Bill;
use App\Models\BillItem;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use App\Services\BaseSubscriptionService;
use App\Services\BillingService;
use App\Services\SmsPricingService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * Local demo for the super admin Activate button. Only touches the "Activation Demo" organizations below,
 * and puts them back to "expired" every time it runs, so you can test Activate again:
 * `php artisan db:seed --class=ActivationDemoSeeder`.
 *
 * - demo-scan / password: expired school, has sent a Base (Scan only) payment that is waiting for review
 * - demo-sms  / password: expired school, has sent a Base + SMS (Scan + SMS) payment that is waiting for review
 * - demo-pay  / password: expired school with an SMS price set and nothing paid yet, so you can pay yourself
 * - demo-addsms / password: Base paid and activated 3 days ago, no SMS, so you can press "Add SMS", pay, then Activate
 */
class ActivationDemoSeeder extends Seeder
{
    private const SMS_PRICE_MINOR = 49900;

    public function run(BillingService $billing, SmsPricingService $pricing): void
    {
        if (app()->environment('production')) {
            return;
        }

        $this->call(LocalTestAccountsSeeder::class);
        $admin = User::query()->where('username', 'super')->firstOrFail();

        foreach ([
            ['demo-scan', 'Activation Demo – Scan only', Bill::TYPE_BASE],
            ['demo-sms', 'Activation Demo – Scan + SMS', Bill::TYPE_PACKAGE],
            ['demo-pay', 'Activation Demo – Pay yourself', null],
            ['demo-addsms', 'Activation Demo – Add SMS later', null],
        ] as [$username, $name, $paidPlan]) {
            $school = $this->resetExpiredSchool($name);
            $owner = $this->owner($school, $username);
            if ($username === 'demo-addsms') {
                $this->paidBaseMonth($school, $owner, $admin, now()->subDays(3));
            }
            $pricing->setPrice($school, $admin, self::SMS_PRICE_MINOR, now()->subMonth()->toDateString(), 'Demo SMS price');

            if ($paidPlan) {
                $this->submitPayment($school, $owner, $billing->currentBill($school, $paidPlan, 1), strtoupper($username));
            }
        }
    }

    /**
     * Wipes billing for this demo school only and leaves it with a free trial that ended yesterday.
     */
    private function resetExpiredSchool(string $name): Organization
    {
        $school = Organization::query()->firstOrCreate(['name' => $name], ['type' => 'school', 'timezone' => 'Asia/Manila']);

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
        $school->baseSubscription()->create([
            'trial_starts_at' => now()->subDays(31), 'trial_ends_at' => now()->subDay(), 'status' => BaseSubscription::STATUS_EXPIRED,
        ]);

        return $school;
    }

    /**
     * Replaces the expired trial with one approved, paid Base month that started on $paidFrom.
     */
    private function paidBaseMonth(Organization $school, User $owner, User $admin, \Illuminate\Support\Carbon $paidFrom): void
    {
        $paidUntil = $paidFrom->copy()->addMonthNoOverflow();
        $school->baseSubscription()->update([
            'trial_starts_at' => $paidFrom->copy()->subDays(BaseSubscriptionService::TRIAL_DAYS), 'trial_ends_at' => $paidFrom,
            'subscription_starts_at' => $paidFrom, 'subscription_expires_at' => $paidUntil, 'status' => BaseSubscription::STATUS_ACTIVE, 'activated_by' => $admin->id,
        ]);
        $start = $paidFrom->copy()->setTimezone($school->timezone)->startOfDay();
        $bill = $school->bills()->create([
            'bill_type' => Bill::TYPE_BASE, 'months' => 1, 'currency' => 'PHP', 'status' => Bill::STATUS_PAID, 'sms_included' => false,
            'billing_period_start' => $start->toDateString(), 'billing_period_end' => BillingService::periodEnd($start, 1)->toDateString(),
            'subtotal_minor' => BaseSubscriptionService::MONTHLY_PRICE_MINOR, 'total_minor' => BaseSubscriptionService::MONTHLY_PRICE_MINOR,
        ]);
        $bill->items()->create(['type' => BillItem::TYPE_BASE, 'description' => 'Base Subscription', 'quantity' => 1, 'unit_amount_minor' => BaseSubscriptionService::MONTHLY_PRICE_MINOR, 'amount_minor' => BaseSubscriptionService::MONTHLY_PRICE_MINOR]);
        $school->payments()->create([
            'bill_id' => $bill->id, 'payment_type' => Payment::TYPE_MONTHLY_BILL, 'status' => Payment::STATUS_APPROVED,
            'amount_minor' => BaseSubscriptionService::MONTHLY_PRICE_MINOR, 'currency' => 'PHP', 'payment_method' => Payment::METHOD_GCASH,
            'reference_number' => 'DEMO-ADDSMS-BASE-'.now()->format('His'), 'paid_at' => $paidFrom,
            'coverage_start' => $paidFrom->toDateString(), 'coverage_end' => $paidUntil->toDateString(),
            'submitted_by' => $owner->id, 'reviewed_by' => $admin->id, 'reviewed_at' => $paidFrom, 'recorded_by' => $admin->id,
        ]);
    }

    private function owner(Organization $school, string $username): User
    {
        $user = User::query()->where('username', $username)->first() ?? new User;
        $user->forceFill([
            'organization_id' => $school->id, 'name' => $school->name.' Owner', 'role' => 'owner',
            'username' => $username, 'email' => $username.'@myo.test', 'password' => Hash::make('password'),
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        return $user;
    }

    /**
     * Same records the school's "Submit payment" button makes, with a sample receipt image.
     */
    private function submitPayment(Organization $school, User $owner, Bill $bill, string $reference): void
    {
        $path = 'payment-proofs/'.strtolower($reference).'.png';
        Storage::put($path, $this->receiptImage($reference, $bill->total_minor));

        $payment = $school->payments()->create([
            'bill_id' => $bill->id, 'payment_type' => Payment::TYPE_MONTHLY_BILL, 'status' => Payment::STATUS_PENDING,
            'amount_minor' => $bill->total_minor, 'currency' => 'PHP', 'payment_method' => Payment::METHOD_GCASH,
            'reference_number' => $reference.'-'.now()->format('His'), 'proof_path' => $path, 'paid_at' => now(), 'submitted_by' => $owner->id,
        ]);
        $payment->proofs()->create(['path' => $path, 'submitted_by' => $owner->id, 'submitted_at' => now()]);
        $bill->update(['status' => Bill::STATUS_PAYMENT_PENDING]);
    }

    private function receiptImage(string $reference, int $amountMinor): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        }
        $image = imagecreatetruecolor(360, 160);
        imagefill($image, 0, 0, imagecolorallocate($image, 0, 112, 224));
        $white = imagecolorallocate($image, 255, 255, 255);
        imagestring($image, 5, 20, 30, 'DEMO GCash receipt', $white);
        imagestring($image, 4, 20, 70, 'Amount: PHP '.number_format($amountMinor / 100, 2), $white);
        imagestring($image, 4, 20, 100, 'Ref: '.$reference, $white);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}

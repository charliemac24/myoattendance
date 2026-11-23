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
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * Permanent local test logins. Safe to run any number of times:
 * `php artisan db:seed --class=LocalTestAccountsSeeder`.
 *
 * - School admin: admin / password
 * - Super admin:  super / password
 * - Trial school owner: trial / password (a separate school on its 30-day free trial)
 * - Base school owner: base / password (a separate school on a paid Base plan, no SMS)
 */
class LocalTestAccountsSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        $school = Organization::query()->where('name', 'MYO Demo Organization')->first()
            ?? Organization::query()->orderBy('id')->first()
            ?? Organization::create(['name' => 'MYO Demo Organization', 'type' => 'training_center', 'timezone' => 'Asia/Manila']);

        $this->account(['admin', 'admin@myo.test'], [
            'organization_id' => $school->id,
            'name' => 'School Admin',
            'role' => 'admin',
        ]);

        $this->account(['super', 'super@myo.test', 'superadmin@myo.test'], [
            'organization_id' => null,
            'name' => 'Super Admin',
            'role' => User::ROLE_SUPER_ADMIN,
        ]);

        $trialSchool = Organization::query()->firstOrCreate(
            ['name' => 'Trial Demo School'],
            ['type' => 'school', 'timezone' => 'Asia/Manila'],
        );
        // The trial starts when the school is first created here; re-running the seeder never resets it.
        if (! $trialSchool->baseSubscription()->exists()) {
            app(BaseSubscriptionService::class)->createTrial($trialSchool, now()->subDays(10));
        }
        $this->account(['trial', 'trial@myo.test'], [
            'organization_id' => $trialSchool->id,
            'name' => 'Trial School Owner',
            'role' => 'owner',
        ]);

        $baseSchool = Organization::query()->firstOrCreate(
            ['name' => 'Base Demo School'],
            ['type' => 'school', 'timezone' => 'Asia/Manila'],
        );
        $this->account(['base', 'base@myo.test'], [
            'organization_id' => $baseSchool->id,
            'name' => 'Base School Owner',
            'role' => 'owner',
        ]);
        // Set up once: trial already used, then one paid Base month that started 10 days ago.
        if (! $baseSchool->baseSubscription()->exists()) {
            $this->paidBaseMonth($baseSchool, now()->subDays(10));
        }
    }

    /**
     * A finished free trial followed by one approved, paid Base month, recorded like a real GCash payment.
     */
    private function paidBaseMonth(Organization $school, Carbon $paidFrom): void
    {
        $owner = User::query()->where('username', 'base')->firstOrFail();
        $admin = User::query()->where('username', 'super')->firstOrFail();
        $paidUntil = $paidFrom->copy()->addMonthNoOverflow();

        $school->baseSubscription()->create([
            'trial_starts_at' => $paidFrom->copy()->subDays(BaseSubscriptionService::TRIAL_DAYS),
            'trial_ends_at' => $paidFrom,
            'subscription_starts_at' => $paidFrom,
            'subscription_expires_at' => $paidUntil,
            'status' => BaseSubscription::STATUS_ACTIVE,
            'activated_by' => $admin->id,
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
            'reference_number' => 'SEED-BASE-'.$school->id, 'paid_at' => $paidFrom->copy()->subDay(),
            'coverage_start' => $paidFrom->toDateString(), 'coverage_end' => $paidUntil->toDateString(),
            'submitted_by' => $owner->id, 'reviewed_by' => $admin->id, 'reviewed_at' => $paidFrom, 'recorded_by' => $admin->id,
        ]);
    }

    /**
     * @param  array{0: string, 1: string, 2?: string}  $identity  username, email, and an optional older email to adopt
     * @param  array<string, mixed>  $attributes
     */
    private function account(array $identity, array $attributes): void
    {
        [$username, $email] = $identity;
        $user = User::query()->where('username', $username)->orWhereIn('email', array_slice($identity, 1))->orderBy('id')->first() ?? new User;

        $user->forceFill($attributes + [
            'username' => $username,
            'email' => $email,
            'password' => Hash::make('password'),
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();
    }
}

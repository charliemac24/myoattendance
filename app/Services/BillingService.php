<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\BillItem;
use App\Models\FeatureEntitlement;
use App\Models\Organization;
use App\Models\OrganizationFeatureRequest;
use App\Models\Payment;
use App\Models\SmsPricingAgreement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BillingService
{
    /**
     * "Add SMS" is only offered when at least this many days are left in the paid Base period.
     */
    public const SMS_NOW_MIN_DAYS = 3;

    public function __construct(private readonly BaseSubscriptionService $subscriptions) {}

    /**
     * The one-time SMS charge for the days left until the Base period ends.
     *
     * Whole calendar months left are charged at the full monthly price; the remaining days are
     * charged as a share of the calendar month they fall in, rounded to the nearest centavo.
     * Example: ₱1,000/month with 25 of 30 days left = ₱833.33.
     *
     * @return array{amount_minor: int, days_left: int, full_months: int, partial_days: int, partial_month_days: int}
     */
    public static function proratedSmsCharge(int $monthlyPriceMinor, Carbon $today, Carbon $periodEnd): array
    {
        $today = $today->copy()->startOfDay();
        $end = $periodEnd->copy()->startOfDay();
        $fullMonths = 0;
        while ($end->copy()->subMonthsNoOverflow($fullMonths + 1)->gte($today)) {
            $fullMonths++;
        }
        $partialEnd = $end->copy()->subMonthsNoOverflow($fullMonths);
        $partialMonthStart = $end->copy()->subMonthsNoOverflow($fullMonths + 1);
        $partialDays = (int) $today->diffInDays($partialEnd);
        $partialMonthDays = (int) $partialMonthStart->diffInDays($partialEnd);
        $partialAmount = $partialDays > 0 ? (int) round($monthlyPriceMinor * $partialDays / $partialMonthDays) : 0;

        return [
            'amount_minor' => $fullMonths * $monthlyPriceMinor + $partialAmount,
            'days_left' => (int) $today->diffInDays($end),
            'full_months' => $fullMonths,
            'partial_days' => $partialDays,
            'partial_month_days' => $partialMonthDays,
        ];
    }

    /**
     * Whether a paid Base school can start SMS today, and what it would cost.
     *
     * @return array{available: bool, reason: ?string, monthly_price_minor: ?int, amount_minor: ?int, start: ?string, end: ?string, sms_ends_at: ?string, days_left: ?int, full_months: ?int, partial_days: ?int, partial_month_days: ?int, min_days: int}
     */
    public function smsNowQuote(Organization $organization): array
    {
        $empty = ['available' => false, 'reason' => null, 'monthly_price_minor' => null, 'amount_minor' => null, 'start' => null, 'end' => null, 'sms_ends_at' => null, 'days_left' => null, 'full_months' => null, 'partial_days' => null, 'partial_month_days' => null, 'min_days' => self::SMS_NOW_MIN_DAYS];
        $subscription = $organization->baseSubscription()->first();
        // Paid Base access right now (including a renewal paid ahead), but not a free trial.
        $paidNow = $subscription?->subscription_expires_at && $subscription->subscription_expires_at->isFuture()
            && (($subscription->subscription_starts_at && $subscription->subscription_starts_at->lte(now()))
                || ! ($subscription->trial_ends_at && $subscription->trial_ends_at->isFuture()));
        if (! $paidNow) {
            return ['reason' => 'not_paid_base'] + $empty;
        }
        $today = $this->today($organization);
        $agreement = $this->agreementFor($organization, $today) ?? $this->latestActiveAgreement($organization);
        if (! $agreement) {
            return ['reason' => 'no_price'] + $empty;
        }
        if (in_array($this->currentPlan($organization)['sms']['state'], ['on', 'scheduled'], true)) {
            return ['reason' => 'sms_already_on'] + $empty;
        }

        $end = Carbon::instance($subscription->subscription_expires_at)->setTimezone($this->timezone($organization))->startOfDay();
        $charge = self::proratedSmsCharge($agreement->sms_monthly_price_minor, $today, $end);
        $quote = [
            'monthly_price_minor' => $agreement->sms_monthly_price_minor,
            'amount_minor' => $charge['amount_minor'],
            'start' => $today->toDateString(),
            'end' => $end->copy()->subDay()->toDateString(),
            'sms_ends_at' => $end->toDateString(),
            'days_left' => $charge['days_left'],
            'full_months' => $charge['full_months'],
            'partial_days' => $charge['partial_days'],
            'partial_month_days' => $charge['partial_month_days'],
            'min_days' => self::SMS_NOW_MIN_DAYS,
            'pricing_agreement_id' => $agreement->id,
        ];

        return $charge['days_left'] < self::SMS_NOW_MIN_DAYS
            ? ['available' => false, 'reason' => 'too_few_days'] + $quote
            : ['available' => true, 'reason' => null] + $quote;
    }

    /**
     * What the school has right now, independent of what it is about to pay for.
     *
     * @return array{plan: string, label: string, status: string, trial_ends_at: mixed, base_paid_through: mixed, sms: array{state: string, starts_at: ?string, ends_at: ?string}, pending_payment: ?array<string, mixed>}
     */
    public function currentPlan(Organization $organization): array
    {
        $status = $this->subscriptions->statusFor($organization);
        $subscription = $organization->baseSubscription()->first();
        $entitlement = $organization->featureEntitlements()->where('feature', FeatureEntitlement::SMS)->first();
        $today = now()->toDateString();
        $smsCurrent = $entitlement?->enabled && ($entitlement->expires_at === null || $entitlement->expires_at->toDateString() >= $today);
        $smsState = match (true) {
            $smsCurrent && $entitlement->starts_at && $entitlement->starts_at->toDateString() > $today => 'scheduled',
            $smsCurrent => 'on',
            $this->hasPendingSmsRequest($organization) => $this->latestActiveAgreement($organization) ? 'price_ready' : 'requested',
            $this->latestActiveAgreement($organization) !== null => 'available',
            default => 'off',
        };
        $plan = in_array($smsState, ['on', 'scheduled'], true) ? Bill::TYPE_PACKAGE : Bill::TYPE_BASE;
        $planName = $plan === Bill::TYPE_PACKAGE ? 'Base + SMS' : 'Base';

        $pending = Payment::query()->with('bill')->where('organization_id', $organization->id)
            ->whereIn('status', [Payment::STATUS_PENDING, Payment::STATUS_PROOF_REQUIRED])->whereNotNull('bill_id')
            ->latest()->latest('id')->first();

        return [
            'plan' => $plan,
            'label' => match ($status) {
                'trialing' => 'Free trial'.($plan === Bill::TYPE_PACKAGE ? ' + SMS' : ''),
                'expired' => 'Expired',
                default => $planName,
            },
            'status' => $status,
            'in_trial' => $status === 'trialing' && $subscription?->subscription_expires_at === null,
            'start_choice' => $this->trialStartChoice($organization),
            'trial_ends_at' => $subscription?->trial_ends_at,
            'base_paid_through' => $subscription?->subscription_expires_at,
            'sms' => ['state' => $smsState, 'starts_at' => $smsCurrent ? $entitlement->starts_at?->toDateString() : null, 'ends_at' => $smsCurrent ? $entitlement->expires_at?->toDateString() : null, 'requested_start' => $this->pendingSmsRequest($organization)?->activation_mode],
            'pending_payment' => $pending ? [
                'id' => $pending->id,
                'status' => $pending->status,
                'plan' => $pending->bill->bill_type,
                'plan_label' => Bill::labelFor($pending->bill->bill_type),
                'months' => $pending->bill->months,
                'amount_minor' => $pending->amount_minor,
                'submitted_at' => $pending->created_at,
                'period_start' => $pending->bill->billing_period_start?->toDateString(),
                'period_end' => $pending->bill->billing_period_end?->toDateString(),
            ] : null,
        ];
    }

    /**
     * What the school should do next to get SMS, based on its current plan. Only schools still on
     * their free trial choose when SMS starts; everyone else adds SMS by paying for Base + SMS.
     *
     * @param  array<string, mixed>  $currentPlan
     */
    public function smsNextStep(array $currentPlan): string
    {
        $smsState = $currentPlan['sms']['state'];

        return match (true) {
            in_array($smsState, ['on', 'scheduled'], true) => $smsState,
            $smsState === 'requested' => 'waiting_for_price',
            in_array($smsState, ['price_ready', 'available'], true) => $currentPlan['status'] === 'expired' ? 'renew_with_package' : 'pay_package',
            $currentPlan['status'] === 'expired' => 'renew_first',
            $currentPlan['in_trial'] => 'choose_trial_timing',
            default => 'request',
        };
    }

    /**
     * How paying this bill changes the school's plan, in words a school or admin can act on.
     *
     * @param  array<string, mixed>  $currentPlan
     * @return array{kind: string, message: string}
     */
    public function describeChange(array $currentPlan, Bill $bill, bool $forAdmin = false): array
    {
        $change = $this->describeChangeForSchool($currentPlan, $bill, $forAdmin);

        // Admins read the same sentence about the school, not to the school.
        return $forAdmin && $change['kind'] !== 'sms_now'
            ? ['kind' => $change['kind'], 'message' => str_replace(['as you chose', 'you chose', 'your ', 'Your '], ['as the school chose', 'the school chose', "the school's ", "The school's "], $change['message'])]
            : $change;
    }

    /**
     * @param  array<string, mixed>  $currentPlan
     * @return array{kind: string, message: string}
     */
    private function describeChangeForSchool(array $currentPlan, Bill $bill, bool $forAdmin): array
    {
        $start = $bill->billing_period_start->format('M j, Y');
        $end = $bill->billing_period_end->format('M j, Y');
        $length = $bill->months === 1 ? '1 month' : $bill->months.' months';
        // SMS only counts as part of the plan being renewed if it is still on when this bill starts
        // (a school that already switched to Base keeps SMS only until its paid SMS period ends).
        $smsEndsAt = $currentPlan['sms']['ends_at'] ?? null;
        $hasSms = $currentPlan['plan'] === Bill::TYPE_PACKAGE
            && ($smsEndsAt === null || $smsEndsAt >= $bill->billing_period_start->toDateString());

        if ($bill->bill_type === Bill::TYPE_SMS_NOW) {
            $smsEnd = $bill->billing_period_end->copy()->addDay()->format('M j, Y');
            if ($forAdmin) {
                return ['kind' => 'sms_now', 'message' => "Turns SMS on from the day you approve until {$smsEnd}, when the school's paid Base time ends. Base is not changed. One-time charge for the days left in the period."];
            }

            return ['kind' => 'sms_now', 'message' => "Starts SMS as soon as this payment is approved. SMS stays on until {$smsEnd}, when your paid Base time ends. Your Base plan is not changed. To keep SMS after {$smsEnd}, choose Base + SMS when you renew."];
        }

        if ($bill->bill_type === Bill::TYPE_PACKAGE && ! $hasSms) {
            $smsStarts = match (true) {
                ($currentPlan['start_choice'] ?? null) === OrganizationFeatureRequest::ACTIVATION_IMMEDIATE && $currentPlan['in_trial'] => 'SMS and paid Base start as soon as this payment is approved, which ends your free trial early (you chose "Right away")',
                ($currentPlan['start_choice'] ?? null) === OrganizationFeatureRequest::ACTIVATION_AFTER_TRIAL && $currentPlan['in_trial'] => "SMS and paid Base start on {$start}, when your free trial ends (you chose \"After my free trial ends\")",
                $bill->billing_period_start->toDateString() > $this->billToday($bill) => "SMS starts on {$start}, when your paid Base time ends",
                default => 'SMS starts as soon as this payment is approved',
            };


            return ['kind' => 'add_sms', 'message' => "Adds SMS. {$smsStarts}. This payment covers Base + SMS from {$start} to {$end} ({$length})."];
        }
        if ($bill->bill_type === Bill::TYPE_BASE && $hasSms) {
            $smsEnd = $currentPlan['sms']['ends_at'] ? Carbon::parse($currentPlan['sms']['ends_at'])->format('M j, Y') : 'the end of your paid period';

            return ['kind' => 'remove_sms', 'message' => "Removes SMS. SMS stays on until {$smsEnd}, then your plan continues as Base from {$start} to {$end} ({$length})."];
        }

        $name = $bill->bill_type === Bill::TYPE_PACKAGE ? 'Base + SMS' : 'Base';
        $verb = $currentPlan['status'] === 'active' ? 'Renews' : 'Starts';
        // During the free trial the bill's own start date decides: today means "right away", later means "after the trial".
        $startsNow = $bill->billing_period_start->toDateString() <= $this->billToday($bill);
        $message = match (true) {
            $currentPlan['in_trial'] && $startsNow && ($currentPlan['start_choice'] ?? null) === OrganizationFeatureRequest::ACTIVATION_IMMEDIATE => "Starts paid {$name} as soon as this payment is approved, which ends your free trial early (you chose \"Right away\"). Covers {$start} to {$end} ({$length}).",
            $currentPlan['in_trial'] && ! $startsNow => "Starts paid {$name} on {$start}, when your free trial ends".(($currentPlan['start_choice'] ?? null) === OrganizationFeatureRequest::ACTIVATION_AFTER_TRIAL ? ' (you chose "After my free trial ends")' : '').". Covers {$start} to {$end} ({$length}).",
            default => "{$verb} {$name} from {$start} to {$end} ({$length}).",
        };

        // A Base payment does not include a requested SMS; say so, so the SMS choice is not expected to apply here.
        $requestedStart = $currentPlan['sms']['requested_start'] ?? null;
        if ($bill->bill_type === Bill::TYPE_BASE && $requestedStart && in_array($currentPlan['sms']['state'], ['requested', 'price_ready'], true)) {
            $timing = ' SMS is added once Base + SMS is paid and approved.';
            $message .= $currentPlan['sms']['state'] === 'requested'
                ? ' This payment is for Base only. Your SMS price is not ready yet.'.$timing
                : ' This payment is for Base only. To add SMS, choose Base + SMS instead.'.$timing;
        }

        return ['kind' => 'renew', 'message' => $message];
    }

    /**
     * The payable bill for the next period of the requested plan.
     *
     * Plans are `base` and `package` (Base + SMS). `$type` also accepts the legacy boolean
     * `include_sms` flag (true = package, false = base). Null keeps an open bill's plan, or
     * defaults to package when an SMS price is agreed and to base otherwise. `$months` is
     * one of Bill::MONTH_OPTIONS; null keeps an open bill's length, or one month.
     *
     * @throws \DomainException when the plan or length is not available to the organization.
     */
    public function currentBill(Organization $organization, string|bool|null $type = null, ?int $months = null): Bill
    {
        $type = $this->resolveType($organization, $type);

        // A payment already under review always shows its own bill, even if the offer has changed since.
        $underReview = $organization->bills()->where('bill_type', $type)->where('status', Bill::STATUS_PAYMENT_PENDING)->latest('id')->first();
        if ($underReview) {
            return $this->withRelations($underReview);
        }
        if ($type === Bill::TYPE_SMS_NOW) {
            return $this->smsNowBill($organization);
        }
        $this->assertAvailable($organization, $type);
        if ($months !== null && ! in_array($months, Bill::MONTH_OPTIONS, true)) {
            throw new \DomainException('Choose a plan length of 1, 3, 6, or 12 months.');
        }
        $periodStart = $this->nextPeriodStart($organization, $type);

        return DB::transaction(function () use ($organization, $type, $months, $periodStart): Bill {
            $open = $organization->bills()
                ->where('bill_type', $type)
                ->whereIn('status', [Bill::STATUS_UNPAID, Bill::STATUS_PAYMENT_PENDING])
                ->latest('billing_period_start')->latest('id')->lockForUpdate()->first();

            if ($open && $open->status === Bill::STATUS_PAYMENT_PENDING) {
                return $this->withRelations($open);
            }
            $months ??= $open?->months ?? 1;
            if ($open && $open->months === $months && $open->billing_period_start->toDateString() === $periodStart->toDateString()) {
                $this->syncItems($open, $organization, $periodStart);

                return $this->withRelations($open);
            }
            // The open bill describes a period or length that no longer applies (another payment moved
            // the paid-through date, or the school picked a different length), so it is retired.
            $open?->update(['status' => Bill::STATUS_CANCELLED]);

            $periodEnd = self::periodEnd($periodStart, $months);
            $bill = $organization->bills()->where('bill_type', $type)
                ->whereDate('billing_period_start', $periodStart->toDateString())
                ->whereDate('billing_period_end', $periodEnd->toDateString())
                ->lockForUpdate()->first()
                ?? $organization->bills()->create(['bill_type' => $type, 'months' => $months, 'billing_period_start' => $periodStart->toDateString(), 'billing_period_end' => $periodEnd->toDateString(), 'currency' => 'PHP', 'status' => Bill::STATUS_UNPAID, 'sms_included' => $type === Bill::TYPE_PACKAGE]);
            if ($bill->status === Bill::STATUS_CANCELLED) {
                $bill->update(['status' => Bill::STATUS_UNPAID]);
            }
            if ($bill->status === Bill::STATUS_UNPAID) {
                $this->syncItems($bill, $organization, $periodStart);
            }

            return $this->withRelations($bill);
        });
    }

    /**
     * The one-time "Add SMS" bill for today. It is rebuilt each day so the amount always matches
     * the days left; a bill from an earlier day is retired.
     */
    private function smsNowBill(Organization $organization): Bill
    {
        $quote = $this->smsNowQuote($organization);
        if (! $quote['available']) {
            throw new \DomainException(match ($quote['reason']) {
                'too_few_days' => "Add SMS is not available in the last {$quote['min_days']} days of your paid Base time. Choose Base + SMS for your next payment instead.",
                'sms_already_on' => 'SMS is already on.',
                'no_price' => 'SMS pricing has not been set for your organization yet. Request SMS Notifications first.',
                default => 'Add SMS is only available while your paid Base period is running.',
            });
        }

        return DB::transaction(function () use ($organization, $quote): Bill {
            $organization->bills()->where('bill_type', Bill::TYPE_SMS_NOW)->where('status', Bill::STATUS_UNPAID)
                ->where(fn ($query) => $query->whereDate('billing_period_start', '!=', $quote['start'])->orWhereDate('billing_period_end', '!=', $quote['end']))
                ->update(['status' => Bill::STATUS_CANCELLED]);

            $bill = $organization->bills()->where('bill_type', Bill::TYPE_SMS_NOW)
                ->whereDate('billing_period_start', $quote['start'])->whereDate('billing_period_end', $quote['end'])
                ->lockForUpdate()->first()
                ?? $organization->bills()->create(['bill_type' => Bill::TYPE_SMS_NOW, 'months' => 1, 'billing_period_start' => $quote['start'], 'billing_period_end' => $quote['end'], 'currency' => 'PHP', 'status' => Bill::STATUS_UNPAID, 'sms_included' => true]);
            if ($bill->status === Bill::STATUS_CANCELLED) {
                $bill->update(['status' => Bill::STATUS_UNPAID]);
            }
            if ($bill->status === Bill::STATUS_UNPAID) {
                $days = $quote['days_left'] === 1 ? '1 day' : $quote['days_left'].' days';
                $bill->items()->where('type', BillItem::TYPE_BASE)->delete();
                $bill->items()->updateOrCreate(
                    ['type' => BillItem::TYPE_SMS],
                    ['description' => "SMS Notifications for the rest of this period ({$days})", 'quantity' => 1, 'unit_amount_minor' => $quote['amount_minor'], 'amount_minor' => $quote['amount_minor'], 'meta' => array_intersect_key($quote, array_flip(['monthly_price_minor', 'days_left', 'full_months', 'partial_days', 'partial_month_days', 'pricing_agreement_id', 'sms_ends_at']))],
                );
                $bill->update(['subtotal_minor' => $quote['amount_minor'], 'total_minor' => $quote['amount_minor']]);
            }

            return $this->withRelations($bill);
        });
    }

    /**
     * Inclusive last day of a period of whole calendar months.
     */
    public static function periodEnd(Carbon $start, int $months): Carbon
    {
        return $start->copy()->addMonthsNoOverflow($months)->subDay();
    }

    /**
     * Which plans the organization can pay for right now. Base + SMS needs an agreed SMS price.
     *
     * @return array{base: bool, package: bool, sms_now: bool}
     */
    public function availableTypes(Organization $organization): array
    {
        return [
            Bill::TYPE_BASE => true,
            Bill::TYPE_PACKAGE => $this->latestActiveAgreement($organization) !== null,
            Bill::TYPE_SMS_NOW => $this->smsNowQuote($organization)['available'],
        ];
    }

    /**
     * The latest paid plan bill (Base or Base + SMS) whose period has not ended yet, current or paid ahead.
     * Both plans pay for Base, so whichever plan the school is looking at, this is the period it already has.
     */
    public function latestUnexpiredPaidBill(Organization $organization, string $type): ?Bill
    {
        if ($type === Bill::TYPE_SMS_NOW) {
            return null;
        }

        return $organization->bills()->with('items')
            ->whereIn('bill_type', Bill::TYPES)
            ->where('status', Bill::STATUS_PAID)
            ->whereDate('billing_period_end', '>=', $this->today($organization)->toDateString())
            ->latest('billing_period_end')->first();
    }

    /**
     * Whether a payment is already under review for any bill paying for one of this plan's features.
     * Both plans include Base, so one payment under review blocks the other plan too.
     */
    public function hasPaymentUnderReview(Organization $organization, string $type, ?int $exceptBillId = null): bool
    {
        return Payment::query()
            ->where('organization_id', $organization->id)
            ->whereIn('status', [Payment::STATUS_PENDING, Payment::STATUS_PROOF_REQUIRED])
            ->whereHas('bill', fn ($query) => $query->whereIn('bill_type', Bill::overlappingTypes($type))->when($exceptBillId, fn ($q) => $q->whereKeyNot($exceptBillId)))
            ->exists();
    }

    public function agreementFor(Organization $organization, Carbon $periodStart): ?SmsPricingAgreement
    {
        // Superseded prices still apply to periods that start before the newer price takes effect.
        return $organization->smsPricingAgreements()
            ->whereIn('status', [SmsPricingAgreement::STATUS_ACTIVE, SmsPricingAgreement::STATUS_SUPERSEDED])
            ->whereDate('effective_from', '<=', $periodStart->toDateString())
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $periodStart->toDateString()))
            ->latest('effective_from')->first();
    }

    /**
     * The first day of the next period: the later of the trial end, the paid subscription end, and today.
     */
    public function nextPeriodStart(Organization $organization, ?string $type = null): Carbon
    {
        // A trial school that chose "Start right away" ends its trial when its payment is approved,
        // so its Base or Base + SMS bill starts today. "Start after my free trial" keeps the normal start.
        if (in_array($type, Bill::TYPES, true) && $this->trialStartChoice($organization) === OrganizationFeatureRequest::ACTIVATION_IMMEDIATE) {
            return $this->today($organization);
        }

        // Always read the subscription fresh: an approval earlier in the same process may have moved it.
        $subscription = $organization->baseSubscription()->first();
        $start = collect([$subscription?->subscription_expires_at, $subscription?->trial_ends_at, now()])
            ->filter(fn ($date) => $date && $date->isFuture())
            ->sortBy(fn ($date) => $date->getTimestamp())
            ->last() ?: now();

        return Carbon::instance($start)->setTimezone($this->timezone($organization))->startOfDay();
    }

    private function resolveType(Organization $organization, string|bool|null $type): string
    {
        if (is_bool($type)) {
            return $type ? Bill::TYPE_PACKAGE : Bill::TYPE_BASE;
        }
        if ($type !== null) {
            if (! in_array($type, Bill::ALL_TYPES, true)) {
                throw new \DomainException('Choose either Base or Base + SMS.');
            }

            return $type;
        }

        // A payment under review wins, so the school sees what it already submitted.
        $underReview = $organization->bills()->whereIn('bill_type', Bill::ALL_TYPES)->where('status', Bill::STATUS_PAYMENT_PENDING)
            ->latest('updated_at')->latest('id')->first();
        if ($underReview) {
            return $underReview->bill_type;
        }

        // Otherwise default to the school's current plan, or to Base + SMS once a requested SMS price is ready.
        $current = $this->currentPlan($organization);

        $smsStopsBeforeBaseEnds = $current['sms']['ends_at'] && $current['base_paid_through']
            && $current['sms']['ends_at'] < Carbon::instance($current['base_paid_through'])->setTimezone($this->timezone($organization))->toDateString();
        // A paid Base school whose SMS price is ready lands on "Add SMS" (only the SMS days left), not a Base + SMS bill for next period.
        if ($current['sms']['state'] === 'price_ready' && $current['plan'] !== Bill::TYPE_PACKAGE && $this->smsNowQuote($organization)['available']) {
            return Bill::TYPE_SMS_NOW;
        }
        if (($current['plan'] === Bill::TYPE_PACKAGE && ! $smsStopsBeforeBaseEnds) || $current['sms']['state'] === 'price_ready') {
            return Bill::TYPE_PACKAGE;
        }

        // An expired school is offered the plan it last paid for, so a Base + SMS school does not lose SMS by accident.
        $lastPaid = $organization->bills()->whereIn('bill_type', Bill::TYPES)->where('status', Bill::STATUS_PAID)->latest('billing_period_end')->value('bill_type');

        return $current['status'] === 'expired' && $lastPaid === Bill::TYPE_PACKAGE && $this->availableTypes($organization)[Bill::TYPE_PACKAGE]
            ? Bill::TYPE_PACKAGE
            : Bill::TYPE_BASE;
    }

    private function assertAvailable(Organization $organization, string $type): void
    {
        if (! $this->availableTypes($organization)[$type]) {
            throw new \DomainException('SMS pricing has not been set for your organization yet. Request SMS Notifications first.');
        }
    }

    private function syncItems(Bill $bill, Organization $organization, Carbon $periodStart): void
    {
        $months = $bill->months;
        $bill->items()->updateOrCreate(
            ['type' => BillItem::TYPE_BASE],
            ['description' => 'Base Subscription', 'quantity' => $months, 'unit_amount_minor' => BaseSubscriptionService::MONTHLY_PRICE_MINOR, 'amount_minor' => BaseSubscriptionService::MONTHLY_PRICE_MINOR * $months, 'meta' => ['price_snapshot' => true]],
        );

        $agreement = $bill->coversSms() ? ($this->agreementFor($organization, $periodStart) ?? $this->latestActiveAgreement($organization)) : null;
        if ($agreement) {
            $bill->items()->updateOrCreate(
                ['type' => BillItem::TYPE_SMS],
                ['description' => 'SMS Notifications', 'quantity' => $months, 'unit_amount_minor' => $agreement->sms_monthly_price_minor, 'amount_minor' => $agreement->sms_monthly_price_minor * $months, 'meta' => ['pricing_agreement_id' => $agreement->id, 'person_count_snapshot' => $agreement->person_count_snapshot]],
            );
        } else {
            $bill->items()->where('type', BillItem::TYPE_SMS)->delete();
        }

        $total = (int) $bill->items()->sum('amount_minor');
        $bill->update(['subtotal_minor' => $total, 'total_minor' => $total, 'sms_included' => $agreement !== null]);
    }

    /**
     * The start choice ("after_trial" or "immediate") of a school still on its unpaid free trial, if it made one.
     */
    public function trialStartChoice(Organization $organization): ?string
    {
        $subscription = $organization->baseSubscription()->first();
        $onUnpaidTrial = $subscription?->trial_ends_at?->isFuture()
            && ! ($subscription->subscription_expires_at && $subscription->subscription_expires_at->isFuture());
        $choice = $onUnpaidTrial ? ($subscription->start_choice ?? $this->pendingSmsRequest($organization)?->activation_mode) : null;

        return in_array($choice, [OrganizationFeatureRequest::ACTIVATION_AFTER_TRIAL, OrganizationFeatureRequest::ACTIVATION_IMMEDIATE], true) ? $choice : null;
    }

    /**
     * Save a trial school's choice of when its paid plan starts, keep any pending SMS request in step,
     * and re-date open bills so the school page, the admin review and the approval all agree.
     *
     * @throws \DomainException when the school is not on its unpaid free trial.
     */
    public function setTrialStartChoice(Organization $organization, string $choice): void
    {
        $subscription = $organization->baseSubscription()->first();
        $onUnpaidTrial = $subscription?->trial_ends_at?->isFuture()
            && ! ($subscription->subscription_expires_at && $subscription->subscription_expires_at->isFuture());
        if (! $onUnpaidTrial) {
            throw new \DomainException('Only schools on their free trial can choose when their paid plan starts.');
        }

        DB::transaction(function () use ($organization, $subscription, $choice): void {
            $subscription->update(['start_choice' => $choice]);
            $this->pendingSmsRequest($organization)?->update(['activation_mode' => $choice]);
            $this->redateTrialBills($organization);
        });
    }

    /**
     * Re-date open Base and Base + SMS bills (including one with a payment under review) after a trial school
     * changes its start choice, so the bill, the admin review and the approval all agree. Amounts do not change.
     */
    public function redateTrialBills(Organization $organization): void
    {
        foreach (Bill::TYPES as $type) {
            $start = $this->nextPeriodStart($organization, $type);
            $organization->bills()->where('bill_type', $type)->whereIn('status', [Bill::STATUS_UNPAID, Bill::STATUS_PAYMENT_PENDING])->get()
                ->each(function (Bill $bill) use ($organization, $type, $start): void {
                    if ($bill->billing_period_start->toDateString() === $start->toDateString()) {
                        return;
                    }
                    $end = self::periodEnd($start, $bill->months);
                    $organization->bills()->where('bill_type', $type)->whereKeyNot($bill->id)
                        ->whereDate('billing_period_start', $start->toDateString())->whereDate('billing_period_end', $end->toDateString())
                        ->where('status', Bill::STATUS_CANCELLED)->whereDoesntHave('payments')->delete();
                    if (! $organization->bills()->where('bill_type', $type)->whereKeyNot($bill->id)->whereDate('billing_period_start', $start->toDateString())->whereDate('billing_period_end', $end->toDateString())->exists()) {
                        $bill->update(['billing_period_start' => $start->toDateString(), 'billing_period_end' => $end->toDateString()]);
                    }
                });
        }
    }

    /**
     * Today's date in the bill's organization timezone, so "today" matches what the school sees.
     */
    private function billToday(Bill $bill): string
    {
        return $bill->organization ? $this->today($bill->organization)->toDateString() : now()->toDateString();
    }

    private function pendingSmsRequest(Organization $organization): ?OrganizationFeatureRequest
    {
        return $organization->featureRequests()->where('feature', OrganizationFeatureRequest::FEATURE_SMS)->where('status', OrganizationFeatureRequest::STATUS_PENDING)->latest('requested_at')->first();
    }

    private function hasPendingSmsRequest(Organization $organization): bool
    {
        return $organization->featureRequests()->where('feature', OrganizationFeatureRequest::FEATURE_SMS)->where('status', OrganizationFeatureRequest::STATUS_PENDING)->exists();
    }

    private function latestActiveAgreement(Organization $organization): ?SmsPricingAgreement
    {
        return $organization->smsPricingAgreements()->where('status', SmsPricingAgreement::STATUS_ACTIVE)->latest('effective_from')->first();
    }

    private function withRelations(Bill $bill): Bill
    {
        return $bill->fresh(['items', 'payments' => fn ($query) => $query->latest()->latest('id')]);
    }

    public function todayFor(Organization $organization): Carbon
    {
        return $this->today($organization);
    }

    private function today(Organization $organization): Carbon
    {
        return Carbon::now($this->timezone($organization))->startOfDay();
    }

    private function timezone(Organization $organization): string
    {
        return $organization->timezone ?: config('app.timezone', 'UTC');
    }
}

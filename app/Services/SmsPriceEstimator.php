<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\SmsPricingAgreement;
use App\Models\User;

/**
 * Turns a school's answers (people to text, which texts, which days) into an estimated monthly SMS price.
 *
 * price = people × texts per day × school days per month × price per text, rounded up to the nearest step.
 * The price per text and the rounding step come from config('services.sms_estimate').
 * A price set by Super Admin always wins over an estimate.
 */
class SmsPriceEstimator
{
    /**
     * Average school days per month for each answer.
     */
    public const DAYS_PER_MONTH = ['weekdays' => 22, 'mon_sat' => 26, 'every_day' => 30];

    public const TEXTS = ['time_in', 'time_out', 'late'];

    public function __construct(private readonly SmsPricingService $pricing) {}

    /**
     * Late replaces Time In for that person, so it never adds an extra text.
     *
     * @param  list<string>  $texts
     */
    public static function textsPerDay(array $texts): int
    {
        return (int) (in_array('time_in', $texts, true) || in_array('late', $texts, true)) + (int) in_array('time_out', $texts, true);
    }

    /**
     * @param  list<string>  $texts
     */
    public function estimateMinor(int $people, array $texts, string $days): int
    {
        $settings = $this->settings();
        $raw = $people * self::textsPerDay($texts) * (self::DAYS_PER_MONTH[$days] ?? 0) * $settings['price_per_text_minor'];
        $step = $settings['round_to_minor'];

        return $step > 0 ? (int) (ceil($raw / $step) * $step) : $raw;
    }

    /**
     * @return array{price_per_text_minor: int, round_to_minor: int, days_per_month: array<string, int>}
     */
    public function settings(): array
    {
        return [
            'price_per_text_minor' => (int) round(((float) config('services.sms_estimate.price_per_text', 0.5)) * 100),
            'round_to_minor' => (int) round(((float) config('services.sms_estimate.round_to', 50)) * 100),
            'days_per_month' => self::DAYS_PER_MONTH,
        ];
    }

    /**
     * Save a school's answers and, unless Super Admin has set the price, make the estimate its SMS price.
     *
     * @param  list<string>  $texts
     * @return array{applied: bool, amount_minor: ?int}
     */
    public function saveAnswers(Organization $organization, User $actor, ?int $people, array $texts, ?string $days): array
    {
        $texts = array_values(array_intersect(self::TEXTS, $texts));
        $organization->update(['sms_estimate_people' => $people, 'sms_estimate_texts' => $texts ?: null, 'sms_estimate_days' => $days]);

        if (! $people || ! $days || self::textsPerDay($texts) === 0) {
            return ['applied' => false, 'amount_minor' => null];
        }

        $amount = $this->estimateMinor($people, $texts, $days);
        $active = $organization->smsPricingAgreements()->where('status', SmsPricingAgreement::STATUS_ACTIVE)->latest('effective_from')->first();
        if ($active && $active->source === SmsPricingAgreement::SOURCE_ADMIN) {
            return ['applied' => false, 'amount_minor' => $amount];
        }
        if (! $active || $active->sms_monthly_price_minor !== $amount) {
            $this->pricing->setPrice($organization, $actor, $amount, null, 'Estimated from the school\'s answers', SmsPricingAgreement::SOURCE_ESTIMATE);
        }

        return ['applied' => true, 'amount_minor' => $amount];
    }
}

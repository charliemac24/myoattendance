<?php

namespace Tests\Unit;

use App\Services\SmsPriceEstimator;
use Tests\TestCase;

class SmsPriceEstimatorTest extends TestCase
{
    public function test_late_replaces_time_in_so_it_never_adds_a_text(): void
    {
        $this->assertSame(2, SmsPriceEstimator::textsPerDay(['time_in', 'time_out']));
        $this->assertSame(2, SmsPriceEstimator::textsPerDay(['time_in', 'time_out', 'late']));
        $this->assertSame(1, SmsPriceEstimator::textsPerDay(['late']));
        $this->assertSame(1, SmsPriceEstimator::textsPerDay(['time_out']));
        $this->assertSame(0, SmsPriceEstimator::textsPerDay([]));
    }

    public function test_estimate_uses_people_texts_days_and_price_per_text(): void
    {
        $estimator = app(SmsPriceEstimator::class);

        // 350 × 2 × 22 × ₱0.50 = ₱7,700 (already a multiple of ₱50)
        $this->assertSame(770000, $estimator->estimateMinor(350, ['time_in', 'time_out'], 'weekdays'));
        // 100 × 1 × 30 × ₱0.50 = ₱1,500
        $this->assertSame(150000, $estimator->estimateMinor(100, ['time_out'], 'every_day'));
        // 10 × 2 × 26 × ₱0.50 = ₱260 -> rounded up to ₱300
        $this->assertSame(30000, $estimator->estimateMinor(10, ['late', 'time_out'], 'mon_sat'));
    }

    public function test_price_per_text_and_rounding_come_from_settings(): void
    {
        config(['services.sms_estimate.price_per_text' => '0.75', 'services.sms_estimate.round_to' => '100']);
        $estimator = app(SmsPriceEstimator::class);

        // 350 × 2 × 22 × ₱0.75 = ₱11,550 -> rounded up to ₱11,600
        $this->assertSame(1160000, $estimator->estimateMinor(350, ['time_in', 'time_out'], 'weekdays'));
        $this->assertSame(['price_per_text_minor' => 75, 'round_to_minor' => 10000, 'days_per_month' => SmsPriceEstimator::DAYS_PER_MONTH], $estimator->settings());

        config(['services.sms_estimate.round_to' => '0']);
        $this->assertSame(1155000, $estimator->estimateMinor(350, ['time_in', 'time_out'], 'weekdays'));
    }
}

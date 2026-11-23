<?php

namespace Tests\Unit;

use App\Services\BillingService;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SmsProrationTest extends TestCase
{
    /**
     * @return array<string, array{int, string, string, int, int, int, int, int}>
     */
    public static function charges(): array
    {
        // price, today, base end (exclusive), amount, days left, full months, partial days, partial month days
        return [
            '25 of 30 days' => [100000, '2026-11-09', '2026-12-04', 83333, 25, 0, 25, 30],
            'exactly one month left' => [100000, '2026-11-04', '2026-12-04', 100000, 30, 1, 0, 31],
            'one month and 25 days' => [100000, '2026-11-09', '2027-01-04', 183333, 56, 1, 25, 30],
            'rounds to the nearest centavo' => [49900, '2026-12-30', '2026-12-31', 1610, 1, 0, 1, 31],
            'month-end period (31-day slice)' => [49900, '2027-03-01', '2027-03-31', 48290, 30, 0, 30, 31],
            'leap-year February counts as a full month' => [49900, '2028-02-01', '2028-03-01', 49900, 29, 1, 0, 31],
            'last day' => [100000, '2026-12-03', '2026-12-04', 3333, 1, 0, 1, 30],
            'period already over' => [100000, '2026-12-04', '2026-12-04', 0, 0, 0, 0, 30],
        ];
    }

    #[DataProvider('charges')]
    public function test_prorated_sms_charge(int $price, string $today, string $end, int $amount, int $daysLeft, int $fullMonths, int $partialDays, int $partialMonthDays): void
    {
        $charge = BillingService::proratedSmsCharge($price, Carbon::parse($today), Carbon::parse($end));

        $this->assertSame($amount, $charge['amount_minor']);
        $this->assertSame($daysLeft, $charge['days_left']);
        $this->assertSame($fullMonths, $charge['full_months']);
        $this->assertSame($partialDays, $charge['partial_days']);
        $this->assertSame($partialMonthDays, $charge['partial_month_days']);
    }

    public function test_charge_never_exceeds_the_monthly_price_times_months_left(): void
    {
        $charge = BillingService::proratedSmsCharge(99999, Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31'));

        $this->assertLessThanOrEqual(12 * 99999, $charge['amount_minor']);
        $this->assertSame(11, $charge['full_months']);
    }
}

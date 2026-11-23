<?php

namespace Tests\Unit;

use App\Services\BillingService;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BillingPeriodTest extends TestCase
{
    /**
     * @return array<string, array{string, int, string}>
     */
    public static function periods(): array
    {
        return [
            'one month' => ['2026-11-20', 1, '2026-12-19'],
            'three months' => ['2026-11-20', 3, '2027-02-19'],
            'six months' => ['2026-11-20', 6, '2027-05-19'],
            'twelve months' => ['2026-11-20', 12, '2027-11-19'],
            'month end, non-leap year' => ['2027-01-31', 1, '2027-02-27'],
            'month end, leap year' => ['2028-01-31', 1, '2028-02-28'],
            'year boundary' => ['2026-12-31', 1, '2027-01-30'],
        ];
    }

    #[DataProvider('periods')]
    public function test_period_end_is_the_day_before_the_same_date_n_months_later(string $start, int $months, string $expectedEnd): void
    {
        $this->assertSame($expectedEnd, BillingService::periodEnd(Carbon::parse($start), $months)->toDateString());
    }
}

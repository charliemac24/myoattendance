<?php

namespace Tests\Unit;

use App\Models\Bill;
use PHPUnit\Framework\TestCase;

class BillTypeTest extends TestCase
{
    public function test_there_are_only_two_plans(): void
    {
        $this->assertSame([Bill::TYPE_BASE, Bill::TYPE_PACKAGE], Bill::TYPES);
        $this->assertSame([1, 3, 6, 12], Bill::MONTH_OPTIONS);
    }

    public function test_each_plan_pays_for_its_own_features(): void
    {
        $this->assertSame(['base'], Bill::featuresFor(Bill::TYPE_BASE));
        $this->assertSame(['base', 'sms'], Bill::featuresFor(Bill::TYPE_PACKAGE));
    }

    public function test_both_plans_overlap_because_both_include_base(): void
    {
        $this->assertSame(Bill::TYPES, Bill::overlappingTypes(Bill::TYPE_BASE));
        $this->assertSame(Bill::ALL_TYPES, Bill::overlappingTypes(Bill::TYPE_PACKAGE));
    }

    public function test_sms_now_is_a_one_time_sms_charge_not_a_plan(): void
    {
        $this->assertNotContains(Bill::TYPE_SMS_NOW, Bill::TYPES);
        $this->assertContains(Bill::TYPE_SMS_NOW, Bill::ALL_TYPES);
        $this->assertSame(['sms'], Bill::featuresFor(Bill::TYPE_SMS_NOW));
        $this->assertSame([Bill::TYPE_PACKAGE, Bill::TYPE_SMS_NOW], Bill::overlappingTypes(Bill::TYPE_SMS_NOW));
        $this->assertSame('SMS for the rest of this period', Bill::labelFor(Bill::TYPE_SMS_NOW));
        $this->assertSame('Base + SMS', Bill::labelFor(Bill::TYPE_PACKAGE));
    }

    public function test_sms_only_and_unknown_plans_are_rejected(): void
    {
        foreach (['sms', 'premium'] as $type) {
            try {
                Bill::featuresFor($type);
                $this->fail("Plan [{$type}] should not exist.");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_bill_coverage_helpers_follow_the_plan(): void
    {
        $this->assertTrue((new Bill(['bill_type' => Bill::TYPE_BASE]))->coversBase());
        $this->assertFalse((new Bill(['bill_type' => Bill::TYPE_BASE]))->coversSms());
        $this->assertTrue((new Bill(['bill_type' => Bill::TYPE_PACKAGE]))->coversBase());
        $this->assertTrue((new Bill(['bill_type' => Bill::TYPE_PACKAGE]))->coversSms());
        $this->assertSame(Bill::TYPE_BASE, (new Bill)->bill_type);
        $this->assertSame(1, (new Bill)->months);
    }
}

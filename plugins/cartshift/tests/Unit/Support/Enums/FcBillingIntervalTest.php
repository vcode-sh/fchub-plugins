<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Support\Enums;

use CartShift\Support\Enums\FcBillingInterval;
use CartShift\Tests\Unit\PluginTestCase;

final class FcBillingIntervalTest extends PluginTestCase
{
    public function testMonthly(): void
    {
        $this->assertSame(FcBillingInterval::Monthly, FcBillingInterval::fromWooCommerce('month', 1));
    }

    public function testMonthlyDefaultInterval(): void
    {
        $this->assertSame(FcBillingInterval::Monthly, FcBillingInterval::fromWooCommerce('month'));
    }

    public function testQuarterly(): void
    {
        $this->assertSame(FcBillingInterval::Quarterly, FcBillingInterval::fromWooCommerce('month', 3));
    }

    public function testHalfYearly(): void
    {
        $this->assertSame(FcBillingInterval::HalfYearly, FcBillingInterval::fromWooCommerce('month', 6));
    }

    public function testYearly(): void
    {
        $this->assertSame(FcBillingInterval::Yearly, FcBillingInterval::fromWooCommerce('year'));
    }

    public function testDaily(): void
    {
        $this->assertSame(FcBillingInterval::Daily, FcBillingInterval::fromWooCommerce('day'));
    }

    public function testWeekly(): void
    {
        $this->assertSame(FcBillingInterval::Weekly, FcBillingInterval::fromWooCommerce('week'));
    }

    public function testDefaultMonthly(): void
    {
        $this->assertSame(FcBillingInterval::Monthly, FcBillingInterval::fromWooCommerce('unknown-period'));
    }

    public function testMonthWithUnusualInterval(): void
    {
        // month with interval 2 should default to Monthly (not Quarterly or HalfYearly)
        $this->assertSame(FcBillingInterval::Monthly, FcBillingInterval::fromWooCommerce('month', 2));
    }

    public function testEnumValues(): void
    {
        $this->assertSame('daily', FcBillingInterval::Daily->value);
        $this->assertSame('weekly', FcBillingInterval::Weekly->value);
        $this->assertSame('monthly', FcBillingInterval::Monthly->value);
        $this->assertSame('quarterly', FcBillingInterval::Quarterly->value);
        $this->assertSame('half_yearly', FcBillingInterval::HalfYearly->value);
        $this->assertSame('yearly', FcBillingInterval::Yearly->value);
    }
}

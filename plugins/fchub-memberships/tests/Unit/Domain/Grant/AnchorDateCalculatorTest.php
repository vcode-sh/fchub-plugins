<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Grant;

use FChubMemberships\Domain\Grant\AnchorDateCalculator;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class AnchorDateCalculatorTest extends PluginTestCase
{
    // --- nextAnchorDate ---

    public function test_anchor_in_future_this_month(): void
    {
        $result = AnchorDateCalculator::nextAnchorDate(20, '2026-03-05 10:00:00');
        self::assertSame('2026-03-20 23:59:59', $result);
    }

    public function test_anchor_already_passed_returns_next_month(): void
    {
        $result = AnchorDateCalculator::nextAnchorDate(10, '2026-03-15 12:00:00');
        self::assertSame('2026-04-10 23:59:59', $result);
    }

    public function test_anchor_is_today_returns_next_month(): void
    {
        $result = AnchorDateCalculator::nextAnchorDate(15, '2026-03-15 08:00:00');
        self::assertSame('2026-04-15 23:59:59', $result);
    }

    public function test_day_31_clamped_to_feb_28(): void
    {
        $result = AnchorDateCalculator::nextAnchorDate(31, '2026-01-31 23:59:59');
        self::assertSame('2026-02-28 23:59:59', $result);
    }

    public function test_day_31_clamped_to_feb_29_leap_year(): void
    {
        $result = AnchorDateCalculator::nextAnchorDate(31, '2028-01-31 12:00:00');
        self::assertSame('2028-02-29 23:59:59', $result);
    }

    public function test_day_31_in_april_clamped_to_30(): void
    {
        $result = AnchorDateCalculator::nextAnchorDate(31, '2026-03-31 12:00:00');
        self::assertSame('2026-04-30 23:59:59', $result);
    }

    public function test_december_to_january_year_rollover(): void
    {
        $result = AnchorDateCalculator::nextAnchorDate(15, '2026-12-20 10:00:00');
        self::assertSame('2027-01-15 23:59:59', $result);
    }

    public function test_anchor_day_1_on_first_of_month(): void
    {
        $result = AnchorDateCalculator::nextAnchorDate(1, '2026-06-01 00:00:00');
        self::assertSame('2026-07-01 23:59:59', $result);
    }

    public function test_anchor_day_last_day_of_month(): void
    {
        $result = AnchorDateCalculator::nextAnchorDate(28, '2026-02-15 12:00:00');
        self::assertSame('2026-02-28 23:59:59', $result);
    }

    public function test_clamps_invalid_high_value(): void
    {
        $result = AnchorDateCalculator::nextAnchorDate(99, '2026-03-05 12:00:00');
        self::assertSame('2026-03-31 23:59:59', $result);
    }

    public function test_clamps_invalid_low_value(): void
    {
        $result = AnchorDateCalculator::nextAnchorDate(0, '2026-03-05 12:00:00');
        // Clamped to 1, which hasn't passed yet — wait, day 1 < day 5, so next month
        self::assertSame('2026-04-01 23:59:59', $result);
    }

    // --- nextAnchorAfter ---

    public function test_next_anchor_after_advances_one_month(): void
    {
        $result = AnchorDateCalculator::nextAnchorAfter(20, '2026-03-20 23:59:59');
        self::assertSame('2026-04-20 23:59:59', $result);
    }

    public function test_next_anchor_after_dec_to_jan(): void
    {
        $result = AnchorDateCalculator::nextAnchorAfter(15, '2026-12-15 23:59:59');
        self::assertSame('2027-01-15 23:59:59', $result);
    }

    public function test_next_anchor_after_jan_31_to_feb_28(): void
    {
        $result = AnchorDateCalculator::nextAnchorAfter(31, '2026-01-31 23:59:59');
        self::assertSame('2026-02-28 23:59:59', $result);
    }

    public function test_next_anchor_after_feb_to_march_restores_full_day(): void
    {
        // Anchor day is 31 but Feb clamped to 28. March should restore to 31.
        $result = AnchorDateCalculator::nextAnchorAfter(31, '2026-02-28 23:59:59');
        self::assertSame('2026-03-31 23:59:59', $result);
    }

    // --- Adversarial / edge cases ---

    public function test_anchor_day_exactly_last_day_of_short_month(): void
    {
        // April has 30 days. Anchor day 30 on April 30 should go to May 30.
        $result = AnchorDateCalculator::nextAnchorDate(30, '2026-04-30 12:00:00');
        self::assertSame('2026-05-30 23:59:59', $result);
    }

    public function test_anchor_day_31_consecutive_short_months(): void
    {
        // June 30 (anchor 31 clamped to 30, and it's today) → July 31
        $result = AnchorDateCalculator::nextAnchorDate(31, '2026-06-30 00:00:00');
        self::assertSame('2026-07-31 23:59:59', $result);
    }

    public function test_next_anchor_after_preserves_day_across_long_months(): void
    {
        // Anchor 15, current July 15 → August 15
        $result = AnchorDateCalculator::nextAnchorAfter(15, '2026-07-15 23:59:59');
        self::assertSame('2026-08-15 23:59:59', $result);
    }

    public function test_reference_date_with_time_component(): void
    {
        // Ensure time of day in reference doesn't affect date logic
        $early = AnchorDateCalculator::nextAnchorDate(20, '2026-03-05 00:00:01');
        $late = AnchorDateCalculator::nextAnchorDate(20, '2026-03-05 23:59:58');
        self::assertSame($early, $late);
    }

    public function test_anchor_day_1_near_end_of_month(): void
    {
        // Anchor day 1 on day 28 of month → next month's 1st
        $result = AnchorDateCalculator::nextAnchorDate(1, '2026-03-28 12:00:00');
        self::assertSame('2026-04-01 23:59:59', $result);
    }

    public function test_anchor_after_year_boundary(): void
    {
        // nextAnchorAfter from Dec 31 → Jan 31 next year
        $result = AnchorDateCalculator::nextAnchorAfter(31, '2026-12-31 23:59:59');
        self::assertSame('2027-01-31 23:59:59', $result);
    }

    public function test_anchor_day_29_in_non_leap_feb(): void
    {
        // 2026 is not a leap year. Anchor 29 in Jan → Feb clamps to 28.
        $result = AnchorDateCalculator::nextAnchorDate(29, '2026-01-30 12:00:00');
        self::assertSame('2026-02-28 23:59:59', $result);
    }

    public function test_anchor_day_29_in_leap_feb(): void
    {
        // 2028 is a leap year. Anchor 29 in Jan → Feb 29.
        $result = AnchorDateCalculator::nextAnchorDate(29, '2028-01-30 12:00:00');
        self::assertSame('2028-02-29 23:59:59', $result);
    }

    public function test_next_anchor_date_with_day_before_anchor(): void
    {
        // Day 1, anchor day 31 → this month's 31st (future)
        $result = AnchorDateCalculator::nextAnchorDate(31, '2026-01-01 00:00:00');
        self::assertSame('2026-01-31 23:59:59', $result);
    }
}

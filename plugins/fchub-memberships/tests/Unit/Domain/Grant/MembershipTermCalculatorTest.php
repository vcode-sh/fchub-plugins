<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Grant;

use FChubMemberships\Domain\Grant\MembershipTermCalculator;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class MembershipTermCalculatorTest extends PluginTestCase
{
    // --- calculateEndDate ---

    public function test_mode_none_returns_null(): void
    {
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => 'none'],
            '2026-03-14 10:00:00'
        );
        self::assertNull($result);
    }

    public function test_preset_1y(): void
    {
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => '1y'],
            '2026-03-14 10:00:00'
        );
        self::assertSame('2027-03-14 23:59:59', $result);
    }

    public function test_preset_2y(): void
    {
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => '2y'],
            '2026-03-14 10:00:00'
        );
        self::assertSame('2028-03-14 23:59:59', $result);
    }

    public function test_preset_3y(): void
    {
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => '3y'],
            '2026-03-14 10:00:00'
        );
        self::assertSame('2029-03-14 23:59:59', $result);
    }

    public function test_preset_1y_year_rollover(): void
    {
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => '1y'],
            '2026-12-01 08:00:00'
        );
        self::assertSame('2027-12-01 23:59:59', $result);
    }

    public function test_preset_1y_leap_year(): void
    {
        // Feb 29 2028 + 1 year = Feb 28 2029 (non-leap)
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => '1y'],
            '2028-02-29 12:00:00'
        );
        self::assertSame('2029-03-01 23:59:59', $result);
    }

    public function test_custom_days(): void
    {
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => 'custom', 'value' => 30, 'unit' => 'days'],
            '2026-03-14 10:00:00'
        );
        self::assertSame('2026-04-13 23:59:59', $result);
    }

    public function test_custom_weeks(): void
    {
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => 'custom', 'value' => 2, 'unit' => 'weeks'],
            '2026-03-14 10:00:00'
        );
        self::assertSame('2026-03-28 23:59:59', $result);
    }

    public function test_custom_months(): void
    {
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => 'custom', 'value' => 6, 'unit' => 'months'],
            '2026-03-14 10:00:00'
        );
        self::assertSame('2026-09-14 23:59:59', $result);
    }

    public function test_custom_years(): void
    {
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => 'custom', 'value' => 5, 'unit' => 'years'],
            '2026-03-14 10:00:00'
        );
        self::assertSame('2031-03-14 23:59:59', $result);
    }

    public function test_custom_zero_value_returns_null(): void
    {
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => 'custom', 'value' => 0, 'unit' => 'months'],
            '2026-03-14 10:00:00'
        );
        self::assertNull($result);
    }

    public function test_custom_invalid_unit_returns_null(): void
    {
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => 'custom', 'value' => 6, 'unit' => 'centuries'],
            '2026-03-14 10:00:00'
        );
        self::assertNull($result);
    }

    public function test_custom_missing_value_returns_null(): void
    {
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => 'custom', 'unit' => 'months'],
            '2026-03-14 10:00:00'
        );
        self::assertNull($result);
    }

    public function test_date_mode(): void
    {
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => 'date', 'date' => '2027-12-31'],
            '2026-03-14 10:00:00'
        );
        self::assertSame('2027-12-31 23:59:59', $result);
    }

    public function test_date_mode_null_date_returns_null(): void
    {
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => 'date', 'date' => null],
            '2026-03-14 10:00:00'
        );
        self::assertNull($result);
    }

    public function test_date_mode_empty_date_returns_null(): void
    {
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => 'date'],
            '2026-03-14 10:00:00'
        );
        self::assertNull($result);
    }

    public function test_unknown_mode_returns_null(): void
    {
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => 'forever_and_ever'],
            '2026-03-14 10:00:00'
        );
        self::assertNull($result);
    }

    public function test_empty_config_returns_null(): void
    {
        $result = MembershipTermCalculator::calculateEndDate([], '2026-03-14 10:00:00');
        self::assertNull($result);
    }

    // --- isTermExpired ---

    public function test_no_term_meta_returns_false(): void
    {
        self::assertFalse(MembershipTermCalculator::isTermExpired([]));
    }

    public function test_null_term_returns_false(): void
    {
        self::assertFalse(MembershipTermCalculator::isTermExpired(
            ['membership_term_ends_at' => null]
        ));
    }

    public function test_future_term_returns_false(): void
    {
        self::assertFalse(MembershipTermCalculator::isTermExpired(
            ['membership_term_ends_at' => '2099-12-31 23:59:59'],
            '2026-03-14 10:00:00'
        ));
    }

    public function test_past_term_returns_true(): void
    {
        self::assertTrue(MembershipTermCalculator::isTermExpired(
            ['membership_term_ends_at' => '2025-01-01 23:59:59'],
            '2026-03-14 10:00:00'
        ));
    }

    public function test_exact_boundary_returns_true(): void
    {
        self::assertTrue(MembershipTermCalculator::isTermExpired(
            ['membership_term_ends_at' => '2026-03-14 10:00:00'],
            '2026-03-14 10:00:00'
        ));
    }

    public function test_one_second_before_returns_false(): void
    {
        self::assertFalse(MembershipTermCalculator::isTermExpired(
            ['membership_term_ends_at' => '2026-03-14 10:00:01'],
            '2026-03-14 10:00:00'
        ));
    }

    // --- capExpiry ---

    public function test_proposed_before_term_returns_proposed(): void
    {
        $result = MembershipTermCalculator::capExpiry(
            '2026-06-01 23:59:59',
            '2027-03-14 23:59:59'
        );
        self::assertSame('2026-06-01 23:59:59', $result);
    }

    public function test_proposed_after_term_returns_term(): void
    {
        $result = MembershipTermCalculator::capExpiry(
            '2028-01-01 00:00:00',
            '2027-03-14 23:59:59'
        );
        self::assertSame('2027-03-14 23:59:59', $result);
    }

    public function test_null_term_returns_proposed(): void
    {
        $result = MembershipTermCalculator::capExpiry(
            '2028-01-01 00:00:00',
            null
        );
        self::assertSame('2028-01-01 00:00:00', $result);
    }

    public function test_equal_dates_returns_proposed(): void
    {
        $result = MembershipTermCalculator::capExpiry(
            '2027-03-14 23:59:59',
            '2027-03-14 23:59:59'
        );
        self::assertSame('2027-03-14 23:59:59', $result);
    }

    // --- validate ---

    public function test_validate_none_is_valid(): void
    {
        self::assertNull(MembershipTermCalculator::validate(['mode' => 'none']));
    }

    public function test_validate_preset_1y_is_valid(): void
    {
        self::assertNull(MembershipTermCalculator::validate(['mode' => '1y']));
    }

    public function test_validate_preset_2y_is_valid(): void
    {
        self::assertNull(MembershipTermCalculator::validate(['mode' => '2y']));
    }

    public function test_validate_preset_3y_is_valid(): void
    {
        self::assertNull(MembershipTermCalculator::validate(['mode' => '3y']));
    }

    public function test_validate_custom_valid(): void
    {
        self::assertNull(MembershipTermCalculator::validate([
            'mode' => 'custom',
            'value' => 6,
            'unit' => 'months',
        ]));
    }

    public function test_validate_custom_missing_value(): void
    {
        $error = MembershipTermCalculator::validate([
            'mode' => 'custom',
            'unit' => 'months',
        ]);
        self::assertNotNull($error);
    }

    public function test_validate_custom_zero_value(): void
    {
        $error = MembershipTermCalculator::validate([
            'mode' => 'custom',
            'value' => 0,
            'unit' => 'months',
        ]);
        self::assertNotNull($error);
    }

    public function test_validate_custom_invalid_unit(): void
    {
        $error = MembershipTermCalculator::validate([
            'mode' => 'custom',
            'value' => 6,
            'unit' => 'centuries',
        ]);
        self::assertNotNull($error);
    }

    public function test_validate_custom_missing_unit(): void
    {
        $error = MembershipTermCalculator::validate([
            'mode' => 'custom',
            'value' => 6,
        ]);
        self::assertNotNull($error);
    }

    public function test_validate_date_valid(): void
    {
        self::assertNull(MembershipTermCalculator::validate([
            'mode' => 'date',
            'date' => '2027-12-31',
        ]));
    }

    public function test_validate_date_missing(): void
    {
        $error = MembershipTermCalculator::validate([
            'mode' => 'date',
        ]);
        self::assertNotNull($error);
    }

    public function test_validate_date_null(): void
    {
        $error = MembershipTermCalculator::validate([
            'mode' => 'date',
            'date' => null,
        ]);
        self::assertNotNull($error);
    }

    public function test_validate_unknown_mode(): void
    {
        $error = MembershipTermCalculator::validate([
            'mode' => 'infinite_power',
        ]);
        self::assertNotNull($error);
    }

    public function test_validate_empty_mode(): void
    {
        $error = MembershipTermCalculator::validate([
            'mode' => '',
        ]);
        self::assertNotNull($error);
    }

    public function test_validate_all_custom_units(): void
    {
        foreach (['days', 'weeks', 'months', 'years'] as $unit) {
            self::assertNull(
                MembershipTermCalculator::validate(['mode' => 'custom', 'value' => 1, 'unit' => $unit]),
                "Unit '{$unit}' should be valid"
            );
        }
    }
}

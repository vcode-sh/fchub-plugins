<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Grant;

use FChubMemberships\Domain\Grant\MembershipTermCalculator;
use FChubMemberships\Tests\Unit\PluginTestCase;

/**
 * Bug hunt: type juggling, strtotime edge cases, validation bypasses.
 */
final class CalculatorBugHuntTest extends PluginTestCase
{
    // --- validate() type juggling ---

    public function test_validate_custom_string_numeric_value_passes(): void
    {
        self::assertNull(MembershipTermCalculator::validate([
            'mode' => 'custom', 'value' => '5', 'unit' => 'months',
        ]));
    }

    public function test_validate_custom_boolean_true_value_passes(): void
    {
        // true is truthy, (int)true = 1 >= 1 → passes
        self::assertNull(MembershipTermCalculator::validate([
            'mode' => 'custom', 'value' => true, 'unit' => 'months',
        ]));
    }

    public function test_validate_custom_boolean_false_value_fails(): void
    {
        self::assertNotNull(MembershipTermCalculator::validate([
            'mode' => 'custom', 'value' => false, 'unit' => 'months',
        ]));
    }

    public function test_validate_custom_float_value_passes(): void
    {
        self::assertNull(MembershipTermCalculator::validate([
            'mode' => 'custom', 'value' => 2.9, 'unit' => 'days',
        ]));
    }

    public function test_validate_custom_negative_float_fails(): void
    {
        self::assertNotNull(MembershipTermCalculator::validate([
            'mode' => 'custom', 'value' => -0.5, 'unit' => 'days',
        ]));
    }

    public function test_validate_custom_array_value_fails(): void
    {
        self::assertNotNull(MembershipTermCalculator::validate([
            'mode' => 'custom', 'value' => [], 'unit' => 'days',
        ]));
    }

    public function test_validate_date_with_relative_string_passes(): void
    {
        // strtotime('+1 year') is not false — passes (admin input only)
        self::assertNull(MembershipTermCalculator::validate([
            'mode' => 'date', 'date' => '+1 year',
        ]));
    }

    // --- calculateEndDate: strtotime edge cases ---

    public function test_calculate_custom_with_boolean_true_gives_1_unit(): void
    {
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => 'custom', 'value' => true, 'unit' => 'months'],
            '2026-03-14 10:00:00'
        );
        self::assertSame('2026-04-14 23:59:59', $result);
    }

    public function test_calculate_preset_with_garbage_reference_does_not_crash(): void
    {
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => '1y'],
            'garbage-date'
        );
        self::assertNotNull($result);
    }

    public function test_calculate_date_mode_ignores_reference_date(): void
    {
        $r1 = MembershipTermCalculator::calculateEndDate(
            ['mode' => 'date', 'date' => '2030-06-15'],
            '2026-01-01 00:00:00'
        );
        $r2 = MembershipTermCalculator::calculateEndDate(
            ['mode' => 'date', 'date' => '2030-06-15'],
            '2099-12-31 23:59:59'
        );
        self::assertSame($r1, $r2);
        self::assertSame('2030-06-15 23:59:59', $r1);
    }

    public function test_calculate_custom_1_day_year_boundary(): void
    {
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => 'custom', 'value' => 1, 'unit' => 'days'],
            '2026-12-31 10:00:00'
        );
        self::assertSame('2027-01-01 23:59:59', $result);
    }

    public function test_calculate_custom_1_week(): void
    {
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => 'custom', 'value' => 1, 'unit' => 'weeks'],
            '2026-03-14 10:00:00'
        );
        self::assertSame('2026-03-21 23:59:59', $result);
    }

    // --- isTermExpired: falsy-but-valid edge cases ---

    public function test_is_term_expired_false_value_returns_false(): void
    {
        self::assertFalse(MembershipTermCalculator::isTermExpired([
            'membership_term_ends_at' => false,
        ]));
    }

    // --- capExpiry: boundary conditions ---

    public function test_cap_expiry_both_at_epoch(): void
    {
        $result = MembershipTermCalculator::capExpiry(
            '1970-01-01 00:00:00',
            '1970-01-01 00:00:00'
        );
        self::assertSame('1970-01-01 00:00:00', $result);
    }

    public function test_cap_expiry_far_future_capped(): void
    {
        $result = MembershipTermCalculator::capExpiry(
            '2099-12-31 23:59:59',
            '2050-06-15 23:59:59'
        );
        self::assertSame('2050-06-15 23:59:59', $result);
    }

    // --- validate + calculateEndDate consistency ---

    public function test_every_valid_mode_produces_non_null_and_correct_format(): void
    {
        $configs = [
            ['mode' => '1y'],
            ['mode' => '2y'],
            ['mode' => '3y'],
            ['mode' => 'custom', 'value' => 6, 'unit' => 'months'],
            ['mode' => 'date', 'date' => '2030-01-01'],
        ];

        foreach ($configs as $config) {
            self::assertNull(MembershipTermCalculator::validate($config), 'Should validate: ' . $config['mode']);
            $result = MembershipTermCalculator::calculateEndDate($config, '2026-03-14 10:00:00');
            self::assertNotNull($result, 'Should calculate: ' . $config['mode']);
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} 23:59:59$/', $result);
        }
    }

    public function test_mode_none_validates_and_returns_null(): void
    {
        self::assertNull(MembershipTermCalculator::validate(['mode' => 'none']));
        self::assertNull(MembershipTermCalculator::calculateEndDate(['mode' => 'none'], '2026-03-14'));
    }
}

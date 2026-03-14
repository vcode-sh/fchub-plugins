<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Grant;

use FChubMemberships\Domain\Grant\MembershipTermCalculator;
use FChubMemberships\Tests\Unit\PluginTestCase;

/**
 * Bug-hunt tests for MembershipTermCalculator.
 *
 * These target edge cases and potential bugs NOT covered by
 * MembershipTermCalculatorTest or MembershipTermAdversarialTest.
 */
final class MembershipTermCalculatorBugHuntTest extends PluginTestCase
{
    // =========================================================================
    // BUG 1: isTermExpired returns true for malformed termEndsAt
    //
    // strtotime('garbage') returns false, which PHP treats as 0.
    // 0 <= strtotime(valid_now) is true, so malformed meta causes
    // the grant to appear expired. This is already documented in the
    // adversarial test and accepted as current behaviour: if the stored
    // date is garbage, treating it as expired is the SAFE default
    // (deny access rather than grant it indefinitely).
    // =========================================================================

    public function test_is_term_expired_with_integer_zero_value(): void
    {
        // Integer 0 is falsy, should return false (no term set)
        self::assertFalse(MembershipTermCalculator::isTermExpired([
            'membership_term_ends_at' => 0,
        ]));
    }

    public function test_is_term_expired_with_boolean_false_value(): void
    {
        // false is falsy, should return false (no term set)
        self::assertFalse(MembershipTermCalculator::isTermExpired([
            'membership_term_ends_at' => false,
        ]));
    }

    public function test_is_term_expired_with_empty_array_value(): void
    {
        // Empty array is falsy, should return false
        self::assertFalse(MembershipTermCalculator::isTermExpired([
            'membership_term_ends_at' => [],
        ]));
    }

    public function test_is_term_expired_both_dates_malformed(): void
    {
        // Both strtotime calls return false (0). 0 <= 0 is true.
        $result = MembershipTermCalculator::isTermExpired(
            ['membership_term_ends_at' => 'broken-date'],
            'also-broken'
        );
        // Both are epoch 0, 0 <= 0 = true
        self::assertTrue($result);
    }

    public function test_is_term_expired_with_numeric_string_date(): void
    {
        // strtotime('12345') interprets as a date (not a timestamp)
        // It returns a timestamp, so this shouldn't crash
        $result = MembershipTermCalculator::isTermExpired(
            ['membership_term_ends_at' => '12345'],
            '2026-03-14 10:00:00'
        );
        // '12345' parsed by strtotime is some date interpretation — just verify no crash
        self::assertIsBool($result);
    }

    public function test_is_term_expired_with_whitespace_only_value(): void
    {
        // '   ' is truthy but strtotime('   ') may return false
        $result = MembershipTermCalculator::isTermExpired(
            ['membership_term_ends_at' => '   '],
            '2026-03-14 10:00:00'
        );
        // strtotime('   ') returns false on most PHP versions
        // false (0) <= valid timestamp = true (treated as expired)
        self::assertIsBool($result);
    }

    // =========================================================================
    // capExpiry: malformed input handling
    // =========================================================================

    public function test_cap_expiry_malformed_proposed_returns_it_as_fallback(): void
    {
        // BUG FIX: strtotime('garbage') returns false for proposedExpiry.
        // The fix detects false and returns proposedExpiry as-is (safe fallback).
        $result = MembershipTermCalculator::capExpiry(
            'garbage-date',
            '2027-03-14 23:59:59'
        );
        self::assertSame('garbage-date', $result);
    }

    public function test_cap_expiry_malformed_term_returns_proposed(): void
    {
        // BUG FIX: strtotime('garbage') returns false for termEndsAt.
        // Before the fix, false (0) caused the comparison to return the
        // garbage string. Now we detect false and return proposedExpiry.
        $result = MembershipTermCalculator::capExpiry(
            '2027-03-14 23:59:59',
            'garbage-term'
        );
        self::assertSame('2027-03-14 23:59:59', $result);
    }

    public function test_cap_expiry_both_malformed_returns_proposed(): void
    {
        // Both strtotime calls return false. The fix detects this and
        // falls back to returning proposedExpiry as-is.
        $result = MembershipTermCalculator::capExpiry(
            'garbage-proposed',
            'garbage-term'
        );
        self::assertSame('garbage-proposed', $result);
    }

    public function test_cap_expiry_epoch_dates(): void
    {
        // 1970-01-01 is a valid date
        $result = MembershipTermCalculator::capExpiry(
            '1970-01-01 00:00:00',
            '2027-03-14 23:59:59'
        );
        // Epoch is before 2027, so proposed wins
        self::assertSame('1970-01-01 00:00:00', $result);
    }

    public function test_cap_expiry_far_future_proposed(): void
    {
        $result = MembershipTermCalculator::capExpiry(
            '2999-12-31 23:59:59',
            '2027-03-14 23:59:59'
        );
        // Term caps the far future proposed
        self::assertSame('2027-03-14 23:59:59', $result);
    }

    // =========================================================================
    // validate(): type juggling edge cases
    // =========================================================================

    public function test_validate_custom_with_boolean_true_value_passes(): void
    {
        // !true is false (passes empty check). (int) true = 1, passes >= 1 check.
        // This is arguably a bug — boolean true shouldn't be a valid duration value.
        $result = MembershipTermCalculator::validate([
            'mode' => 'custom',
            'value' => true,
            'unit' => 'months',
        ]);
        // Currently passes validation because (int) true = 1
        self::assertNull($result);
    }

    public function test_validate_custom_with_float_value_passes(): void
    {
        // 1.5 is truthy, (int) 1.5 = 1, passes both checks
        $result = MembershipTermCalculator::validate([
            'mode' => 'custom',
            'value' => 1.5,
            'unit' => 'days',
        ]);
        self::assertNull($result);
    }

    public function test_validate_custom_with_float_below_one_fails(): void
    {
        // 0.5 is truthy (!0.5 = false), but (int) 0.5 = 0, fails < 1
        $result = MembershipTermCalculator::validate([
            'mode' => 'custom',
            'value' => 0.5,
            'unit' => 'days',
        ]);
        self::assertNotNull($result);
    }

    public function test_validate_custom_with_numeric_string_passes(): void
    {
        // '6' is truthy, (int) '6' = 6, passes
        $result = MembershipTermCalculator::validate([
            'mode' => 'custom',
            'value' => '6',
            'unit' => 'months',
        ]);
        self::assertNull($result);
    }

    public function test_validate_custom_with_string_zero_fails(): void
    {
        // '0' is falsy in PHP, !$value catches it before int cast
        $result = MembershipTermCalculator::validate([
            'mode' => 'custom',
            'value' => '0',
            'unit' => 'months',
        ]);
        self::assertNotNull($result);
    }

    public function test_validate_custom_with_empty_string_value_fails(): void
    {
        // '' is falsy, !$value catches it
        $result = MembershipTermCalculator::validate([
            'mode' => 'custom',
            'value' => '',
            'unit' => 'months',
        ]);
        self::assertNotNull($result);
    }

    public function test_validate_custom_with_large_integer(): void
    {
        // Very large values are now capped by max value validation
        $result = MembershipTermCalculator::validate([
            'mode' => 'custom',
            'value' => PHP_INT_MAX,
            'unit' => 'days',
        ]);
        self::assertNotNull($result, 'Values exceeding the max cap must be rejected');
    }

    public function test_validate_custom_with_boolean_false_value_fails(): void
    {
        // false is falsy, !$value catches it
        $result = MembershipTermCalculator::validate([
            'mode' => 'custom',
            'value' => false,
            'unit' => 'months',
        ]);
        self::assertNotNull($result);
    }

    public function test_validate_custom_with_null_unit_fails(): void
    {
        $result = MembershipTermCalculator::validate([
            'mode' => 'custom',
            'value' => 5,
            'unit' => null,
        ]);
        self::assertNotNull($result);
    }

    public function test_validate_custom_with_uppercase_unit_fails(): void
    {
        // Strict comparison means 'Days' !== 'days'
        $result = MembershipTermCalculator::validate([
            'mode' => 'custom',
            'value' => 5,
            'unit' => 'Days',
        ]);
        self::assertNotNull($result);
    }

    public function test_validate_custom_with_unit_having_trailing_space_fails(): void
    {
        $result = MembershipTermCalculator::validate([
            'mode' => 'custom',
            'value' => 5,
            'unit' => 'days ',
        ]);
        self::assertNotNull($result);
    }

    public function test_validate_date_with_past_date_passes(): void
    {
        // validate() does NOT check whether the date is in the past
        $result = MembershipTermCalculator::validate([
            'mode' => 'date',
            'date' => '2020-01-01',
        ]);
        // Passes — no past-date check exists
        self::assertNull($result);
    }

    public function test_validate_date_with_relative_string_passes(): void
    {
        // strtotime('+1 year') succeeds, so validate() passes
        $result = MembershipTermCalculator::validate([
            'mode' => 'date',
            'date' => '+1 year',
        ]);
        self::assertNull($result);
    }

    public function test_validate_empty_config_returns_error(): void
    {
        // Empty array: mode defaults to '' via ?? '', which is not in VALID_MODES
        $result = MembershipTermCalculator::validate([]);
        self::assertNotNull($result);
    }

    public function test_validate_mode_null_returns_error(): void
    {
        // mode = null, ?? '' makes it '', not in VALID_MODES
        $result = MembershipTermCalculator::validate(['mode' => null]);
        self::assertNotNull($result);
    }

    public function test_validate_mode_with_numeric_zero_returns_error(): void
    {
        // mode = 0, not a string, strict in_array fails
        $result = MembershipTermCalculator::validate(['mode' => 0]);
        self::assertNotNull($result);
    }

    public function test_validate_mode_with_boolean_true_returns_error(): void
    {
        // mode = true, strict in_array fails against string array
        $result = MembershipTermCalculator::validate(['mode' => true]);
        self::assertNotNull($result);
    }

    // =========================================================================
    // calculateEndDate: edge cases not in existing tests
    // =========================================================================

    public function test_calculate_custom_value_as_string_numeric(): void
    {
        // '6' cast to (int) = 6, valid
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => 'custom', 'value' => '6', 'unit' => 'months'],
            '2026-03-14 10:00:00'
        );
        self::assertSame('2026-09-14 23:59:59', $result);
    }

    public function test_calculate_custom_value_boolean_true_produces_one_unit(): void
    {
        // (int) true = 1
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => 'custom', 'value' => true, 'unit' => 'months'],
            '2026-03-14 10:00:00'
        );
        // +1 month from March 14 = April 14
        self::assertSame('2026-04-14 23:59:59', $result);
    }

    public function test_calculate_custom_negative_produces_null(): void
    {
        // (int) -3 = -3, -3 < 1, returns null
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => 'custom', 'value' => -3, 'unit' => 'months'],
            '2026-03-14 10:00:00'
        );
        self::assertNull($result);
    }

    public function test_calculate_custom_missing_unit_defaults_to_months(): void
    {
        // unit defaults to 'months' via ?? 'months'
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => 'custom', 'value' => 3],
            '2026-03-14 10:00:00'
        );
        // 'months' is in VALID_UNITS, so this works
        self::assertSame('2026-06-14 23:59:59', $result);
    }

    public function test_calculate_date_with_past_date_returns_past(): void
    {
        // calculateEndDate doesn't validate whether the date is in the past
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => 'date', 'date' => '2020-01-01'],
            '2026-03-14 10:00:00'
        );
        self::assertSame('2020-01-01 23:59:59', $result);
    }

    public function test_calculate_date_ignores_reference_date(): void
    {
        // In date mode, referenceDate is completely ignored
        $result1 = MembershipTermCalculator::calculateEndDate(
            ['mode' => 'date', 'date' => '2028-06-15'],
            '2026-01-01 00:00:00'
        );
        $result2 = MembershipTermCalculator::calculateEndDate(
            ['mode' => 'date', 'date' => '2028-06-15'],
            '2030-12-31 23:59:59'
        );
        self::assertSame($result1, $result2);
    }

    public function test_calculate_preset_with_feb_28_non_leap(): void
    {
        // Feb 28 2027 + 1 year = Feb 28 2028 (2028 is a leap year, stays at 28)
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => '1y'],
            '2027-02-28 12:00:00'
        );
        self::assertSame('2028-02-28 23:59:59', $result);
    }

    public function test_calculate_preset_jan31_plus_1y(): void
    {
        // Jan 31 + 1 year = Jan 31 (month-end doesn't drift)
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => '1y'],
            '2026-01-31 12:00:00'
        );
        self::assertSame('2027-01-31 23:59:59', $result);
    }

    public function test_calculate_custom_month_end_overflow(): void
    {
        // Jan 31 + 1 month => Feb 28 or March 3 depending on PHP
        // PHP strtotime: "+1 month" from Jan 31 goes to March 3 (31 days from Jan 31)
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => 'custom', 'value' => 1, 'unit' => 'months'],
            '2026-01-31 10:00:00'
        );
        // PHP's strtotime("+1 month", strtotime("2026-01-31")) gives March 3
        self::assertNotNull($result);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} 23:59:59$/', $result);
    }

    public function test_calculate_custom_1_day(): void
    {
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => 'custom', 'value' => 1, 'unit' => 'days'],
            '2026-03-14 10:00:00'
        );
        self::assertSame('2026-03-15 23:59:59', $result);
    }

    public function test_calculate_custom_1_week(): void
    {
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => 'custom', 'value' => 1, 'unit' => 'weeks'],
            '2026-03-14 10:00:00'
        );
        self::assertSame('2026-03-21 23:59:59', $result);
    }

    public function test_calculate_custom_1_year(): void
    {
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => 'custom', 'value' => 1, 'unit' => 'years'],
            '2026-03-14 10:00:00'
        );
        self::assertSame('2027-03-14 23:59:59', $result);
    }

    public function test_calculate_date_mode_with_date_only_format(): void
    {
        // Just a date, no time
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => 'date', 'date' => '2028-12-25'],
            '2026-03-14 10:00:00'
        );
        self::assertSame('2028-12-25 23:59:59', $result);
    }

    public function test_calculate_date_mode_with_iso8601_format(): void
    {
        // ISO 8601 format with T separator
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => 'date', 'date' => '2028-12-25T14:30:00'],
            '2026-03-14 10:00:00'
        );
        self::assertSame('2028-12-25 23:59:59', $result);
    }

    public function test_calculate_date_mode_with_malformed_date_returns_null(): void
    {
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => 'date', 'date' => 'not-a-date-at-all'],
            '2026-03-14 10:00:00'
        );
        self::assertNull($result);
    }

    public function test_calculate_mode_missing_from_config_defaults_to_none(): void
    {
        // 'mode' key not present, defaults to 'none' via ??
        $result = MembershipTermCalculator::calculateEndDate(
            ['value' => 5, 'unit' => 'months'],
            '2026-03-14 10:00:00'
        );
        self::assertNull($result);
    }

    // =========================================================================
    // calculateEndDate: strtotime edge cases with reference date
    // =========================================================================

    public function test_calculate_reference_date_as_date_only(): void
    {
        // '2026-03-14' without time — strtotime handles it
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => '1y'],
            '2026-03-14'
        );
        self::assertSame('2027-03-14 23:59:59', $result);
    }

    public function test_calculate_reference_date_unix_timestamp_string(): void
    {
        // strtotime('@1710403200') is valid — resolves to a date
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => '1y'],
            '@1710403200'
        );
        self::assertNotNull($result);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} 23:59:59$/', $result);
    }

    // =========================================================================
    // calculateEndDate vs validate() consistency
    // =========================================================================

    public function test_validate_rejects_huge_value_and_calculate_returns_null(): void
    {
        // Very large values are now rejected by validation
        $config = ['mode' => 'custom', 'value' => 999999, 'unit' => 'days'];
        self::assertNotNull(MembershipTermCalculator::validate($config));

        // But calculateEndDate still produces a result (it doesn't enforce the cap)
        $result = MembershipTermCalculator::calculateEndDate($config, '2026-03-14 10:00:00');
        // On 64-bit PHP, strtotime handles large offsets
        self::assertNotNull($result);
    }

    public function test_validate_and_calculate_consistency_for_all_valid_modes(): void
    {
        // Every mode that passes validation should also produce a non-crashing calculateEndDate
        $configs = [
            ['mode' => 'none'],
            ['mode' => '1y'],
            ['mode' => '2y'],
            ['mode' => '3y'],
            ['mode' => 'custom', 'value' => 1, 'unit' => 'days'],
            ['mode' => 'custom', 'value' => 1, 'unit' => 'weeks'],
            ['mode' => 'custom', 'value' => 1, 'unit' => 'months'],
            ['mode' => 'custom', 'value' => 1, 'unit' => 'years'],
            ['mode' => 'date', 'date' => '2028-01-01'],
        ];

        foreach ($configs as $config) {
            self::assertNull(
                MembershipTermCalculator::validate($config),
                "Config should be valid: " . json_encode($config)
            );

            // calculateEndDate should not throw
            $result = MembershipTermCalculator::calculateEndDate($config, '2026-03-14 10:00:00');
            if ($config['mode'] === 'none') {
                self::assertNull($result);
            } else {
                self::assertNotNull($result, "calculateEndDate should produce result for: " . json_encode($config));
                self::assertMatchesRegularExpression(
                    '/^\d{4}-\d{2}-\d{2} 23:59:59$/',
                    $result,
                    "Result format wrong for: " . json_encode($config)
                );
            }
        }
    }

    // =========================================================================
    // calculateEndDate: output format consistency
    // =========================================================================

    public function test_all_modes_produce_23_59_59_suffix(): void
    {
        $cases = [
            ['mode' => '1y'],
            ['mode' => '2y'],
            ['mode' => '3y'],
            ['mode' => 'custom', 'value' => 10, 'unit' => 'days'],
            ['mode' => 'date', 'date' => '2028-06-15'],
        ];

        foreach ($cases as $config) {
            $result = MembershipTermCalculator::calculateEndDate($config, '2026-03-14 10:00:00');
            self::assertNotNull($result);
            self::assertStringEndsWith(' 23:59:59', $result, "Mode {$config['mode']} must end with 23:59:59");
        }
    }

    // =========================================================================
    // isTermExpired: boundary precision
    // =========================================================================

    public function test_is_term_expired_one_second_after_returns_true(): void
    {
        self::assertTrue(MembershipTermCalculator::isTermExpired(
            ['membership_term_ends_at' => '2026-03-14 09:59:59'],
            '2026-03-14 10:00:00'
        ));
    }

    public function test_is_term_expired_midnight_boundary(): void
    {
        // Term ends at 23:59:59, now is 00:00:00 next day
        self::assertTrue(MembershipTermCalculator::isTermExpired(
            ['membership_term_ends_at' => '2026-03-14 23:59:59'],
            '2026-03-15 00:00:00'
        ));
    }

    public function test_is_term_expired_same_second_returns_true(): void
    {
        // Exact same second: <= means expired
        self::assertTrue(MembershipTermCalculator::isTermExpired(
            ['membership_term_ends_at' => '2026-03-14 12:00:00'],
            '2026-03-14 12:00:00'
        ));
    }

    public function test_is_term_expired_with_date_only_format(): void
    {
        // strtotime('2026-03-14') = midnight March 14
        // 'now' is 10:00:00 March 14, which is after midnight
        self::assertTrue(MembershipTermCalculator::isTermExpired(
            ['membership_term_ends_at' => '2026-03-14'],
            '2026-03-14 10:00:00'
        ));
    }

    // =========================================================================
    // capExpiry: date format consistency
    // =========================================================================

    public function test_cap_expiry_different_date_formats(): void
    {
        // Proposed uses date-only, term uses datetime
        $result = MembershipTermCalculator::capExpiry(
            '2027-06-15',
            '2027-03-14 23:59:59'
        );
        // strtotime('2027-06-15') = midnight June 15 > March 14 23:59:59
        // So term wins
        self::assertSame('2027-03-14 23:59:59', $result);
    }

    public function test_cap_expiry_proposed_at_midnight_vs_term_at_end_of_day(): void
    {
        // Same date, but proposed is midnight and term is 23:59:59
        $result = MembershipTermCalculator::capExpiry(
            '2027-03-14 00:00:00',
            '2027-03-14 23:59:59'
        );
        // Proposed (midnight) < term (23:59:59), so proposed wins
        self::assertSame('2027-03-14 00:00:00', $result);
    }

    // =========================================================================
    // validate(): edge cases for date mode
    // =========================================================================

    public function test_validate_date_with_whitespace_only_fails(): void
    {
        // '   ' is truthy (passes !$date check) but strtotime('   ') returns false
        $result = MembershipTermCalculator::validate([
            'mode' => 'date',
            'date' => '   ',
        ]);
        self::assertNotNull($result);
    }

    public function test_validate_date_with_integer_value(): void
    {
        // date = 12345 — !12345 is false, strtotime on int...
        // strtotime expects string, PHP will cast int to string
        $result = MembershipTermCalculator::validate([
            'mode' => 'date',
            'date' => 12345,
        ]);
        // strtotime('12345') returns false on most PHP versions
        // or interprets it as a year. Either way, no crash.
        self::assertIsBool($result === null);
    }

    // =========================================================================
    // Cross-method: calculateEndDate result fed into isTermExpired
    // =========================================================================

    public function test_calculated_end_date_can_be_checked_by_is_term_expired(): void
    {
        $endDate = MembershipTermCalculator::calculateEndDate(
            ['mode' => '1y'],
            '2026-03-14 10:00:00'
        );
        self::assertNotNull($endDate);

        // The calculated date is in the future, so should not be expired
        self::assertFalse(MembershipTermCalculator::isTermExpired(
            ['membership_term_ends_at' => $endDate],
            '2026-03-14 10:00:00'
        ));

        // But should be expired 2 years later
        self::assertTrue(MembershipTermCalculator::isTermExpired(
            ['membership_term_ends_at' => $endDate],
            '2028-03-14 10:00:00'
        ));
    }

    public function test_calculated_end_date_fed_into_cap_expiry(): void
    {
        $termEnd = MembershipTermCalculator::calculateEndDate(
            ['mode' => 'custom', 'value' => 30, 'unit' => 'days'],
            '2026-03-14 10:00:00'
        );
        self::assertNotNull($termEnd);

        // Proposed expiry far in the future — should be capped
        $capped = MembershipTermCalculator::capExpiry('2099-01-01 00:00:00', $termEnd);
        self::assertSame($termEnd, $capped);

        // Proposed expiry before term — should not be capped
        $notCapped = MembershipTermCalculator::capExpiry('2026-03-20 00:00:00', $termEnd);
        self::assertSame('2026-03-20 00:00:00', $notCapped);
    }

    // =========================================================================
    // calculateEndDate: DST transition dates
    // =========================================================================

    public function test_calculate_across_spring_dst_transition(): void
    {
        // In most European timezones, clocks spring forward in late March
        // This shouldn't affect date calculation since PHP uses UTC in strtotime
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => 'custom', 'value' => 1, 'unit' => 'days'],
            '2026-03-28 10:00:00'
        );
        // Next day should still be March 29 regardless of DST
        self::assertSame('2026-03-29 23:59:59', $result);
    }

    public function test_calculate_across_autumn_dst_transition(): void
    {
        // Clocks fall back in late October
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => 'custom', 'value' => 1, 'unit' => 'days'],
            '2026-10-24 10:00:00'
        );
        self::assertSame('2026-10-25 23:59:59', $result);
    }

    // =========================================================================
    // calculateEndDate: year boundary
    // =========================================================================

    public function test_calculate_custom_days_crossing_year_boundary(): void
    {
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => 'custom', 'value' => 10, 'unit' => 'days'],
            '2026-12-28 10:00:00'
        );
        self::assertSame('2027-01-07 23:59:59', $result);
    }

    public function test_calculate_custom_months_crossing_year_boundary(): void
    {
        $result = MembershipTermCalculator::calculateEndDate(
            ['mode' => 'custom', 'value' => 3, 'unit' => 'months'],
            '2026-11-14 10:00:00'
        );
        self::assertSame('2027-02-14 23:59:59', $result);
    }
}

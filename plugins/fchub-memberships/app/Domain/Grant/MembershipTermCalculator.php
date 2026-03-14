<?php

namespace FChubMemberships\Domain\Grant;

defined('ABSPATH') || exit;

/**
 * Pure date calculation for membership term limits.
 *
 * A membership term is an absolute upper bound on how long a grant
 * can remain active. It works across all duration types (lifetime,
 * fixed_days, subscription_mirror, fixed_anchor).
 */
final class MembershipTermCalculator
{
    private const PRESET_YEARS = [
        '1y' => 1,
        '2y' => 2,
        '3y' => 3,
    ];

    private const VALID_MODES = ['none', '1y', '2y', '3y', 'custom', 'date'];
    private const VALID_UNITS = ['days', 'weeks', 'months', 'years'];

    /**
     * Calculate the absolute end date for a membership term.
     *
     * @param array  $termConfig    {mode, value?, unit?, date?}
     * @param string $referenceDate Any strtotime-parseable date (grant creation date)
     * @return ?string Y-m-d 23:59:59 or null if no term
     */
    public static function calculateEndDate(array $termConfig, string $referenceDate): ?string
    {
        $mode = $termConfig['mode'] ?? 'none';

        if ($mode === 'none') {
            return null;
        }

        // Preset year modes
        if (isset(self::PRESET_YEARS[$mode])) {
            $years = self::PRESET_YEARS[$mode];
            $time = strtotime("+{$years} year", strtotime($referenceDate));
            return $time !== false ? date('Y-m-d', $time) . ' 23:59:59' : null;
        }

        // Custom duration
        if ($mode === 'custom') {
            $value = (int) ($termConfig['value'] ?? 0);
            $unit = $termConfig['unit'] ?? 'months';

            if ($value < 1 || !in_array($unit, self::VALID_UNITS, true)) {
                return null;
            }

            $time = strtotime("+{$value} {$unit}", strtotime($referenceDate));
            return $time !== false ? date('Y-m-d', $time) . ' 23:59:59' : null;
        }

        // Specific date
        if ($mode === 'date') {
            $date = $termConfig['date'] ?? null;
            if (!$date) {
                return null;
            }
            $time = strtotime($date);
            return $time !== false ? date('Y-m-d', $time) . ' 23:59:59' : null;
        }

        // Unknown mode
        return null;
    }

    /**
     * Check if a grant's membership term has expired.
     *
     * @param array   $grantMeta The grant's meta array
     * @param ?string $now       Override current time for testing
     * @return bool True if term has expired
     */
    public static function isTermExpired(array $grantMeta, ?string $now = null): bool
    {
        $termEndsAt = $grantMeta['membership_term_ends_at'] ?? null;
        if (!$termEndsAt) {
            return false;
        }

        $now = $now ?? current_time('mysql');
        return strtotime($termEndsAt) <= strtotime($now);
    }

    /**
     * Cap a proposed expiry date at the term end date.
     *
     * @param string  $proposedExpiry The calculated expiry (Y-m-d H:i:s)
     * @param ?string $termEndsAt     The term end date, or null for no cap
     * @return string The earlier of the two dates
     */
    public static function capExpiry(string $proposedExpiry, ?string $termEndsAt): string
    {
        if ($termEndsAt === null) {
            return $proposedExpiry;
        }

        $proposedTime = strtotime($proposedExpiry);
        $termTime = strtotime($termEndsAt);

        if ($proposedTime === false || $termTime === false) {
            return $proposedExpiry;
        }

        return $proposedTime <= $termTime ? $proposedExpiry : $termEndsAt;
    }

    /**
     * Validate a term configuration.
     *
     * @param array $termConfig {mode, value?, unit?, date?}
     * @return ?string Error message or null if valid
     */
    public static function validate(array $termConfig): ?string
    {
        $mode = $termConfig['mode'] ?? '';

        if (!in_array($mode, self::VALID_MODES, true)) {
            return __('Invalid membership term mode.', 'fchub-memberships');
        }

        if ($mode === 'custom') {
            $value = $termConfig['value'] ?? null;
            $unit = $termConfig['unit'] ?? null;

            if (!$value || (int) $value < 1) {
                return __('Membership term value must be at least 1.', 'fchub-memberships');
            }
            if (!$unit || !in_array($unit, self::VALID_UNITS, true)) {
                return __('Invalid membership term unit.', 'fchub-memberships');
            }

            $maxValues = ['days' => 36500, 'weeks' => 5200, 'months' => 1200, 'years' => 100];
            if ((int) $value > ($maxValues[$unit] ?? 100)) {
                return __('Membership term value is too large.', 'fchub-memberships');
            }
        }

        if ($mode === 'date') {
            $date = $termConfig['date'] ?? null;
            if (!$date || !is_string($date) || trim($date) === '' || strtotime($date) === false) {
                return __('Invalid membership term date.', 'fchub-memberships');
            }
        }

        return null;
    }
}

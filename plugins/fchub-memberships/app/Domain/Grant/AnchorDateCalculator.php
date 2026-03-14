<?php

namespace FChubMemberships\Domain\Grant;

defined('ABSPATH') || exit;

/**
 * Pure date calculation for fixed billing anchor plans.
 *
 * Anchor day is the monthly due date (1-31). The membership expires
 * on that day each month. Short months clamp the anchor to the last
 * valid day (e.g. day 31 in February becomes the 28th/29th).
 */
final class AnchorDateCalculator
{
    /**
     * Next occurrence of the anchor day from a reference date.
     *
     * If anchor day hasn't passed this month, returns this month's anchor.
     * If anchor day has passed (or is today), returns next month's anchor.
     *
     * @param int    $anchorDay     Day of month (1-31)
     * @param string $referenceDate Any strtotime-parseable date
     * @return string Y-m-d 23:59:59
     */
    public static function nextAnchorDate(int $anchorDay, string $referenceDate): string
    {
        $anchorDay = self::clampDay($anchorDay);
        $refTime = strtotime($referenceDate);
        $refYear = (int) date('Y', $refTime);
        $refMonth = (int) date('n', $refTime);
        $refDay = (int) date('j', $refTime);

        $clampedThisMonth = min($anchorDay, self::daysInMonth($refMonth, $refYear));

        if ($clampedThisMonth > $refDay) {
            return self::formatAnchor($refYear, $refMonth, $clampedThisMonth);
        }

        // Anchor day has passed or is today — advance to next month
        return self::advanceMonth($anchorDay, $refYear, $refMonth);
    }

    /**
     * Always returns the following month's anchor from a known current anchor.
     *
     * Used for on-time renewals where the current period's anchor date is known.
     *
     * @param int    $anchorDay     Day of month (1-31)
     * @param string $currentAnchor The current anchor date (Y-m-d ...)
     * @return string Y-m-d 23:59:59
     */
    public static function nextAnchorAfter(int $anchorDay, string $currentAnchor): string
    {
        $anchorDay = self::clampDay($anchorDay);
        $time = strtotime($currentAnchor);
        $year = (int) date('Y', $time);
        $month = (int) date('n', $time);

        return self::advanceMonth($anchorDay, $year, $month);
    }

    private static function advanceMonth(int $anchorDay, int $year, int $month): string
    {
        $month++;
        if ($month > 12) {
            $month = 1;
            $year++;
        }

        $clamped = min($anchorDay, self::daysInMonth($month, $year));

        return self::formatAnchor($year, $month, $clamped);
    }

    private static function daysInMonth(int $month, int $year): int
    {
        return (int) date('t', mktime(0, 0, 0, $month, 1, $year));
    }

    private static function formatAnchor(int $year, int $month, int $day): string
    {
        return sprintf('%04d-%02d-%02d 23:59:59', $year, $month, $day);
    }

    private static function clampDay(int $day): int
    {
        return max(1, min(31, $day));
    }
}

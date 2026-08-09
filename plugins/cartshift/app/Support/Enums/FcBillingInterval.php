<?php

declare(strict_types=1);

namespace CartShift\Support\Enums;

defined('ABSPATH') || exit;

/**
 * The six values FluentCart 1.6.0 accepts for `billing_interval`.
 *
 * Verified against the installed source rather than inferred: both
 * `Modules/Subscriptions/Services/SubscriptionService.php:941` (the
 * `fluent_cart/subscription/allowed_intervals` default) and
 * `Modules/MCP/Tools/ContextTools.php:63` list exactly
 * daily / weekly / monthly / quarterly / half_yearly / yearly, and
 * `Models/Subscription.php:1289` switches on `half_yearly` spelled that way.
 */
enum FcBillingInterval: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case HalfYearly = 'half_yearly';
    case Yearly = 'yearly';

    /**
     * The exact WooCommerce period/multiplier table, with no fallback arm.
     *
     * Six pairs are supported and everything else is null. That is the whole
     * design: `week/2`, `year/2`, `month/2` and `month/12` are real WooCommerce
     * Subscriptions cadences that FluentCart cannot express, and the only honest
     * answers are "here is the exact equivalent" or "there isn't one". A record
     * whose cadence lands here as null is blocked with
     * `unsupported_billing_cadence` — it is not quietly rebilled monthly.
     *
     * The multiplier is gated before matching begins, so a zero or negative
     * value never reaches the table and is never read as an implied one.
     *
     * Adding a seventh pair requires WCS-versus-FluentCart schedule parity
     * tests, particularly around month ends and leap years.
     */
    public static function tryFromWooCommerce(string $period, int $multiplier): ?self
    {
        if ($multiplier < 1) {
            return null;
        }

        $period = strtolower(trim($period));

        return match (true) {
            $period === 'day' && $multiplier === 1   => self::Daily,
            $period === 'week' && $multiplier === 1  => self::Weekly,
            $period === 'month' && $multiplier === 1 => self::Monthly,
            $period === 'month' && $multiplier === 3 => self::Quarterly,
            $period === 'month' && $multiplier === 6 => self::HalfYearly,
            $period === 'year' && $multiplier === 1  => self::Yearly,
            default                                  => null,
        };
    }

    /**
     * The lenient reading, kept for the one-time catalogue path only.
     *
     * ProductMigrator and OrderMapper write a FluentCart product variation's
     * `repeat_interval` from a WooCommerce product's meta, where a cadence
     * CartShift cannot express is a cosmetic wrong answer on a catalogue row the
     * owner can edit. In a *subscription contract* the same collapse bills a
     * yearly customer twelve times a year, so the subscription path asks
     * tryFromWooCommerce() and blocks on null instead.
     *
     * Everything about this method is more inviting than the safe one — the
     * shorter name, the total return type, and the `$interval = 1` default that
     * lets "forgot the multiplier" compile — which is precisely why the call
     * sites are pinned by SubscriptionCadenceGateTest rather than left to
     * whoever reads this docblock. Do not use it for anything that decides how
     * a customer is charged.
     *
     * @deprecated Use tryFromWooCommerce() and block on null. This arm exists
     *             only for the catalogue/order paths listed in
     *             SubscriptionCadenceGateTest::CATALOGUE_CALL_SITES.
     */
    public static function fromWooCommerce(string $period, int $interval = 1): self
    {
        return self::tryFromWooCommerce($period, $interval)
            ?? match (strtolower(trim($period))) {
                'day'   => self::Daily,
                'week'  => self::Weekly,
                'year'  => self::Yearly,
                default => self::Monthly,
            };
    }
}

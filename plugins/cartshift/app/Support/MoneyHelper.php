<?php

declare(strict_types=1);

namespace CartShift\Support;

defined('ABSPATH') || exit;

/**
 * Money conversion for the FluentCart storage format.
 *
 * FluentCart stores every money column as the decimal amount multiplied by 100,
 * for EVERY currency — there is no per-currency exponent. Verified against
 * FluentCart 1.6.0:
 *
 *   - app/Helpers/Helper.php::toCent()                       — `floatval($amount) * 100`, unconditional.
 *   - app/Helpers/Helper.php::toDecimal()                    — `$amount / 100`, unconditional; the
 *                                                              zero-decimal flag only changes how many
 *                                                              decimal places are *rendered*, never the divisor.
 *   - app/Http/Controllers/ProductVariationController.php:299 — admin-entered price -> `Helper::toCent()`.
 *   - app/Modules/WooCommerceMigrator/Services/BaseMigrationService.php:135
 *                                                            — FluentCart's own WooCommerce importer, `* 100`.
 *
 * Currency exponents only appear at gateway boundaries, where FluentCart converts
 * in and out of its x100 internal format:
 *
 *   - app/Modules/PaymentMethods/StripeGateway/Plan.php:92,144       — divide by 100 before sending a
 *                                                                     zero-decimal amount to Stripe.
 *   - app/Modules/PaymentMethods/StripeGateway/StripeHelper.php:95   — multiply by 100 when reading a
 *                                                                     zero-decimal amount back from Stripe.
 *
 * Consequences for this migrator:
 *
 *   - Zero-decimal currencies (JPY, KRW, ...) are NOT special-cased. JPY 1000 is stored
 *     as 100000, which FluentCart renders as "1,000". Storing 1000 instead — as this class
 *     previously did — under-reports every zero-decimal amount by a factor of 100.
 *   - Three-decimal currencies (BHD, IQD, JOD, KWD, LYD, OMR, TND) are NOT special-cased
 *     either. FluentCart has no notion of a 1000-unit exponent anywhere in its schema or
 *     helpers, so x1000 would be a mismatch, not a fix. KWD 1.234 is stored as 123 and
 *     the third decimal is lost — that loss is FluentCart's, and matching it is the only
 *     way to keep totals consistent with what FluentCart itself computes.
 */
final class MoneyHelper
{
    /**
     * Convert a decimal price to FluentCart's integer storage format.
     *
     * Always multiplies by 100. See the class docblock for why no currency is exempt.
     * The $currency parameter is retained for call-site clarity and so a future
     * FluentCart release that does introduce per-currency exponents can be honoured
     * here without touching every caller.
     */
    public static function toCents(string|float|int $price, string $currency = ''): int
    {
        if (empty($price)) {
            return 0;
        }

        return intval(round(floatval($price) * 100));
    }
}

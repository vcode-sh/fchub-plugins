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

        return self::decimalToCents(is_float($price) ? (string) $price : (string) $price);
    }

    /**
     * Parse source-owned decimal text without passing through binary floats.
     *
     * The source ledger calls this strict entry point. More than two decimal
     * places are rounded half away from zero to match FluentCart's x100 helper;
     * malformed text and values outside PHP's integer range are refused.
     */
    public static function decimalToCents(string $price): int
    {
        if (preg_match('/\A(-?)([0-9]+)(?:\.([0-9]+))?\z/D', $price, $matches) !== 1) {
            throw new \InvalidArgumentException('Money must be canonical decimal text.');
        }

        $negative = $matches[1] === '-';
        $whole = ltrim($matches[2], '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = $matches[3] ?? '';
        $hundredths = str_pad(substr($fraction, 0, 2), 2, '0');
        $digits = ltrim($whole . $hundredths, '0');
        $digits = $digits === '' ? '0' : $digits;

        if (isset($fraction[2]) && $fraction[2] >= '5') {
            $digits = self::incrementDecimalDigits($digits);
        }

        $limit = (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
            throw new \OverflowException('Money exceeds the target integer range.');
        }

        $amount = (int) $digits;
        return $negative && $amount !== 0 ? -$amount : $amount;
    }

    private static function incrementDecimalDigits(string $digits): string
    {
        $carry = 1;
        for ($index = strlen($digits) - 1; $index >= 0 && $carry === 1; --$index) {
            $next = ((int) $digits[$index]) + 1;
            $digits[$index] = (string) ($next % 10);
            $carry = $next > 9 ? 1 : 0;
        }

        return $carry === 1 ? '1' . $digits : $digits;
    }
}

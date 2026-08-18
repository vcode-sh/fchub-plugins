<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Integration;

use FChubMultiCurrency\Domain\ValueObjects\Currency;
use FluentCart\Api\CurrencySettings;
use FluentCart\App\Helpers\CurrenciesHelper;

defined('ABSPATH') || exit;

/** Owns the FluentCart currency contract: settings, separators, and catalogue metadata. */
final class FluentCartCurrency
{
    public static function code(): string
    {
        return strtoupper((string) CurrencySettings::get('currency'));
    }

    /**
     * @return array{decimal: string, thousand: string}
     */
    public static function separators(): array
    {
        $usesCommaDecimal = CurrencySettings::get('decimal_separator') === 'comma';

        return [
            'decimal' => $usesCommaDecimal ? ',' : '.',
            'thousand' => $usesCommaDecimal ? '.' : ',',
        ];
    }

    /**
     * The store's own base currency, hydrated from FluentCart's catalogue.
     *
     * The store's configured sign stands in when the catalogue lacks the code:
     * for the base currency it is the one symbol the shop already shows.
     */
    public static function baseCurrency(string $code): Currency
    {
        return self::catalogueCurrency($code, preferStoreSign: true);
    }

    /**
     * An arbitrary display code, hydrated from FluentCart's catalogue.
     *
     * Unknown codes keep the raw code as their symbol: the store's configured
     * sign belongs to the base currency and would label a foreign price wrongly.
     */
    public static function displayCurrency(string $code): Currency
    {
        return self::catalogueCurrency($code, preferStoreSign: false);
    }

    private static function catalogueCurrency(string $code, bool $preferStoreSign): Currency
    {
        $settings = CurrencySettings::get();
        $names = CurrenciesHelper::getCurrencies();
        $signs = CurrenciesHelper::getCurrencySigns();
        $zeroDecimal = CurrenciesHelper::zeroDecimalCurrencies();
        $sign = $signs[$code] ?? ($preferStoreSign
            ? (string) ($settings['currency_sign'] ?? $code)
            : $code);
        // Mirrors FluentCart's Helper::toDecimal() position switch into the
        // plugin's four-slot model: ISO positions carry the ISO code in the
        // symbol slot, and symbool_and_iso carries FluentCart's exact
        // "CODE sign" prefix. The two positions that split sign and code
        // across the amount (symbool_before_iso, symbool_after_iso) keep
        // their ISO side — a one-sided symbol cannot also carry the sign.
        // Unknown values fall through to sign-left, as toDecimal's own do.
        [$symbol, $position] = match ((string) ($settings['currency_position'] ?? 'before')) {
            'after' => [$sign, 'right'],
            'iso_before' => [$code, 'left_space'],
            'iso_after', 'symbool_before_iso' => [$code, 'right_space'],
            'symbool_after_iso' => [$code, 'left_space'],
            'symbool_and_iso' => [$code . ' ' . $sign, 'left'],
            default => [$sign, 'left'],
        };

        return Currency::from([
            'code' => $code,
            'name' => $names[$code] ?? $code,
            'symbol' => $symbol,
            'decimals' => isset($zeroDecimal[$code]) ? 0 : 2,
            'position' => $position,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Support;

defined('ABSPATH') || exit;

/**
 * Which timezones imply which offered currency.
 *
 * The map ships in every page, so it stays a store fact: only currencies the
 * store offers produce entries, and the inversion runs from PHP's own zone
 * database rather than a bundled copy. When two offered currencies claim the
 * same zone, the first offered code wins deterministically — irrelevant in
 * practice, because ISO 4217 gives each country one primary currency.
 */
final class CurrencyGeography
{
    /**
     * Primary ISO 3166 countries per ISO 4217 currency. Extend a currency
     * here when a merchant reports a hole; an absent currency simply ships no
     * locale hint, which is the safe default.
     */
    private const COUNTRIES = [
        'USD' => ['US'],
        'EUR' => ['AT', 'BE', 'CY', 'DE', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PT', 'SI', 'SK'],
        'GBP' => ['GB'],
        'PLN' => ['PL'],
        'CZK' => ['CZ'],
        'HUF' => ['HU'],
        'RON' => ['RO'],
        'BGN' => ['BG'],
        'SEK' => ['SE'],
        'NOK' => ['NO'],
        'DKK' => ['DK'],
        'CHF' => ['CH', 'LI'],
        'ISK' => ['IS'],
        'TRY' => ['TR'],
        'UAH' => ['UA'],
        'JPY' => ['JP'],
        'CNY' => ['CN'],
        'KRW' => ['KR'],
        'INR' => ['IN'],
        'IDR' => ['ID'],
        'MYR' => ['MY'],
        'PHP' => ['PH'],
        'THB' => ['TH'],
        'VND' => ['VN'],
        'SGD' => ['SG'],
        'HKD' => ['HK'],
        'TWD' => ['TW'],
        'AUD' => ['AU'],
        'NZD' => ['NZ'],
        'CAD' => ['CA'],
        'MXN' => ['MX'],
        'BRL' => ['BR'],
        'ARS' => ['AR'],
        'CLP' => ['CL'],
        'COP' => ['CO'],
        'PEN' => ['PE'],
        'ZAR' => ['ZA'],
        'NGN' => ['NG'],
        'KES' => ['KE'],
        'GHS' => ['GH'],
        'EGP' => ['EG'],
        'ILS' => ['IL'],
        'AED' => ['AE'],
        'SAR' => ['SA'],
        'QAR' => ['QA'],
        'KWD' => ['KW'],
        'BHD' => ['BH'],
        'OMR' => ['OM'],
    ];

    /**
     * @param array<int, string> $offeredCodes
     * @return array<string, string> IANA timezone => offered currency code
     */
    public static function timezoneMap(array $offeredCodes): array
    {
        $map = [];

        foreach ($offeredCodes as $code) {
            $code = strtoupper($code);

            foreach (self::COUNTRIES[$code] ?? [] as $country) {
                foreach (\DateTimeZone::listIdentifiers(\DateTimeZone::PER_COUNTRY, $country) as $zone) {
                    $map[$zone] ??= $code;
                }
            }
        }

        return $map;
    }
}

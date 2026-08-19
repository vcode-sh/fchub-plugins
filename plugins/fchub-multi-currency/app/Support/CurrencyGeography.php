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
     * The zone spellings ICU actually reports that PHP's canonical list
     * lacks. Intl in Chrome and Node returns CLDR-canonical ids, which for
     * these zones are the pre-rename IANA names; PHP cannot derive them
     * (getLocation() on a link returns no country), so they are pinned here,
     * keyed by the canonical zone whose presence in the map earns them.
     */
    private const CLDR_ALIASES = [
        'Asia/Kolkata'                     => ['Asia/Calcutta'],
        'Asia/Ho_Chi_Minh'                 => ['Asia/Saigon'],
        'Europe/Kyiv'                      => ['Europe/Kiev', 'Europe/Uzhgorod', 'Europe/Zaporozhye'],
        'America/Argentina/Buenos_Aires'   => ['America/Buenos_Aires'],
        'America/Argentina/Catamarca'      => ['America/Catamarca'],
        'America/Argentina/Cordoba'        => ['America/Cordoba'],
        'America/Argentina/Jujuy'          => ['America/Jujuy'],
        'America/Argentina/Mendoza'        => ['America/Mendoza'],
        'America/Indiana/Indianapolis'     => ['America/Indianapolis'],
        'America/Kentucky/Louisville'      => ['America/Louisville'],
        'America/Atikokan'                 => ['America/Coral_Harbour'],
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

                    foreach (self::CLDR_ALIASES[$zone] ?? [] as $alias) {
                        $map[$alias] ??= $code;
                    }
                }
            }
        }

        return $map;
    }
}

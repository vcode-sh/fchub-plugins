<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Http\Controllers\Admin;

use FluentCart\App\Helpers\CurrenciesHelper;

defined('ABSPATH') || exit;

final class CurrencyCatalogueController
{
    /**
     * ISO 4217 currency code → ISO 3166-1 country code overrides.
     *
     * Most currency codes share first two letters with the country code,
     * but these are the exceptions that need explicit mapping.
     */
    private const FLAG_OVERRIDES = [
        'EUR' => 'EU',
        'GBP' => 'GB',
        'ANG' => 'CW',
        'XAF' => '',
        'XCD' => '',
        'XOF' => '',
        'XPF' => '',
    ];

    /**
     * Currency codes that have bundled SVG flag files.
     *
     * Maps ISO 4217 currency code → ISO 3166-1 alpha-2 country code (lowercase).
     */
    private const SVG_FLAG_MAP = [
        'USD' => 'us', 'EUR' => 'eu', 'GBP' => 'gb', 'JPY' => 'jp',
        'CHF' => 'ch', 'CAD' => 'ca', 'AUD' => 'au', 'NZD' => 'nz',
        'SEK' => 'se', 'NOK' => 'no', 'DKK' => 'dk', 'PLN' => 'pl',
        'CZK' => 'cz', 'HUF' => 'hu', 'RON' => 'ro', 'BGN' => 'bg',
        'HRK' => 'hr', 'ISK' => 'is', 'TRY' => 'tr', 'RUB' => 'ru',
        'UAH' => 'ua', 'BRL' => 'br', 'MXN' => 'mx', 'ARS' => 'ar',
        'CLP' => 'cl', 'COP' => 'co', 'PEN' => 'pe', 'CNY' => 'cn',
        'HKD' => 'hk', 'SGD' => 'sg', 'TWD' => 'tw', 'KRW' => 'kr',
        'INR' => 'in', 'IDR' => 'id', 'MYR' => 'my', 'PHP' => 'ph',
        'THB' => 'th', 'VND' => 'vn', 'AED' => 'ae', 'SAR' => 'sa',
        'QAR' => 'qa', 'KWD' => 'kw', 'BHD' => 'bh', 'OMR' => 'om',
        'ILS' => 'il', 'EGP' => 'eg', 'ZAR' => 'za', 'NGN' => 'ng',
        'KES' => 'ke', 'GHS' => 'gh',
    ];

    public function index(\WP_REST_Request $request): \WP_REST_Response
    {
        return new \WP_REST_Response([
            'data' => [
                'catalogue' => self::getCatalogue(),
            ],
        ]);
    }

    /**
     * Build the enriched currency catalogue from FluentCart's data sources.
     *
     * @return array<int, array{code: string, name: string, symbol: string, decimals: int, flag: string}>
     */
    public static function getCatalogue(): array
    {
        $currencies    = CurrenciesHelper::getCurrencies();
        $signs         = CurrenciesHelper::getCurrencySigns();
        $zeroDecimals  = CurrenciesHelper::zeroDecimalCurrencies();

        $catalogue = [];

        foreach ($currencies as $code => $name) {
            $catalogue[] = [
                'code'     => $code,
                'name'     => $name,
                'symbol'   => $signs[$code] ?? $code,
                'decimals' => isset($zeroDecimals[$code]) ? 0 : 2,
                'flag'     => self::codeToFlag($code),
            ];
        }

        return $catalogue;
    }

    /**
     * Convert an ISO 4217 currency code to a flag emoji.
     *
     * Uses regional indicator symbols: each letter A-Z maps to U+1F1E6..U+1F1FF.
     * Two regional indicators form a flag emoji for the ISO 3166-1 country code.
     */
    public static function codeToFlag(string $currencyCode): string
    {
        $countryCode = self::FLAG_OVERRIDES[$currencyCode]
            ?? substr($currencyCode, 0, 2);

        if ($countryCode === '') {
            return '';
        }

        $countryCode = strtoupper($countryCode);

        // Regional indicator A = U+1F1E6
        $first  = mb_chr(0x1F1E6 + ord($countryCode[0]) - ord('A'));
        $second = mb_chr(0x1F1E6 + ord($countryCode[1]) - ord('A'));

        return $first . $second;
    }

    /**
     * Convert an ISO 4217 currency code to an SVG flag <img> tag.
     *
     * Falls back to emoji via codeToFlag() if no bundled SVG exists.
     */
    public static function codeToFlagImg(string $currencyCode): string
    {
        $countryCode = self::SVG_FLAG_MAP[strtoupper($currencyCode)] ?? null;

        if ($countryCode === null) {
            return self::codeToFlag($currencyCode);
        }

        $url = FCHUB_MC_URL . 'assets/flags/4x3/' . $countryCode . '.svg';

        return '<img src="' . esc_url($url) . '"'
            . ' alt="' . esc_attr($currencyCode) . '"'
            . ' class="fchub-mc-flag"'
            . ' width="20" height="15" />';
    }
}

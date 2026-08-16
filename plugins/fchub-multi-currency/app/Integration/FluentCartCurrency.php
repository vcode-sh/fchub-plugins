<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Integration;

use FluentCart\Api\CurrencySettings;

defined('ABSPATH') || exit;

/** Owns the FluentCart currency contract consumed by display projection. */
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
}

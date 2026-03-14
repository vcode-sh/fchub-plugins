<?php

declare(strict_types=1);

namespace CartShift\Support;

defined('ABSPATH') or die;

final class MoneyHelper
{
    private const array ZERO_DECIMAL_CURRENCIES = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
        'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    public static function toCents(string|float|int $price, string $currency = ''): int
    {
        if (empty($price)) {
            return 0;
        }

        if (in_array(strtoupper($currency), self::ZERO_DECIMAL_CURRENCIES, true)) {
            return intval(round(floatval($price)));
        }

        return intval(round(floatval($price) * 100));
    }
}

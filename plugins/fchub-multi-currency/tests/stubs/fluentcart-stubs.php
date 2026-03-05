<?php

declare(strict_types=1);

namespace FluentCart\App\Helpers;

final class CurrenciesHelper
{
    /**
     * @return array<string, string>
     */
    public static function getCurrencies(): array
    {
        return [
            'USD' => 'US Dollar',
            'EUR' => 'Euro',
            'GBP' => 'British Pound',
            'JPY' => 'Japanese Yen',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getCurrencySigns(): array
    {
        return [
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'JPY' => '¥',
        ];
    }

    /**
     * @return array<string, bool>
     */
    public static function zeroDecimalCurrencies(): array
    {
        return [
            'JPY' => true,
        ];
    }
}

namespace FluentCart\Api;

final class CurrencySettings
{
    /**
     * @return array<string, mixed>
     */
    public static function get(): array
    {
        return [
            'currency_separator' => 'dot',
            'currency_sign'      => '$',
            'currency_position'  => 'before',
            'currency'           => 'USD',
            'is_zero_decimal'    => false,
        ];
    }

    public static function getPriceHtml(float $price, string $currencyCode = 'USD'): string
    {
        return sprintf('%s %s', $currencyCode, number_format($price, 2, '.', ''));
    }
}

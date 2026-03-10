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

class Helper
{
    /**
     * @param int|float $amount Amount in cents
     * @param bool $withCurrency Whether to prepend currency sign
     * @param string|null $currencyCode Currency code for symbol lookup
     * @return string Formatted decimal string
     */
    public static function toDecimal($amount, bool $withCurrency = true, ?string $currencyCode = null): string
    {
        $decimal = $amount / 100;
        $sign = match ($currencyCode) {
            'EUR' => "\xe2\x82\xac",
            'GBP' => "\xc2\xa3",
            'JPY' => "\xc2\xa5",
            default => '$',
        };
        $formatted = number_format($decimal, 2, '.', '');

        return $withCurrency ? ($sign . $formatted) : $formatted;
    }
}

namespace FluentCart\App\Models;

class Order
{
    /** @var array<int, self> */
    private static array $mockOrders = [];

    public int $id = 0;
    public string $currency = 'USD';
    public int $total_amount = 0;
    public int $subtotal = 0;

    /** @var array<string, mixed> */
    private array $meta = [];

    public static function setMockOrder(int $id, self $order): void
    {
        self::$mockOrders[$id] = $order;
    }

    public static function resetMockOrders(): void
    {
        self::$mockOrders = [];
    }

    public static function find(int $id): ?self
    {
        return self::$mockOrders[$id] ?? null;
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function getMeta(string $key, $default = null)
    {
        return $this->meta[$key] ?? $default;
    }

    /** @param mixed $value */
    public function setMeta(string $key, $value): void
    {
        $this->meta[$key] = $value;
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

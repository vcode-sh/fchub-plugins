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

    public static function isZeroDecimal(string $currencyCode): bool
    {
        return isset(self::zeroDecimalCurrencies()[strtoupper($currencyCode)]);
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

    public static function query(): object
    {
        return new class {
            public function find(int $id): ?Order
            {
                return Order::find($id);
            }
        };
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
    /** @var array<string, mixed> */
    private static array $mock = [];

    /**
     * @param array<string, mixed> $settings
     */
    public static function setMock(array $settings): void
    {
        self::$mock = $settings;
    }

    public static function resetMock(): void
    {
        self::$mock = [];
    }

    /**
     * Defaults mirror FluentCart 1.6.1 `CurrencySettings::get()`, including its
     * `decimal_separator` default of the character `.` rather than the `dot`
     * token an untouched store never stores.
     *
     * @return array<string, mixed>
     */
    public static function get(string $key = ''): mixed
    {
        $settings = array_merge([
            'currency_separator' => 'dot',
            'decimal_separator'  => '.',
            'currency_sign'      => '$',
            'currency_position'  => 'before',
            'currency'           => 'USD',
            'is_zero_decimal'    => false,
        ], self::$mock);

        return $key !== '' ? ($settings[$key] ?? null) : $settings;
    }

    public static function getPriceHtml(
        float $price,
        string $currencyCode = 'USD',
        bool $showDecimal = true,
    ): string
    {
        return sprintf(
            '%s %s',
            $currencyCode,
            number_format($price / 100, $showDecimal ? 2 : 0, '.', ''),
        );
    }
}

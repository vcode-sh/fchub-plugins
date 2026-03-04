<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Support;

use FChubMultiCurrency\Domain\Enums\CurrencyPosition;
use FChubMultiCurrency\Domain\Enums\RateProvider;
use FChubMultiCurrency\Domain\Enums\ResolverSource;
use FChubMultiCurrency\Domain\ValueObjects\Currency;
use FChubMultiCurrency\Domain\ValueObjects\CurrencyContext;
use FChubMultiCurrency\Domain\ValueObjects\ExchangeRate;
use FChubMultiCurrency\Domain\ValueObjects\MoneyAmount;

final class MockBuilder
{
    public static function currency(string $code = 'USD', array $overrides = []): Currency
    {
        $defaults = [
            'code'     => $code,
            'name'     => $code . ' Dollar',
            'symbol'   => '$',
            'decimals' => 2,
            'position' => 'left',
        ];

        return Currency::from(array_merge($defaults, $overrides));
    }

    public static function exchangeRate(array $overrides = []): ExchangeRate
    {
        $defaults = [
            'base_currency'  => 'USD',
            'quote_currency' => 'EUR',
            'rate'           => '0.92000000',
            'provider'       => 'manual',
            'fetched_at'     => date('Y-m-d H:i:s'),
        ];

        return ExchangeRate::from(array_merge($defaults, $overrides));
    }

    public static function moneyAmount(int $minorUnits = 1000, string $code = 'USD'): MoneyAmount
    {
        return new MoneyAmount(minorUnits: $minorUnits, currencyCode: $code);
    }

    public static function context(array $overrides = []): CurrencyContext
    {
        $base = self::currency('USD');
        $display = isset($overrides['display_code'])
            ? self::currency($overrides['display_code'], $overrides['display_overrides'] ?? [])
            : self::currency('EUR', ['symbol' => '€', 'name' => 'Euro']);

        $rate = $overrides['rate'] ?? self::exchangeRate();
        $source = $overrides['source'] ?? ResolverSource::Cookie;
        $isBaseDisplay = $overrides['is_base_display'] ?? false;

        return new CurrencyContext(
            displayCurrency: $display,
            baseCurrency: $base,
            rate: $rate,
            source: $source,
            isBaseDisplay: $isBaseDisplay,
        );
    }

    public static function baseOnlyContext(): CurrencyContext
    {
        return CurrencyContext::baseOnly(self::currency('USD'));
    }
}

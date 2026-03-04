<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Enums;

defined('ABSPATH') || exit;

enum RateProvider: string
{
    case ExchangeRateApi   = 'exchange_rate_api';
    case OpenExchangeRates = 'open_exchange_rates';
    case Ecb               = 'ecb';
    case Manual            = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::ExchangeRateApi   => 'ExchangeRate-API (free tier)',
            self::OpenExchangeRates => 'Open Exchange Rates',
            self::Ecb               => 'European Central Bank (EUR base, free)',
            self::Manual            => 'Manual rates',
        };
    }

    public function requiresApiKey(): bool
    {
        return match ($this) {
            self::ExchangeRateApi, self::OpenExchangeRates => true,
            self::Ecb, self::Manual => false,
        };
    }
}

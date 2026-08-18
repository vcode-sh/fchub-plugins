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
            self::ExchangeRateApi   => __('ExchangeRate-API (free tier)', 'fchub-multi-currency'),
            self::OpenExchangeRates => __('Open Exchange Rates', 'fchub-multi-currency'),
            self::Ecb               => __('European Central Bank (EUR base, free)', 'fchub-multi-currency'),
            self::Manual            => __('Manual rates', 'fchub-multi-currency'),
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

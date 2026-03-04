<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Providers;

use FChubMultiCurrency\Domain\Enums\RateProvider;
use FChubMultiCurrency\Storage\ExchangeRateRepository;
use FChubMultiCurrency\Storage\OptionStore;

defined('ABSPATH') || exit;

final class ProviderRegistry
{
    public static function resolve(OptionStore $optionStore): ProviderContract
    {
        $settings = $optionStore->all();
        $providerSlug = $settings['rate_provider'] ?? 'exchange_rate_api';
        $apiKey = $settings['rate_provider_api_key'] ?? '';

        $provider = RateProvider::tryFrom($providerSlug) ?? RateProvider::ExchangeRateApi;

        return match ($provider) {
            RateProvider::ExchangeRateApi   => new ExchangeRateApiProvider($apiKey),
            RateProvider::OpenExchangeRates => new OpenExchangeRatesProvider($apiKey),
            RateProvider::Ecb               => new EcbProvider(),
            RateProvider::Manual            => new ManualProvider(new ExchangeRateRepository()),
        };
    }
}

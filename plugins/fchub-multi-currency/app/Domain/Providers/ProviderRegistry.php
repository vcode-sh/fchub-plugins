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
        $providerSlug = $settings['rate_provider'] ?? 'manual';
        $apiKey = $settings['rate_provider_api_key'] ?? '';

        $provider = RateProvider::tryFrom($providerSlug) ?? RateProvider::Manual;

        return match ($provider) {
            RateProvider::ExchangeRateApi   => new ExchangeRateApiProvider($apiKey),
            RateProvider::OpenExchangeRates => new OpenExchangeRatesProvider($apiKey),
            RateProvider::Ecb               => new EcbProvider(),
            RateProvider::Manual            => new ManualProvider(new ExchangeRateRepository()),
        };
    }

    public static function usesRemoteProvider(OptionStore $optionStore): bool
    {
        $provider = RateProvider::tryFrom((string) $optionStore->get('rate_provider', 'manual'));

        return $provider !== null && $provider !== RateProvider::Manual;
    }
}

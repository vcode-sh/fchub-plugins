<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Actions;

use FChubMultiCurrency\Domain\Enums\RateProvider;
use FChubMultiCurrency\Domain\Providers\ProviderContract;
use FChubMultiCurrency\Domain\Providers\ProviderRegistry;
use FChubMultiCurrency\Domain\ValueObjects\ExchangeRate;
use FChubMultiCurrency\Storage\ExchangeRateRepository;
use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Storage\RatesCacheStore;
use FChubMultiCurrency\Support\Logger;

defined('ABSPATH') || exit;

final class RefreshRatesAction
{
    public function __construct(
        private ExchangeRateRepository $repository,
        private RatesCacheStore $cache,
    ) {
    }

    public function execute(): void
    {
        $lockKey = 'fchub_mc_rate_refresh_lock';

        if (wp_cache_get($lockKey)) {
            return;
        }

        wp_cache_set($lockKey, true, '', 120);

        try {
            $optionStore = new OptionStore();
            $settings = $optionStore->all();
            $baseCurrency = $settings['base_currency'] ?? 'USD';
            $displayCurrencies = $settings['display_currencies'] ?? [];

            $provider = ProviderRegistry::resolve($optionStore);
            $rates = $provider->fetchRates($baseCurrency);

            if (empty($rates)) {
                Logger::error('Rate refresh returned empty rates', [
                    'provider' => $provider->name(),
                ]);
                return;
            }

            $now = current_time('mysql');
            $providerEnum = RateProvider::tryFrom($provider->name()) ?? RateProvider::Manual;

            foreach ($displayCurrencies as $currency) {
                $code = is_array($currency) ? ($currency['code'] ?? '') : $currency;

                if ($code === '' || $code === $baseCurrency || !isset($rates[$code])) {
                    continue;
                }

                $rate = ExchangeRate::from([
                    'base_currency'  => $baseCurrency,
                    'quote_currency' => $code,
                    'rate'           => $rates[$code],
                    'provider'       => $providerEnum->value,
                    'fetched_at'     => $now,
                ]);

                $this->repository->insert($rate);
                $this->cache->set($rate);
            }

            do_action('fchub_mc/rates_refreshed', $baseCurrency, count($rates));
            Logger::info('Rates refreshed successfully', [
                'provider' => $provider->name(),
                'count'    => count($rates),
            ]);
        } finally {
            wp_cache_delete($lockKey);
        }
    }
}

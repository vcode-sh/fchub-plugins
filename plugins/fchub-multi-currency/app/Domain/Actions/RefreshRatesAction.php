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

    public function execute(): bool
    {
        $lockKey = 'fchub_mc_rate_refresh_lock';

        if (wp_cache_get($lockKey)) {
            return false;
        }

        wp_cache_set($lockKey, true, '', 120);

        try {
            $optionStore = new OptionStore();
            $settings = $optionStore->all();
            $baseCurrency = $settings['base_currency'] ?? 'USD';
            $displayCurrencies = $settings['display_currencies'] ?? [];

            $provider = ProviderRegistry::resolve($optionStore);

            try {
                $rates = $provider->fetchRates($baseCurrency);
            } catch (\Throwable $e) {
                Logger::error('Rate refresh failed: provider threw an exception', [
                    'provider' => $provider->name(),
                    'error'    => $e->getMessage(),
                ]);
                return false;
            }

            if (empty($rates)) {
                Logger::error('Rate refresh returned empty rates', [
                    'provider' => $provider->name(),
                ]);
                return false;
            }

            $now = current_time('mysql');
            $providerEnum = RateProvider::tryFrom($provider->name()) ?? RateProvider::Manual;

            // Collect quote codes we're about to refresh so we can invalidate them first
            $quoteCodes = [];
            foreach ($displayCurrencies as $currency) {
                $code = is_array($currency) ? ($currency['code'] ?? '') : $currency;
                if ($code !== '' && $code !== $baseCurrency) {
                    $quoteCodes[] = $code;
                }
            }

            // Delete existing cache entries before writing new ones
            $this->cache->deleteMany($baseCurrency, $quoteCodes);

            foreach ($displayCurrencies as $currency) {
                $code = is_array($currency) ? ($currency['code'] ?? '') : $currency;

                if ($code === '' || $code === $baseCurrency || !isset($rates[$code])) {
                    continue;
                }

                // Guard against zero or negative rates from provider
                if (bccomp((string) $rates[$code], '0', 10) <= 0) {
                    Logger::error('Skipping invalid rate (zero or negative)', [
                        'currency' => $code,
                        'rate'     => $rates[$code],
                        'provider' => $provider->name(),
                    ]);
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

            return true;
        } finally {
            wp_cache_delete($lockKey);
        }
    }
}

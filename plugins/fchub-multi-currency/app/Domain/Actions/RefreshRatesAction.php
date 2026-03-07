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
use FChubMultiCurrency\Support\EventLogger;
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
        if (!$this->acquireLock()) {
            EventLogger::log('rates_refresh_skipped_lock', get_current_user_id());
            return false;
        }

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
                EventLogger::log('rates_refresh_failed', get_current_user_id(), [
                    'provider' => $provider->name(),
                    'reason' => 'exception',
                ]);
                return false;
            }

            if (empty($rates)) {
                Logger::error('Rate refresh returned empty rates', [
                    'provider' => $provider->name(),
                ]);
                EventLogger::log('rates_refresh_failed', get_current_user_id(), [
                    'provider' => $provider->name(),
                    'reason' => 'empty',
                ]);
                return false;
            }

            $now = gmdate('Y-m-d H:i:s');
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

                // Guard against non-numeric rates (NaN, Infinity, strings)
                $rateStr = (string) $rates[$code];
                if (!is_numeric($rateStr) || preg_match('/[eE]/', $rateStr)) {
                    Logger::error('Skipping non-numeric rate', [
                        'currency' => $code,
                        'rate'     => $rates[$code],
                        'provider' => $provider->name(),
                    ]);
                    continue;
                }

                // Guard against zero or negative rates from provider
                $isInvalidRate = function_exists('bccomp')
                    ? (bccomp($rateStr, '0', 10) <= 0)
                    : ((float) $rateStr <= 0.0);

                if ($isInvalidRate) {
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
            EventLogger::log('rates_refreshed', get_current_user_id(), [
                'base_currency' => $baseCurrency,
                'provider' => $provider->name(),
                'count' => count($rates),
            ]);
            Logger::info('Rates refreshed successfully', [
                'provider' => $provider->name(),
                'count'    => count($rates),
            ]);

            return true;
        } finally {
            $this->releaseLock();
        }
    }

    private function acquireLock(): bool
    {
        $lockKey = 'fchub_mc_rate_refresh_lock';
        $ttl = 120;

        // Attempt atomic lock acquisition
        $acquired = add_option($lockKey, (string) time(), '', false);
        if ($acquired) {
            return true;
        }

        // Lock exists — check if stale
        $currentLock = get_option($lockKey, false);
        if ($currentLock === false) {
            return add_option($lockKey, (string) time(), '', false);
        }

        $age = time() - (int) $currentLock;
        if ($age >= $ttl) {
            // Atomic compare-and-swap: only overwrite if value hasn't changed
            global $wpdb;
            $updated = $wpdb->update(
                $wpdb->options,
                ['option_value' => (string) time()],
                ['option_name' => $lockKey, 'option_value' => $currentLock],
            );
            return $updated > 0;
        }

        return false;
    }

    private function releaseLock(): void
    {
        delete_option('fchub_mc_rate_refresh_lock');
    }
}

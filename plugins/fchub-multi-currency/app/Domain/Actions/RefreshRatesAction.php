<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Actions;

use FChubMultiCurrency\Domain\Enums\RateProvider;
use FChubMultiCurrency\Domain\Providers\ProviderContract;
use FChubMultiCurrency\Domain\Providers\ProviderRegistry;
use FChubMultiCurrency\Domain\ValueObjects\ExchangeRate;
use FChubMultiCurrency\Domain\ValueObjects\SelectableCurrencyCodes;
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
        $optionStore = new OptionStore();
        $settings = $optionStore->all();

        if (!ProviderRegistry::usesRemoteProvider($optionStore)) {
            EventLogger::log('rates_refresh_skipped_manual', get_current_user_id());
            return false;
        }

        $baseCurrency = strtoupper((string) ($settings['base_currency'] ?? 'USD'));
        $quoteCodes = SelectableCurrencyCodes::fromSettings($settings)->quoteCurrencies();
        if ($quoteCodes === []) {
            Logger::error('Rate refresh has no configured quote currencies');
            EventLogger::log('rates_refresh_failed', get_current_user_id(), [
                'reason' => 'no_quotes',
            ]);
            return false;
        }

        if (!$this->acquireLock()) {
            EventLogger::log('rates_refresh_skipped_lock', get_current_user_id());
            return false;
        }

        try {
            $provider = ProviderRegistry::resolve($optionStore);
            $rates = $this->fetchProviderRates($provider, $baseCurrency);
            if ($rates === null) {
                return false;
            }

            $snapshot = $this->buildSnapshot($rates, $quoteCodes, $baseCurrency, $provider);
            if ($snapshot === null) {
                EventLogger::log('rates_refresh_failed', get_current_user_id(), [
                    'provider' => $provider->name(),
                    'reason' => 'incomplete_snapshot',
                ]);
                return false;
            }

            if (!$this->repository->insertMany($snapshot)) {
                Logger::error('Rate refresh snapshot could not be persisted', [
                    'provider' => $provider->name(),
                ]);
                EventLogger::log('rates_refresh_failed', get_current_user_id(), [
                    'provider' => $provider->name(),
                    'reason' => 'persistence',
                ]);
                return false;
            }

            foreach ($snapshot as $rate) {
                $this->cache->set($rate);
            }

            $persistedCount = count($snapshot);
            do_action('fchub_mc/rates_refreshed', $baseCurrency, $persistedCount);
            EventLogger::log('rates_refreshed', get_current_user_id(), [
                'base_currency' => $baseCurrency,
                'provider' => $provider->name(),
                'count' => $persistedCount,
            ]);
            Logger::info('Rates refreshed successfully', [
                'provider' => $provider->name(),
                'count'    => $persistedCount,
            ]);

            return true;
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchProviderRates(ProviderContract $provider, string $baseCurrency): ?array
    {
        try {
            $rates = $provider->fetchRates($baseCurrency);
        } catch (\Throwable $exception) {
            Logger::error('Rate refresh failed: provider threw an exception', [
                'provider' => $provider->name(),
                'error' => $exception->getMessage(),
            ]);
            EventLogger::log('rates_refresh_failed', get_current_user_id(), [
                'provider' => $provider->name(),
                'reason' => 'exception',
            ]);
            return null;
        }

        if ($rates === []) {
            Logger::error('Rate refresh returned empty rates', [
                'provider' => $provider->name(),
            ]);
            EventLogger::log('rates_refresh_failed', get_current_user_id(), [
                'provider' => $provider->name(),
                'reason' => 'empty',
            ]);
            return null;
        }

        return $rates;
    }

    /**
     * @param array<string, mixed> $rates
     * @param string[] $quoteCodes
     * @return ExchangeRate[]|null
     */
    private function buildSnapshot(
        array $rates,
        array $quoteCodes,
        string $baseCurrency,
        ProviderContract $provider,
    ): ?array {
        $fetchedAt = gmdate('Y-m-d H:i:s');
        $providerEnum = RateProvider::tryFrom($provider->name()) ?? RateProvider::Manual;
        $snapshot = [];

        foreach ($quoteCodes as $code) {
            if (!array_key_exists($code, $rates)) {
                Logger::error('Rate refresh omitted a configured currency', [
                    'currency' => $code,
                    'provider' => $provider->name(),
                ]);
                return null;
            }

            $rate = self::normalizeProviderRate($rates[$code]);
            if ($rate === null) {
                Logger::error('Rate refresh returned an invalid configured rate', [
                    'currency' => $code,
                    'provider' => $provider->name(),
                ]);
                return null;
            }

            $snapshot[] = new ExchangeRate(
                baseCurrency: $baseCurrency,
                quoteCurrency: $code,
                rate: $rate,
                provider: $providerEnum,
                fetchedAt: $fetchedAt,
            );
        }

        return $snapshot;
    }

    private static function normalizeProviderRate(mixed $rate): ?string
    {
        if (!is_int($rate) && !is_float($rate) && !is_string($rate)) {
            return null;
        }

        $rate = trim((string) $rate);
        if ($rate === '' || !is_numeric($rate) || preg_match('/[eE]/', $rate) === 1) {
            return null;
        }

        $isPositive = function_exists('bccomp')
            ? bccomp($rate, '0', 10) > 0
            : (float) $rate > 0.0;

        return $isPositive ? $rate : null;
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
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- WordPress has no atomic option compare-and-swap API; the successful write invalidates the option cache below.
            $updated = $wpdb->update(
                $wpdb->options,
                ['option_value' => (string) time()],
                ['option_name' => $lockKey, 'option_value' => $currentLock],
            );

            if ($updated > 0) {
                wp_cache_delete($lockKey, 'options');
            }

            return $updated > 0;
        }

        return false;
    }

    private function releaseLock(): void
    {
        delete_option('fchub_mc_rate_refresh_lock');
    }
}

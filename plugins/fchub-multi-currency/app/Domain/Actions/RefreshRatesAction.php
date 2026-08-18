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
            return $this->giveUp('rates_refresh_skipped_manual');
        }

        $baseCurrency = strtoupper((string) ($settings['base_currency'] ?? 'USD'));
        $quoteCodes = SelectableCurrencyCodes::fromSettings($settings)->quoteCurrencies();
        if ($quoteCodes === []) {
            Logger::error('Rate refresh has no configured quote currencies');

            return $this->giveUp('rates_refresh_failed', ['reason' => 'no_quotes']);
        }

        if (!$this->acquireLock()) {
            return $this->giveUp('rates_refresh_skipped_lock');
        }

        try {
            return $this->refresh(ProviderRegistry::resolve($optionStore), $baseCurrency, $quoteCodes);
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Fetch, verify, persist, publish — in that order, and all or nothing.
     *
     * Every exit before the last leaves the previous rates exactly as they were.
     * A half-written snapshot would have a store quoting prices at a rate nobody
     * ever fetched, which is worse than quoting yesterday's.
     *
     * @param string[] $quoteCodes
     */
    private function refresh(ProviderContract $provider, string $baseCurrency, array $quoteCodes): bool
    {
        $rates = $this->fetchProviderRates($provider, $baseCurrency);
        if ($rates === null) {
            return false;
        }

        $snapshot = $this->buildSnapshot($rates, $quoteCodes, $baseCurrency, $provider);
        if ($snapshot === null) {
            return $this->giveUp('rates_refresh_failed', [
                'provider' => $provider->name(),
                'reason' => 'incomplete_snapshot',
            ]);
        }

        if (!$this->repository->insertMany($snapshot)) {
            Logger::error('Rate refresh snapshot could not be persisted', [
                'provider' => $provider->name(),
            ]);

            return $this->giveUp('rates_refresh_failed', [
                'provider' => $provider->name(),
                'reason' => 'persistence',
            ]);
        }

        $this->publish($snapshot, $baseCurrency, $provider);

        return true;
    }

    /**
     * Only reached once the snapshot is safely stored, so the cache and the hook
     * can never describe rates the database does not have.
     *
     * @param ExchangeRate[] $snapshot
     */
    private function publish(array $snapshot, string $baseCurrency, ProviderContract $provider): void
    {
        foreach ($snapshot as $rate) {
            $this->cache->set($rate);
        }

        $count = count($snapshot);
        do_action('fchub_mc/rates_refreshed', $baseCurrency, $count);
        EventLogger::log('rates_refreshed', get_current_user_id(), [
            'base_currency' => $baseCurrency,
            'provider' => $provider->name(),
            'count' => $count,
        ]);
        Logger::info('Rates refreshed successfully', [
            'provider' => $provider->name(),
            'count'    => $count,
        ]);
    }

    /**
     * Records why the refresh stopped and reports the failure to the caller.
     *
     * Five paths end here. Writing the event and returning false at each of them
     * is how one of them ends up silent.
     *
     * @param array<string, mixed> $context
     */
    private function giveUp(string $event, array $context = []): bool
    {
        EventLogger::log($event, get_current_user_id(), $context === [] ? null : $context);

        return false;
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
            $this->giveUp('rates_refresh_failed', [
                'provider' => $provider->name(),
                'reason' => 'exception',
            ]);

            return null;
        }

        if ($rates === []) {
            Logger::error('Rate refresh returned empty rates', [
                'provider' => $provider->name(),
            ]);
            $this->giveUp('rates_refresh_failed', [
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

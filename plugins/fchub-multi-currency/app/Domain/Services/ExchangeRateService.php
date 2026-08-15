<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Services;

use FChubMultiCurrency\Domain\ValueObjects\ExchangeRate;
use FChubMultiCurrency\Storage\ExchangeRateRepository;
use FChubMultiCurrency\Storage\RatesCacheStore;
use FChubMultiCurrency\Support\Profiler;

defined('ABSPATH') || exit;

final class ExchangeRateService
{
    public function __construct(
        private ExchangeRateRepository $repository,
        private RatesCacheStore $cache,
    ) {
    }

    public function getRate(string $baseCurrency, string $quoteCurrency): ?ExchangeRate
    {
        if ($baseCurrency === $quoteCurrency) {
            return new ExchangeRate(
                baseCurrency: $baseCurrency,
                quoteCurrency: $quoteCurrency,
                rate: '1.00000000',
                provider: \FChubMultiCurrency\Domain\Enums\RateProvider::Manual,
                fetchedAt: gmdate('Y-m-d H:i:s'),
            );
        }

        // Temporary instrumentation for issue #72 — see Support\Profiler. This
        // is the only place a live provider (Ecb/ExchangeRateApi/OpenExchangeRates)
        // could plausibly get invoked synchronously during context resolution;
        // it doesn't, by inspection (ProviderRegistry::resolve() is only ever
        // called from RefreshRatesAction, which runs from cron or the admin
        // "Refresh Now" button — never from this read path), but this marks the
        // cache check and the DB fallback separately so that claim is verified
        // against real timing on the host in question, not just asserted from
        // reading the code.
        Profiler::mark('rate_lookup_start');
        $cached = $this->cache->get($baseCurrency, $quoteCurrency);
        Profiler::mark($cached !== null ? 'rate_cache_hit' : 'rate_cache_miss');

        if ($cached !== null) {
            return $cached;
        }

        $rate = $this->repository->findLatest($baseCurrency, $quoteCurrency);
        Profiler::mark('rate_db_query_done');

        if ($rate !== null) {
            $this->cache->set($rate);
            Profiler::mark('rate_cache_set_done');
        }

        return $rate;
    }
}

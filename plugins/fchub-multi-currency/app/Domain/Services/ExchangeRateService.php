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

        // Temporary instrumentation for issue #72 — see Support\Profiler.
        // RatesCacheStore::get() marks its own internal hit/miss; the marks
        // here bound the cache lookup and the DB fallback as seen from this
        // call site, so the two can be compared against each other and
        // against the request total.
        $debug = Profiler::isRequested();
        if ($debug) {
            Profiler::mark('rate_lookup_start');
        }

        $cached = $this->cache->get($baseCurrency, $quoteCurrency);

        if ($debug) {
            Profiler::mark($cached !== null ? 'rate_cache_hit' : 'rate_cache_miss');
        }

        if ($cached !== null) {
            return $cached;
        }

        $rate = $this->repository->findLatest($baseCurrency, $quoteCurrency);

        if ($debug) {
            Profiler::mark('rate_db_fallback_done');
        }

        if ($rate !== null) {
            $this->cache->set($rate);

            if ($debug) {
                Profiler::mark('rate_cache_set_done');
            }
        }

        return $rate;
    }
}

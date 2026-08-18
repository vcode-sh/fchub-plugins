<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Services;

use FChubMultiCurrency\Domain\Enums\StaleRateFallback;
use FChubMultiCurrency\Domain\ValueObjects\ExchangeRate;
use FChubMultiCurrency\Storage\ExchangeRateRepository;
use FChubMultiCurrency\Storage\RatesCacheStore;

defined('ABSPATH') || exit;

final class ExchangeRateService
{
    public function __construct(
        private ExchangeRateRepository $repository,
        private RatesCacheStore $cache,
    ) {
    }

    /**
     * Loads every rate for a base currency in one read.
     *
     * `getRate()` caches per pair, and `wp_cache_get` spans a single request unless
     * the site runs a persistent object cache — so a page offering N currencies paid
     * N round trips before this existed. Callers that are about to ask for the whole
     * set should warm it first; callers asking for one pair should not bother.
     */
    public function primeCache(string $baseCurrency): void
    {
        foreach ($this->repository->findAllLatest($baseCurrency) as $rate) {
            $this->cache->set($rate);
        }
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

        $cached = $this->cache->get($baseCurrency, $quoteCurrency);

        if ($cached !== null) {
            return $cached;
        }

        $rate = $this->repository->findLatest($baseCurrency, $quoteCurrency);

        if ($rate !== null) {
            $this->cache->set($rate);
        }

        return $rate;
    }

    public function getUsableRate(
        string $baseCurrency,
        string $quoteCurrency,
        int $maxAgeSeconds,
        StaleRateFallback $fallback,
    ): ?ExchangeRate {
        $rate = $this->getRate($baseCurrency, $quoteCurrency);

        if ($rate === null || !$rate->isStale($maxAgeSeconds)) {
            return $rate;
        }

        return $fallback === StaleRateFallback::LastKnown ? $rate : null;
    }
}

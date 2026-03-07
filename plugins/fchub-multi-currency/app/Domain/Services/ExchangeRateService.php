<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Services;

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
}

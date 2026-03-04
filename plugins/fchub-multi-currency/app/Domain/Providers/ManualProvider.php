<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Providers;

use FChubMultiCurrency\Storage\ExchangeRateRepository;

defined('ABSPATH') || exit;

final class ManualProvider implements ProviderContract
{
    public function __construct(
        private ExchangeRateRepository $repository,
    ) {
    }

    public function fetchRates(string $baseCurrency): array
    {
        $rates = $this->repository->findAllLatest($baseCurrency);

        $result = [];

        foreach ($rates as $rate) {
            $result[$rate->quoteCurrency] = $rate->rate;
        }

        return $result;
    }

    public function name(): string
    {
        return 'manual';
    }
}

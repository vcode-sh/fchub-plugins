<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\ValueObjects;

use FChubMultiCurrency\Domain\Enums\RateProvider;

defined('ABSPATH') || exit;

final readonly class ExchangeRate
{
    public function __construct(
        public string $baseCurrency,
        public string $quoteCurrency,
        public string $rate,
        public RateProvider $provider,
        public string $fetchedAt,
    ) {
    }

    public static function from(array $data): self
    {
        return new self(
            baseCurrency: strtoupper($data['base_currency']),
            quoteCurrency: strtoupper($data['quote_currency']),
            rate: (string) $data['rate'],
            provider: RateProvider::tryFrom($data['provider'] ?? 'manual') ?? RateProvider::Manual,
            fetchedAt: $data['fetched_at'] ?? gmdate('Y-m-d H:i:s'),
        );
    }

    public function rateAsFloat(): float
    {
        return (float) $this->rate;
    }

    public function isStale(int $maxAgeSeconds): bool
    {
        $fetchedTimestamp = strtotime($this->fetchedAt . ' UTC');

        if ($fetchedTimestamp === false) {
            return true;
        }

        // Future dates are suspicious — treat as stale
        if ($fetchedTimestamp > time()) {
            return true;
        }

        return (time() - $fetchedTimestamp) > $maxAgeSeconds;
    }
}

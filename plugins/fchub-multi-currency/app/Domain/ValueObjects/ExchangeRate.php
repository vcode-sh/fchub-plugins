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
            provider: RateProvider::from($data['provider'] ?? 'manual'),
            fetchedAt: $data['fetched_at'] ?? current_time('mysql'),
        );
    }

    public function rateAsFloat(): float
    {
        return (float) $this->rate;
    }

    public function isStale(int $maxAgeSeconds): bool
    {
        $fetchedTimestamp = strtotime($this->fetchedAt);

        if ($fetchedTimestamp === false) {
            return true;
        }

        return (time() - $fetchedTimestamp) > $maxAgeSeconds;
    }
}

<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\ValueObjects;

use FChubMultiCurrency\Domain\Enums\ResolverSource;

defined('ABSPATH') || exit;

final readonly class CurrencyContext
{
    public function __construct(
        public Currency $displayCurrency,
        public Currency $baseCurrency,
        public ExchangeRate $rate,
        public ResolverSource $source,
        public bool $isBaseDisplay,
    ) {
    }

    public static function baseOnly(Currency $base): self
    {
        return new self(
            displayCurrency: $base,
            baseCurrency: $base,
            rate: new ExchangeRate(
                baseCurrency: $base->code,
                quoteCurrency: $base->code,
                rate: '1.00000000',
                provider: \FChubMultiCurrency\Domain\Enums\RateProvider::Manual,
                fetchedAt: wp_date('Y-m-d H:i:s') ?: gmdate('Y-m-d H:i:s'),
            ),
            source: ResolverSource::Fallback,
            isBaseDisplay: true,
        );
    }
}

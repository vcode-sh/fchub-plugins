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

    /**
     * $source defaults to Fallback for the genuine "nothing in the resolver
     * chain matched at all" case (CurrencyContextService::resolve()'s own
     * use of this). Callers that reached base pricing via a resolver that
     * actually matched — the visitor's code just happened to equal the base
     * currency, or a rate lookup failed for an otherwise-resolved code — must
     * pass that resolver's real source through instead of letting it default:
     * losing it here silently mislabels a url_param/user_meta/cookie
     * resolution as Fallback ("default"), which both misrepresents where the
     * visitor's preference actually came from and, since RECONCILABLE_SOURCES
     * treats "default" as reconcilable, can make client-side reconciliation
     * incorrectly reconsider a source that must never be second-guessed.
     */
    public static function baseOnly(Currency $base, ResolverSource $source = ResolverSource::Fallback): self
    {
        return new self(
            displayCurrency: $base,
            baseCurrency: $base,
            rate: new ExchangeRate(
                baseCurrency: $base->code,
                quoteCurrency: $base->code,
                rate: '1.00000000',
                provider: \FChubMultiCurrency\Domain\Enums\RateProvider::Manual,
                fetchedAt: gmdate('Y-m-d H:i:s'),
            ),
            source: $source,
            isBaseDisplay: true,
        );
    }
}

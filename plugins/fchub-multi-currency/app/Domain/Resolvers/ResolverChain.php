<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Resolvers;

use FChubMultiCurrency\Domain\Enums\ResolverSource;
use FChubMultiCurrency\Domain\ValueObjects\CurrencyContext;

defined('ABSPATH') || exit;

final class ResolverChain
{
    /** @var array<ResolverSource, callable> */
    private array $resolvers = [];

    public function add(ResolverSource $source, callable $resolver): self
    {
        $this->resolvers[$source->value] = $resolver;

        return $this;
    }

    /**
     * @param array<string, mixed> $enabledCurrencies
     */
    public function resolve(string $baseCurrencyCode, array $enabledCurrencies): ?CurrencyContext
    {
        foreach ($this->resolvers as $resolver) {
            $result = $resolver($baseCurrencyCode, $enabledCurrencies);

            if ($result instanceof CurrencyContext) {
                return $result;
            }
        }

        return null;
    }
}

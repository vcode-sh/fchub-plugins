<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Resolvers;

defined('ABSPATH') || exit;

final class DefaultResolver
{
    public function resolve(string $baseCurrencyCode, array $enabledCurrencies): string
    {
        return $baseCurrencyCode;
    }
}

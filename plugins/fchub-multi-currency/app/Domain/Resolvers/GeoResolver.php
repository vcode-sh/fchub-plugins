<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Resolvers;

use FChubMultiCurrency\Domain\Contracts\GeoProviderContract;

defined('ABSPATH') || exit;

final class GeoResolver
{
    public function __construct(
        private ?GeoProviderContract $provider = null,
    ) {
    }

    public function resolve(string $baseCurrencyCode, array $enabledCurrencies): ?string
    {
        if ($this->provider === null) {
            return null;
        }

        $currencyCode = $this->provider->detectCurrency();

        if ($currencyCode === null) {
            return null;
        }

        $code = strtoupper($currencyCode);

        if (!in_array($code, array_column($enabledCurrencies, 'code'), true)) {
            return null;
        }

        return $code;
    }
}

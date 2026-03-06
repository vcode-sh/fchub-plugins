<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Resolvers;

use FChubMultiCurrency\Domain\Contracts\GeoProviderContract;

defined('ABSPATH') || exit;

final class GeoResolver
{
    use AllowedCurrencyCheck;

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

        if (!$this->isAllowedCurrency($code, $baseCurrencyCode, $enabledCurrencies)) {
            return null;
        }

        return $code;
    }

}

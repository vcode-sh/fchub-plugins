<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Resolvers;

use FChubMultiCurrency\Domain\Contracts\GeoProviderContract;
use FChubMultiCurrency\Domain\ValueObjects\SelectableCurrencyCodes;

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

        if (!SelectableCurrencyCodes::from($baseCurrencyCode, $enabledCurrencies)->contains($code)) {
            return null;
        }

        return $code;
    }
}

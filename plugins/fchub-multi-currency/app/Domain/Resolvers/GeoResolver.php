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

        if (!$this->isAllowedCurrency($code, $baseCurrencyCode, $enabledCurrencies)) {
            return null;
        }

        return $code;
    }

    /**
     * @param array<int|string, mixed> $enabledCurrencies
     */
    private function isAllowedCurrency(string $code, string $baseCurrencyCode, array $enabledCurrencies): bool
    {
        if ($code === strtoupper($baseCurrencyCode)) {
            return true;
        }

        foreach ($enabledCurrencies as $currency) {
            if (!is_array($currency)) {
                continue;
            }

            $enabledCode = strtoupper((string) ($currency['code'] ?? ''));
            if ($enabledCode === $code) {
                return true;
            }
        }

        return false;
    }
}

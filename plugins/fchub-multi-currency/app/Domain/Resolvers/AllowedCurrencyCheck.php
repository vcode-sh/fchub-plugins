<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Resolvers;

defined('ABSPATH') || exit;

trait AllowedCurrencyCheck
{
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

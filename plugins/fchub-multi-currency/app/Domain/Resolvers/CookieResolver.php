<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Resolvers;

defined('ABSPATH') || exit;

final class CookieResolver
{
    public function resolve(string $baseCurrencyCode, array $enabledCurrencies): ?string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cookie read for currency preference
        $cookie = isset($_COOKIE['fchub_mc_currency']) ? sanitize_text_field(wp_unslash($_COOKIE['fchub_mc_currency'])) : null;

        if ($cookie === null) {
            return null;
        }

        $code = strtoupper($cookie);

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

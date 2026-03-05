<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Resolvers;

defined('ABSPATH') || exit;

final class UrlParamResolver
{
    public function __construct(
        private string $paramKey = 'currency',
    ) {
    }

    public function resolve(string $baseCurrencyCode, array $enabledCurrencies): ?string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only currency preference
        $param = isset($_GET[$this->paramKey]) ? sanitize_text_field(wp_unslash($_GET[$this->paramKey])) : null;

        if ($param === null) {
            return null;
        }

        $code = strtoupper($param);

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

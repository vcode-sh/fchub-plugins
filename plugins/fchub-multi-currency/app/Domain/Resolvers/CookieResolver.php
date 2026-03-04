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

        if (!in_array($code, array_column($enabledCurrencies, 'code'), true)) {
            return null;
        }

        return $code;
    }
}

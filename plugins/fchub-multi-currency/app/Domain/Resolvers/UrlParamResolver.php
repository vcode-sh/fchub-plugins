<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Resolvers;

use FChubMultiCurrency\Domain\ValueObjects\SelectableCurrencyCodes;

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

        if (!SelectableCurrencyCodes::from($baseCurrencyCode, $enabledCurrencies)->contains($code)) {
            return null;
        }

        return $code;
    }
}

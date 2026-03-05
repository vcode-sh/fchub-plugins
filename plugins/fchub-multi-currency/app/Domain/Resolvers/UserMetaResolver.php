<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Resolvers;

defined('ABSPATH') || exit;

final class UserMetaResolver
{
    public function resolve(string $baseCurrencyCode, array $enabledCurrencies): ?string
    {
        $userId = get_current_user_id();

        if ($userId === 0) {
            return null;
        }

        $preference = get_user_meta($userId, '_fchub_mc_currency', true);

        if (!is_string($preference) || $preference === '') {
            return null;
        }

        $code = strtoupper($preference);

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

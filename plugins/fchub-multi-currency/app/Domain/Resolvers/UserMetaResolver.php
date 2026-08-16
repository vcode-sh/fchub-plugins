<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Resolvers;

use FChubMultiCurrency\Domain\ValueObjects\SelectableCurrencyCodes;

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

        if (!SelectableCurrencyCodes::from($baseCurrencyCode, $enabledCurrencies)->contains($code)) {
            return null;
        }

        return $code;
    }
}

<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Resolvers;

defined('ABSPATH') || exit;

final class UserMetaResolver
{
    use AllowedCurrencyCheck;

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

}

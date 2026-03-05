<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Storage;

use FChubMultiCurrency\Support\Constants;

defined('ABSPATH') || exit;

final class PreferenceRepository
{
    public function saveCookie(string $currencyCode, int $lifetimeDays = Constants::COOKIE_DAYS): void
    {
        $expire = time() + ($lifetimeDays * DAY_IN_SECONDS);

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- cookie value is sanitized ISO code
        setcookie(Constants::COOKIE_KEY, strtoupper($currencyCode), [
            'expires'  => $expire,
            'path'     => COOKIEPATH,
            'domain'   => COOKIE_DOMAIN,
            'secure'   => is_ssl(),
            'httponly'  => true,
            'samesite' => 'Lax',
        ]);
    }

    public function saveUserMeta(int $userId, string $currencyCode): void
    {
        update_user_meta($userId, Constants::USER_META_KEY, strtoupper($currencyCode));
    }

    public function getUserMeta(int $userId): ?string
    {
        $value = get_user_meta($userId, Constants::USER_META_KEY, true);

        return is_string($value) && $value !== '' ? strtoupper($value) : null;
    }

    public function deleteUserMeta(int $userId): void
    {
        delete_user_meta($userId, Constants::USER_META_KEY);
    }
}

<?php

declare(strict_types=1);

namespace FChubMemberships\Http;

defined('ABSPATH') || exit;

final class ApplicationPasswordRequestContext
{
    private static int $userId = 0;
    private static ?string $mode = null;

    public static function register(): void
    {
        if (has_action('application_password_did_authenticate', [self::class, 'authenticated']) !== false) {
            return;
        }

        add_action('application_password_did_authenticate', [self::class, 'authenticated'], 10, 2);
    }

    /**
     * @param array<string, mixed> $applicationPassword
     */
    public static function authenticated(\WP_User $user, array $applicationPassword = []): void
    {
        if ($user->ID <= 0 || self::$mode !== null) {
            return;
        }

        self::$userId = $user->ID;
        self::$mode = 'application_password';
    }

    public static function isAuthenticatedUser(int $userId): bool
    {
        return self::$mode === 'application_password'
            && $userId > 0
            && self::$userId === $userId;
    }

    public static function mode(): ?string
    {
        return self::$mode;
    }

    public static function clear(): void
    {
        self::$userId = 0;
        self::$mode = null;
    }
}

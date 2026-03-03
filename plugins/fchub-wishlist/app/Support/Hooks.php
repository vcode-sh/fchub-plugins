<?php

declare(strict_types=1);

namespace FChubWishlist\Support;

defined('ABSPATH') || exit;

final class Hooks
{
    /**
     * Get a setting value with fallback to defaults.
     */
    public static function getSetting(string $key, mixed $default = null): mixed
    {
        $settings = get_option(Constants::OPTION_SETTINGS, []);

        if (isset($settings[$key])) {
            return $settings[$key];
        }

        return $default ?? (Constants::DEFAULT_SETTINGS[$key] ?? null);
    }

    /**
     * Get all settings merged with defaults.
     *
     * @return array<string, mixed>
     */
    public static function getSettings(): array
    {
        $settings = get_option(Constants::OPTION_SETTINGS, []);
        return array_merge(Constants::DEFAULT_SETTINGS, $settings);
    }

    /**
     * Check whether the wishlist feature is globally enabled.
     */
    public static function isEnabled(): bool
    {
        return self::getSetting('enabled', 'yes') === 'yes';
    }

    /**
     * Check whether guest wishlist functionality is enabled.
     */
    public static function isGuestEnabled(): bool
    {
        return self::getSetting('guest_wishlist_enabled', 'yes') === 'yes';
    }

    /**
     * Get the current user's FluentCart customer ID if available.
     */
    public static function getCustomerId(int $userId): ?int
    {
        if (!function_exists('fluent_cart_api')) {
            return null;
        }

        try {
            $customer = fluent_cart_api()->getCustomerByUserId($userId);
            return $customer ? (int) $customer->id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}

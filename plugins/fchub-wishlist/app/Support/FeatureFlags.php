<?php

declare(strict_types=1);

namespace FChubWishlist\Support;

defined('ABSPATH') || exit;

final class FeatureFlags
{
    /**
     * Check if a feature flag is enabled.
     */
    public static function isEnabled(string $flag): bool
    {
        $flags = get_option('fchub_wishlist_feature_flags', []);

        if (isset($flags[$flag])) {
            return (bool) $flags[$flag];
        }

        return (bool) apply_filters("fchub_wishlist/feature_flag/{$flag}", false);
    }

    /**
     * Enable a feature flag.
     */
    public static function enable(string $flag): void
    {
        $flags = get_option('fchub_wishlist_feature_flags', []);
        $flags[$flag] = true;
        update_option('fchub_wishlist_feature_flags', $flags);
    }

    /**
     * Disable a feature flag.
     */
    public static function disable(string $flag): void
    {
        $flags = get_option('fchub_wishlist_feature_flags', []);
        $flags[$flag] = false;
        update_option('fchub_wishlist_feature_flags', $flags);
    }
}

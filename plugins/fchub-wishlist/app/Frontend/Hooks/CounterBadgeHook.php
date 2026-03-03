<?php

declare(strict_types=1);

namespace FChubWishlist\Frontend\Hooks;

defined('ABSPATH') || exit;

final class CounterBadgeHook
{
    public static function register(): void
    {
        $settings = get_option('fchub_wishlist_settings', []);

        if (($settings['counter_badge_enabled'] ?? 'yes') !== 'yes') {
            return;
        }

        add_action('wp_footer', [self::class, 'render'], 10);
    }

    public static function render(): void
    {
        include FCHUB_WISHLIST_PATH . 'views/counter-badge.php';
    }
}

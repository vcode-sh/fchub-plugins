<?php

declare(strict_types=1);

namespace FChubWishlist\Support;

defined('ABSPATH') || exit;

class AdminMenu
{
    public static function register(): void
    {
        global $submenu;

        $submenu['fluent-cart']['wishlist'] = [
            __('Wishlist', 'fchub-wishlist'),
            'manage_options',
            'admin.php?page=fluent-cart#/settings/wishlist',
            '',
            'fchub_wishlist',
        ];
    }

    public static function enqueueAssets(): void
    {
        $bundlePath = FCHUB_WISHLIST_PATH . 'admin/wishlist-admin.js';

        if (!file_exists($bundlePath)) {
            echo '<div class="notice notice-warning"><p>Wishlist admin assets not found. The admin interface requires the built JavaScript bundle.</p></div>';
            return;
        }

        wp_enqueue_script(
            'fchub-wishlist-admin',
            FCHUB_WISHLIST_URL . 'admin/wishlist-admin.js',
            ['fluent-cart_global_admin_hooks'],
            (string) filemtime(FCHUB_WISHLIST_PATH . 'admin/wishlist-admin.js'),
            true
        );

        wp_localize_script('fchub-wishlist-admin', 'fchubWishlistAdmin', [
            'rest_url' => esc_url_raw(rest_url(Constants::REST_NAMESPACE . '/')),
            'nonce'    => wp_create_nonce('wp_rest'),
        ]);
    }

    public static function ensureLoadOrder(): void
    {
        global $wp_scripts;

        if (isset($wp_scripts->registered['fluent-cart_admin_app_start'])) {
            $wp_scripts->registered['fluent-cart_admin_app_start']->deps[] = 'fchub-wishlist-admin';
        }
    }
}

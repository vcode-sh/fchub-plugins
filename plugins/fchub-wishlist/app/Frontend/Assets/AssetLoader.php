<?php

declare(strict_types=1);

namespace FChubWishlist\Frontend\Assets;

defined('ABSPATH') || exit;

final class AssetLoader
{
    public static function enqueue(): void
    {
        if (!self::shouldLoad()) {
            return;
        }

        self::doEnqueue();
    }

    /**
     * Force-enqueue assets regardless of page detection.
     * Used as a safety net when product card hooks fire on pages
     * where shouldLoad() couldn't detect FluentCart content early enough.
     */
    public static function forceEnqueue(): void
    {
        if (is_admin()) {
            return;
        }

        self::doEnqueue();
    }

    private static function doEnqueue(): void
    {
        if (wp_style_is('fchub-wishlist', 'enqueued')) {
            return;
        }

        wp_enqueue_style(
            'fchub-wishlist',
            FCHUB_WISHLIST_URL . 'assets/css/wishlist.css',
            [],
            FCHUB_WISHLIST_VERSION
        );

        wp_enqueue_script(
            'fchub-wishlist',
            FCHUB_WISHLIST_URL . 'assets/js/wishlist.js',
            [],
            FCHUB_WISHLIST_VERSION,
            true
        );

        wp_localize_script('fchub-wishlist', 'fchubWishlistVars', [
            'restUrl' => esc_url_raw(rest_url('fchub-wishlist/v1/')),
            'nonce'   => wp_create_nonce('wp_rest'),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'i18n'    => [
                'add'     => __('Add to Wishlist', 'fchub-wishlist'),
                'remove'  => __('Remove from Wishlist', 'fchub-wishlist'),
                'added'   => __('Added to wishlist', 'fchub-wishlist'),
                'removed' => __('Removed from wishlist', 'fchub-wishlist'),
                'error'   => __('Something went wrong. Please try again.', 'fchub-wishlist'),
            ],
        ]);
    }

    private static function shouldLoad(): bool
    {
        if (is_admin()) {
            return false;
        }

        $shouldLoad = false;

        if (is_singular('fluent-products')) {
            $shouldLoad = true;
        }

        if (!$shouldLoad) {
            global $post;

            if ($post && is_a($post, 'WP_Post')) {
                // Shortcode-based pages
                if (has_shortcode($post->post_content, 'fluent_cart_products')
                    || has_shortcode($post->post_content, 'fluent_cart_product')
                    || has_shortcode($post->post_content, 'fluent_cart_checkout')
                    || has_shortcode($post->post_content, 'fchub_wishlist')
                ) {
                    $shouldLoad = true;
                }

                // Gutenberg block-based pages (shop, product grids)
                if (!$shouldLoad && has_blocks($post->post_content)) {
                    if (str_contains($post->post_content, 'fluent-cart/')
                        || str_contains($post->post_content, 'fluent_cart_')
                    ) {
                        $shouldLoad = true;
                    }
                }
            }
        }

        /**
         * Filter whether wishlist assets should be enqueued on the current page.
         *
         * @param bool $shouldLoad Whether assets would be loaded based on built-in checks.
         */
        return (bool) apply_filters('fchub_wishlist/should_load_assets', $shouldLoad);
    }
}

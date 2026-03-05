<?php

declare(strict_types=1);

namespace FChubWishlist\Frontend\Assets;

use FChubWishlist\Support\Constants;

defined('ABSPATH') || exit;

final class ScriptData
{
    /**
     * @return array<string, mixed>
     */
    public static function build(): array
    {
        $settings = wp_parse_args(get_option(Constants::OPTION_SETTINGS, []), Constants::DEFAULT_SETTINGS);
        $addLabel = trim((string) ($settings['button_text'] ?? ''));
        $removeLabel = trim((string) ($settings['button_text_remove'] ?? ''));

        return [
            'restUrl' => esc_url_raw(rest_url(Constants::REST_NAMESPACE . '/')),
            'nonce'   => wp_create_nonce('wp_rest'),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'cartAjax' => [
                'action'          => (string) apply_filters('fchub_wishlist/cart_ajax_action', 'fluent_cart_checkout_routes'),
                'checkout_action' => (string) apply_filters('fchub_wishlist/cart_ajax_checkout_action', 'fluent_cart_cart_update'),
            ],
            'i18n'    => [
                'add'     => $addLabel !== '' ? $addLabel : __('Add to Wishlist', 'fchub-wishlist'),
                'remove'  => $removeLabel !== '' ? $removeLabel : __('Remove from Wishlist', 'fchub-wishlist'),
                'added'   => __('Added to wishlist', 'fchub-wishlist'),
                'removed' => __('Removed from wishlist', 'fchub-wishlist'),
                'error'   => __('Something went wrong. Please try again.', 'fchub-wishlist'),
            ],
        ];
    }
}

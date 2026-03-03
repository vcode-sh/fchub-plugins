<?php

declare(strict_types=1);

namespace FChubWishlist\Frontend\Hooks;

use FChubWishlist\Support\Constants;

defined('ABSPATH') || exit;

final class SingleProductButtonHook
{
    public static function register(): void
    {
        $hook = apply_filters(
            'fchub_wishlist/single_product_hook',
            'fluent_cart/product/single/after_quantity_block'
        );

        add_action($hook, [self::class, 'render'], 10, 1);
    }

    /**
     * @param array<string, mixed> $args
     */
    public static function render(array $args): void
    {
        $settings = wp_parse_args(get_option(Constants::OPTION_SETTINGS, []), Constants::DEFAULT_SETTINGS);

        if (($settings['show_on_single_product'] ?? 'yes') !== 'yes') {
            return;
        }

        if (!apply_filters('fchub_wishlist/show_on_single_product', true)) {
            return;
        }

        $product = $args['product'] ?? null;

        if (!$product || !isset($product->ID)) {
            return;
        }

        $productId = (int) $product->ID;
        $defaultVariantId = (int) ($product->detail->default_variation_id ?? 0);
        $iconStyle = (string) ($settings['icon_style'] ?? 'heart');

        $buttonText = apply_filters(
            'fchub_wishlist/button_text',
            $settings['button_text'] ?? __('Add to Wishlist', 'fchub-wishlist')
        );
        $buttonTextRemove = apply_filters(
            'fchub_wishlist/button_text_remove',
            $settings['button_text_remove'] ?? __('Remove from Wishlist', 'fchub-wishlist')
        );

        $heartSvg = WishlistIcon::render($iconStyle, 18, 18);
        $heartSvg = apply_filters('fchub_wishlist/heart_icon_svg', $heartSvg, $iconStyle);

        include FCHUB_WISHLIST_PATH . 'views/wishlist-button.php';
    }
}

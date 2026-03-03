<?php

declare(strict_types=1);

namespace FChubWishlist\Frontend\Hooks;

use FChubWishlist\Support\Constants;

defined('ABSPATH') || exit;

final class ProductCardHeartHook
{
    public static function register(): void
    {
        $hook = apply_filters(
            'fchub_wishlist/product_card_hook',
            'fluent_cart/product/group/after_image_block'
        );

        add_action($hook, [self::class, 'render'], 10, 1);
    }

    /**
     * @param array<string, mixed> $args
     */
    public static function render(array $args): void
    {
        $settings = wp_parse_args(get_option(Constants::OPTION_SETTINGS, []), Constants::DEFAULT_SETTINGS);

        if (($settings['show_on_product_cards'] ?? 'yes') !== 'yes') {
            return;
        }

        if (!apply_filters('fchub_wishlist/show_on_product_cards', true)) {
            return;
        }

        // Safety net: enqueue assets if not already loaded (e.g. block-rendered pages)
        \FChubWishlist\Frontend\Assets\AssetLoader::forceEnqueue();

        $product = $args['product'] ?? null;

        if (!$product || !isset($product->ID)) {
            return;
        }

        $productId = (int) $product->ID;
        $defaultVariantId = (int) ($product->detail->default_variation_id ?? 0);
        $addLabel = (string) ($settings['button_text'] ?? __('Add to Wishlist', 'fchub-wishlist'));
        $removeLabel = (string) ($settings['button_text_remove'] ?? __('Remove from Wishlist', 'fchub-wishlist'));
        $iconStyle = (string) ($settings['icon_style'] ?? 'heart');

        $heartSvg = WishlistIcon::render($iconStyle, 20, 20);
        $heartSvg = apply_filters('fchub_wishlist/heart_icon_svg', $heartSvg, $iconStyle);

        include FCHUB_WISHLIST_PATH . 'views/heart-button.php';
    }
}

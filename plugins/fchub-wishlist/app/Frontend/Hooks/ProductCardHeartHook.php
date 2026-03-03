<?php

declare(strict_types=1);

namespace FChubWishlist\Frontend\Hooks;

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
        $settings = get_option('fchub_wishlist_settings', []);

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

        $heartSvg = '<svg class="fchub-wishlist-heart-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>'
            . '</svg>';
        $heartSvg = apply_filters('fchub_wishlist/heart_icon_svg', $heartSvg);

        include FCHUB_WISHLIST_PATH . 'views/heart-button.php';
    }
}

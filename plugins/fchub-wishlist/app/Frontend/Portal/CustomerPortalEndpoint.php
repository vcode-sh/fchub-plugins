<?php

declare(strict_types=1);

namespace FChubWishlist\Frontend\Portal;

use FChubWishlist\Frontend\Assets\ScriptData;
use FChubWishlist\Domain\WishlistService;
use FChubWishlist\Storage\WishlistItemRepository;

defined('ABSPATH') || exit;

final class CustomerPortalEndpoint
{
    public static function register(): void
    {
        if (!function_exists('fluent_cart_api')) {
            return;
        }

        $iconPath = FCHUB_WISHLIST_PATH . 'assets/icons/wishlist.svg';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file, not a remote URL
        $iconSvg = file_exists($iconPath) ? file_get_contents($iconPath) : '';

        fluent_cart_api()->addCustomerDashboardEndpoint('wishlist', [
            'title'           => __('My Wishlist', 'fchub-wishlist'),
            'icon_svg'        => $iconSvg,
            'render_callback' => [self::class, 'render'],
        ]);
    }

    public static function render(): void
    {
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

        wp_localize_script('fchub-wishlist', 'fchubWishlistVars', ScriptData::build());

        $service = WishlistService::make();
        $wishlist = $service->resolveWishlist();

        $items = [];
        if ($wishlist) {
            $itemRepo = new WishlistItemRepository();
            $items = $itemRepo->getItemsWithProductData($wishlist['id']);
        }

        include FCHUB_WISHLIST_PATH . 'views/customer-portal.php';
    }
}

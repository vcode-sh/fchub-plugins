<?php

declare(strict_types=1);

namespace FChubWishlist\Frontend\Portal;

use FChubWishlist\Frontend\Assets\ScriptData;
use FChubWishlist\Domain\WishlistService;
use FChubWishlist\Storage\WishlistItemRepository;
use FChubWishlist\Support\Hooks;

defined('ABSPATH') || exit;

final class CustomerPortalEndpoint
{
    public static function register(): void
    {
        if (!function_exists('fluent_cart_api') || !Hooks::isEnabled()) {
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
        if (!Hooks::isEnabled()) {
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

        wp_localize_script('fchub-wishlist', 'fchubWishlistVars', ScriptData::build());

        $service = WishlistService::make();
        $wishlist = $service->resolveWishlist();

        $page = max(1, absint($_GET['wishlist_page'] ?? 1));
        $perPage = min(100, max(1, (int) apply_filters('fchub_wishlist/portal_items_per_page', 20)));

        $items = [];
        $pagination = [
            'page'        => $page,
            'per_page'    => $perPage,
            'total'       => 0,
            'total_pages' => 0,
        ];

        if ($wishlist) {
            $itemRepo = new WishlistItemRepository();
            $result = $itemRepo->getItemsWithProductDataPaginated((int) $wishlist['id'], $page, $perPage);
            $items = $result['items'];
            $pagination['total'] = (int) $result['total'];
            $pagination['total_pages'] = (int) ceil(((int) $result['total']) / $perPage);
        }

        include FCHUB_WISHLIST_PATH . 'views/customer-portal.php';
    }
}

<?php

declare(strict_types=1);

namespace FChubWishlist\Frontend\Shortcode;

use FChubWishlist\Domain\WishlistService;
use FChubWishlist\Storage\WishlistItemRepository;
use FChubWishlist\Support\Hooks;

defined('ABSPATH') || exit;

final class WishlistShortcode
{
    public static function register(): void
    {
        add_shortcode('fchub_wishlist', [self::class, 'renderWishlistPage']);
        add_shortcode('fchub_wishlist_count', [self::class, 'renderCount']);
    }

    public static function renderWishlistPage(): string
    {
        if (!Hooks::isEnabled()) {
            return '';
        }

        $service = WishlistService::make();
        $wishlist = $service->resolveWishlist();

        $page = max(1, absint($_GET['wishlist_page'] ?? 1));
        $perPage = min(100, max(1, (int) apply_filters('fchub_wishlist/shortcode_items_per_page', 20)));

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

        ob_start();
        include FCHUB_WISHLIST_PATH . 'views/wishlist-page.php';
        $output = (string) ob_get_clean();

        return apply_filters('fchub_wishlist/wishlist_page_output', $output);
    }

    public static function renderCount(): string
    {
        ob_start();
        include FCHUB_WISHLIST_PATH . 'views/counter-badge.php';
        $output = (string) ob_get_clean();

        return apply_filters('fchub_wishlist/counter_shortcode_output', $output);
    }
}

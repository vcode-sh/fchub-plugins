<?php

declare(strict_types=1);

namespace FChubWishlist\Frontend\Shortcode;

use FChubWishlist\Domain\WishlistService;
use FChubWishlist\Domain\GuestSession;
use FChubWishlist\Storage\WishlistItemRepository;

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
        $service = WishlistService::make();
        $wishlist = $service->resolveWishlist();

        $items = [];
        if ($wishlist) {
            $itemRepo = new WishlistItemRepository();
            $items = $itemRepo->getItemsWithProductData($wishlist['id']);
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

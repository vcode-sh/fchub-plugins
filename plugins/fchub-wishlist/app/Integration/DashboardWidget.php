<?php

declare(strict_types=1);

namespace FChubWishlist\Integration;

use FChubWishlist\Storage\WishlistItemRepository;

defined('ABSPATH') || exit;

final class DashboardWidget
{
    private const CACHE_KEY = 'fchub_wishlist_dashboard_total_items';

    public static function register(): void
    {
        add_filter('fluent_cart/dashboard_stats', [self::class, 'addStatCard']);
        add_action('fchub_wishlist/item_added', [self::class, 'flushCache']);
        add_action('fchub_wishlist/item_removed', [self::class, 'flushCache']);
        add_action('fchub_wishlist/wishlist_cleared', [self::class, 'flushCache']);
        add_action('fchub_wishlist/items_auto_removed', [self::class, 'flushCache']);
        add_action('fchub_wishlist/wishlist_merged', [self::class, 'flushCache']);
    }

    /**
     * @param array<int, array<string, mixed>> $stats
     * @return array<int, array<string, mixed>>
     */
    public static function addStatCard(array $stats): array
    {
        $count = get_transient(self::CACHE_KEY);
        if ($count === false) {
            $itemRepo = new WishlistItemRepository();
            $count = $itemRepo->totalCount();
            set_transient(self::CACHE_KEY, (int) $count, 5 * MINUTE_IN_SECONDS);
        }

        $stats[] = [
            'title'         => __('Wishlisted Items', 'fchub-wishlist'),
            'current_count' => (int) $count,
            'icon'          => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" '
                . 'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" '
                . 'stroke-linejoin="round" width="24" height="24">'
                . '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06'
                . 'a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06'
                . 'a5.5 5.5 0 0 0 0-7.78z"></path></svg>',
            'url'           => admin_url('admin.php?page=fluent-cart#/wishlist'),
            'has_currency'  => false,
        ];

        return $stats;
    }

    public static function flushCache(): void
    {
        delete_transient(self::CACHE_KEY);
    }
}

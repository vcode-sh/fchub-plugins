<?php

declare(strict_types=1);

namespace FChubWishlist\Storage\Queries;

defined('ABSPATH') || exit;

class WishlistStatsQuery
{
    private string $itemsTable;
    private string $listsTable;

    public function __construct()
    {
        global $wpdb;
        $this->itemsTable = $wpdb->prefix . 'fchub_wishlist_items';
        $this->listsTable = $wpdb->prefix . 'fchub_wishlist_lists';
    }

    /**
     * Get overall wishlist statistics.
     *
     * @return array{total_wishlists: int, total_items: int, user_wishlists: int, guest_wishlists: int}
     */
    public function getOverview(): array
    {
        global $wpdb;

        $totalWishlists = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->listsTable}");
        $totalItems = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->itemsTable}");
        $userWishlists = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->listsTable} WHERE user_id IS NOT NULL");
        $guestWishlists = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->listsTable} WHERE user_id IS NULL");

        return [
            'total_wishlists' => $totalWishlists,
            'total_items'     => $totalItems,
            'user_wishlists'  => $userWishlists,
            'guest_wishlists' => $guestWishlists,
        ];
    }

    /**
     * Get wishlist counts for multiple products in a single query.
     *
     * @param array<int> $productIds
     * @return array<int, int>
     */
    public function countByProductIds(array $productIds): array
    {
        global $wpdb;

        if (empty($productIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($productIds), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT product_id, COUNT(*) AS cnt
             FROM {$this->itemsTable}
             WHERE product_id IN ({$placeholders})
             GROUP BY product_id",
            ...array_map('intval', $productIds)
        ), ARRAY_A);

        $counts = [];
        foreach ($rows ?: [] as $row) {
            $counts[(int) $row['product_id']] = (int) $row['cnt'];
        }

        return $counts;
    }

    /**
     * Get most wishlisted products with count, ordered by popularity.
     *
     * @return array<int, array{product_id: int, wishlist_count: int}>
     */
    public function getMostWishlisted(int $limit = 20): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT product_id, COUNT(*) AS wishlist_count
             FROM {$this->itemsTable}
             GROUP BY product_id
             ORDER BY wishlist_count DESC
             LIMIT %d",
            $limit
        ), ARRAY_A);

        return array_map(static fn(array $row): array => [
            'product_id'     => (int) $row['product_id'],
            'wishlist_count' => (int) $row['wishlist_count'],
        ], $rows ?: []);
    }

    /**
     * Get most wishlisted products with product title.
     *
     * @return array<int, array{product_id: int, product_title: string, wishlist_count: int}>
     */
    public function getMostWishlistedWithTitles(int $limit = 20): array
    {
        global $wpdb;

        $postsTable = $wpdb->posts;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT
                i.product_id,
                p.post_title AS product_title,
                COUNT(*) AS wishlist_count
             FROM {$this->itemsTable} i
             LEFT JOIN {$postsTable} p ON i.product_id = p.ID
             GROUP BY i.product_id, p.post_title
             ORDER BY wishlist_count DESC
             LIMIT %d",
            $limit
        ), ARRAY_A);

        return array_map(function (array $row): array {
            return [
                'product_id'     => (int) $row['product_id'],
                'product_title'  => $row['product_title'] ?? '',
                'wishlist_count' => (int) $row['wishlist_count'],
            ];
        }, $rows ?: []);
    }

    /**
     * Get daily wishlist activity for charting (items added per day).
     *
     * @return array<int, array{date: string, items_added: int}>
     */
    public function getDailyActivity(int $days = 30): array
    {
        global $wpdb;

        $startDate = gmdate('Y-m-d', strtotime("-{$days} days"));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT
                DATE(created_at) AS date,
                COUNT(*) AS items_added
             FROM {$this->itemsTable}
             WHERE created_at >= %s
             GROUP BY DATE(created_at)
             ORDER BY date ASC",
            $startDate
        ), ARRAY_A);

        return array_map(function (array $row): array {
            return [
                'date'        => $row['date'],
                'items_added' => (int) $row['items_added'],
            ];
        }, $rows ?: []);
    }

    /**
     * Get the average number of items per wishlist.
     */
    public function getAverageItemsPerWishlist(): float
    {
        global $wpdb;

        $avg = $wpdb->get_var(
            "SELECT AVG(item_count) FROM {$this->listsTable} WHERE item_count > 0"
        );

        return round((float) ($avg ?? 0), 1);
    }

    /**
     * Count wishlists that have been active (updated) in the last N days.
     */
    public function getActiveWishlistCount(int $days = 30): int
    {
        global $wpdb;

        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->listsTable} WHERE updated_at >= %s AND item_count > 0",
            $cutoff
        ));
    }
}

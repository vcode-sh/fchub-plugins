<?php

declare(strict_types=1);

namespace FChubWishlist\Storage\Queries;

defined('ABSPATH') || exit;

class WishlistItemsQuery
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
     * Get items with full product data for a user, with pagination.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function getItemsForUser(int $userId, int $page = 1, int $perPage = 20): array
    {
        global $wpdb;

        $postsTable = $wpdb->posts;
        $variationsTable = $wpdb->prefix . 'fct_product_variations';

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$this->itemsTable} i
             INNER JOIN {$this->listsTable} l ON i.wishlist_id = l.id
             WHERE l.user_id = %d",
            $userId
        ));

        $offset = ($page - 1) * $perPage;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT
                i.*,
                p.post_title AS product_title,
                p.post_status AS product_status,
                p.post_name AS product_slug,
                v.variation_title AS variant_title,
                v.item_price AS current_price,
                v.item_status AS variant_status,
                v.sku AS variant_sku
             FROM {$this->itemsTable} i
             INNER JOIN {$this->listsTable} l ON i.wishlist_id = l.id
             LEFT JOIN {$postsTable} p ON i.product_id = p.ID
             LEFT JOIN {$variationsTable} v ON i.variant_id = v.id
             WHERE l.user_id = %d
             ORDER BY i.created_at DESC
             LIMIT %d OFFSET %d",
            $userId,
            $perPage,
            $offset
        ), ARRAY_A);

        return [
            'items' => array_map([$this, 'hydrateWithProduct'], $rows ?: []),
            'total' => $total,
        ];
    }

    /**
     * Get items for a guest session hash with full product data.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function getItemsForGuest(string $sessionHash, int $page = 1, int $perPage = 20): array
    {
        global $wpdb;

        $postsTable = $wpdb->posts;
        $variationsTable = $wpdb->prefix . 'fct_product_variations';

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$this->itemsTable} i
             INNER JOIN {$this->listsTable} l ON i.wishlist_id = l.id
             WHERE l.session_hash = %s AND l.user_id IS NULL",
            $sessionHash
        ));

        $offset = ($page - 1) * $perPage;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT
                i.*,
                p.post_title AS product_title,
                p.post_status AS product_status,
                p.post_name AS product_slug,
                v.variation_title AS variant_title,
                v.item_price AS current_price,
                v.item_status AS variant_status,
                v.sku AS variant_sku
             FROM {$this->itemsTable} i
             INNER JOIN {$this->listsTable} l ON i.wishlist_id = l.id
             LEFT JOIN {$postsTable} p ON i.product_id = p.ID
             LEFT JOIN {$variationsTable} v ON i.variant_id = v.id
             WHERE l.session_hash = %s AND l.user_id IS NULL
             ORDER BY i.created_at DESC
             LIMIT %d OFFSET %d",
            $sessionHash,
            $perPage,
            $offset
        ), ARRAY_A);

        return [
            'items' => array_map([$this, 'hydrateWithProduct'], $rows ?: []),
            'total' => $total,
        ];
    }

    /**
     * Get product IDs that exist in a specific wishlist (for status checks).
     *
     * @return array<int, array{product_id: int, variant_id: int}>
     */
    public function getProductIdsInWishlist(int $wishlistId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT product_id, variant_id FROM {$this->itemsTable} WHERE wishlist_id = %d",
            $wishlistId
        ), ARRAY_A);

        return array_map(function (array $row): array {
            return [
                'product_id' => (int) $row['product_id'],
                'variant_id' => (int) $row['variant_id'],
            ];
        }, $rows ?: []);
    }

    private function hydrateWithProduct(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['wishlist_id'] = (int) $row['wishlist_id'];
        $row['product_id'] = (int) $row['product_id'];
        $row['variant_id'] = (int) $row['variant_id'];
        $row['price_at_addition'] = (float) $row['price_at_addition'];
        $row['product_title'] = $row['product_title'] ?? '';
        $row['product_status'] = $row['product_status'] ?? '';
        $row['product_slug'] = $row['product_slug'] ?? '';
        $row['variant_title'] = $row['variant_title'] ?? '';
        $row['current_price'] = isset($row['current_price']) ? (float) $row['current_price'] : 0.0;
        $row['variant_status'] = $row['variant_status'] ?? '';
        $row['variant_sku'] = $row['variant_sku'] ?? '';
        return $row;
    }
}

<?php

declare(strict_types=1);

namespace FChubWishlist\Storage;

defined('ABSPATH') || exit;

class WishlistItemRepository
{
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'fchub_wishlist_items';
    }

    public function find(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d",
            $id
        ), ARRAY_A);

        return $row ? $this->hydrate($row) : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByWishlistId(int $wishlistId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE wishlist_id = %d ORDER BY created_at DESC",
            $wishlistId
        ), ARRAY_A);

        return array_map([$this, 'hydrate'], $rows ?: []);
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function findByWishlistIdPaginated(int $wishlistId, int $page, int $perPage): array
    {
        global $wpdb;

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE wishlist_id = %d",
            $wishlistId
        ));

        $offset = ($page - 1) * $perPage;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE wishlist_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $wishlistId,
            $perPage,
            $offset
        ), ARRAY_A);

        return [
            'items'    => array_map([$this, 'hydrate'], $rows ?: []),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    public function exists(int $wishlistId, int $productId, int $variantId): bool
    {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE wishlist_id = %d AND product_id = %d AND variant_id = %d",
            $wishlistId,
            $productId,
            $variantId
        ));
    }

    public function create(array $data): int
    {
        global $wpdb;

        $insert = [
            'wishlist_id'      => (int) $data['wishlist_id'],
            'product_id'       => (int) $data['product_id'],
            'variant_id'       => (int) ($data['variant_id'] ?? 0),
            'price_at_addition' => (float) ($data['price_at_addition'] ?? 0),
            'note'             => $data['note'] ?? null,
            'created_at'       => current_time('mysql'),
        ];

        $wpdb->insert($this->table, $insert);
        return (int) $wpdb->insert_id;
    }

    public function delete(int $id): bool
    {
        global $wpdb;
        return $wpdb->delete($this->table, ['id' => $id]) !== false;
    }

    public function deleteByProduct(int $wishlistId, int $productId, int $variantId): bool
    {
        global $wpdb;
        return $wpdb->delete($this->table, [
            'wishlist_id' => $wishlistId,
            'product_id'  => $productId,
            'variant_id'  => $variantId,
        ]) !== false;
    }

    public function deleteByWishlistId(int $wishlistId): int
    {
        global $wpdb;
        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table} WHERE wishlist_id = %d",
            $wishlistId
        ));
    }

    public function countByProductId(int $productId): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE product_id = %d",
            $productId
        ));
    }

    /**
     * Get wishlist counts for multiple products in a single query.
     *
     * @param array<int> $productIds
     * @return array<int, int> Map of product_id => count
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
             FROM {$this->table}
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
             FROM {$this->table}
             GROUP BY product_id
             ORDER BY wishlist_count DESC
             LIMIT %d",
            $limit
        ), ARRAY_A);

        return array_map(function (array $row): array {
            return [
                'product_id'     => (int) $row['product_id'],
                'wishlist_count' => (int) $row['wishlist_count'],
            ];
        }, $rows ?: []);
    }

    /**
     * Get wishlist items with joined product and variant data.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getItemsWithProductData(int $wishlistId): array
    {
        global $wpdb;

        $postsTable = $wpdb->posts;
        $variationsTable = $wpdb->prefix . 'fct_product_variations';

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
             FROM {$this->table} i
             LEFT JOIN {$postsTable} p ON i.product_id = p.ID
             LEFT JOIN {$variationsTable} v ON i.variant_id = v.id
             WHERE i.wishlist_id = %d
             ORDER BY i.created_at DESC",
            $wishlistId
        ), ARRAY_A);

        return array_map(function (array $row): array {
            $item = $this->hydrate($row);
            $item['product_title'] = $row['product_title'] ?? '';
            $item['product_status'] = $row['product_status'] ?? '';
            $item['product_slug'] = $row['product_slug'] ?? '';
            $item['variant_title'] = $row['variant_title'] ?? '';
            $item['current_price'] = isset($row['current_price']) ? (float) $row['current_price'] : 0.0;
            $item['variant_status'] = $row['variant_status'] ?? '';
            $item['variant_sku'] = $row['variant_sku'] ?? '';
            return $item;
        }, $rows ?: []);
    }

    /**
     * Bulk delete items by product IDs (used for auto-remove after purchase).
     */
    public function deleteByProductIds(int $wishlistId, array $productIds): int
    {
        global $wpdb;

        if (empty($productIds)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($productIds), '%d'));
        $params = array_merge([$wishlistId], array_map('intval', $productIds));

        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table} WHERE wishlist_id = %d AND product_id IN ({$placeholders})",
            ...$params
        ));
    }

    public function findByProductAndVariant(int $wishlistId, int $productId, int $variantId): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE wishlist_id = %d AND product_id = %d AND variant_id = %d",
            $wishlistId,
            $productId,
            $variantId
        ), ARRAY_A);

        return $row ? $this->hydrate($row) : null;
    }

    /**
     * Get total count of all wishlist items across all wishlists.
     */
    public function totalCount(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table}");
    }

    private function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['wishlist_id'] = (int) $row['wishlist_id'];
        $row['product_id'] = (int) $row['product_id'];
        $row['variant_id'] = (int) $row['variant_id'];
        $row['price_at_addition'] = (float) $row['price_at_addition'];
        return $row;
    }
}

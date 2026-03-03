<?php

declare(strict_types=1);

namespace FChubWishlist\Storage;

use FChubWishlist\Storage\Queries\WishlistItemsQuery;
use FChubWishlist\Storage\Queries\WishlistStatsQuery;

defined('ABSPATH') || exit;

class WishlistItemRepository
{
    private string $table;
    private WishlistItemsQuery $itemsQuery;
    private WishlistStatsQuery $statsQuery;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'fchub_wishlist_items';
        $this->itemsQuery = new WishlistItemsQuery();
        $this->statsQuery = new WishlistStatsQuery();
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

        $result = $wpdb->insert($this->table, $insert);
        if ($result === false) {
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    public function delete(int $id): bool
    {
        global $wpdb;
        return (int) $wpdb->delete($this->table, ['id' => $id]) > 0;
    }

    public function deleteByProduct(int $wishlistId, int $productId, int $variantId): bool
    {
        global $wpdb;
        return (int) $wpdb->delete($this->table, [
            'wishlist_id' => $wishlistId,
            'product_id'  => $productId,
            'variant_id'  => $variantId,
        ]) > 0;
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

    public function countByProductIds(array $productIds): array
    {
        return $this->statsQuery->countByProductIds($productIds);
    }

    public function getMostWishlisted(int $limit = 20): array
    {
        return $this->statsQuery->getMostWishlisted($limit);
    }

    public function getItemsWithProductData(int $wishlistId): array
    {
        return $this->itemsQuery->getItemsWithProductData($wishlistId);
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

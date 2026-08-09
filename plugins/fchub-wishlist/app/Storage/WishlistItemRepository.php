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
    private WishlistItemBulkOperations $bulkOps;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'fchub_wishlist_items';
        $this->itemsQuery = new WishlistItemsQuery();
        $this->statsQuery = new WishlistStatsQuery();
        $this->bulkOps = new WishlistItemBulkOperations($this->table);
    }

    public function find(int $id): ?array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned wishlist state is mutation-heavy and must be returned live.
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM %i WHERE id = %d",
            $this->table,
            $id
        ), ARRAY_A);

        return $row ? $this->hydrate($row) : null;
    }

    public function findByWishlistId(int $wishlistId): array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned wishlist state is mutation-heavy and must be returned live.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM %i WHERE wishlist_id = %d ORDER BY created_at DESC",
            $this->table,
            $wishlistId
        ), ARRAY_A);

        return array_map([$this, 'hydrate'], $rows ?: []);
    }

    public function findByWishlistIdPaginated(int $wishlistId, int $page, int $perPage): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Count and page rows must come from the same live wishlist state.
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM %i WHERE wishlist_id = %d",
            $this->table,
            $wishlistId
        ));

        $offset = ($page - 1) * $perPage;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Count and page rows must come from the same live wishlist state.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM %i WHERE wishlist_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $this->table,
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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Duplicate prevention must observe the live wishlist immediately before insertion.
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM %i WHERE wishlist_id = %d AND product_id = %d AND variant_id = %d",
            $this->table,
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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- $wpdb->insert is the WordPress CRUD API for this plugin-owned custom table.
        $result = $wpdb->insert($this->table, $insert);
        if ($result === false) {
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    public function delete(int $id): bool
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->delete is the WordPress CRUD API; this write has no result cache.
        return (int) $wpdb->delete($this->table, ['id' => $id]) > 0;
    }

    public function deleteByProduct(int $wishlistId, int $productId, int $variantId): bool
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->delete is the WordPress CRUD API; this write has no result cache.
        return (int) $wpdb->delete($this->table, [
            'wishlist_id' => $wishlistId,
            'product_id'  => $productId,
            'variant_id'  => $variantId,
        ]) > 0;
    }

    public function findByProductAndVariant(int $wishlistId, int $productId, int $variantId): ?array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Toggle operations require the current item row and cannot accept stale cache data.
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM %i WHERE wishlist_id = %d AND product_id = %d AND variant_id = %d",
            $this->table,
            $wishlistId,
            $productId,
            $variantId
        ), ARRAY_A);

        return $row ? $this->hydrate($row) : null;
    }

    public function deleteByWishlistId(int $wishlistId): int
    {
        return $this->bulkOps->deleteByWishlistId($wishlistId);
    }

    public function countByProductId(int $productId): int
    {
        return $this->statsQuery->countByProductId($productId);
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

    public function getItemsWithProductDataPaginated(int $wishlistId, int $page = 1, int $perPage = 20): array
    {
        return $this->itemsQuery->getItemsWithProductDataPaginated($wishlistId, $page, $perPage);
    }

    public function deleteByProductIds(int $wishlistId, array $productIds): int
    {
        return $this->bulkOps->deleteByProductIds($wishlistId, $productIds);
    }

    public function totalCount(): int
    {
        return $this->statsQuery->totalCount();
    }

    public function countByWishlistId(int $wishlistId): int
    {
        return $this->bulkOps->countByWishlistId($wishlistId);
    }

    public function deleteByIds(array $itemIds): int
    {
        return $this->bulkOps->deleteByIds($itemIds);
    }

    public function deleteByWishlistIds(array $wishlistIds): int
    {
        return $this->bulkOps->deleteByWishlistIds($wishlistIds);
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

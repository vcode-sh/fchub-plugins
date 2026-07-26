<?php

declare(strict_types=1);

namespace FChubWishlist\Storage;

defined('ABSPATH') || exit;

final class WishlistItemBulkOperations
{
    public function __construct(private string $itemsTable)
    {
    }

    public function countByWishlistId(int $wishlistId): int
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Limit enforcement must observe the live item count before a mutation.
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM %i WHERE wishlist_id = %d",
            $this->itemsTable,
            $wishlistId
        ));
    }

    public function deleteByIds(array $itemIds): int
    {
        global $wpdb;

        if (empty($itemIds)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($itemIds), '%d'));
        $params = array_merge([$this->itemsTable], array_map('intval', $itemIds));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- WordPress has no bulk-delete API; no item cache is retained.
        return (int) $wpdb->query($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- WordPress has no array placeholder; every generated %d is prepared.
            "DELETE FROM %i WHERE id IN ({$placeholders})",
            ...$params
        ));
    }

    public function deleteByWishlistIds(array $wishlistIds): int
    {
        global $wpdb;

        if (empty($wishlistIds)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($wishlistIds), '%d'));
        $params = array_merge([$this->itemsTable], array_map('intval', $wishlistIds));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- WordPress has no bulk-delete API; no item cache is retained.
        return (int) $wpdb->query($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- WordPress has no array placeholder; every generated %d is prepared.
            "DELETE FROM %i WHERE wishlist_id IN ({$placeholders})",
            ...$params
        ));
    }
}

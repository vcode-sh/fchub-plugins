<?php

declare(strict_types=1);

namespace FChubWishlist\Storage;

defined('ABSPATH') || exit;

final class WishlistRepositoryMaintenance
{
    public function __construct(private string $listsTable)
    {
    }

    public function deleteBySessionHash(string $hash): int
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- WordPress has no transaction API; session cleanup is deliberately atomic and uncached.
        $transactionStarted = $wpdb->query('START TRANSACTION') !== false;

        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The row lock serialises session ownership transfer and cannot be cached.
            $wishlistIds = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM %i WHERE session_hash = %s AND user_id IS NULL FOR UPDATE",
                $this->listsTable,
                $hash
            ));

            if (!empty($wishlistIds)) {
                $itemsTable = $wpdb->prefix . 'fchub_wishlist_items';
                $placeholders = implode(',', array_fill(0, count($wishlistIds), '%d'));
                $params = array_merge([$itemsTable], array_map('intval', $wishlistIds));
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- This atomic custom-table mutation has no bulk-delete API.
                $deletedItems = $wpdb->query($wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- WordPress has no array placeholder; every generated %d is prepared.
                    "DELETE FROM %i WHERE wishlist_id IN ({$placeholders})",
                    ...$params
                ));

                if ($deletedItems === false) {
                    throw new \RuntimeException('Could not delete wishlist items by session hash.');
                }
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- This custom-table mutation completes the atomic session cleanup.
            $deletedLists = $wpdb->query($wpdb->prepare(
                "DELETE FROM %i WHERE session_hash = %s AND user_id IS NULL",
                $this->listsTable,
                $hash
            ));

            if ($deletedLists === false) {
                throw new \RuntimeException('Could not delete wishlists by session hash.');
            }

            if ($transactionStarted) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- WordPress has no transaction API; this commits the atomic cleanup.
                $wpdb->query('COMMIT');
            }

            return (int) $deletedLists;
        } catch (\Throwable $e) {
            if ($transactionStarted) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- WordPress has no transaction API; this rolls back the failed cleanup.
                $wpdb->query('ROLLBACK');
            }

            return 0;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getOrphanedGuestLists(int $olderThanDays, int $limit = 0): array
    {
        global $wpdb;

        $cutoff = gmdate('Y-m-d H:i:s', time() - ($olderThanDays * DAY_IN_SECONDS));

        if ($limit > 0) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Scheduled cleanup must scan current rows so ownership changes are not deleted from stale cache.
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM %i
                 WHERE user_id IS NULL AND session_hash IS NOT NULL AND updated_at < %s
                 ORDER BY id ASC
                 LIMIT %d",
                $this->listsTable,
                $cutoff,
                $limit
            ), ARRAY_A) ?: [];
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Scheduled cleanup must scan current rows so ownership changes are not deleted from stale cache.
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM %i
             WHERE user_id IS NULL AND session_hash IS NOT NULL AND updated_at < %s
             ORDER BY id ASC",
            $this->listsTable,
            $cutoff
        ), ARRAY_A) ?: [];
    }

    public function deleteByIds(array $wishlistIds): int
    {
        global $wpdb;

        if (empty($wishlistIds)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($wishlistIds), '%d'));
        $params = array_merge([$this->listsTable], array_map('intval', $wishlistIds));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- This custom-table mutation has no bulk-delete API.
        return (int) $wpdb->query($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- WordPress has no array placeholder; every generated %d is prepared.
            "DELETE FROM %i WHERE id IN ({$placeholders})",
            ...$params
        ));
    }

    public function getItemCount(int $wishlistId): int
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Limit enforcement must observe the current persisted count.
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT item_count FROM %i WHERE id = %d",
            $this->listsTable,
            $wishlistId
        ));
    }
}

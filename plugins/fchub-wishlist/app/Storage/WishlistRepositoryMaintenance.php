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

        $transactionStarted = $wpdb->query('START TRANSACTION') !== false;

        try {
            $wishlistIds = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$this->listsTable} WHERE session_hash = %s AND user_id IS NULL FOR UPDATE",
                $hash
            ));

            if (!empty($wishlistIds)) {
                $itemsTable = $wpdb->prefix . 'fchub_wishlist_items';
                $placeholders = implode(',', array_fill(0, count($wishlistIds), '%d'));
                // phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- dynamic placeholder count
                $deletedItems = $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$itemsTable} WHERE wishlist_id IN ({$placeholders})",
                    ...array_map('intval', $wishlistIds)
                ));
                // phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

                if ($deletedItems === false) {
                    throw new \RuntimeException('Could not delete wishlist items by session hash.');
                }
            }

            $deletedLists = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$this->listsTable} WHERE session_hash = %s AND user_id IS NULL",
                $hash
            ));

            if ($deletedLists === false) {
                throw new \RuntimeException('Could not delete wishlists by session hash.');
            }

            if ($transactionStarted) {
                $wpdb->query('COMMIT');
            }

            return (int) $deletedLists;
        } catch (\Throwable $e) {
            if ($transactionStarted) {
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
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->listsTable}
                 WHERE user_id IS NULL AND session_hash IS NOT NULL AND updated_at < %s
                 ORDER BY id ASC
                 LIMIT %d",
                $cutoff,
                $limit
            ), ARRAY_A) ?: [];
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->listsTable}
             WHERE user_id IS NULL AND session_hash IS NOT NULL AND updated_at < %s
             ORDER BY id ASC",
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

        // phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- dynamic placeholder count
        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->listsTable} WHERE id IN ({$placeholders})",
            ...array_map('intval', $wishlistIds)
        ));
        // phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
    }

    public function getItemCount(int $wishlistId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT item_count FROM {$this->listsTable} WHERE id = %d",
            $wishlistId
        ));
    }
}


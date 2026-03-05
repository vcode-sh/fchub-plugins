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

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->itemsTable} WHERE wishlist_id = %d",
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

        // phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- dynamic placeholder count
        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->itemsTable} WHERE id IN ({$placeholders})",
            ...array_map('intval', $itemIds)
        ));
        // phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
    }

    public function deleteByWishlistIds(array $wishlistIds): int
    {
        global $wpdb;

        if (empty($wishlistIds)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($wishlistIds), '%d'));

        // phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- dynamic placeholder count
        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->itemsTable} WHERE wishlist_id IN ({$placeholders})",
            ...array_map('intval', $wishlistIds)
        ));
        // phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
    }
}


<?php

declare(strict_types=1);

namespace FChubWishlist\Domain\Actions;

use FChubWishlist\Storage\WishlistRepository;
use FChubWishlist\Support\Logger;

defined('ABSPATH') || exit;

class CleanupOrphansAction
{
    /**
     * Remove wishlist items where the product no longer exists or is trashed.
     *
     * After removing orphaned items, recalculate the item_count on affected wishlists.
     */
    public function execute(): int
    {
        global $wpdb;

        $itemsTable = $wpdb->prefix . 'fchub_wishlist_items';
        $postsTable = $wpdb->posts;

        // Get affected wishlist IDs before deletion
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- This scheduled maintenance sweep must use live item and post state before deleting orphans.
        $affectedWishlistIds = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT wi.wishlist_id
             FROM %i wi
             LEFT JOIN %i p ON wi.product_id = p.ID
             WHERE p.ID IS NULL OR p.post_status = 'trash'",
            $itemsTable,
            $postsTable
        ));

        // Delete orphaned items
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A joined delete has no WordPress API; the maintenance query acts on live state and the repository recalculates affected counts below.
        $deleted = (int) $wpdb->query($wpdb->prepare(
            "DELETE wi FROM %i wi
             LEFT JOIN %i p ON wi.product_id = p.ID
             WHERE p.ID IS NULL OR p.post_status = 'trash'",
            $itemsTable,
            $postsTable
        ));

        // Recalculate item counts for affected wishlists
        if (!empty($affectedWishlistIds)) {
            $repo = new WishlistRepository();

            foreach ($affectedWishlistIds as $wishlistId) {
                $repo->recalculateItemCount((int) $wishlistId);
            }
        }

        if ($deleted > 0) {
            Logger::info('Cleaned up orphaned wishlist items', [
                'deleted'    => $deleted,
                'wishlists'  => count($affectedWishlistIds),
            ]);
        }

        return $deleted;
    }
}

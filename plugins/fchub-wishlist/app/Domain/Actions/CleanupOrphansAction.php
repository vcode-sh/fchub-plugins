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
        $affectedWishlistIds = $wpdb->get_col(
            "SELECT DISTINCT wi.wishlist_id
             FROM {$itemsTable} wi
             LEFT JOIN {$postsTable} p ON wi.product_id = p.ID
             WHERE p.ID IS NULL OR p.post_status = 'trash'"
        );

        // Delete orphaned items
        $deleted = (int) $wpdb->query(
            "DELETE wi FROM {$itemsTable} wi
             LEFT JOIN {$postsTable} p ON wi.product_id = p.ID
             WHERE p.ID IS NULL OR p.post_status = 'trash'"
        );

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

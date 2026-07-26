<?php

declare(strict_types=1);

namespace FChubWishlist\Domain\Actions;

use FChubWishlist\Domain\Context\WishlistContextResolver;
use FChubWishlist\Storage\WishlistRepository;
use FChubWishlist\Support\Logger;

defined('ABSPATH') || exit;

class MergeGuestWishlistAction
{
    private WishlistRepository $wishlists;
    private WishlistContextResolver $context;

    public function __construct(
        WishlistRepository $wishlists,
        WishlistContextResolver $context,
    ) {
        $this->wishlists = $wishlists;
        $this->context = $context;
    }

    /**
     * Merge a guest wishlist into a user's wishlist.
     *
     * For each guest item:
     * - If already in user wishlist (same product+variant), delete the guest duplicate.
     * - If not in user wishlist, move it (UPDATE wishlist_id).
     *
     * After merge, recalculate counts and delete the empty guest wishlist.
     */
    public function execute(string $sessionHash, int $userId): int
    {
        $guestWishlist = $this->wishlists->findBySessionHash($sessionHash);

        if (!$guestWishlist) {
            return 0;
        }

        $userWishlist = $this->context->getOrCreateForUser($userId);
        if (!$userWishlist) {
            Logger::error('Could not resolve user wishlist for guest merge', [
                'user_id'    => $userId,
                'guest_hash' => $sessionHash,
            ]);
            return 0;
        }

        global $wpdb;
        $itemsTable = $wpdb->prefix . 'fchub_wishlist_items';
        $listsTable = $wpdb->prefix . 'fchub_wishlist_lists';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- WordPress has no transaction API; this merge is deliberately atomic and uncached.
        $transactionStarted = $wpdb->query('START TRANSACTION') !== false;

        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The row lock is required to serialise concurrent merges and cannot be served from cache.
            $wpdb->query($wpdb->prepare(
                "SELECT id FROM %i WHERE id IN (%d, %d) FOR UPDATE",
                $listsTable,
                (int) $guestWishlist['id'],
                (int) $userWishlist['id']
            ));

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- WordPress has no joined-delete API; this mutation runs inside the merge transaction.
            $deletedDuplicates = $wpdb->query($wpdb->prepare(
                "DELETE guest_items
                 FROM %i guest_items
                 INNER JOIN %i user_items
                    ON user_items.wishlist_id = %d
                   AND user_items.product_id = guest_items.product_id
                   AND user_items.variant_id = guest_items.variant_id
                 WHERE guest_items.wishlist_id = %d",
                $itemsTable,
                $itemsTable,
                (int) $userWishlist['id'],
                (int) $guestWishlist['id']
            ));

            if ($deletedDuplicates === false) {
                throw new \RuntimeException('Could not delete duplicate guest wishlist items.');
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The bulk move is an atomic custom-table mutation with no WordPress CRUD equivalent.
            $movedCountResult = $wpdb->query($wpdb->prepare(
                "UPDATE %i
                 SET wishlist_id = %d
                 WHERE wishlist_id = %d",
                $itemsTable,
                (int) $userWishlist['id'],
                (int) $guestWishlist['id']
            ));

            if ($movedCountResult === false) {
                throw new \RuntimeException('Could not move guest wishlist items.');
            }

            $movedCount = (int) $movedCountResult;

            $this->wishlists->recalculateItemCount((int) $userWishlist['id']);

            if (!$this->wishlists->delete((int) $guestWishlist['id'])) {
                throw new \RuntimeException('Could not delete merged guest wishlist.');
            }

            if ($transactionStarted) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- WordPress has no transaction API; this commits the atomic merge.
                $wpdb->query('COMMIT');
            }
        } catch (\Throwable $e) {
            if ($transactionStarted) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- WordPress has no transaction API; this rolls back the failed atomic merge.
                $wpdb->query('ROLLBACK');
            }

            Logger::error('Guest wishlist merge failed', [
                'user_id'           => $userId,
                'guest_wishlist_id' => (int) $guestWishlist['id'],
                'user_wishlist_id'  => (int) $userWishlist['id'],
                'error'             => $e->getMessage(),
            ]);

            return 0;
        }

        if ($movedCount > 0) {
            do_action(
                'fchub_wishlist/wishlist_merged',
                $userId,
                $guestWishlist['id'],
                $userWishlist['id'],
                $movedCount
            );
        }

        Logger::debug('Guest wishlist merged', [
            'user_id'           => $userId,
            'guest_wishlist_id' => $guestWishlist['id'],
            'user_wishlist_id'  => $userWishlist['id'],
            'moved'             => $movedCount,
        ]);

        return $movedCount;
    }
}

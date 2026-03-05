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

        $transactionStarted = $wpdb->query('START TRANSACTION') !== false;

        try {
            $wpdb->query($wpdb->prepare(
                "SELECT id FROM {$listsTable} WHERE id IN (%d, %d) FOR UPDATE",
                (int) $guestWishlist['id'],
                (int) $userWishlist['id']
            ));

            $deletedDuplicates = $wpdb->query($wpdb->prepare(
                "DELETE guest_items
                 FROM {$itemsTable} guest_items
                 INNER JOIN {$itemsTable} user_items
                    ON user_items.wishlist_id = %d
                   AND user_items.product_id = guest_items.product_id
                   AND user_items.variant_id = guest_items.variant_id
                 WHERE guest_items.wishlist_id = %d",
                (int) $userWishlist['id'],
                (int) $guestWishlist['id']
            ));

            if ($deletedDuplicates === false) {
                throw new \RuntimeException('Could not delete duplicate guest wishlist items.');
            }

            $movedCountResult = $wpdb->query($wpdb->prepare(
                "UPDATE {$itemsTable}
                 SET wishlist_id = %d
                 WHERE wishlist_id = %d",
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
                $wpdb->query('COMMIT');
            }
        } catch (\Throwable $e) {
            if ($transactionStarted) {
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

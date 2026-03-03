<?php

declare(strict_types=1);

namespace FChubWishlist\Domain\Actions;

use FChubWishlist\Domain\Context\WishlistContextResolver;
use FChubWishlist\Storage\WishlistItemRepository;
use FChubWishlist\Storage\WishlistRepository;
use FChubWishlist\Support\Logger;

defined('ABSPATH') || exit;

class MergeGuestWishlistAction
{
    private WishlistRepository $wishlists;
    private WishlistItemRepository $items;
    private WishlistContextResolver $context;

    public function __construct(
        WishlistRepository $wishlists,
        WishlistItemRepository $items,
        WishlistContextResolver $context,
    ) {
        $this->wishlists = $wishlists;
        $this->items = $items;
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
        $guestItems = $this->items->findByWishlistId($guestWishlist['id']);

        if (empty($guestItems)) {
            $this->wishlists->delete($guestWishlist['id']);
            return 0;
        }

        $movedCount = 0;

        foreach ($guestItems as $guestItem) {
            $existsInUser = $this->items->exists(
                $userWishlist['id'],
                $guestItem['product_id'],
                $guestItem['variant_id']
            );

            if ($existsInUser) {
                // Duplicate: discard guest item
                $this->items->delete($guestItem['id']);
            } else {
                // Move item to user wishlist
                $this->moveItem($guestItem['id'], $userWishlist['id']);
                $movedCount++;
            }
        }

        // Recalculate counts
        $this->wishlists->recalculateItemCount($userWishlist['id']);
        $this->wishlists->recalculateItemCount($guestWishlist['id']);

        // Delete the now-empty guest wishlist
        $this->wishlists->delete($guestWishlist['id']);

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

    /**
     * Move an item from one wishlist to another via direct UPDATE.
     */
    private function moveItem(int $itemId, int $targetWishlistId): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'fchub_wishlist_items';

        $wpdb->update(
            $table,
            ['wishlist_id' => $targetWishlistId],
            ['id' => $itemId]
        );
    }
}

<?php

declare(strict_types=1);

namespace FChubWishlist\Domain\Actions;

use FChubWishlist\Storage\WishlistItemRepository;
use FChubWishlist\Storage\WishlistRepository;
use FChubWishlist\Support\Logger;

defined('ABSPATH') || exit;

class AutoRemovePurchasedAction
{
    private WishlistItemRepository $items;
    private WishlistRepository $wishlists;

    public function __construct(WishlistItemRepository $items, WishlistRepository $wishlists)
    {
        $this->items = $items;
        $this->wishlists = $wishlists;
    }

    /**
     * Remove purchased items from a user's wishlist.
     *
     * Takes order items and removes matching product+variant pairs from the wishlist.
     *
     * @param array<int, array{product_id: int, variant_id: int}> $purchasedItems
     */
    public function execute(int $userId, array $purchasedItems, int $orderId): int
    {
        if (empty($purchasedItems)) {
            return 0;
        }

        $wishlist = $this->wishlists->findByUserId($userId);

        if (!$wishlist) {
            return 0;
        }

        $wishlistItems = $this->items->findByWishlistId($wishlist['id']);
        if (empty($wishlistItems)) {
            return 0;
        }

        $purchasedKeys = [];
        foreach ($purchasedItems as $purchased) {
            $purchasedKeys[$this->pairKey((int) $purchased['product_id'], (int) $purchased['variant_id'])] = true;
        }

        $itemIdsToDelete = [];
        $removedProductIds = [];

        foreach ($wishlistItems as $wishlistItem) {
            $key = $this->pairKey((int) $wishlistItem['product_id'], (int) $wishlistItem['variant_id']);
            if (!isset($purchasedKeys[$key])) {
                continue;
            }

            $itemIdsToDelete[] = (int) $wishlistItem['id'];
            $removedProductIds[] = (int) $wishlistItem['product_id'];
        }

        if (empty($itemIdsToDelete)) {
            return 0;
        }

        $removedCount = $this->items->deleteByIds($itemIdsToDelete);
        if ($removedCount > 0) {
            $this->wishlists->recalculateItemCount($wishlist['id']);

            do_action(
                'fchub_wishlist/items_auto_removed',
                $userId,
                $removedProductIds,
                $wishlist['id'],
                $orderId
            );

            Logger::info('Auto-removed purchased items from wishlist', [
                'user_id'    => $userId,
                'order_id'   => $orderId,
                'removed'    => $removedCount,
                'product_ids' => $removedProductIds,
            ]);
        }

        return $removedCount;
    }

    private function pairKey(int $productId, int $variantId): string
    {
        return $productId . ':' . $variantId;
    }
}

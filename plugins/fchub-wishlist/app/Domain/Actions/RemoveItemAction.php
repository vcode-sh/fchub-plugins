<?php

declare(strict_types=1);

namespace FChubWishlist\Domain\Actions;

use FChubWishlist\Storage\WishlistItemRepository;
use FChubWishlist\Storage\WishlistRepository;

defined('ABSPATH') || exit;

class RemoveItemAction
{
    private WishlistItemRepository $items;
    private WishlistRepository $wishlists;

    public function __construct(WishlistItemRepository $items, WishlistRepository $wishlists)
    {
        $this->items = $items;
        $this->wishlists = $wishlists;
    }

    /**
     * Remove an item from a wishlist by product and variant.
     */
    public function execute(array $wishlist, int $productId, int $variantId): bool
    {
        $wishlistId = $wishlist['id'];

        $item = $this->items->findByProductAndVariant($wishlistId, $productId, $variantId);

        if (!$item) {
            return false;
        }

        $deleted = $this->items->delete($item['id']);
        if (!$deleted) {
            return false;
        }

        $this->wishlists->decrementItemCount($wishlistId);

        $userId = $wishlist['user_id'] ?? 0;
        do_action('fchub_wishlist/item_removed', $userId, $productId, $variantId, $wishlistId);

        return true;
    }
}

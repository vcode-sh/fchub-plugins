<?php

declare(strict_types=1);

namespace FChubWishlist\Domain\Rules;

use FChubWishlist\Storage\WishlistItemRepository;

defined('ABSPATH') || exit;

class WishlistRules
{
    private WishlistItemRepository $items;

    public function __construct(WishlistItemRepository $items)
    {
        $this->items = $items;
    }

    /**
     * Check if the wishlist has reached the maximum item limit.
     */
    public function isAtMaxItems(int $wishlistId, int $currentCount): bool
    {
        $max = (int) apply_filters('fchub_wishlist/max_items_per_list', 100);

        return $currentCount >= $max;
    }

    /**
     * Check if a product+variant already exists in the wishlist.
     */
    public function isDuplicate(int $wishlistId, int $productId, int $variantId): bool
    {
        return $this->items->exists($wishlistId, $productId, $variantId);
    }

    /**
     * Check if the wishlist belongs to the given user.
     */
    public function isOwnedByUser(array $wishlist, int $userId): bool
    {
        return $wishlist['user_id'] === $userId;
    }

    /**
     * Check if the wishlist belongs to the given guest session.
     */
    public function isOwnedByGuest(array $wishlist, string $sessionHash): bool
    {
        return $wishlist['session_hash'] === $sessionHash && $wishlist['user_id'] === null;
    }

    /**
     * Get the maximum items allowed per wishlist.
     */
    public function getMaxItems(): int
    {
        return (int) apply_filters('fchub_wishlist/max_items_per_list', 100);
    }
}

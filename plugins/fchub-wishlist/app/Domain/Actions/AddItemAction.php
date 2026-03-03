<?php

declare(strict_types=1);

namespace FChubWishlist\Domain\Actions;

use FChubWishlist\Domain\Context\VariantResolver;
use FChubWishlist\Domain\Rules\ProductRules;
use FChubWishlist\Domain\Rules\WishlistRules;
use FChubWishlist\Storage\WishlistItemRepository;
use FChubWishlist\Storage\WishlistRepository;

defined('ABSPATH') || exit;

class AddItemAction
{
    private WishlistItemRepository $items;
    private WishlistRepository $wishlists;
    private ProductRules $productRules;
    private WishlistRules $wishlistRules;
    private VariantResolver $variantResolver;

    public function __construct(
        WishlistItemRepository $items,
        WishlistRepository $wishlists,
        ProductRules $productRules,
        WishlistRules $wishlistRules,
        VariantResolver $variantResolver,
    ) {
        $this->items = $items;
        $this->wishlists = $wishlists;
        $this->productRules = $productRules;
        $this->wishlistRules = $wishlistRules;
        $this->variantResolver = $variantResolver;
    }

    /**
     * Add an item to a wishlist.
     *
     * @return array{success: bool, item: array|null, count: int, error: string}
     */
    public function execute(array $wishlist, int $productId, int $variantId): array
    {
        $wishlistId = $wishlist['id'];

        // Validate product
        $validation = $this->productRules->validate($productId, $variantId);
        if (!$validation['valid']) {
            return ['success' => false, 'item' => null, 'count' => $wishlist['item_count'], 'error' => $validation['error']];
        }

        // Resolve variant (0 → default)
        $variantId = $this->variantResolver->resolve($productId, $variantId);
        if ($variantId <= 0) {
            return ['success' => false, 'item' => null, 'count' => $wishlist['item_count'], 'error' => 'Could not resolve a valid variant for this product.'];
        }

        // Re-validate the resolved variant
        if (!$this->variantResolver->validate($variantId)) {
            return ['success' => false, 'item' => null, 'count' => $wishlist['item_count'], 'error' => 'Resolved variant is not active.'];
        }

        // Check duplicate
        if ($this->wishlistRules->isDuplicate($wishlistId, $productId, $variantId)) {
            return ['success' => false, 'item' => null, 'count' => $wishlist['item_count'], 'error' => 'This item is already in your wishlist.'];
        }

        // Check max items
        if ($this->wishlistRules->isAtMaxItems($wishlistId, $wishlist['item_count'])) {
            return [
                'success' => false,
                'item'    => null,
                'count'   => $wishlist['item_count'],
                'error'   => sprintf('Wishlist is full. Maximum %d items allowed.', $this->wishlistRules->getMaxItems()),
            ];
        }

        // Snapshot the current price
        $price = $this->productRules->getVariantPrice($variantId);

        // Create the item
        $itemId = $this->items->create([
            'wishlist_id'       => $wishlistId,
            'product_id'        => $productId,
            'variant_id'        => $variantId,
            'price_at_addition' => $price,
        ]);

        if ($itemId <= 0) {
            if ($this->wishlistRules->isDuplicate($wishlistId, $productId, $variantId)) {
                return ['success' => false, 'item' => null, 'count' => $wishlist['item_count'], 'error' => 'This item is already in your wishlist.'];
            }

            return ['success' => false, 'item' => null, 'count' => $wishlist['item_count'], 'error' => 'Could not save wishlist item.'];
        }

        // Increment denormalised count
        $this->wishlists->incrementItemCount($wishlistId);
        $newCount = $wishlist['item_count'] + 1;

        $item = $this->items->find($itemId);

        // Fire hook
        $userId = $wishlist['user_id'] ?? 0;
        do_action('fchub_wishlist/item_added', $userId, $productId, $variantId, $wishlistId);

        return ['success' => true, 'item' => $item, 'count' => $newCount, 'error' => ''];
    }
}

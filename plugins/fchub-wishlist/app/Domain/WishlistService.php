<?php

declare(strict_types=1);

namespace FChubWishlist\Domain;

use FChubWishlist\Domain\Actions\AddAllToCartAction;
use FChubWishlist\Domain\Actions\AddItemAction;
use FChubWishlist\Domain\Actions\RemoveItemAction;
use FChubWishlist\Domain\Actions\ToggleItemAction;
use FChubWishlist\Domain\Context\VariantResolver;
use FChubWishlist\Domain\Context\WishlistContextResolver;
use FChubWishlist\Domain\Rules\ProductRules;
use FChubWishlist\Domain\Rules\WishlistRules;
use FChubWishlist\Storage\WishlistItemRepository;
use FChubWishlist\Storage\WishlistRepository;

defined('ABSPATH') || exit;

class WishlistService
{
    private AddItemAction $addItem;
    private RemoveItemAction $removeItem;
    private ToggleItemAction $toggleItem;
    private AddAllToCartAction $addAllToCart;
    private WishlistContextResolver $context;
    private WishlistRepository $wishlists;
    private WishlistItemRepository $items;

    public function __construct(
        AddItemAction $addItem,
        RemoveItemAction $removeItem,
        ToggleItemAction $toggleItem,
        AddAllToCartAction $addAllToCart,
        WishlistContextResolver $context,
        WishlistRepository $wishlists,
        WishlistItemRepository $items,
    ) {
        $this->addItem = $addItem;
        $this->removeItem = $removeItem;
        $this->toggleItem = $toggleItem;
        $this->addAllToCart = $addAllToCart;
        $this->context = $context;
        $this->wishlists = $wishlists;
        $this->items = $items;
    }

    /**
     * Build a fully-wired WishlistService instance.
     *
     * Controllers and external callers should use this instead of the constructor.
     */
    public static function make(): self
    {
        $wishlists = new WishlistRepository();
        $items = new WishlistItemRepository();
        $context = new WishlistContextResolver($wishlists);
        $variantResolver = new VariantResolver();
        $productRules = new ProductRules();
        $wishlistRules = new WishlistRules($items);

        $addItem = new AddItemAction($items, $wishlists, $productRules, $wishlistRules, $variantResolver);
        $removeItem = new RemoveItemAction($items, $wishlists);
        $toggleItem = new ToggleItemAction($addItem, $removeItem, $items);
        $addAllToCart = new AddAllToCartAction($items, $productRules);

        return new self(
            $addItem,
            $removeItem,
            $toggleItem,
            $addAllToCart,
            $context,
            $wishlists,
            $items,
        );
    }

    /** @return array{success: bool, item: array|null, count: int, error: string} */
    public function addItem(int $wishlistId, int $productId, int $variantId): array
    {
        $wishlist = $this->wishlists->find($wishlistId);
        if (!$wishlist) {
            return ['success' => false, 'item' => null, 'count' => 0, 'error' => 'Wishlist not found.'];
        }
        return $this->addItem->execute($wishlist, $productId, $variantId);
    }

    public function removeItem(int $wishlistId, int $productId, int $variantId): bool
    {
        $wishlist = $this->wishlists->find($wishlistId);
        if (!$wishlist) {
            return false;
        }
        return $this->removeItem->execute($wishlist, $productId, $variantId);
    }

    /** @return array{action: string, item: array|null, count: int, error: string} */
    public function toggleItem(int $wishlistId, int $productId, int $variantId): array
    {
        $wishlist = $this->wishlists->find($wishlistId);
        if (!$wishlist) {
            return ['action' => 'failed', 'item' => null, 'count' => 0, 'error' => 'Wishlist not found.'];
        }
        return $this->toggleItem->execute($wishlist, $productId, $variantId);
    }

    /** @return array{items: array, total: int, page: int, per_page: int} */
    public function getItems(int $wishlistId, int $page = 1, int $perPage = 20): array
    {
        return $this->items->findByWishlistIdPaginated($wishlistId, $page, $perPage);
    }

    public function isInWishlist(int $wishlistId, int $productId, int $variantId): bool
    {
        return $this->items->exists($wishlistId, $productId, $variantId);
    }

    public function clearWishlist(int $wishlistId): int
    {
        $wishlist = $this->wishlists->find($wishlistId);
        if (!$wishlist) {
            return 0;
        }
        $count = $this->items->deleteByWishlistId($wishlistId);
        $this->wishlists->recalculateItemCount($wishlistId);
        do_action('fchub_wishlist/wishlist_cleared', $wishlistId, $wishlist['user_id'] ?? 0, $count);
        return $count;
    }

    public function getItemCount(int $wishlistId): int
    {
        $wishlist = $this->wishlists->find($wishlistId);
        return $wishlist ? $wishlist['item_count'] : 0;
    }

    public function getOrCreateForCurrentUser(): ?array
    {
        $userId = get_current_user_id();
        return $userId > 0 ? $this->context->getOrCreateForUser($userId) : null;
    }

    public function getOrCreateForGuest(string $sessionHash): array
    {
        return $this->context->getOrCreateForGuest($sessionHash);
    }

    public function resolveWishlist(): ?array
    {
        return $this->context->resolve();
    }

    /** @return array{items: array, failed: array} */
    public function addAllToCart(int $wishlistId): array
    {
        return $this->addAllToCart->execute($wishlistId);
    }

    public function removeByProductIds(int $wishlistId, array $productIds): int
    {
        $removed = $this->items->deleteByProductIds($wishlistId, $productIds);
        if ($removed > 0) {
            $this->wishlists->recalculateItemCount($wishlistId);
        }
        return $removed;
    }

    /** @return array<int, array{product_id: int, wishlist_count: int}> */
    public function getMostWishlisted(int $limit = 20): array
    {
        return $this->items->getMostWishlisted($limit);
    }
}

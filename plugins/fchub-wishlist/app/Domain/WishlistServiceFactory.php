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

final class WishlistServiceFactory
{
    public static function make(): WishlistService
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

        return new WishlistService($addItem, $removeItem, $toggleItem, $addAllToCart, $context, $wishlists, $items);
    }
}

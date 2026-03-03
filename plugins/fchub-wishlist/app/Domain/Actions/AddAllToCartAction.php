<?php

declare(strict_types=1);

namespace FChubWishlist\Domain\Actions;

use FChubWishlist\Domain\Rules\ProductRules;
use FChubWishlist\Storage\WishlistItemRepository;

defined('ABSPATH') || exit;

class AddAllToCartAction
{
    private WishlistItemRepository $items;
    private ProductRules $productRules;

    public function __construct(WishlistItemRepository $items, ProductRules $productRules)
    {
        $this->items = $items;
        $this->productRules = $productRules;
    }

    /**
     * Validate all wishlist items for cart addition.
     *
     * Returns validated items that can be added to cart and items that failed validation.
     * Actual cart addition is handled client-side via FluentCart's JS API
     * (FluentCartCart.addProduct), so this only validates and returns the data.
     *
     * @return array{items: array<int, array{variant_id: int, product_id: int, product_title: string}>,
     *               failed: array<int, array{product_id: int, variant_id: int, reason: string}>}
     */
    public function execute(int $wishlistId): array
    {
        $wishlistItems = $this->items->getItemsWithProductData($wishlistId);

        $validItems = [];
        $failed = [];

        foreach ($wishlistItems as $item) {
            $productId = $item['product_id'];
            $variantId = $item['variant_id'];

            // Check product still exists and is published
            if (!$this->productRules->productExists($productId)) {
                $failed[] = [
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'reason'     => 'Product no longer available.',
                ];
                continue;
            }

            // Check variant is still purchasable
            if ($variantId > 0 && !$this->productRules->isVariantPurchasable($variantId)) {
                $failed[] = [
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'reason'     => 'Variant is no longer available for purchase.',
                ];
                continue;
            }

            $validItems[] = [
                'variant_id'    => $variantId,
                'product_id'    => $productId,
                'product_title' => $item['product_title'] ?? '',
            ];
        }

        return [
            'items'  => $validItems,
            'failed' => $failed,
        ];
    }
}

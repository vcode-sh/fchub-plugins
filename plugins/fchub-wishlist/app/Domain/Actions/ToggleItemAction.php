<?php

declare(strict_types=1);

namespace FChubWishlist\Domain\Actions;

use FChubWishlist\Storage\WishlistItemRepository;

defined('ABSPATH') || exit;

class ToggleItemAction
{
    private AddItemAction $addItem;
    private RemoveItemAction $removeItem;
    private WishlistItemRepository $items;

    public function __construct(
        AddItemAction $addItem,
        RemoveItemAction $removeItem,
        WishlistItemRepository $items,
    ) {
        $this->addItem = $addItem;
        $this->removeItem = $removeItem;
        $this->items = $items;
    }

    /**
     * Toggle an item in the wishlist: remove if present, add if absent.
     *
     * @return array{action: string, item: array|null, count: int, error: string}
     */
    public function execute(array $wishlist, int $productId, int $variantId): array
    {
        $exists = $this->items->exists($wishlist['id'], $productId, $variantId);

        if ($exists) {
            $removed = $this->removeItem->execute($wishlist, $productId, $variantId);
            $newCount = max(0, $wishlist['item_count'] - ($removed ? 1 : 0));

            return [
                'action' => 'removed',
                'item'   => null,
                'count'  => $newCount,
                'error'  => '',
            ];
        }

        $result = $this->addItem->execute($wishlist, $productId, $variantId);

        if (!$result['success']) {
            return [
                'action' => 'failed',
                'item'   => null,
                'count'  => $wishlist['item_count'],
                'error'  => $result['error'],
            ];
        }

        return [
            'action' => 'added',
            'item'   => $result['item'],
            'count'  => $result['count'],
            'error'  => '',
        ];
    }
}

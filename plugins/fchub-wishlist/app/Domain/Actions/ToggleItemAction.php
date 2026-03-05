<?php

declare(strict_types=1);

namespace FChubWishlist\Domain\Actions;

use FChubWishlist\Storage\WishlistRepository;

defined('ABSPATH') || exit;

class ToggleItemAction
{
    private AddItemAction $addItem;
    private RemoveItemAction $removeItem;
    private WishlistRepository $wishlists;

    public function __construct(
        AddItemAction $addItem,
        RemoveItemAction $removeItem,
        WishlistRepository $wishlists,
    ) {
        $this->addItem = $addItem;
        $this->removeItem = $removeItem;
        $this->wishlists = $wishlists;
    }

    /**
     * Toggle an item in the wishlist: remove if present, add if absent.
     *
     * @return array{action: string, item: array|null, count: int, error: string}
     */
    public function execute(array $wishlist, int $productId, int $variantId): array
    {
        $result = $this->addItem->execute($wishlist, $productId, $variantId);

        if ($result['success']) {
            return [
                'action' => 'added',
                'item'   => $result['item'],
                'count'  => $result['count'],
                'error'  => '',
            ];
        }

        if ($result['error'] === AddItemAction::ERROR_DUPLICATE) {
            $removed = $this->removeItem->execute($wishlist, $productId, $variantId);
            $newCount = $this->wishlists->getItemCount((int) $wishlist['id']);

            return [
                'action' => $removed ? 'removed' : 'failed',
                'item'   => null,
                'count'  => $newCount,
                'error'  => $removed ? '' : 'Could not remove wishlist item.',
            ];
        }

        return [
            'action' => 'failed',
            'item'   => null,
            'count'  => $result['count'],
            'error'  => $result['error'],
        ];
    }
}

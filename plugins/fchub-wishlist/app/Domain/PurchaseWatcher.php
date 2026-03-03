<?php

declare(strict_types=1);

namespace FChubWishlist\Domain;

use FChubWishlist\Domain\Actions\AutoRemovePurchasedAction;
use FChubWishlist\Storage\WishlistItemRepository;
use FChubWishlist\Storage\WishlistRepository;
use FChubWishlist\Support\Hooks;
use FChubWishlist\Support\Logger;

defined('ABSPATH') || exit;

class PurchaseWatcher
{
    /**
     * Register the order_paid_done hook listener.
     */
    public static function register(): void
    {
        add_action('fluent_cart/order_paid_done', [self::class, 'onOrderPaid'], 20, 1);
    }

    /**
     * Handle order paid event: auto-remove purchased items from wishlist.
     *
     * @param array{order: object, transaction: object, customer: object} $data
     */
    public static function onOrderPaid(array $data): void
    {
        // Check if auto-remove is enabled
        $enabled = apply_filters(
            'fchub_wishlist/auto_remove_purchased',
            Hooks::getSetting('auto_remove_purchased', 'yes') === 'yes'
        );

        if (!$enabled) {
            return;
        }

        $order = $data['order'] ?? null;

        if (!$order) {
            return;
        }

        $userId = (int) ($order->user_id ?? 0);

        if ($userId <= 0) {
            return;
        }

        // Extract product+variant pairs from order items
        $purchasedItems = self::extractOrderItems($order);

        if (empty($purchasedItems)) {
            return;
        }

        $action = new AutoRemovePurchasedAction(
            new WishlistItemRepository(),
            new WishlistRepository()
        );

        $removed = $action->execute($userId, $purchasedItems, (int) $order->id);

        if ($removed > 0) {
            Logger::debug('PurchaseWatcher removed items after order', [
                'order_id' => $order->id,
                'user_id'  => $userId,
                'removed'  => $removed,
            ]);
        }
    }

    /**
     * Extract product_id and variant_id from order items.
     *
     * @return array<int, array{product_id: int, variant_id: int}>
     */
    private static function extractOrderItems(object $order): array
    {
        $items = [];

        // FluentCart order items: post_id = product ID, object_id = variant ID
        $orderItems = $order->order_items ?? [];

        if (is_object($orderItems) && method_exists($orderItems, 'all')) {
            $orderItems = $orderItems->all();
        }

        foreach ($orderItems as $orderItem) {
            $productId = (int) ($orderItem->post_id ?? $orderItem->product_id ?? 0);
            $variantId = (int) ($orderItem->object_id ?? $orderItem->variant_id ?? 0);

            if ($productId > 0) {
                $items[] = [
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                ];
            }
        }

        return $items;
    }
}

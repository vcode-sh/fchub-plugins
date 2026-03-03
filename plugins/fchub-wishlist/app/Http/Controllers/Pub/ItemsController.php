<?php

declare(strict_types=1);

namespace FChubWishlist\Http\Controllers\Pub;

use FChubWishlist\Domain\WishlistService;
use FChubWishlist\Http\Requests\ItemRequest;

defined('ABSPATH') || exit;

final class ItemsController
{
    public static function add(\WP_REST_Request $request): \WP_REST_Response
    {
        if ($blocked = WishlistMutationGuard::assertAllowed(__('Guest wishlists are disabled. Please sign in to manage your wishlist.', 'fchub-wishlist'))) {
            return $blocked;
        }

        $validation = ItemRequest::validate($request);
        if (is_wp_error($validation)) {
            return new \WP_REST_Response(['success' => false, 'message' => $validation->get_error_message()], 400);
        }

        $wishlist = WishlistMutationGuard::resolveWishlist();
        if (!$wishlist) {
            return new \WP_REST_Response(['success' => false, 'message' => __('Could not resolve wishlist.', 'fchub-wishlist')], 500);
        }

        $productId = ItemRequest::getProductId($request);
        $variantId = ItemRequest::getVariantId($request);

        $service = WishlistService::make();
        $result = $service->addItem($wishlist['id'], $productId, $variantId);

        if (!empty($result['error'])) {
            return new \WP_REST_Response(['success' => false, 'message' => $result['error']], 422);
        }

        return new \WP_REST_Response([
            'success' => true,
            'data'    => [
                'item'  => $result['item'] ?? null,
                'count' => $service->getItemCount($wishlist['id']),
            ],
        ]);
    }

    public static function remove(\WP_REST_Request $request): \WP_REST_Response
    {
        if ($blocked = WishlistMutationGuard::assertAllowed(__('Guest wishlists are disabled. Please sign in to manage your wishlist.', 'fchub-wishlist'))) {
            return $blocked;
        }

        $validation = ItemRequest::validate($request);
        if (is_wp_error($validation)) {
            return new \WP_REST_Response(['success' => false, 'message' => $validation->get_error_message()], 400);
        }

        $wishlist = WishlistMutationGuard::resolveWishlist();
        if (!$wishlist) {
            return new \WP_REST_Response(['success' => false, 'message' => __('Could not resolve wishlist.', 'fchub-wishlist')], 500);
        }

        $productId = ItemRequest::getProductId($request);
        $variantId = ItemRequest::getVariantId($request);

        $service = WishlistService::make();
        $removed = $service->removeItem($wishlist['id'], $productId, $variantId);

        return new \WP_REST_Response([
            'success' => true,
            'data'    => [
                'removed' => $removed,
                'count'   => $service->getItemCount($wishlist['id']),
            ],
        ]);
    }

    public static function toggle(\WP_REST_Request $request): \WP_REST_Response
    {
        if ($blocked = WishlistMutationGuard::assertAllowed(__('Guest wishlists are disabled. Please sign in to manage your wishlist.', 'fchub-wishlist'))) {
            return $blocked;
        }

        $validation = ItemRequest::validate($request);
        if (is_wp_error($validation)) {
            return new \WP_REST_Response(['success' => false, 'message' => $validation->get_error_message()], 400);
        }

        $wishlist = WishlistMutationGuard::resolveWishlist();
        if (!$wishlist) {
            return new \WP_REST_Response(['success' => false, 'message' => __('Could not resolve wishlist.', 'fchub-wishlist')], 500);
        }

        $productId = ItemRequest::getProductId($request);
        $variantId = ItemRequest::getVariantId($request);

        $service = WishlistService::make();
        $result = $service->toggleItem($wishlist['id'], $productId, $variantId);

        if (!empty($result['error'])) {
            return new \WP_REST_Response(['success' => false, 'message' => $result['error']], 422);
        }

        return new \WP_REST_Response([
            'success' => true,
            'data'    => [
                'action' => $result['action'],
                'item'   => $result['item'] ?? null,
                'count'  => $service->getItemCount($wishlist['id']),
            ],
        ]);
    }

    public static function clearAll(\WP_REST_Request $request): \WP_REST_Response
    {
        if ($blocked = WishlistMutationGuard::assertAllowed(__('Guest wishlists are disabled. Please sign in to manage your wishlist.', 'fchub-wishlist'))) {
            return $blocked;
        }

        $wishlist = WishlistMutationGuard::resolveWishlist();
        if (!$wishlist) {
            return new \WP_REST_Response(['success' => false, 'message' => __('Could not resolve wishlist.', 'fchub-wishlist')], 500);
        }

        $service = WishlistService::make();
        $removed = $service->clearWishlist($wishlist['id']);

        return new \WP_REST_Response([
            'success' => true,
            'data'    => [
                'removed' => $removed,
                'count'   => 0,
            ],
        ]);
    }
}

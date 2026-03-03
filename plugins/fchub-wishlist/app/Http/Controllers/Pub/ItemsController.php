<?php

declare(strict_types=1);

namespace FChubWishlist\Http\Controllers\Pub;

use FChubWishlist\Domain\Context\WishlistContextResolver;
use FChubWishlist\Domain\GuestSession;
use FChubWishlist\Domain\WishlistService;
use FChubWishlist\Http\Requests\ItemRequest;

defined('ABSPATH') || exit;

final class ItemsController
{
    public static function add(\WP_REST_Request $request): \WP_REST_Response
    {
        $validation = ItemRequest::validate($request);
        if (is_wp_error($validation)) {
            return new \WP_REST_Response(['success' => false, 'message' => $validation->get_error_message()], 400);
        }

        $wishlist = self::resolveWishlist();
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
                'item'  => $result,
                'count' => $service->getItemCount($wishlist['id']),
            ],
        ]);
    }

    public static function remove(\WP_REST_Request $request): \WP_REST_Response
    {
        $validation = ItemRequest::validate($request);
        if (is_wp_error($validation)) {
            return new \WP_REST_Response(['success' => false, 'message' => $validation->get_error_message()], 400);
        }

        $wishlist = self::resolveWishlist();
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
        $validation = ItemRequest::validate($request);
        if (is_wp_error($validation)) {
            return new \WP_REST_Response(['success' => false, 'message' => $validation->get_error_message()], 400);
        }

        $wishlist = self::resolveWishlist();
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
        $wishlist = self::resolveWishlist();
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

    private static function resolveWishlist(): ?array
    {
        $resolver = WishlistContextResolver::make();

        $userId = get_current_user_id();
        if ($userId) {
            return $resolver->getOrCreateForUser($userId);
        }

        $hash = GuestSession::getHash();
        if (!$hash) {
            $hash = GuestSession::generateHash();
            GuestSession::setHash($hash);
        }

        return $resolver->getOrCreateForGuest($hash);
    }
}

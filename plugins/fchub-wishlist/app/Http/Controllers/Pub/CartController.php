<?php

declare(strict_types=1);

namespace FChubWishlist\Http\Controllers\Pub;

use FChubWishlist\Domain\GuestSession;
use FChubWishlist\Domain\WishlistService;
use FChubWishlist\Storage\WishlistRepository;

defined('ABSPATH') || exit;

final class CartController
{
    public static function addAll(\WP_REST_Request $request): \WP_REST_Response
    {
        $wishlist = self::resolveWishlist();
        if (!$wishlist) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => __('No wishlist found.', 'fchub-wishlist'),
            ], 404);
        }

        if ($wishlist['item_count'] < 1) {
            return new \WP_REST_Response([
                'success' => true,
                'data'    => ['added' => 0, 'failed' => []],
            ]);
        }

        $service = WishlistService::make();
        $result = $service->addAllToCart($wishlist['id']);

        return new \WP_REST_Response([
            'success' => true,
            'data'    => $result,
        ]);
    }

    private static function resolveWishlist(): ?array
    {
        $repo = new WishlistRepository();

        $userId = get_current_user_id();
        if ($userId) {
            return $repo->findByUserId($userId);
        }

        $hash = GuestSession::getHash();
        if ($hash) {
            return $repo->findBySessionHash($hash);
        }

        return null;
    }
}

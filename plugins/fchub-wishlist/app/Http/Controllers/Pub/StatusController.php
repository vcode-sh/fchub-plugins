<?php

declare(strict_types=1);

namespace FChubWishlist\Http\Controllers\Pub;

use FChubWishlist\Domain\GuestSession;
use FChubWishlist\Storage\WishlistItemRepository;
use FChubWishlist\Storage\WishlistRepository;
use FChubWishlist\Support\Hooks;

defined('ABSPATH') || exit;

final class StatusController
{
    public static function items(\WP_REST_Request $request): \WP_REST_Response
    {
        if (!Hooks::isEnabled() || (!get_current_user_id() && !Hooks::isGuestEnabled())) {
            return self::emptyItemsResponse();
        }

        $wishlist = self::resolveWishlist();
        if (!$wishlist) {
            return self::emptyItemsResponse();
        }

        $page = max(1, absint($request->get_param('page') ?? 1));
        $perPage = min(100, max(1, absint($request->get_param('per_page') ?? 20)));

        $queryArgs = apply_filters('fchub_wishlist/items_query', [
            'wishlist_id' => $wishlist['id'],
            'page'        => $page,
            'per_page'    => $perPage,
        ]);

        if (is_array($queryArgs)) {
            $page = max(1, absint($queryArgs['page'] ?? $page));
            $perPage = min(100, max(1, absint($queryArgs['per_page'] ?? $perPage)));
        }

        $itemRepo = new WishlistItemRepository();
        $allItems = $itemRepo->getItemsWithProductData($wishlist['id']);
        $total = count($allItems);
        $offset = ($page - 1) * $perPage;
        $items = array_slice($allItems, $offset, $perPage);

        $enriched = array_map(static function (array $item): array {
            return apply_filters('fchub_wishlist/enriched_item', $item);
        }, $items);

        return new \WP_REST_Response([
            'success' => true,
            'data'    => [
                'items'    => $enriched,
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
            ],
        ]);
    }

    public static function status(\WP_REST_Request $request): \WP_REST_Response
    {
        if (!Hooks::isEnabled() || (!get_current_user_id() && !Hooks::isGuestEnabled())) {
            return self::emptyStatusResponse();
        }

        $wishlist = self::resolveWishlist();
        if (!$wishlist) {
            return self::emptyStatusResponse();
        }

        $itemRepo = new WishlistItemRepository();
        $items = $itemRepo->findByWishlistId($wishlist['id']);

        $pairs = array_map(static function (array $item): array {
            return [
                'product_id' => $item['product_id'],
                'variant_id' => $item['variant_id'],
            ];
        }, $items);

        return new \WP_REST_Response([
            'success' => true,
            'data'    => [
                'items' => $pairs,
                'count' => $wishlist['item_count'],
            ],
        ]);
    }

    private static function resolveWishlist(): ?array
    {
        $repo = new WishlistRepository();

        $userId = get_current_user_id();
        if ($userId) {
            return $repo->findByUserId($userId);
        }

        if (!Hooks::isGuestEnabled()) {
            return null;
        }

        $hash = GuestSession::getHash();
        if ($hash) {
            return $repo->findBySessionHash($hash);
        }

        return null;
    }

    private static function emptyItemsResponse(): \WP_REST_Response
    {
        return new \WP_REST_Response([
            'success' => true,
            'data'    => ['items' => [], 'total' => 0, 'page' => 1, 'per_page' => 20],
        ]);
    }

    private static function emptyStatusResponse(): \WP_REST_Response
    {
        return new \WP_REST_Response([
            'success' => true,
            'data'    => ['items' => [], 'count' => 0],
        ]);
    }
}

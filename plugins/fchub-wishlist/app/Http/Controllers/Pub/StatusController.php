<?php

declare(strict_types=1);

namespace FChubWishlist\Http\Controllers\Pub;

use FChubWishlist\Domain\GuestSession;
use FChubWishlist\Storage\Queries\WishlistItemsQuery;
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

        $page = max(1, absint($request->get_param('page') ?? 1));
        $perPage = min(100, max(1, absint($request->get_param('per_page') ?? 20)));
        $wishlist = self::resolveWishlist();

        $queryArgs = apply_filters('fchub_wishlist/items_query', [
            'wishlist_id' => (int) ($wishlist['id'] ?? 0),
            'page'        => $page,
            'per_page'    => $perPage,
        ]);

        if (is_array($queryArgs)) {
            $page = max(1, absint($queryArgs['page'] ?? $page));
            $perPage = min(100, max(1, absint($queryArgs['per_page'] ?? $perPage)));
        }

        $query = new WishlistItemsQuery();
        $userId = get_current_user_id();

        if ($userId > 0) {
            $result = $query->getItemsForUser($userId, $page, $perPage);
        } else {
            $hash = GuestSession::getHash();
            if ($hash === '') {
                return self::emptyItemsResponse();
            }

            $result = $query->getItemsForGuest($hash, $page, $perPage);
        }

        $enriched = array_map(static function (array $item): array {
            $item = apply_filters('fchub_wishlist/enriched_item', $item);
            unset($item['id'], $item['wishlist_id']);
            return $item;
        }, $result['items']);

        return new \WP_REST_Response([
            'success' => true,
            'data'    => [
                'items'    => $enriched,
                'total'    => $result['total'],
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

        $query = new WishlistItemsQuery();
        $pairs = $query->getProductIdsInWishlist((int) $wishlist['id']);
        $count = count($pairs);

        return new \WP_REST_Response([
            'success' => true,
            'data'    => [
                'items' => $pairs,
                'count' => $count,
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

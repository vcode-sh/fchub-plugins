<?php

declare(strict_types=1);

namespace FChubWishlist\Http\Controllers\Pub;

use FChubWishlist\Domain\GuestSession;
use FChubWishlist\Storage\WishlistItemRepository;
use FChubWishlist\Storage\WishlistRepository;

defined('ABSPATH') || exit;

final class StatusController
{
    public static function items(\WP_REST_Request $request): \WP_REST_Response
    {
        $wishlist = self::resolveWishlist();
        if (!$wishlist) {
            return new \WP_REST_Response([
                'success' => true,
                'data'    => ['items' => [], 'total' => 0, 'page' => 1, 'per_page' => 20],
            ]);
        }

        $page = max(1, absint($request->get_param('page') ?? 1));
        $perPage = min(100, max(1, absint($request->get_param('per_page') ?? 20)));

        $itemRepo = new WishlistItemRepository();
        $result = $itemRepo->findByWishlistIdPaginated($wishlist['id'], $page, $perPage);

        // Enrich with product data
        $enriched = [];
        foreach ($result['items'] as $item) {
            $enriched[] = self::enrichItem($item);
        }

        return new \WP_REST_Response([
            'success' => true,
            'data'    => [
                'items'    => $enriched,
                'total'    => $result['total'],
                'page'     => $result['page'],
                'per_page' => $result['per_page'],
            ],
        ]);
    }

    public static function status(\WP_REST_Request $request): \WP_REST_Response
    {
        $wishlist = self::resolveWishlist();
        if (!$wishlist) {
            return new \WP_REST_Response([
                'success' => true,
                'data'    => ['items' => [], 'count' => 0],
            ]);
        }

        $itemRepo = new WishlistItemRepository();
        $items = $itemRepo->findByWishlistId($wishlist['id']);

        $pairs = array_map(function (array $item): array {
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

        $hash = GuestSession::getHash();
        if ($hash) {
            return $repo->findBySessionHash($hash);
        }

        return null;
    }

    private static function enrichItem(array $item): array
    {
        $product = get_post($item['product_id']);
        $item['product_title'] = $product ? $product->post_title : '';
        $item['product_status'] = $product ? $product->post_status : '';
        $item['product_slug'] = $product ? $product->post_name : '';

        if ($item['variant_id'] > 0) {
            global $wpdb;
            $variationsTable = $wpdb->prefix . 'fct_product_variations';
            $variant = $wpdb->get_row($wpdb->prepare(
                "SELECT variation_title, item_price, item_status, sku FROM {$variationsTable} WHERE id = %d",
                $item['variant_id']
            ), ARRAY_A);

            $item['variant_title'] = $variant['variation_title'] ?? '';
            $item['current_price'] = isset($variant['item_price']) ? (float) $variant['item_price'] : 0.0;
            $item['variant_status'] = $variant['item_status'] ?? '';
            $item['variant_sku'] = $variant['sku'] ?? '';
        } else {
            $item['variant_title'] = '';
            $item['current_price'] = 0.0;
            $item['variant_status'] = '';
            $item['variant_sku'] = '';
        }

        return apply_filters('fchub_wishlist/enriched_item', $item);
    }
}

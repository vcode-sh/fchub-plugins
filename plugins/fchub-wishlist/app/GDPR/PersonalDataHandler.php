<?php

declare(strict_types=1);

namespace FChubWishlist\GDPR;

use FChubWishlist\Storage\WishlistRepository;
use FChubWishlist\Storage\WishlistItemRepository;

defined('ABSPATH') || exit;

final class PersonalDataHandler
{
    public static function register(): void
    {
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'registerExporter']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'registerEraser']);
    }

    /**
     * @param array<string, array<string, mixed>> $exporters
     * @return array<string, array<string, mixed>>
     */
    public static function registerExporter(array $exporters): array
    {
        $exporters['fchub-wishlist'] = [
            'exporter_friendly_name' => __('FCHub Wishlist Data', 'fchub-wishlist'),
            'callback'               => [self::class, 'exportPersonalData'],
        ];

        return $exporters;
    }

    /**
     * @param array<string, array<string, mixed>> $erasers
     * @return array<string, array<string, mixed>>
     */
    public static function registerEraser(array $erasers): array
    {
        $erasers['fchub-wishlist'] = [
            'eraser_friendly_name' => __('FCHub Wishlist Data', 'fchub-wishlist'),
            'callback'             => [self::class, 'erasePersonalData'],
        ];

        return $erasers;
    }

    /**
     * Export wishlist data for a user.
     *
     * @return array{data: array<int, array<string, mixed>>, done: bool}
     */
    public static function exportPersonalData(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        if (!$user) {
            return ['data' => [], 'done' => true];
        }

        $wishlistRepo = new WishlistRepository();
        $wishlist = $wishlistRepo->findByUserId($user->ID);

        if (!$wishlist) {
            return ['data' => [], 'done' => true];
        }

        $itemRepo = new WishlistItemRepository();
        $items = $itemRepo->getItemsWithProductData($wishlist['id']);

        $exportData = [];
        foreach ($items as $item) {
            $exportData[] = [
                'group_id'    => 'fchub-wishlist',
                'group_label' => __('Wishlist', 'fchub-wishlist'),
                'item_id'     => 'wishlist-item-' . $item['id'],
                'data'        => [
                    [
                        'name'  => __('Product', 'fchub-wishlist'),
                        'value' => $item['product_title'] ?: __('(Deleted product)', 'fchub-wishlist'),
                    ],
                    [
                        'name'  => __('Variant', 'fchub-wishlist'),
                        'value' => $item['variant_title'] ?: __('Default', 'fchub-wishlist'),
                    ],
                    [
                        'name'  => __('Price at Addition', 'fchub-wishlist'),
                        'value' => (string) $item['price_at_addition'],
                    ],
                    [
                        'name'  => __('Added On', 'fchub-wishlist'),
                        'value' => $item['created_at'] ?? '',
                    ],
                ],
            ];
        }

        return [
            'data' => $exportData,
            'done' => true,
        ];
    }

    /**
     * Erase wishlist data for a user.
     *
     * @return array{items_removed: int, items_retained: int, messages: array<int, string>, done: bool}
     */
    public static function erasePersonalData(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        if (!$user) {
            return [
                'items_removed'  => 0,
                'items_retained' => 0,
                'messages'       => [],
                'done'           => true,
            ];
        }

        $wishlistRepo = new WishlistRepository();
        $itemRepo = new WishlistItemRepository();

        $wishlist = $wishlistRepo->findByUserId($user->ID);
        $removedCount = 0;

        if ($wishlist) {
            $removedCount = $itemRepo->deleteByWishlistId($wishlist['id']);
            $wishlistRepo->delete($wishlist['id']);
            $removedCount++; // +1 for the wishlist record itself
        }

        return [
            'items_removed'  => $removedCount,
            'items_retained' => 0,
            'messages'       => [],
            'done'           => true,
        ];
    }
}

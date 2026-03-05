<?php

declare(strict_types=1);

namespace FChubWishlist\Http\Controllers\Pub;

defined('ABSPATH') || exit;

final class ItemResponseMapper
{
    /**
     * @param array<string, mixed>|null $item
     * @return array<string, mixed>|null
     */
    public static function sanitize(?array $item): ?array
    {
        if (!$item) {
            return null;
        }

        unset($item['id'], $item['wishlist_id']);
        return $item;
    }
}


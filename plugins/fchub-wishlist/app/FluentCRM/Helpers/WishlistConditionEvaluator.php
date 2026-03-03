<?php

declare(strict_types=1);

namespace FChubWishlist\FluentCRM\Helpers;

use FChubWishlist\Storage\WishlistItemRepository;
use FChubWishlist\Storage\WishlistRepository;

defined('ABSPATH') || exit;

final class WishlistConditionEvaluator
{
    public static function evaluate(bool $result, array $condition, object $subscriber): bool
    {
        $property = $condition['property'] ?? '';
        $operator = $condition['operator'] ?? '';
        $value = $condition['value'] ?? '';
        $userId = (int) $subscriber->getWpUserId();

        if (!$userId) {
            return $operator === 'not_exist';
        }

        return match ($property) {
            'wishlist_has_items' => self::assessHasItems($userId, $operator),
            'wishlist_item_count' => self::assessItemCount($userId, $operator, $value),
            'wishlist_contains_products' => self::assessContainsProducts($userId, $operator, $value),
            default => $result,
        };
    }

    private static function assessHasItems(int $userId, string $operator): bool
    {
        $count = WishlistFunnelHelper::getUserItemCount($userId);
        return $operator === 'not_exist' ? $count === 0 : $count > 0;
    }

    private static function assessItemCount(int $userId, string $operator, mixed $value): bool
    {
        if ($value === '') {
            return true;
        }

        $count = WishlistFunnelHelper::getUserItemCount($userId);
        $target = (int) $value;

        return match ($operator) {
            '=' => $count === $target,
            '!=' => $count !== $target,
            '>' => $count > $target,
            '<' => $count < $target,
            '>=' => $count >= $target,
            '<=' => $count <= $target,
            default => true,
        };
    }

    private static function assessContainsProducts(int $userId, string $operator, mixed $value): bool
    {
        $productIds = is_array($value) ? array_map('intval', $value) : [intval($value)];
        $productIds = array_filter($productIds);

        if (empty($productIds)) {
            return true;
        }

        $wishlistRepo = new WishlistRepository();
        $wishlist = $wishlistRepo->findByUserId($userId);
        if (!$wishlist) {
            return $operator === 'not_exist';
        }

        $itemRepo = new WishlistItemRepository();
        $items = $itemRepo->findByWishlistId($wishlist['id']);
        $wishlistProductIds = array_column($items, 'product_id');
        $hasAll = empty(array_diff($productIds, $wishlistProductIds));

        return $operator === 'not_exist' ? !$hasAll : $hasAll;
    }
}

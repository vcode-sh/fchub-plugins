<?php

declare(strict_types=1);

namespace FChubWishlist\FluentCRM\Filters;

defined('ABSPATH') || exit;

class WishlistFilters
{
    public static function register(): void
    {
        add_filter('fluentcrm_advanced_filter_options', [static::class, 'addFilterOptions']);
        add_action('fluentcrm_contacts_filter_fchub_wishlist', [static::class, 'applyFilters'], 10, 2);
    }

    public static function addFilterOptions(array $groups): array
    {
        $groups['fchub_wishlist'] = [
            'label'    => __('Wishlist', 'fchub-wishlist'),
            'value'    => 'fchub_wishlist',
            'children' => [
                [
                    'value'             => 'fchub_has_wishlist_items',
                    'label'             => __('Has Wishlist Items', 'fchub-wishlist'),
                    'type'              => 'selections',
                    'is_multiple'       => false,
                    'disable_values'    => true,
                    'value_description' => __('Check if the contact has items in their wishlist', 'fchub-wishlist'),
                    'custom_operators'  => [
                        'exist'     => __('Yes', 'fchub-wishlist'),
                        'not_exist' => __('No', 'fchub-wishlist'),
                    ],
                ],
                [
                    'value' => 'fchub_wishlist_item_count',
                    'label' => __('Wishlist Item Count', 'fchub-wishlist'),
                    'type'  => 'numeric',
                ],
            ],
        ];

        return $groups;
    }

    public static function applyFilters($query, array $filters)
    {
        foreach ($filters as $filter) {
            $query = self::applyFilter($query, $filter);
        }
        return $query;
    }

    private static function applyFilter($query, array $filter)
    {
        $key = $filter['property'] ?? '';
        $operator = $filter['operator'] ?? '';
        if (!$key || !$operator) {
            return $query;
        }

        global $wpdb;
        $listsTable = $wpdb->prefix . 'fchub_wishlist_lists';
        $itemsTable = $wpdb->prefix . 'fchub_wishlist_items';

        return match ($key) {
            'fchub_has_wishlist_items' => self::filterHasItems($query, $operator, $listsTable, $itemsTable),
            'fchub_wishlist_item_count' => self::filterItemCount($query, $operator, $filter['value'] ?? '', $listsTable, $itemsTable),
            default => $query,
        };
    }

    private static function filterHasItems($query, string $operator, string $listsTable, string $itemsTable)
    {
        $method = $operator === 'not_exist' ? 'whereNotExists' : 'whereExists';

        return $query->{$method}(function ($q) use ($listsTable, $itemsTable) {
            $q->select(fluentCrmDb()->raw(1))
                ->from($listsTable)
                ->whereColumn($listsTable . '.user_id', 'fc_subscribers.user_id')
                ->whereExists(function ($sub) use ($listsTable, $itemsTable) {
                    $sub->select(fluentCrmDb()->raw(1))
                        ->from($itemsTable)
                        ->whereColumn($itemsTable . '.wishlist_id', $listsTable . '.id');
                });
        });
    }

    private static function filterItemCount($query, string $operator, $value, string $listsTable, string $itemsTable)
    {
        if ($value === '') {
            return $query;
        }

        $operator = self::sanitizeOperator($operator);
        if (!$operator) {
            return $query;
        }

        return $query->whereExists(function ($q) use ($listsTable, $operator, $value) {
            $q->select(fluentCrmDb()->raw(1))
                ->from($listsTable)
                ->whereColumn($listsTable . '.user_id', 'fc_subscribers.user_id')
                ->where($listsTable . '.item_count', $operator, (int) $value);
        });
    }

    private static function sanitizeOperator(string $operator): ?string
    {
        $allowed = ['=', '!=', '<>', '>', '<', '>=', '<='];
        return in_array($operator, $allowed, true) ? $operator : null;
    }
}

<?php

declare(strict_types=1);

namespace FChubWishlist\Bootstrap\Modules;

defined('ABSPATH') || exit;

use FChubWishlist\Bootstrap\ModuleContract;
use FChubWishlist\FluentCRM\Helpers\WishlistConditionEvaluator;
use FChubWishlist\FluentCRM\Helpers\WishlistFunnelHelper;
use FChubWishlist\Integration\FluentCrmSync;

final class FluentCrmModule implements ModuleContract
{
    public function register(): void
    {
        if (!defined('FLUENTCRM')) {
            return;
        }

        // WishlistAutomation::boot() is called from fchub-wishlist.php at init priority 30

        // Register direct tag sync on wishlist events
        $sync = new FluentCrmSync();
        $sync->register();

        // AJAX selector providers for automation editor fields
        add_filter('fluentcrm_ajax_options_fchub_wishlist_products', [$this, 'getWishlistProducts'], 10, 3);
        add_filter('fluentcrm_ajax_options_fchub_wishlist_variants', [$this, 'getWishlistVariants'], 10, 3);

        // Automation condition group + evaluator
        add_filter('fluentcrm_automation_condition_groups', [$this, 'addAutomationConditions'], 10, 2);
        add_filter('fluentcrm_automation_conditions_assess_fchub_wishlist', [$this, 'assessAutomationConditions'], 10, 3);
    }

    /**
     * @return array<int, array{id: string, title: string}>
     */
    public function getWishlistProducts($options, $search = '', $includedIds = []): array
    {
        return WishlistFunnelHelper::getProductOptions((string) $search);
    }

    /**
     * @return array<int, array{id: string, title: string}>
     */
    public function getWishlistVariants($options, $search = '', $includedIds = []): array
    {
        return WishlistFunnelHelper::getVariantOptions(0, (string) $search);
    }

    public function addAutomationConditions(array $groups, $funnel): array
    {
        $groups['fchub_wishlist'] = [
            'label'    => __('Wishlist', 'fchub-wishlist'),
            'value'    => 'fchub_wishlist',
            'children' => [
                [
                    'value'             => 'wishlist_has_items',
                    'label'             => __('Has Wishlist Items', 'fchub-wishlist'),
                    'type'              => 'selections',
                    'is_multiple'       => false,
                    'disable_values'    => true,
                    'custom_operators'  => [
                        'exist'     => __('Yes', 'fchub-wishlist'),
                        'not_exist' => __('No', 'fchub-wishlist'),
                    ],
                ],
                [
                    'value' => 'wishlist_item_count',
                    'label' => __('Wishlist Item Count', 'fchub-wishlist'),
                    'type'  => 'numeric',
                ],
                [
                    'value'        => 'wishlist_contains_products',
                    'label'        => __('Wishlist Contains Products', 'fchub-wishlist'),
                    'type'         => 'selections',
                    'is_multiple'  => true,
                    'option_key'   => 'fchub_wishlist_products',
                    'custom_operators' => [
                        'exist'     => __('contains', 'fchub-wishlist'),
                        'not_exist' => __('does not contain', 'fchub-wishlist'),
                    ],
                ],
            ],
        ];

        return $groups;
    }

    public function assessAutomationConditions(bool $result, array $condition, $subscriber): bool
    {
        return WishlistConditionEvaluator::evaluate($result, $condition, $subscriber);
    }
}

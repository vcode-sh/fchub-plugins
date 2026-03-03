<?php

declare(strict_types=1);

namespace FChubWishlist\FluentCRM\Actions;

defined('ABSPATH') || exit;

use FluentCrm\App\Services\Funnel\BaseAction;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\Framework\Support\Arr;
use FChubWishlist\FluentCRM\Helpers\WishlistFunnelHelper;
use FChubWishlist\Storage\WishlistItemRepository;

class AddToWishlistAction extends BaseAction
{
    public function __construct()
    {
        $this->actionName = 'fchub_add_to_wishlist';
        $this->priority = 20;
        parent::__construct();
    }

    public function getBlock(): array
    {
        return [
            'category'    => __('FCHub Wishlist', 'fchub-wishlist'),
            'title'       => __('Add to Wishlist', 'fchub-wishlist'),
            'description' => __('Add a product to the contact\'s wishlist', 'fchub-wishlist'),
            'icon'        => 'fc-icon-trigger',
            'settings'    => [
                'product_id' => '',
                'variant_id' => '',
            ],
        ];
    }

    public function getBlockFields(): array
    {
        return [
            'title'     => __('Add to Wishlist', 'fchub-wishlist'),
            'sub_title' => __('Add a product to the contact\'s wishlist', 'fchub-wishlist'),
            'fields'    => [
                'product_id' => [
                    'type'        => 'rest_selector',
                    'option_key'  => 'fchub_wishlist_products',
                    'is_multiple' => false,
                    'label'       => __('Product', 'fchub-wishlist'),
                    'placeholder' => __('Select Product', 'fchub-wishlist'),
                    'is_required' => true,
                ],
                'variant_id' => [
                    'type'        => 'rest_selector',
                    'option_key'  => 'fchub_wishlist_variants',
                    'is_multiple' => false,
                    'label'       => __('Variant', 'fchub-wishlist'),
                    'placeholder' => __('Default Variant', 'fchub-wishlist'),
                    'inline_help' => __('Leave blank to use the default variant', 'fchub-wishlist'),
                ],
            ],
        ];
    }

    public function handle($subscriber, $sequence, $funnelSubscriberId, $funnelMetric): void
    {
        $productId = (int) Arr::get($sequence->settings, 'product_id');
        if (!$productId) {
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return;
        }

        $userId = WishlistFunnelHelper::resolveUserIdFromSubscriber($subscriber);
        if (!$userId) {
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return;
        }

        $variantId = (int) Arr::get($sequence->settings, 'variant_id', 0);
        if (!$variantId) {
            $variantId = $this->resolveDefaultVariant($productId);
        }

        $wishlist = WishlistFunnelHelper::getOrCreateWishlist($userId);
        if (!$wishlist) {
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return;
        }

        $itemRepo = new WishlistItemRepository();
        if ($itemRepo->exists($wishlist['id'], $productId, $variantId)) {
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return;
        }

        $itemId = $itemRepo->create([
            'wishlist_id' => $wishlist['id'],
            'product_id'  => $productId,
            'variant_id'  => $variantId,
        ]);

        if ($itemId <= 0) {
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'failed');
            return;
        }

        $wishlistRepo = new \FChubWishlist\Storage\WishlistRepository();
        $wishlistRepo->incrementItemCount($wishlist['id']);

        do_action('fchub_wishlist/item_added', $userId, $productId, $variantId, $wishlist['id']);
    }

    private function resolveDefaultVariant(int $productId): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fct_product_variations';

        $variantId = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE post_id = %d AND item_status = 'active' ORDER BY id ASC LIMIT 1",
            $productId
        ));

        return $variantId ? (int) $variantId : 0;
    }
}

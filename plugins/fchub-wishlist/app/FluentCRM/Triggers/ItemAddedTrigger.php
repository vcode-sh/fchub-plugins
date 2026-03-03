<?php

declare(strict_types=1);

namespace FChubWishlist\FluentCRM\Triggers;

defined('ABSPATH') || exit;

use FluentCrm\App\Services\Funnel\BaseTrigger;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\Framework\Support\Arr;
use FChubWishlist\FluentCRM\Helpers\WishlistFunnelHelper;

class ItemAddedTrigger extends BaseTrigger
{
    public function __construct()
    {
        $this->triggerName = 'fchub_wishlist/item_added';
        $this->priority = 20;
        $this->actionArgNum = 4;
        parent::__construct();
    }

    public function getTrigger(): array
    {
        return [
            'category'    => __('FCHub Wishlist', 'fchub-wishlist'),
            'label'       => __('Item Added to Wishlist', 'fchub-wishlist'),
            'description' => __('This will start when an item is added to a wishlist', 'fchub-wishlist'),
        ];
    }

    public function getFunnelSettingsDefaults(): array
    {
        return [
            'subscription_status' => 'subscribed',
        ];
    }

    public function getSettingsFields($funnel): array
    {
        return [
            'title'     => __('Item Added to Wishlist', 'fchub-wishlist'),
            'sub_title' => __('This will start when an item is added to a wishlist', 'fchub-wishlist'),
            'fields'    => [
                'subscription_status'      => [
                    'type'        => 'option_selectors',
                    'option_key'  => 'editable_statuses',
                    'is_multiple' => false,
                    'label'       => __('Subscription Status', 'fchub-wishlist'),
                    'placeholder' => __('Select Status', 'fchub-wishlist'),
                ],
                'subscription_status_info' => [
                    'type'       => 'html',
                    'info'       => '<b>' . __('An automated double-optin email will be sent for new subscribers', 'fchub-wishlist') . '</b>',
                    'dependency' => [
                        'depends_on' => 'subscription_status',
                        'operator'   => '=',
                        'value'      => 'pending',
                    ],
                ],
            ],
        ];
    }

    public function getFunnelConditionDefaults($funnel): array
    {
        return [
            'product_ids'  => [],
            'update_type'  => 'update',
            'run_multiple' => 'no',
        ];
    }

    public function getConditionFields($funnel): array
    {
        return [
            'update_type'  => [
                'type'    => 'radio',
                'label'   => __('If Contact Already Exist?', 'fchub-wishlist'),
                'help'    => __('Please specify what will happen if the subscriber already exists in the database', 'fchub-wishlist'),
                'options' => FunnelHelper::getUpdateOptions(),
            ],
            'product_ids'  => [
                'type'        => 'rest_selector',
                'option_key'  => 'fchub_wishlist_products',
                'is_multiple' => true,
                'label'       => __('Target Products', 'fchub-wishlist'),
                'help'        => __('Select products this automation applies to', 'fchub-wishlist'),
                'placeholder' => __('All Products', 'fchub-wishlist'),
                'inline_help' => __('Leave blank to trigger for any product', 'fchub-wishlist'),
            ],
            'run_multiple' => [
                'type'        => 'yes_no_check',
                'label'       => '',
                'check_label' => __('Restart automation for same contact on repeat events', 'fchub-wishlist'),
                'inline_help' => __('If enabled, the automation will restart for a contact even if they are already in this automation', 'fchub-wishlist'),
            ],
        ];
    }

    public function handle($funnel, $originalArgs): void
    {
        $userId = (int) $originalArgs[0];
        $productId = (int) $originalArgs[1];

        if (!$userId) {
            return;
        }

        $user = get_user_by('ID', $userId);
        if (!$user) {
            return;
        }

        if (!$this->isProcessable($funnel, $user, $productId)) {
            return;
        }

        $subscriberData = FunnelHelper::prepareUserData($user);
        $subscriberData = wp_parse_args($subscriberData, $funnel->settings);
        $subscriberData['status'] = $subscriberData['subscription_status'];
        unset($subscriberData['subscription_status']);

        (new FunnelProcessor())->startFunnelSequence($funnel, $subscriberData, [
            'source_trigger_name' => $this->triggerName,
            'source_ref_id'       => $productId,
        ]);
    }

    private function isProcessable($funnel, \WP_User $user, int $productId): bool
    {
        $conditions = $funnel->conditions;

        $subscriber = FunnelHelper::getSubscriber($user->user_email);
        $updateType = Arr::get($conditions, 'update_type');
        if ($updateType === 'skip_all_if_exist' && $subscriber) {
            return false;
        }

        if ($checkIds = Arr::get($conditions, 'product_ids', [])) {
            if (!in_array($productId, array_map('intval', $checkIds), true)) {
                return false;
            }
        }

        if ($subscriber && FunnelHelper::ifAlreadyInFunnel($funnel->id, $subscriber->id)) {
            $multipleRun = Arr::get($conditions, 'run_multiple') === 'yes';
            if ($multipleRun) {
                FunnelHelper::removeSubscribersFromFunnel($funnel->id, [$subscriber->id]);
            }
            return $multipleRun;
        }

        return true;
    }
}

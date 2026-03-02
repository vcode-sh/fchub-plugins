<?php

namespace FChubMemberships\FluentCRM\Triggers;

defined('ABSPATH') || exit;

use FluentCrm\App\Services\Funnel\BaseTrigger;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\Framework\Support\Arr;

class MembershipGrantedTrigger extends BaseTrigger
{
    public function __construct()
    {
        $this->triggerName = 'fchub_memberships/grant_created';
        $this->priority = 20;
        $this->actionArgNum = 3;
        parent::__construct();
    }

    public function getTrigger()
    {
        return [
            'category'    => __('FCHub Memberships', 'fchub-memberships'),
            'label'       => __('Membership Plan Granted', 'fchub-memberships'),
            'description' => __('This will start when a membership plan is granted to a user', 'fchub-memberships'),
        ];
    }

    public function getFunnelSettingsDefaults()
    {
        return [
            'subscription_status' => 'subscribed',
        ];
    }

    public function getSettingsFields($funnel)
    {
        return [
            'title'     => __('Membership Plan Granted', 'fchub-memberships'),
            'sub_title' => __('This will start when a membership plan is granted to a user', 'fchub-memberships'),
            'fields'    => [
                'subscription_status'      => [
                    'type'        => 'option_selectors',
                    'option_key'  => 'editable_statuses',
                    'is_multiple' => false,
                    'label'       => __('Subscription Status', 'fchub-memberships'),
                    'placeholder' => __('Select Status', 'fchub-memberships'),
                ],
                'subscription_status_info' => [
                    'type'       => 'html',
                    'info'       => '<b>' . __('An automated double-optin email will be sent for new subscribers', 'fchub-memberships') . '</b>',
                    'dependency' => [
                        'depends_on' => 'subscription_status',
                        'operator'   => '=',
                        'value'      => 'pending',
                    ],
                ],
            ],
        ];
    }

    public function getFunnelConditionDefaults($funnel)
    {
        return [
            'plan_ids'     => [],
            'source_types' => [],
            'update_type'  => 'update',
            'run_multiple' => 'no',
        ];
    }

    public function getConditionFields($funnel)
    {
        return [
            'update_type'  => [
                'type'    => 'radio',
                'label'   => __('If Contact Already Exist?', 'fchub-memberships'),
                'help'    => __('Please specify what will happen if the subscriber already exists in the database', 'fchub-memberships'),
                'options' => FunnelHelper::getUpdateOptions(),
            ],
            'plan_ids'     => [
                'type'        => 'multi-select',
                'is_multiple' => true,
                'label'       => __('Target Plans', 'fchub-memberships'),
                'help'        => __('Select plans this automation applies to', 'fchub-memberships'),
                'placeholder' => __('All Plans', 'fchub-memberships'),
                'options'     => $this->getPlanOptions(),
                'inline_help' => __('Leave blank to trigger for any plan', 'fchub-memberships'),
            ],
            'source_types' => [
                'type'        => 'multi-select',
                'is_multiple' => true,
                'label'       => __('Source Types', 'fchub-memberships'),
                'help'        => __('Filter by how the membership was granted', 'fchub-memberships'),
                'placeholder' => __('All Sources', 'fchub-memberships'),
                'options'     => [
                    ['id' => 'order', 'title' => __('Order', 'fchub-memberships')],
                    ['id' => 'subscription', 'title' => __('Subscription', 'fchub-memberships')],
                    ['id' => 'manual', 'title' => __('Manual', 'fchub-memberships')],
                    ['id' => 'trial', 'title' => __('Trial', 'fchub-memberships')],
                ],
                'inline_help' => __('Leave blank to trigger for any source type', 'fchub-memberships'),
            ],
            'run_multiple' => [
                'type'        => 'yes_no_check',
                'label'       => '',
                'check_label' => __('Restart automation for same contact on repeat events', 'fchub-memberships'),
                'inline_help' => __('If enabled, the automation will restart for a contact even if they are already in this automation', 'fchub-memberships'),
            ],
        ];
    }

    public function handle($funnel, $originalArgs)
    {
        $userId = $originalArgs[0];
        $planId = $originalArgs[1];
        $context = $originalArgs[2] ?? [];

        $user = get_user_by('ID', $userId);
        if (!$user) {
            return false;
        }

        if (!$this->isProcessable($funnel, $user, $planId, $context)) {
            return false;
        }

        $subscriberData = FunnelHelper::prepareUserData($user);
        $subscriberData = wp_parse_args($subscriberData, $funnel->settings);
        $subscriberData['status'] = $subscriberData['subscription_status'];
        unset($subscriberData['subscription_status']);

        (new FunnelProcessor())->startFunnelSequence($funnel, $subscriberData, [
            'source_trigger_name' => $this->triggerName,
            'source_ref_id'       => $planId,
        ]);
    }

    private function isProcessable($funnel, $user, $planId, $context)
    {
        $conditions = $funnel->conditions;

        $subscriber = FunnelHelper::getSubscriber($user->user_email);
        $updateType = Arr::get($conditions, 'update_type');
        if ($updateType == 'skip_all_if_exist' && $subscriber) {
            return false;
        }

        if ($checkIds = Arr::get($conditions, 'plan_ids', [])) {
            if (!in_array($planId, $checkIds)) {
                return false;
            }
        }

        if ($sourceTypes = Arr::get($conditions, 'source_types', [])) {
            $sourceType = $context['source_type'] ?? '';
            if (!in_array($sourceType, $sourceTypes)) {
                return false;
            }
        }

        if ($subscriber && FunnelHelper::ifAlreadyInFunnel($funnel->id, $subscriber->id)) {
            $multipleRun = Arr::get($conditions, 'run_multiple') == 'yes';
            if ($multipleRun) {
                FunnelHelper::removeSubscribersFromFunnel($funnel->id, [$subscriber->id]);
            }
            return $multipleRun;
        }

        return true;
    }

    private function getPlanOptions()
    {
        $planRepo = new \FChubMemberships\Storage\PlanRepository();
        $plans = $planRepo->all();
        return array_map(fn($p) => ['id' => $p['id'], 'title' => $p['title']], $plans);
    }
}

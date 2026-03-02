<?php

namespace FChubMemberships\FluentCRM\Triggers;

defined('ABSPATH') || exit;

use FluentCrm\App\Services\Funnel\BaseTrigger;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\Framework\Support\Arr;

class PaymentFailedTrigger extends BaseTrigger
{
    public function __construct()
    {
        $this->triggerName = 'fchub_memberships/payment_failed';
        $this->priority = 20;
        $this->actionArgNum = 3;
        parent::__construct();
    }

    public function getTrigger()
    {
        return [
            'category'    => __('FCHub Memberships', 'fchub-memberships'),
            'label'       => __('Payment Failed', 'fchub-memberships'),
            'description' => __('This will start when a payment fails for a subscription linked to a membership', 'fchub-memberships'),
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
            'title'     => __('Payment Failed', 'fchub-memberships'),
            'sub_title' => __('This will start when a payment fails for a membership subscription', 'fchub-memberships'),
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
            'run_multiple' => 'yes',
        ];
    }

    public function getConditionFields($funnel)
    {
        return [
            'plan_ids'     => [
                'type'        => 'multi-select',
                'is_multiple' => true,
                'label'       => __('Target Plans', 'fchub-memberships'),
                'help'        => __('Select plans this automation applies to', 'fchub-memberships'),
                'placeholder' => __('All Plans', 'fchub-memberships'),
                'options'     => $this->getPlanOptions(),
                'inline_help' => __('Leave blank to trigger for any plan', 'fchub-memberships'),
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
        // Hook signature: ($grants, $subscription, $eventData)
        $grants = $originalArgs[0];
        $subscription = $originalArgs[1];

        if (empty($grants)) {
            return false;
        }

        $userId = $grants[0]['user_id'] ?? 0;
        $planId = $grants[0]['plan_id'] ?? 0;

        $user = get_user_by('ID', $userId);
        if (!$user) {
            return false;
        }

        if (!$this->isProcessable($funnel, $user, $planId)) {
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

    private function isProcessable($funnel, $user, $planId)
    {
        $conditions = $funnel->conditions;

        // Plan filter
        if ($checkIds = Arr::get($conditions, 'plan_ids', [])) {
            if (!in_array($planId, $checkIds)) {
                return false;
            }
        }

        // Duplicate check
        $subscriber = FunnelHelper::getSubscriber($user->user_email);
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

<?php

namespace FChubMemberships\FluentCRM\Triggers;

defined('ABSPATH') || exit;

use FluentCrm\App\Services\Funnel\BaseTrigger;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\Framework\Support\Arr;

class DripMilestoneTrigger extends BaseTrigger
{
    public function __construct()
    {
        $this->triggerName = 'fchub_memberships/drip_milestone_reached';
        $this->priority = 20;
        $this->actionArgNum = 3;
        parent::__construct();
    }

    public function getTrigger()
    {
        return [
            'category'    => __('FCHub Memberships', 'fchub-memberships'),
            'label'       => __('Drip Milestone Reached', 'fchub-memberships'),
            'description' => __('This will start when a user reaches a drip content completion milestone (e.g. 25%, 50%, 75%, 100%)', 'fchub-memberships'),
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
            'title'     => __('Drip Milestone Reached', 'fchub-memberships'),
            'sub_title' => __('This will start when a user reaches a drip content completion milestone', 'fchub-memberships'),
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
            'plan_ids'               => [],
            'milestone_percentages'  => [25, 50, 75, 100],
            'run_multiple'           => 'yes',
        ];
    }

    public function getConditionFields($funnel)
    {
        return [
            'plan_ids'              => [
                'type'        => 'multi-select',
                'is_multiple' => true,
                'label'       => __('Target Plans', 'fchub-memberships'),
                'help'        => __('Select plans this automation applies to', 'fchub-memberships'),
                'placeholder' => __('All Plans', 'fchub-memberships'),
                'options'     => $this->getPlanOptions(),
                'inline_help' => __('Leave blank to trigger for any plan', 'fchub-memberships'),
            ],
            'milestone_percentages' => [
                'type'        => 'multi-select',
                'is_multiple' => true,
                'label'       => __('Milestone Percentages', 'fchub-memberships'),
                'help'        => __('Select which completion milestones should trigger', 'fchub-memberships'),
                'placeholder' => __('Select milestones', 'fchub-memberships'),
                'options'     => [
                    ['id' => 25,  'title' => __('25%', 'fchub-memberships')],
                    ['id' => 50,  'title' => __('50%', 'fchub-memberships')],
                    ['id' => 75,  'title' => __('75%', 'fchub-memberships')],
                    ['id' => 100, 'title' => __('100%', 'fchub-memberships')],
                ],
                'inline_help' => __('Trigger fires when drip progress reaches or passes each selected milestone', 'fchub-memberships'),
            ],
            'run_multiple'          => [
                'type'        => 'yes_no_check',
                'label'       => '',
                'check_label' => __('Restart automation for same contact on repeat events', 'fchub-memberships'),
                'inline_help' => __('Enable to allow multiple milestone triggers per contact', 'fchub-memberships'),
            ],
        ];
    }

    public function handle($funnel, $originalArgs)
    {
        // Hook signature: ($grant, $percentage, $userId)
        $grant = $originalArgs[0];
        $percentage = (int) $originalArgs[1];
        $userId = (int) $originalArgs[2];

        $planId = $grant['plan_id'] ?? 0;

        $user = get_user_by('ID', $userId);
        if (!$user) {
            return false;
        }

        if (!$this->isProcessable($funnel, $user, $planId, $percentage)) {
            return false;
        }

        $subscriberData = FunnelHelper::prepareUserData($user);
        $subscriberData = wp_parse_args($subscriberData, $funnel->settings);
        $subscriberData['status'] = $subscriberData['subscription_status'];
        unset($subscriberData['subscription_status']);

        (new FunnelProcessor())->startFunnelSequence($funnel, $subscriberData, [
            'source_trigger_name' => $this->triggerName,
            'source_ref_id'       => $grant['id'] ?? $planId,
        ]);
    }

    private function isProcessable($funnel, $user, $planId, $percentage)
    {
        $conditions = $funnel->conditions;

        // Plan filter
        if ($checkIds = Arr::get($conditions, 'plan_ids', [])) {
            if (!in_array($planId, $checkIds)) {
                return false;
            }
        }

        // Milestone percentage filter
        $allowedMilestones = Arr::get($conditions, 'milestone_percentages', []);
        if (!empty($allowedMilestones)) {
            $allowedMilestones = array_map('intval', $allowedMilestones);
            if (!in_array($percentage, $allowedMilestones, true)) {
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

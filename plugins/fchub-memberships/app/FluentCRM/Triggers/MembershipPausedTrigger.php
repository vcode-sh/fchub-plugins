<?php

namespace FChubMemberships\FluentCRM\Triggers;

defined('ABSPATH') || exit;

use FluentCrm\App\Services\Funnel\BaseTrigger;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\Framework\Support\Arr;

class MembershipPausedTrigger extends BaseTrigger
{
    public function __construct()
    {
        $this->triggerName = 'fchub_memberships/grant_paused';
        $this->priority = 20;
        $this->actionArgNum = 2;
        parent::__construct();
    }

    public function getTrigger()
    {
        return [
            'category'    => __('FCHub Memberships', 'fchub-memberships'),
            'label'       => __('Membership Paused', 'fchub-memberships'),
            'description' => __('This will start when a membership is paused', 'fchub-memberships'),
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
            'title'     => __('Membership Paused', 'fchub-memberships'),
            'sub_title' => __('This will start when a membership is paused', 'fchub-memberships'),
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
            'plan_ids'       => [],
            'pause_reasons'  => [],
            'run_multiple'   => 'no',
        ];
    }

    public function getConditionFields($funnel)
    {
        return [
            'plan_ids'      => [
                'type'        => 'multi-select',
                'is_multiple' => true,
                'label'       => __('Target Plans', 'fchub-memberships'),
                'help'        => __('Select plans this automation applies to', 'fchub-memberships'),
                'placeholder' => __('All Plans', 'fchub-memberships'),
                'options'     => $this->getPlanOptions(),
                'inline_help' => __('Leave blank to trigger for any plan', 'fchub-memberships'),
            ],
            'pause_reasons' => [
                'type'        => 'multi-select',
                'is_multiple' => true,
                'label'       => __('Pause Reasons', 'fchub-memberships'),
                'help'        => __('Only trigger for specific pause reasons', 'fchub-memberships'),
                'placeholder' => __('All Reasons', 'fchub-memberships'),
                'options'     => [
                    ['id' => 'subscription_cancelled', 'title' => __('Subscription Cancelled', 'fchub-memberships')],
                    ['id' => 'subscription_paused', 'title' => __('Subscription Paused', 'fchub-memberships')],
                    ['id' => 'payment_failed', 'title' => __('Payment Failed', 'fchub-memberships')],
                    ['id' => 'manual', 'title' => __('Manual / Admin', 'fchub-memberships')],
                ],
                'inline_help' => __('Leave blank to trigger for all reasons', 'fchub-memberships'),
            ],
            'run_multiple'  => [
                'type'        => 'yes_no_check',
                'label'       => '',
                'check_label' => __('Restart automation for same contact on repeat events', 'fchub-memberships'),
                'inline_help' => __('If enabled, the automation will restart for a contact even if they are already in this automation', 'fchub-memberships'),
            ],
        ];
    }

    public function handle($funnel, $originalArgs)
    {
        // Hook signature: ($grant, $reason)
        $grant = $originalArgs[0];
        $reason = $originalArgs[1] ?? '';

        $userId = $grant['user_id'] ?? 0;
        $planId = $grant['plan_id'] ?? 0;

        $user = get_user_by('ID', $userId);
        if (!$user) {
            return false;
        }

        if (!$this->isProcessable($funnel, $user, $planId, $reason)) {
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

    private function isProcessable($funnel, $user, $planId, $reason = '')
    {
        $conditions = $funnel->conditions;

        if ($checkIds = Arr::get($conditions, 'plan_ids', [])) {
            if (!in_array($planId, $checkIds)) {
                return false;
            }
        }

        // Pause reason filter
        $pauseReasons = Arr::get($conditions, 'pause_reasons', []);
        if (!empty($pauseReasons)) {
            $mappedReason = self::mapPauseReason($reason);
            if (!in_array($mappedReason, $pauseReasons, true)) {
                return false;
            }
        }

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

    /**
     * Map a raw pause reason string to a known category.
     */
    public static function mapPauseReason(string $reason): string
    {
        $lower = strtolower($reason);

        if (strpos($lower, 'cancel') !== false) {
            return 'subscription_cancelled';
        }

        if (strpos($lower, 'pause') !== false) {
            return 'subscription_paused';
        }

        if (strpos($lower, 'payment') !== false || strpos($lower, 'fail') !== false) {
            return 'payment_failed';
        }

        return 'manual';
    }

    private function getPlanOptions()
    {
        $planRepo = new \FChubMemberships\Storage\PlanRepository();
        $plans = $planRepo->all();
        return array_map(fn($p) => ['id' => $p['id'], 'title' => $p['title']], $plans);
    }
}

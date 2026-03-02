<?php

namespace FChubMemberships\FluentCRM\Triggers;

defined('ABSPATH') || exit;

use FluentCrm\App\Services\Funnel\BaseTrigger;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\Framework\Support\Arr;
use FChubMemberships\Storage\GrantRepository;

class MembershipAnniversaryTrigger extends BaseTrigger
{
    public function __construct()
    {
        $this->triggerName = 'fchub_memberships/grant_anniversary';
        $this->priority = 20;
        $this->actionArgNum = 2;
        parent::__construct();
    }

    public function getTrigger()
    {
        return [
            'category'    => __('FCHub Memberships', 'fchub-memberships'),
            'label'       => __('Membership Anniversary', 'fchub-memberships'),
            'description' => __('This will start when a membership reaches a milestone anniversary (e.g. 30, 90, 365 days)', 'fchub-memberships'),
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
            'title'     => __('Membership Anniversary', 'fchub-memberships'),
            'sub_title' => __('This will start when a membership reaches a milestone anniversary', 'fchub-memberships'),
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
            'plan_ids'        => [],
            'milestone_days'  => [30, 90, 365],
            'run_multiple'    => 'yes',
        ];
    }

    public function getConditionFields($funnel)
    {
        return [
            'plan_ids'       => [
                'type'        => 'multi-select',
                'is_multiple' => true,
                'label'       => __('Target Plans', 'fchub-memberships'),
                'help'        => __('Select plans this automation applies to', 'fchub-memberships'),
                'placeholder' => __('All Plans', 'fchub-memberships'),
                'options'     => $this->getPlanOptions(),
                'inline_help' => __('Leave blank to trigger for any plan', 'fchub-memberships'),
            ],
            'milestone_days' => [
                'type'        => 'multi-select',
                'is_multiple' => true,
                'label'       => __('Milestone Days', 'fchub-memberships'),
                'help'        => __('Select which day milestones should trigger', 'fchub-memberships'),
                'placeholder' => __('Select milestones', 'fchub-memberships'),
                'options'     => [
                    ['id' => 30,  'title' => __('30 days', 'fchub-memberships')],
                    ['id' => 60,  'title' => __('60 days', 'fchub-memberships')],
                    ['id' => 90,  'title' => __('90 days', 'fchub-memberships')],
                    ['id' => 180, 'title' => __('180 days', 'fchub-memberships')],
                    ['id' => 365, 'title' => __('365 days (1 year)', 'fchub-memberships')],
                    ['id' => 730, 'title' => __('730 days (2 years)', 'fchub-memberships')],
                ],
                'creatable'   => true,
                'inline_help' => __('You can also type a custom number of days', 'fchub-memberships'),
            ],
            'run_multiple'   => [
                'type'        => 'yes_no_check',
                'label'       => '',
                'check_label' => __('Restart automation for same contact on repeat events', 'fchub-memberships'),
                'inline_help' => __('Enable to allow multiple milestone triggers per contact', 'fchub-memberships'),
            ],
        ];
    }

    public function handle($funnel, $originalArgs)
    {
        // Hook signature: ($grant, $milestoneDays)
        $grant = $originalArgs[0];
        $milestoneDays = (int) $originalArgs[1];

        $userId = $grant['user_id'] ?? 0;
        $planId = $grant['plan_id'] ?? 0;

        $user = get_user_by('ID', $userId);
        if (!$user) {
            return false;
        }

        if (!$this->isProcessable($funnel, $user, $planId, $milestoneDays)) {
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

    private function isProcessable($funnel, $user, $planId, $milestoneDays)
    {
        $conditions = $funnel->conditions;

        // Plan filter
        if ($checkIds = Arr::get($conditions, 'plan_ids', [])) {
            if (!in_array($planId, $checkIds)) {
                return false;
            }
        }

        // Milestone days filter
        $allowedMilestones = Arr::get($conditions, 'milestone_days', []);
        if (!empty($allowedMilestones)) {
            $allowedMilestones = array_map('intval', $allowedMilestones);
            if (!in_array($milestoneDays, $allowedMilestones, true)) {
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

    /**
     * Check all active grants for anniversary milestones and fire hooks.
     * Called by the daily cron job.
     */
    public static function checkAnniversaries(): void
    {
        $grantRepo = new GrantRepository();

        global $wpdb;
        $table = $wpdb->prefix . 'fchub_membership_grants';

        $milestones = [30, 60, 90, 180, 365, 730];

        foreach ($milestones as $days) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE status = 'active'
                   AND DATEDIFF(NOW(), created_at) = %d",
                $days
            ), ARRAY_A);

            if (empty($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                $grant = self::hydrateGrant($row);
                $meta = $grant['meta'] ?? [];
                $firedMilestones = $meta['anniversary_milestones_fired'] ?? [];

                if (in_array($days, $firedMilestones, true)) {
                    continue;
                }

                do_action('fchub_memberships/grant_anniversary', $grant, $days);

                // Track fired milestone
                $firedMilestones[] = $days;
                $meta['anniversary_milestones_fired'] = $firedMilestones;
                $grantRepo->update($grant['id'], ['meta' => $meta]);
            }
        }
    }

    private static function hydrateGrant(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['user_id'] = (int) $row['user_id'];
        $row['plan_id'] = $row['plan_id'] !== null ? (int) $row['plan_id'] : null;
        $row['source_id'] = (int) $row['source_id'];
        $row['feed_id'] = $row['feed_id'] !== null ? (int) $row['feed_id'] : null;
        $row['renewal_count'] = (int) ($row['renewal_count'] ?? 0);
        $row['source_ids'] = json_decode($row['source_ids'] ?? '[]', true) ?: [];
        $row['meta'] = json_decode($row['meta'] ?? '{}', true) ?: [];
        return $row;
    }

    private function getPlanOptions()
    {
        $planRepo = new \FChubMemberships\Storage\PlanRepository();
        $plans = $planRepo->all();
        return array_map(fn($p) => ['id' => $p['id'], 'title' => $p['title']], $plans);
    }
}

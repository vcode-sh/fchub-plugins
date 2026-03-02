<?php

namespace FChubMemberships\FluentCRM\Benchmarks;

defined('ABSPATH') || exit;

use FluentCrm\App\Services\Funnel\BaseBenchMark;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\Framework\Support\Arr;
use FChubMemberships\FluentCRM\Helpers\MembershipFunnelHelper;

class TrialConvertedBenchmark extends BaseBenchMark
{
    public function __construct()
    {
        $this->triggerName = 'fchub_memberships/trial_converted';
        $this->actionArgNum = 3;
        $this->priority = 20;

        parent::__construct();
    }

    public function getBlock()
    {
        return [
            'title'       => __('Trial Converted to Paid', 'fchub-memberships'),
            'description' => __('Goal met when a trial membership converts to paid', 'fchub-memberships'),
            'icon'        => 'fc-icon-trigger',
            'settings'    => [
                'plan_ids'  => [],
                'type'      => 'optional',
                'can_enter' => 'yes',
            ],
        ];
    }

    public function getDefaultSettings()
    {
        return [
            'plan_ids'  => [],
            'type'      => 'optional',
            'can_enter' => 'yes',
        ];
    }

    public function getBlockFields($funnel)
    {
        return [
            'title'     => __('Trial Converted to Paid', 'fchub-memberships'),
            'sub_title' => __('Goal met when a trial membership converts to paid', 'fchub-memberships'),
            'fields'    => [
                'plan_ids'  => [
                    'type'        => 'multi-select',
                    'is_multiple' => true,
                    'label'       => __('Target Membership Plans', 'fchub-memberships'),
                    'placeholder' => __('Select Plans (blank = any)', 'fchub-memberships'),
                    'options'     => MembershipFunnelHelper::getPlanOptions(),
                ],
                'type'      => $this->benchmarkTypeField(),
                'can_enter' => $this->canEnterField(),
            ],
        ];
    }

    public function handle($benchMark, $originalArgs)
    {
        $grant  = $originalArgs[0];
        $planId = $originalArgs[1];
        $userId = $originalArgs[2];

        $user = get_user_by('ID', $userId);
        if (!$user) {
            return;
        }

        $subscriber = FunnelHelper::getSubscriber($user->user_email);
        if (!$subscriber) {
            return;
        }

        $benchmarkPlanIds = Arr::get($benchMark->settings, 'plan_ids', []);

        if (!MembershipFunnelHelper::matchesPlanCondition($planId, $benchmarkPlanIds)) {
            return;
        }

        (new FunnelProcessor())->startFunnelFromSequencePoint($benchMark, $subscriber);
    }

    public function assertCurrentGoalState($asserted, $benchmark, $funnelSubscriber)
    {
        if (!$funnelSubscriber || !$funnelSubscriber->subscriber) {
            return $asserted;
        }

        $userId = $funnelSubscriber->subscriber->user_id;
        if (!$userId) {
            return false;
        }

        $activeGrants = MembershipFunnelHelper::getUserActiveGrants($userId);
        if (empty($activeGrants)) {
            return false;
        }

        $benchmarkPlanIds = Arr::get($benchmark->settings, 'plan_ids', []);

        // Check for active grants that are no longer in trial (converted)
        foreach ($activeGrants as $grant) {
            $isNonTrial = empty($grant['trial_ends_at']);
            $planMatch  = MembershipFunnelHelper::matchesPlanCondition($grant['plan_id'], $benchmarkPlanIds);

            if ($isNonTrial && $planMatch) {
                return true;
            }
        }

        return false;
    }
}

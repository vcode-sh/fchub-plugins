<?php

namespace FChubMemberships\FluentCRM\Benchmarks;

defined('ABSPATH') || exit;

use FluentCrm\App\Services\Funnel\BaseBenchMark;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\Framework\Support\Arr;
use FChubMemberships\FluentCRM\Helpers\MembershipFunnelHelper;

class HasActiveMembershipBenchmark extends BaseBenchMark
{
    private ?int $currentUserId = null;

    public function __construct()
    {
        $this->triggerName = 'fchub_memberships/grant_created';
        $this->actionArgNum = 3;
        $this->priority = 20;

        parent::__construct();
    }

    public function getBlock()
    {
        return [
            'title'       => __('Has Active Membership', 'fchub-memberships'),
            'description' => __('Goal met when contact has an active membership plan', 'fchub-memberships'),
            'icon'        => 'fc-icon-trigger',
            'settings'    => [
                'plan_ids'    => [],
                'select_type' => 'any',
                'type'        => 'optional',
                'can_enter'   => 'yes',
            ],
        ];
    }

    public function getDefaultSettings()
    {
        return [
            'plan_ids'    => [],
            'select_type' => 'any',
            'type'        => 'optional',
            'can_enter'   => 'yes',
        ];
    }

    public function getBlockFields($funnel)
    {
        return [
            'title'     => __('Has Active Membership', 'fchub-memberships'),
            'sub_title' => __('Goal met when contact has an active membership plan', 'fchub-memberships'),
            'fields'    => [
                'plan_ids'    => [
                    'type'        => 'multi-select',
                    'is_multiple' => true,
                    'label'       => __('Target Membership Plans', 'fchub-memberships'),
                    'placeholder' => __('Select Plans (blank = any)', 'fchub-memberships'),
                    'options'     => MembershipFunnelHelper::getPlanOptions(),
                ],
                'select_type' => [
                    'label'   => __('Run When', 'fchub-memberships'),
                    'type'    => 'radio',
                    'options' => [
                        [
                            'id'    => 'any',
                            'title' => __('Contact has any of the selected plans', 'fchub-memberships'),
                        ],
                        [
                            'id'    => 'all',
                            'title' => __('Contact has all of the selected plans', 'fchub-memberships'),
                        ],
                    ],
                    'dependency' => [
                        'depends_on' => 'plan_ids',
                        'operator'   => '!=',
                        'value'      => [],
                    ],
                ],
                'type'        => $this->benchmarkTypeField(),
                'can_enter'   => $this->canEnterField(),
            ],
        ];
    }

    public function handle($benchMark, $originalArgs)
    {
        $userId = $originalArgs[0];
        $planId = $originalArgs[1];
        $this->currentUserId = (int) $userId;

        $user = get_user_by('ID', $userId);
        if (!$user) {
            return;
        }

        $subscriber = FunnelHelper::getSubscriber($user->user_email);
        if (!$subscriber) {
            return;
        }

        $settings = $benchMark->settings;
        $benchmarkPlanIds = Arr::get($settings, 'plan_ids', []);

        if (!$this->isPlanMatched($planId, $benchmarkPlanIds, $settings)) {
            return;
        }

        (new FunnelProcessor())->startFunnelFromSequencePoint($benchMark, $subscriber);
    }

    private function isPlanMatched($planId, $benchmarkPlanIds, $settings)
    {
        if (empty($benchmarkPlanIds)) {
            return true;
        }

        $benchmarkPlanIds = array_map('intval', $benchmarkPlanIds);
        $matchType = Arr::get($settings, 'select_type', 'any');

        if ($matchType === 'any') {
            return in_array($planId, $benchmarkPlanIds);
        }

        // For 'all' match, verify the user holds ALL required plans (not just the one just granted)
        $userId = $this->currentUserId;
        if (!$userId) {
            return false;
        }

        $activeGrants = MembershipFunnelHelper::getUserActiveGrants($userId);
        $userPlanIds = array_map('intval', array_column($activeGrants, 'plan_id'));
        $intersection = array_intersect($benchmarkPlanIds, $userPlanIds);

        return count($intersection) === count($benchmarkPlanIds);
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
        if (empty($benchmarkPlanIds)) {
            return true;
        }

        $userPlanIds = array_column($activeGrants, 'plan_id');
        $benchmarkPlanIds = array_map('intval', $benchmarkPlanIds);
        $intersection = array_intersect($benchmarkPlanIds, $userPlanIds);

        $matchType = Arr::get($benchmark->settings, 'select_type', 'any');

        if ($matchType === 'any') {
            return !empty($intersection);
        }

        return count($intersection) === count($benchmarkPlanIds);
    }
}

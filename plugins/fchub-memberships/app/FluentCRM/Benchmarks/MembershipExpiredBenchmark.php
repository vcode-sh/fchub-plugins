<?php

namespace FChubMemberships\FluentCRM\Benchmarks;

defined('ABSPATH') || exit;

use FluentCrm\App\Services\Funnel\BaseBenchMark;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\Framework\Support\Arr;
use FChubMemberships\FluentCRM\Helpers\MembershipFunnelHelper;
use FChubMemberships\Storage\GrantRepository;

class MembershipExpiredBenchmark extends BaseBenchMark
{
    public function __construct()
    {
        $this->triggerName = 'fchub_memberships/grant_expired';
        $this->actionArgNum = 1;
        $this->priority = 20;

        parent::__construct();
    }

    public function getBlock()
    {
        return [
            'title'       => __('Membership Expired', 'fchub-memberships'),
            'description' => __('Goal met when a membership plan expires', 'fchub-memberships'),
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
            'title'     => __('Membership Expired', 'fchub-memberships'),
            'sub_title' => __('Goal met when a membership plan expires', 'fchub-memberships'),
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
        $grant = $originalArgs[0];
        if (empty($grant['user_id'])) {
            return;
        }

        $user = get_user_by('ID', $grant['user_id']);
        if (!$user) {
            return;
        }

        $subscriber = FunnelHelper::getSubscriber($user->user_email);
        if (!$subscriber) {
            return;
        }

        $benchmarkPlanIds = Arr::get($benchMark->settings, 'plan_ids', []);
        $planId = $grant['plan_id'] ?? 0;

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

        $grantRepo = new GrantRepository();
        $expiredGrants = $grantRepo->getByUserId($userId, ['status' => 'expired']);
        if (empty($expiredGrants)) {
            return false;
        }

        $benchmarkPlanIds = Arr::get($benchmark->settings, 'plan_ids', []);
        if (empty($benchmarkPlanIds)) {
            return true;
        }

        $benchmarkPlanIds = array_map('intval', $benchmarkPlanIds);
        foreach ($expiredGrants as $grant) {
            if (in_array($grant['plan_id'], $benchmarkPlanIds, true)) {
                return true;
            }
        }

        return false;
    }
}

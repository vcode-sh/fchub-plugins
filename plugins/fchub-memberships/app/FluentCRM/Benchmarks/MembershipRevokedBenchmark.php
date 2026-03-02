<?php

namespace FChubMemberships\FluentCRM\Benchmarks;

defined('ABSPATH') || exit;

use FluentCrm\App\Services\Funnel\BaseBenchMark;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\Framework\Support\Arr;
use FChubMemberships\FluentCRM\Helpers\MembershipFunnelHelper;
use FChubMemberships\Storage\GrantRepository;

class MembershipRevokedBenchmark extends BaseBenchMark
{
    public function __construct()
    {
        $this->triggerName = 'fchub_memberships/grant_revoked';
        $this->actionArgNum = 4;
        $this->priority = 20;

        parent::__construct();
    }

    public function getBlock()
    {
        return [
            'title'       => __('Membership Revoked', 'fchub-memberships'),
            'description' => __('Goal met when a membership plan is revoked', 'fchub-memberships'),
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
            'title'     => __('Membership Revoked', 'fchub-memberships'),
            'sub_title' => __('Goal met when a membership plan is revoked', 'fchub-memberships'),
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
        // Hook signature: ($grants, $planId, $userId, $reason)
        $grants = $originalArgs[0];
        $planId = (int) $originalArgs[1];
        $userId = (int) $originalArgs[2];

        if (!$userId) {
            return;
        }

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

        $grantRepo = new GrantRepository();
        $revokedGrants = $grantRepo->getByUserId($userId, ['status' => 'revoked']);
        if (empty($revokedGrants)) {
            return false;
        }

        $benchmarkPlanIds = Arr::get($benchmark->settings, 'plan_ids', []);
        if (empty($benchmarkPlanIds)) {
            return true;
        }

        $benchmarkPlanIds = array_map('intval', $benchmarkPlanIds);
        foreach ($revokedGrants as $grant) {
            if (in_array($grant['plan_id'], $benchmarkPlanIds, true)) {
                return true;
            }
        }

        return false;
    }
}

<?php

namespace FChubMemberships\FluentCRM\Benchmarks;

defined('ABSPATH') || exit;

use FluentCrm\App\Services\Funnel\BaseBenchMark;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\Framework\Support\Arr;
use FChubMemberships\FluentCRM\Helpers\MembershipFunnelHelper;
use FChubMemberships\Storage\GrantRepository;

class PaymentRecoveredBenchmark extends BaseBenchMark
{
    public function __construct()
    {
        $this->triggerName = 'fchub_memberships/grant_renewed';
        $this->actionArgNum = 2;
        $this->priority = 20;

        parent::__construct();
    }

    public function getBlock()
    {
        return [
            'title'       => __('Payment Recovered', 'fchub-memberships'),
            'description' => __('Goal met when a failed payment is recovered (subscription renewed after failure)', 'fchub-memberships'),
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
            'title'     => __('Payment Recovered', 'fchub-memberships'),
            'sub_title' => __('Goal met when a failed payment is recovered', 'fchub-memberships'),
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
        // Hook signature: ($grant, $renewalCount)
        $grant = $originalArgs[0];

        if (empty($grant['user_id'])) {
            return;
        }

        $userId = (int) $grant['user_id'];
        $planId = $grant['plan_id'] ?? 0;

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

        // Verify the linked subscription is now active (not still failing)
        if (!$this->isSubscriptionActive($grant)) {
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
        $activeGrants = $grantRepo->getByUserId($userId, ['status' => 'active']);
        if (empty($activeGrants)) {
            return false;
        }

        $benchmarkPlanIds = Arr::get($benchmark->settings, 'plan_ids', []);

        // Check that at least one active grant matches plan filter
        // AND its linked subscription is active (not failing/past_due)
        foreach ($activeGrants as $grant) {
            if (!MembershipFunnelHelper::matchesPlanCondition($grant['plan_id'], $benchmarkPlanIds)) {
                continue;
            }

            if ($grant['source_type'] === 'subscription' && $grant['source_id']) {
                if ($this->isSubscriptionActive($grant)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if the subscription linked to a grant is in an active (non-failing) state.
     */
    private function isSubscriptionActive(array $grant): bool
    {
        if ($grant['source_type'] !== 'subscription' || empty($grant['source_id'])) {
            return true; // Non-subscription grants are considered recovered
        }

        if (!class_exists('\FluentCart\App\Models\Subscription')) {
            return true;
        }

        $subscription = \FluentCart\App\Models\Subscription::find($grant['source_id']);
        if (!$subscription) {
            return false;
        }

        $failingStatuses = ['failing', 'past_due', 'expiring'];
        return !in_array($subscription->status, $failingStatuses, true);
    }
}

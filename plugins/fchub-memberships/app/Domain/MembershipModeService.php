<?php

namespace FChubMemberships\Domain;

use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Support\Logger;

defined('ABSPATH') || exit;

final class MembershipModeService
{
    private GrantRepository $grants;
    private PlanRepository $plans;

    public function __construct(?GrantRepository $grants = null, ?PlanRepository $plans = null)
    {
        $this->grants = $grants ?? new GrantRepository();
        $this->plans = $plans ?? new PlanRepository();
    }

    /**
     * @param callable(int, int, array): array $revokePlan
     */
    public function enforce(int $userId, int $planId, ?array $plan, array $context, callable $revokePlan): ?array
    {
        $settings = get_option('fchub_memberships_settings', []);
        $mode = $settings['membership_mode'] ?? 'stack';

        if ($mode === 'stack') {
            return null;
        }

        $activePlanIds = $this->grants->getUserActivePlanIds($userId);
        $otherPlanIds = array_values(array_filter($activePlanIds, static fn(int $id): bool => $id !== $planId));

        if (empty($otherPlanIds)) {
            return null;
        }

        if ($mode === 'exclusive') {
            foreach ($otherPlanIds as $oldPlanId) {
                $revokePlan($userId, $oldPlanId, [
                    'reason' => sprintf('Replaced by plan #%d (exclusive mode)', $planId),
                ]);
            }

            do_action('fchub_memberships/plan_replaced', $userId, $planId, $otherPlanIds);

            return null;
        }

        if ($mode !== 'upgrade_only') {
            return null;
        }

        $planLevel = (int) ($plan['level'] ?? 0);
        $currentHighest = $this->grants->getHighestActivePlanLevel($userId);
        $order = $context['order'] ?? null;

        if ($planLevel < $currentHighest) {
            Logger::log('Downgrade blocked', sprintf(
                'User #%d attempted plan #%d (level %d) but has active plan at level %d',
                $userId,
                $planId,
                $planLevel,
                $currentHighest
            ));

            if ($order) {
                Logger::orderLog(
                    $order,
                    __('Membership grant blocked', 'fchub-memberships'),
                    sprintf(
                        __('Plan level %d is lower than current level %d. Upgrade-only mode prevents downgrades.', 'fchub-memberships'),
                        $planLevel,
                        $currentHighest
                    ),
                    'warning'
                );
            }

            return [
                'created' => 0,
                'updated' => 0,
                'total'   => 0,
                'blocked' => true,
                'reason'  => 'downgrade_blocked',
            ];
        }

        $revokedPlanIds = [];
        foreach ($otherPlanIds as $oldPlanId) {
            $oldPlan = $this->plans->find($oldPlanId);
            if ($oldPlan && (int) ($oldPlan['level'] ?? 0) < $planLevel) {
                $revokePlan($userId, $oldPlanId, [
                    'reason' => sprintf('Upgraded to plan #%d level %d (upgrade_only mode)', $planId, $planLevel),
                ]);
                $revokedPlanIds[] = $oldPlanId;
            }
        }

        if (!empty($revokedPlanIds)) {
            do_action('fchub_memberships/plan_upgraded', $userId, $planId, $revokedPlanIds);
        }

        return null;
    }
}

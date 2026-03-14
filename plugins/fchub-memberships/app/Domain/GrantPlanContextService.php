<?php

namespace FChubMemberships\Domain;

use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\PlanRepository;

defined('ABSPATH') || exit;

final class GrantPlanContextService
{
    private PlanRepository $plans;
    private GrantRepository $grants;

    public function __construct(?PlanRepository $plans = null, ?GrantRepository $grants = null)
    {
        $this->plans = $plans ?? new PlanRepository();
        $this->grants = $grants ?? new GrantRepository();
    }

    /**
     * @return array{plan:?array, context:array}
     */
    public function resolve(int $userId, int $planId, array $context): array
    {
        $plan = $this->plans->find($planId);

        if ($plan && ($plan['trial_days'] ?? 0) > 0) {
            $existingGrants = $this->grants->getByUserId($userId, ['plan_id' => $planId]);
            $hasActiveOrPaused = array_filter(
                $existingGrants,
                static fn(array $grant): bool => in_array($grant['status'], ['active', 'paused'], true)
            );

            if (empty($hasActiveOrPaused)) {
                $context['is_trial'] = true;
                $context['trial_ends_at'] = date('Y-m-d H:i:s', strtotime('+' . (int) $plan['trial_days'] . ' days'));
            }
        }

        if ($plan && empty($context['expires_at'])) {
            $durationType = $plan['duration_type'] ?? 'lifetime';

            if ($durationType === 'fixed_days' && ($plan['duration_days'] ?? 0) > 0) {
                $context['expires_at'] = date('Y-m-d H:i:s', strtotime('+' . (int) $plan['duration_days'] . ' days'));
            } elseif ($durationType === 'lifetime') {
                $context['expires_at'] = null;
            }
        }

        return [
            'plan' => $plan,
            'context' => $context,
        ];
    }
}

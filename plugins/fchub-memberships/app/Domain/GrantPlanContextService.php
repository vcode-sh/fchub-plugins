<?php

namespace FChubMemberships\Domain;

use FChubMemberships\Domain\Grant\AnchorDateCalculator;
use FChubMemberships\Domain\Grant\MembershipTermCalculator;
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
            } elseif ($durationType === 'fixed_anchor') {
                $planMeta = $plan['meta'] ?? [];
                $anchorDay = (int) ($planMeta['billing_anchor_day'] ?? 1);
                $context['expires_at'] = AnchorDateCalculator::nextAnchorDate($anchorDay, current_time('mysql'));
                $context['meta'] = array_merge($context['meta'] ?? [], [
                    'billing_anchor_day' => $anchorDay,
                ]);
            } elseif ($durationType === 'lifetime') {
                $context['expires_at'] = null;
            }
        }

        // Apply membership term cap (universal, runs after all duration type logic).
        // Skip if feed-level override already set a term end date.
        if ($plan && empty($context['meta']['membership_term_ends_at'])) {
            $termConfig = $plan['meta']['membership_term'] ?? null;
            if ($termConfig && ($termConfig['mode'] ?? 'none') !== 'none') {
                $termEndsAt = MembershipTermCalculator::calculateEndDate($termConfig, current_time('mysql'));
                if ($termEndsAt) {
                    $context['meta'] = array_merge($context['meta'] ?? [], [
                        'membership_term_ends_at' => $termEndsAt,
                    ]);

                    $currentExpiry = $context['expires_at'] ?? null;
                    if ($currentExpiry === null) {
                        // Lifetime / subscription_mirror with no expiry gets one from the term
                        $context['expires_at'] = $termEndsAt;
                    } else {
                        // Cap existing expiry at term end
                        $context['expires_at'] = MembershipTermCalculator::capExpiry(
                            $currentExpiry,
                            $termEndsAt
                        );
                    }
                }
            }
        }

        return [
            'plan' => $plan,
            'context' => $context,
        ];
    }
}

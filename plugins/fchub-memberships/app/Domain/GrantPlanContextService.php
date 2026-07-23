<?php

namespace FChubMemberships\Domain;

use FChubMemberships\Domain\Grant\AnchorDateCalculator;
use FChubMemberships\Domain\Grant\MembershipTermCalculator;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Support\Clock;

defined('ABSPATH') || exit;

final class GrantPlanContextService
{
    private PlanRepository $plans;
    private GrantRepository $grants;
    private Clock $clock;

    public function __construct(?PlanRepository $plans = null, ?GrantRepository $grants = null, ?Clock $clock = null)
    {
        $this->plans = $plans ?? new PlanRepository();
        $this->grants = $grants ?? new GrantRepository();
        $this->clock = $clock ?? new Clock();
    }

    /**
     * @return array{plan:?array, context:array}
     */
    public function resolve(int $userId, int $planId, array $context): array
    {
        $plan = $this->plans->find($planId);
        $preserveExpiry = !empty($context['preserve_expiry']);
        $now = $this->clock->now();
        $nowStorage = $this->clock->storage($now);

        if ($plan && ($plan['trial_days'] ?? 0) > 0) {
            $existingGrants = $this->grants->getByUserId($userId, ['plan_id' => $planId]);
            $hasActiveOrPaused = array_filter(
                $existingGrants,
                static fn(array $grant): bool => in_array($grant['status'], ['active', 'paused'], true)
            );

            if (empty($hasActiveOrPaused)) {
                $context['is_trial'] = true;
                $context['trial_ends_at'] = $this->clock->storage(
                    $this->clock->plusDays((int) $plan['trial_days'], $now)
                );
            }
        }

        if ($plan && !$preserveExpiry && empty($context['expires_at'])) {
            $durationType = $plan['duration_type'] ?? 'lifetime';

            if ($durationType === 'fixed_days' && ($plan['duration_days'] ?? 0) > 0) {
                $context['expires_at'] = $this->clock->storage(
                    $this->clock->plusDays((int) $plan['duration_days'], $now)
                );
            } elseif ($durationType === 'fixed_anchor') {
                $planMeta = $plan['meta'] ?? [];
                $anchorDay = (int) ($planMeta['billing_anchor_day'] ?? 1);
                $context['expires_at'] = AnchorDateCalculator::nextAnchorDate($anchorDay, $nowStorage, $this->clock);
                $context['meta'] = array_merge($context['meta'] ?? [], [
                    'billing_anchor_day' => $anchorDay,
                ]);
            } elseif ($durationType === 'lifetime') {
                $context['expires_at'] = null;
            }
        }

        // Apply membership term cap (universal, runs after all duration type logic).
        // Skip if feed-level override already set a term end date.
        if ($plan && !$preserveExpiry && empty($context['meta']['membership_term_ends_at'])) {
            $termConfig = $plan['meta']['membership_term'] ?? null;
            if ($termConfig && ($termConfig['mode'] ?? 'none') !== 'none') {
                $termEndsAt = MembershipTermCalculator::calculateEndDate($termConfig, $nowStorage, $this->clock);
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
                            $termEndsAt,
                            $this->clock
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

<?php

namespace FChubMemberships\Domain;

use FChubMemberships\Domain\Grant\AnchorDateCalculator;
use FChubMemberships\Domain\Grant\MembershipTermCalculator;
use FChubMemberships\Storage\GrantRepository;

defined('ABSPATH') || exit;

final class SubscriptionGrantLifecycleService
{
    private AccessGrantService $grants;
    private GrantRepository $grantRepo;

    public function __construct(?AccessGrantService $grants = null, ?GrantRepository $grantRepo = null)
    {
        $this->grants = $grants ?? new AccessGrantService();
        $this->grantRepo = $grantRepo ?? new GrantRepository();
    }

    public function pause(object $subscription): void
    {
        $grants = $this->grantRepo->getBySourceId((int) $subscription->id, 'subscription');
        foreach ($grants as $grant) {
            if ($grant['status'] === 'active') {
                $this->grants->pauseGrant($grant['id'], 'Subscription paused');
            }
        }
    }

    public function resume(object $subscription): void
    {
        $grants = $this->grantRepo->getBySourceId((int) $subscription->id, 'subscription');
        foreach ($grants as $grant) {
            if ($grant['status'] === 'paused') {
                // Don't resume if the membership term expired during the pause
                if (MembershipTermCalculator::isTermExpired($grant['meta'])) {
                    continue;
                }
                $this->grants->resumeGrant($grant['id']);
            }
        }
    }

    public function cancel(object $subscription): void
    {
        $grants = $this->grantRepo->getBySourceId((int) $subscription->id, 'subscription');
        $planIds = array_unique(array_column($grants, 'plan_id'));

        foreach ($planIds as $planId) {
            $planGrants = array_filter($grants, static fn(array $grant): bool => $grant['plan_id'] == $planId);
            if (empty($planGrants)) {
                continue;
            }

            $userId = (int) reset($planGrants)['user_id'];
            $hasActiveOrPaused = array_filter(
                $planGrants,
                static fn(array $grant): bool => in_array($grant['status'], ['active', 'paused'], true)
            );

            if (empty($hasActiveOrPaused)) {
                continue;
            }

            $this->grants->revokePlan($userId, (int) $planId, [
                'source_id' => (int) $subscription->id,
                'reason'    => 'Subscription cancelled',
            ]);
        }
    }

    public function renew(object $subscription): void
    {
        $grants = $this->grantRepo->getBySourceId((int) $subscription->id, 'subscription');
        $nextBilling = $subscription->next_billing_date ?? null;

        foreach ($grants as $grant) {
            // Skip grants whose membership term has already expired
            if (MembershipTermCalculator::isTermExpired($grant['meta'])) {
                continue;
            }

            $termEndsAt = $grant['meta']['membership_term_ends_at'] ?? null;
            $anchorDay = $grant['meta']['billing_anchor_day'] ?? null;

            if ($anchorDay) {
                $anchorDay = (int) $anchorDay;

                if ($grant['status'] === 'paused') {
                    // Late payment: resume access, then extend to next anchor
                    $this->grants->resumeGrant($grant['id']);
                    $newExpiry = AnchorDateCalculator::nextAnchorDate($anchorDay, current_time('mysql'));
                    $newExpiry = MembershipTermCalculator::capExpiry($newExpiry, $termEndsAt);
                    $this->grants->extendExpiry((int) $grant['user_id'], (int) $grant['plan_id'], $newExpiry, (int) $subscription->id);
                } elseif ($grant['status'] === 'active') {
                    // On-time renewal: extend to the following month's anchor
                    $currentExpiry = $grant['expires_at'] ?? current_time('mysql');
                    $newExpiry = AnchorDateCalculator::nextAnchorAfter($anchorDay, $currentExpiry);
                    $newExpiry = MembershipTermCalculator::capExpiry($newExpiry, $termEndsAt);
                    $this->grants->extendExpiry((int) $grant['user_id'], (int) $grant['plan_id'], $newExpiry, (int) $subscription->id);
                }
            } else {
                // Non-anchor: existing mirror behaviour
                if ($grant['status'] === 'active' && $nextBilling) {
                    $capped = MembershipTermCalculator::capExpiry((string) $nextBilling, $termEndsAt);
                    $this->grants->extendExpiry((int) $grant['user_id'], (int) $grant['plan_id'], $capped, (int) $subscription->id);
                }
            }
        }
    }
}

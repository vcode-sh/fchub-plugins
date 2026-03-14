<?php

namespace FChubMemberships\Domain;

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
        $nextBilling = $subscription->next_billing_at ?? null;

        foreach ($grants as $grant) {
            if ($grant['status'] === 'active' && $nextBilling) {
                $this->grants->extendExpiry((int) $grant['user_id'], (int) $grant['plan_id'], (string) $nextBilling, (int) $subscription->id);
            }
        }
    }
}

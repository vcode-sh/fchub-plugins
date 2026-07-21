<?php

declare(strict_types=1);

namespace FChubMemberships\Domain\MemberPortal;

defined('ABSPATH') || exit;

use Closure;
use FChubMemberships\Domain\AccessEvaluator;
use FChubMemberships\Domain\Drip\DripEvaluator;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\PlanRepository;

final class MembershipAccountQuery
{
    private Closure $grantsForUser;
    private Closure $planById;
    private Closure $progressForPlan;
    private Closure $timelineForPlan;
    private MembershipAccountProjector $projector;
    private MembershipCommerceResolver $commerce;

    public function __construct(
        ?callable $grantsForUser = null,
        ?callable $planById = null,
        ?callable $progressForPlan = null,
        ?callable $timelineForPlan = null,
        ?MembershipAccountProjector $projector = null,
        ?MembershipCommerceResolver $commerce = null
    ) {
        $this->grantsForUser = Closure::fromCallable(
            $grantsForUser ?? static fn(int $userId): array => (new GrantRepository())->getByUserId($userId)
        );
        $this->planById = Closure::fromCallable(
            $planById ?? static fn(int $planId): ?array => (new PlanRepository())->find($planId)
        );
        $this->progressForPlan = Closure::fromCallable(
            $progressForPlan ?? static fn(int $userId, int $planId): array => (new AccessEvaluator())->getDripProgress($userId, $planId)
        );
        $this->timelineForPlan = Closure::fromCallable(
            $timelineForPlan ?? static fn(int $userId, int $planId): array => (new DripEvaluator())->getTimeline($userId, $planId)
        );
        $this->projector = $projector ?? new MembershipAccountProjector();
        $this->commerce = $commerce ?? new MembershipCommerceResolver();
    }

    /**
     * @return array{plans: array<int, array>, history: array<int, array>}
     */
    public function get(int $userId): array
    {
        $projection = $this->projector->project(($this->grantsForUser)($userId));

        return [
            'plans' => array_map(
                fn(array $episode): array => $this->buildCurrentMembership($userId, $episode),
                $projection['current']
            ),
            'history' => array_map(
                fn(array $episode): array => $this->buildHistoryMembership($episode),
                $projection['history']
            ),
        ];
    }

    private function buildCurrentMembership(int $userId, array $episode): array
    {
        $planId = (int) ($episode['plan_id'] ?? 0);
        $plan = $planId > 0 ? ($this->planById)($planId) : null;
        $commerce = $this->commerce->resolve($episode);
        $sourceType = (string) $episode['source_type'];

        return [
            'membership_key' => $this->membershipKey($episode),
            'plan_id' => $episode['plan_id'],
            'plan_title' => $plan ? (string) $plan['title'] : __('Direct Access', 'fchub-memberships'),
            'plan_slug' => $plan['slug'] ?? null,
            'description' => $plan['description'] ?? '',
            'status' => $episode['status'],
            'source_type' => $sourceType,
            'source_id' => $episode['source_id'],
            'expires_at' => $episode['expires_at'],
            'access_date_kind' => $episode['access_date_kind'],
            'is_lifetime' => $episode['access_date_kind'] === 'lifetime' && $sourceType !== 'subscription',
            'next_billing_date' => $commerce['next_billing_date'],
            'cancellation_effective_at' => $episode['cancellation_effective_at'],
            'resource_count' => $episode['resource_count'],
            'grant_count' => $episode['resource_count'],
            'progress' => $planId > 0 ? ($this->progressForPlan)($userId, $planId) : null,
            'timeline' => $planId > 0 ? ($this->timelineForPlan)($userId, $planId) : [],
            'action' => $commerce['action'],
        ];
    }

    private function buildHistoryMembership(array $episode): array
    {
        $planId = (int) ($episode['plan_id'] ?? 0);
        $plan = $planId > 0 ? ($this->planById)($planId) : null;
        $commerce = $this->commerce->resolve($episode);

        return [
            'membership_key' => $this->membershipKey($episode),
            'plan_id' => $episode['plan_id'],
            'plan_title' => $plan ? (string) $plan['title'] : __('Direct Access', 'fchub-memberships'),
            'status' => $episode['status'],
            'source_type' => $episode['source_type'],
            'source_id' => $episode['source_id'],
            'resource_count' => $episode['resource_count'],
            'starts_at' => $episode['starts_at'],
            'expires_at' => $episode['expires_at'],
            'access_date_kind' => $episode['access_date_kind'],
            'updated_at' => $episode['updated_at'],
            'action' => $commerce['action'],
        ];
    }

    private function membershipKey(array $episode): string
    {
        return implode(':', [
            $episode['plan_id'] ?? 0,
            $episode['source_type'] ?? 'manual',
            $episode['source_id'] ?? 0,
        ]);
    }
}

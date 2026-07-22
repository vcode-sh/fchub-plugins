<?php

declare(strict_types=1);

namespace FChubMemberships\FluentCRM\Actions;

defined('ABSPATH') || exit;

use FChubMemberships\Domain\AccessGrantService;
use FChubMemberships\FluentCRM\Actions\Contracts\MembershipActionRuntimeInterface;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\PlanRepository;

final class MembershipActionRuntime implements MembershipActionRuntimeInterface
{
    private GrantRepository $grants;

    private AccessGrantService $access;

    private PlanRepository $plans;

    public function __construct(
        ?GrantRepository $grants = null,
        ?AccessGrantService $access = null,
        ?PlanRepository $plans = null
    ) {
        $this->grants = $grants ?? new GrantRepository();
        $this->access = $access ?? new AccessGrantService();
        $this->plans = $plans ?? new PlanRepository();
    }

    public function planExists(int $planId): bool
    {
        return $planId > 0 && $this->plans->find($planId) !== null;
    }

    public function getActiveGrants(int $userId, ?int $planId): array
    {
        $filters = ['status' => 'active'];
        if ($planId !== null) {
            $filters['plan_id'] = $planId;
        }

        return $this->grants->getByUserId($userId, $filters);
    }

    public function getPausedGrants(int $userId, ?int $planId): array
    {
        if ($planId === null) {
            return $this->grants->getPausedGrants($userId);
        }

        return $this->grants->getByUserId($userId, [
            'status' => 'paused',
            'plan_id' => $planId,
        ]);
    }

    public function revokePlan(int $userId, int $planId, array $context): array
    {
        return $this->access->revokePlan($userId, $planId, $context);
    }

    public function grantPlan(int $userId, int $planId, array $context): array
    {
        return $this->access->grantPlan($userId, $planId, $context);
    }

    public function pauseGrant(int $grantId, string $reason): array
    {
        return $this->access->pauseGrant($grantId, $reason);
    }

    public function resumeGrant(int $grantId): array
    {
        return $this->access->resumeGrant($grantId);
    }

    public function extendExpiry(int $userId, int $planId, string $newExpiresAt): int
    {
        return $this->access->extendExpiry($userId, $planId, $newExpiresAt);
    }
}

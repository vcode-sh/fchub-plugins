<?php

namespace FChubMemberships\Domain;

use FChubMemberships\Domain\Grant\GrantCreationService;
use FChubMemberships\Domain\Grant\GrantLockService;
use FChubMemberships\Domain\Grant\GrantMaintenanceService;
use FChubMemberships\Domain\Grant\GrantRevocationService;
use FChubMemberships\Domain\Grant\GrantStatusService;
use FChubMemberships\Domain\Grant\PlanGrantExecutionService;
use FChubMemberships\Domain\Plan\PlanRuleResolver;
use FChubMemberships\Storage\DripScheduleRepository;
use FChubMemberships\Storage\EventLockRepository;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\GrantSourceRepository;
use FChubMemberships\Storage\PlanRepository;

defined('ABSPATH') || exit;

class AccessGrantService
{
    private PlanGrantExecutionService $planGrant;
    private GrantCreationService $creation;
    private GrantRevocationService $revocation;
    private GrantStatusService $status;
    private GrantMaintenanceService $maintenance;
    private GrantLockService $locks;

    public function __construct(
        ?GrantRepository $grantRepo = null,
        ?GrantSourceRepository $sourceRepo = null,
        ?PlanRuleResolver $ruleResolver = null,
        ?DripScheduleRepository $dripRepo = null,
        ?EventLockRepository $lockRepo = null,
        ?GrantNotificationService $notifications = null,
        ?GrantAdapterRegistry $adapters = null,
        ?MembershipModeService $membershipModes = null,
        ?GrantPlanContextService $planContext = null
    ) {
        $grantRepo = $grantRepo ?? new GrantRepository();
        $sourceRepo = $sourceRepo ?? new GrantSourceRepository();
        $ruleResolver = $ruleResolver ?? new PlanRuleResolver();
        $dripRepo = $dripRepo ?? new DripScheduleRepository();
        $lockRepo = $lockRepo ?? new EventLockRepository();
        $notifications = $notifications ?? new GrantNotificationService();
        $adapters = $adapters ?? new GrantAdapterRegistry();
        $membershipModes = $membershipModes ?? new MembershipModeService($grantRepo);
        $planContext = $planContext ?? new GrantPlanContextService(new PlanRepository(), $grantRepo);

        $this->creation = new GrantCreationService($grantRepo, $sourceRepo, $dripRepo, $adapters);
        $this->revocation = new GrantRevocationService($grantRepo, $sourceRepo, $dripRepo, $adapters, $notifications);
        $this->status = new GrantStatusService($grantRepo, $notifications);
        $this->maintenance = new GrantMaintenanceService($grantRepo, $sourceRepo);
        $this->locks = new GrantLockService($lockRepo);
        $this->planGrant = new PlanGrantExecutionService(
            $ruleResolver,
            $membershipModes,
            $planContext,
            $this->creation,
            $this->revocation,
            $notifications
        );
    }

    public function grantPlan(int $userId, int $planId, array $context = []): array
    {
        return $this->planGrant->grantPlan($userId, $planId, $context);
    }

    public function grantResource(int $userId, string $provider, string $resourceType, string $resourceId, array $context = []): array
    {
        return $this->creation->grantResource($userId, $provider, $resourceType, $resourceId, $context);
    }

    public function revokePlan(int $userId, int $planId, array $context = []): array
    {
        return $this->revocation->revokePlan($userId, $planId, $context);
    }

    public function revokeBySource(int $sourceId, string $sourceType = 'order', array $context = []): array
    {
        return $this->revocation->revokeBySource($sourceId, $sourceType, $context);
    }

    public function extendExpiry(int $userId, int $planId, string $newExpiresAt, ?int $renewalSourceId = null): int
    {
        return $this->maintenance->extendExpiry($userId, $planId, $newExpiresAt, $renewalSourceId);
    }

    public function manualGrant(int $userId, int $planId, ?string $expiresAt = null): array
    {
        return $this->grantPlan($userId, $planId, [
            'source_type' => 'manual',
            'source_id' => 0,
            'expires_at' => $expiresAt,
        ]);
    }

    public function acquireEventLock(int $orderId, int $feedId, string $trigger, ?int $subscriptionId = null): bool
    {
        return $this->locks->acquireEventLock($orderId, $feedId, $trigger, $subscriptionId);
    }

    public function pauseGrant(int $grantId, string $reason = ''): array
    {
        return $this->status->pauseGrant($grantId, $reason);
    }

    public function resumeGrant(int $grantId): array
    {
        return $this->status->resumeGrant($grantId);
    }

    public function bulkGrant(array $userIds, int $planId, array $context = []): array
    {
        $results = ['granted' => 0, 'failed' => 0, 'errors' => []];
        foreach ($userIds as $userId) {
            try {
                $this->grantPlan((int) $userId, $planId, $context);
                $results['granted']++;
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = sprintf('User #%d: %s', $userId, $e->getMessage());
            }
        }

        return $results;
    }

    public function bulkRevoke(array $userIds, int $planId, array $context = []): array
    {
        $results = ['revoked' => 0, 'failed' => 0, 'errors' => []];
        foreach ($userIds as $userId) {
            try {
                $this->revokePlan((int) $userId, $planId, $context);
                $results['revoked']++;
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = sprintf('User #%d: %s', $userId, $e->getMessage());
            }
        }

        return $results;
    }

    public function expireOverdueGrantsWithHooks(): int
    {
        return $this->maintenance->expireOverdueGrantsWithHooks();
    }

    public function revokeExpiredGracePeriodGrants(): int
    {
        return $this->revocation->revokeExpiredGracePeriodGrants();
    }
}

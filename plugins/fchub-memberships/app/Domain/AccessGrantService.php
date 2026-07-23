<?php

namespace FChubMemberships\Domain;

use FChubMemberships\Domain\Grant\GrantCreationService;
use FChubMemberships\Domain\Event\EventClaimResult;
use FChubMemberships\Domain\Grant\GrantLockService;
use FChubMemberships\Domain\Grant\GrantMaintenanceService;
use FChubMemberships\Domain\Grant\GrantRevocationService;
use FChubMemberships\Domain\Grant\GrantStatusService;
use FChubMemberships\Domain\Grant\PlanGrantExecutionService;
use FChubMemberships\Domain\Plan\PlanRuleResolver;
use FChubMemberships\Integration\FluentCommunitySync;
use FChubMemberships\Storage\DripScheduleRepository;
use FChubMemberships\Storage\EntitlementEdgeRepository;
use FChubMemberships\Storage\EventLockRepository;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\GrantSourceRepository;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Storage\ProviderOperationRepository;

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
        ?GrantPlanContextService $planContext = null,
        ?FluentCommunitySync $communitySync = null
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
        $communitySync = $communitySync ?? new FluentCommunitySync();

        $edgeRepo = new EntitlementEdgeRepository();
        $providerOperationRepo = new ProviderOperationRepository();
        $entitlements = new Entitlement\EntitlementService(
            $edgeRepo,
            $grantRepo,
            null,
            $sourceRepo,
            $dripRepo,
            $providerOperationRepo
        );
        $providerOperations = new ProviderOperationWorker($providerOperationRepo, $edgeRepo, $adapters);
        $this->creation = new GrantCreationService(
            $grantRepo,
            $sourceRepo,
            $dripRepo,
            $adapters,
            null,
            $entitlements,
            $providerOperations
        );
        $this->revocation = new GrantRevocationService(
            $grantRepo,
            $sourceRepo,
            $dripRepo,
            $adapters,
            $notifications,
            null,
            $entitlements,
            $providerOperations
        );
        $this->status = new GrantStatusService($grantRepo, $notifications);
        $this->maintenance = new GrantMaintenanceService($grantRepo, $sourceRepo, $this->status);
        $this->locks = new GrantLockService($lockRepo);
        $this->planGrant = new PlanGrantExecutionService(
            $ruleResolver,
            $membershipModes,
            $planContext,
            $this->creation,
            $this->revocation,
            $notifications,
            null,
            $communitySync->ensurePlanReady(...)
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

    public function orderEventHash(
        int $orderId,
        string $scope,
        int $integrationId,
        string $trigger,
        string $mode
    ): string {
        return $this->locks->orderEventHash($orderId, $scope, $integrationId, $trigger, $mode);
    }

    public function subscriptionRenewalEventHash(array $payload): string
    {
        return $this->locks->subscriptionRenewalEventHash($payload);
    }

    public function claimOrderEvent(
        int $orderId,
        string $scope,
        int $integrationId,
        string $trigger,
        string $mode,
        string $ownerToken,
        int $leaseSeconds = 300
    ): EventClaimResult {
        return $this->locks->claimOrderEvent(
            $orderId,
            $scope,
            $integrationId,
            $trigger,
            $mode,
            $ownerToken,
            $leaseSeconds
        );
    }

    public function claimSubscriptionRenewalEvent(
        array $payload,
        string $ownerToken,
        int $leaseSeconds = 300
    ): EventClaimResult {
        return $this->locks->claimSubscriptionRenewalEvent($payload, $ownerToken, $leaseSeconds);
    }

    public function succeedEventLock(string $eventHash, string $ownerToken): bool
    {
        return $this->locks->succeedEventLock($eventHash, $ownerToken);
    }

    public function failEventLock(
        string $eventHash,
        string $ownerToken,
        string $error,
        bool $retryable = true
    ): bool {
        return $this->locks->failEventLock($eventHash, $ownerToken, $error, $retryable);
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
                $result = $this->grantPlan((int) $userId, $planId, $context);
                if ((int) ($result['failed'] ?? 0) > 0 || !empty($result['blocked'])) {
                    $results['failed']++;
                    $results['errors'][] = sprintf(
                        'User #%d: %s',
                        $userId,
                        $this->resultFailureMessage($result, __('Membership grant failed.', 'fchub-memberships'))
                    );
                    continue;
                }

                $results['granted']++;
            } catch (\Throwable $e) {
                $results['failed']++;
                $results['errors'][] = sprintf('User #%d: %s', $userId, $e->getMessage());
            }
        }

        return $results;
    }

    public function bulkRevoke(array $userIds, int $planId, array $context = []): array
    {
        $results = ['revoked' => 0, 'grace_started' => 0, 'failed' => 0, 'errors' => []];
        foreach ($userIds as $userId) {
            try {
                $result = $this->revokePlan((int) $userId, $planId, $context);
                if (empty($result['success']) || (int) ($result['failed'] ?? 0) > 0) {
                    $results['failed']++;
                    $results['errors'][] = sprintf(
                        'User #%d: %s',
                        $userId,
                        $this->resultFailureMessage($result, __('Membership revocation failed.', 'fchub-memberships'))
                    );
                    continue;
                }

                if ((int) ($result['revoked'] ?? 0) > 0) {
                    $results['revoked']++;
                } elseif ((int) ($result['grace_started'] ?? 0) > 0) {
                    $results['grace_started']++;
                }
            } catch (\Throwable $e) {
                $results['failed']++;
                $results['errors'][] = sprintf('User #%d: %s', $userId, $e->getMessage());
            }
        }

        return $results;
    }

    public function pauseOverdueAnchorGrants(): int
    {
        return $this->maintenance->pauseOverdueAnchorGrants();
    }

    public function expireOverdueGrantsWithHooks(): int
    {
        return $this->maintenance->expireOverdueGrantsWithHooks();
    }

    public function expireTermExpiredGrants(): int
    {
        return $this->maintenance->expireTermExpiredGrants();
    }

    public function revokeExpiredGracePeriodGrants(): int
    {
        return $this->revocation->revokeExpiredGracePeriodGrants();
    }

    private function resultFailureMessage(array $result, string $fallback): string
    {
        $messages = [];
        foreach (($result['errors'] ?? []) as $error) {
            if (is_string($error) && $error !== '') {
                $messages[] = $error;
                continue;
            }

            if (is_array($error) && !empty($error['message'])) {
                $messages[] = (string) $error['message'];
            }
        }

        return $messages ? implode('; ', $messages) : $fallback;
    }
}

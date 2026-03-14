<?php

namespace FChubMemberships\Domain\Grant;

use FChubMemberships\Domain\AuditLogger;
use FChubMemberships\Domain\GrantAdapterRegistry;
use FChubMemberships\Domain\GrantNotificationService;
use FChubMemberships\Domain\StatusTransitionValidator;
use FChubMemberships\Storage\DripScheduleRepository;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\GrantSourceRepository;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Support\Logger;

defined('ABSPATH') || exit;

final class GrantRevocationService
{
    public function __construct(
        private GrantRepository $grants,
        private GrantSourceRepository $sources,
        private DripScheduleRepository $drips,
        private GrantAdapterRegistry $adapters,
        private GrantNotificationService $notifications
    ) {
    }

    public function revokePlan(int $userId, int $planId, array $context = []): array
    {
        $sourceId = (int) ($context['source_id'] ?? 0);
        $reason = $context['reason'] ?? '';
        $order = $context['order'] ?? null;

        $plan = (new PlanRepository())->find($planId);
        $gracePeriodDays = (int) ($context['grace_period_days'] ?? ($plan['grace_period_days'] ?? 0));

        $grants = $this->grants->getByUserId($userId, ['plan_id' => $planId, 'status' => 'active']);
        $revoked = 0;
        $retained = 0;

        foreach ($grants as $grant) {
            try {
                StatusTransitionValidator::assertTransition($grant['status'], 'revoked');
            } catch (\InvalidArgumentException) {
                continue;
            }

            if ($sourceId) {
                $sourceIds = array_values(array_filter(
                    $grant['source_ids'],
                    static fn($id): bool => (int) $id !== $sourceId
                ));

                $this->sources->removeSource($grant['id'], $grant['source_type'], $sourceId);

                if (!empty($sourceIds)) {
                    $this->grants->update($grant['id'], ['source_ids' => $sourceIds]);
                    $retained++;
                    continue;
                }
            }

            if ($gracePeriodDays > 0) {
                $this->grants->update($grant['id'], [
                    'source_ids' => [],
                    'cancellation_requested_at' => current_time('mysql'),
                    'cancellation_effective_at' => gmdate('Y-m-d H:i:s', strtotime("+{$gracePeriodDays} days")),
                    'cancellation_reason' => $reason,
                    'meta' => array_merge($grant['meta'], ['revoke_reason' => $reason]),
                ]);
                $this->sources->removeAllByGrant($grant['id']);

                AuditLogger::logGrantChange($grant['id'], 'grace_period_started', $grant, [
                    'cancellation_effective_at' => gmdate('Y-m-d H:i:s', strtotime("+{$gracePeriodDays} days")),
                ]);
            } else {
                $this->grants->update($grant['id'], [
                    'status' => 'revoked',
                    'source_ids' => [],
                    'cancellation_requested_at' => current_time('mysql'),
                    'cancellation_reason' => $reason,
                    'meta' => array_merge($grant['meta'], ['revoke_reason' => $reason]),
                ]);
                $this->sources->removeAllByGrant($grant['id']);
                $this->drips->deleteByGrantId($grant['id']);

                $adapter = $this->adapters->resolve($grant['provider']);
                if ($adapter) {
                    $adapter->revoke($userId, $grant['resource_type'], $grant['resource_id']);
                }

                AuditLogger::logGrantChange($grant['id'], 'revoked', $grant, ['status' => 'revoked']);
            }

            $revoked++;
        }

        do_action('fchub_memberships/grant_revoked', $grants, $planId, $userId, $reason);

        Logger::log(
            'Plan revoked',
            sprintf('User #%d plan #%d: %d revoked, %d retained', $userId, $planId, $revoked, $retained),
            ['module_id' => $sourceId, 'module_name' => 'Order']
        );

        if ($order) {
            Logger::orderLog(
                $order,
                __('Membership plan revoked', 'fchub-memberships'),
                sprintf(__('Plan #%d: %d resources revoked, %d retained', 'fchub-memberships'), $planId, $revoked, $retained)
            );
        }

        if ($revoked > 0) {
            $this->notifications->sendRevoked($userId, $planId, $reason);
        }

        return ['revoked' => $revoked, 'retained' => $retained];
    }

    public function revokeBySource(int $sourceId, string $sourceType = 'order', array $context = []): array
    {
        $grants = $this->grants->getBySourceId($sourceId, $sourceType);
        $revoked = 0;
        $retained = 0;

        foreach ($grants as $grant) {
            $sourceIds = array_values(array_filter(
                $grant['source_ids'],
                static fn($id): bool => (int) $id !== $sourceId
            ));

            $this->sources->removeSource($grant['id'], $sourceType, $sourceId);

            if (!empty($sourceIds)) {
                $this->grants->update($grant['id'], ['source_ids' => $sourceIds]);
                $retained++;
                continue;
            }

            $this->grants->update($grant['id'], [
                'status' => 'revoked',
                'source_ids' => [],
            ]);
            $this->sources->removeAllByGrant($grant['id']);
            $this->drips->deleteByGrantId($grant['id']);

            $adapter = $this->adapters->resolve($grant['provider']);
            if ($adapter) {
                $adapter->revoke($grant['user_id'], $grant['resource_type'], $grant['resource_id']);
            }

            $revoked++;
        }

        return ['revoked' => $revoked, 'retained' => $retained];
    }

    public function revokeExpiredGracePeriodGrants(): int
    {
        $grants = $this->grants->getDueGracePeriodGrants();
        if (empty($grants)) {
            return 0;
        }

        $revoked = 0;
        foreach ($grants as $grant) {
            $reason = $grant['cancellation_reason'] ?: 'Grace period expired';

            $this->grants->update($grant['id'], [
                'status' => 'revoked',
                'meta' => array_merge($grant['meta'], ['revoke_reason' => $reason]),
            ]);

            $this->drips->deleteByGrantId($grant['id']);
            $adapter = $this->adapters->resolve($grant['provider']);
            if ($adapter) {
                $adapter->revoke((int) $grant['user_id'], $grant['resource_type'], $grant['resource_id']);
            }

            AuditLogger::logGrantChange($grant['id'], 'revoked', $grant, ['status' => 'revoked']);
            do_action('fchub_memberships/grant_revoked', [$grant], (int) $grant['plan_id'], (int) $grant['user_id'], $reason);
            $revoked++;
        }

        if ($revoked > 0) {
            Logger::log('Grace period', sprintf('%d grants revoked after grace period', $revoked));
        }

        return $revoked;
    }
}

<?php

namespace FChubMemberships\Domain\Grant;

use FChubMemberships\Domain\AuditLogger;
use FChubMemberships\Domain\Entitlement\EntitlementService;
use FChubMemberships\Domain\GrantAdapterRegistry;
use FChubMemberships\Domain\GrantNotificationService;
use FChubMemberships\Domain\ProviderOperationOutcome;
use FChubMemberships\Domain\ProviderOperationWorker;
use FChubMemberships\Domain\StatusTransitionValidator;
use FChubMemberships\Storage\DripScheduleRepository;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\GrantSourceRepository;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Support\Logger;
use FChubMemberships\Support\Clock;

defined('ABSPATH') || exit;

final class GrantRevocationService
{
    public function __construct(
        private GrantRepository $grants,
        private GrantSourceRepository $sources,
        private DripScheduleRepository $drips,
        private GrantAdapterRegistry $adapters,
        private GrantNotificationService $notifications,
        private ?Clock $clock = null,
        private ?EntitlementService $entitlements = null,
        private ?ProviderOperationWorker $providerOperations = null
    ) {
        $this->clock ??= new Clock();
    }

    public function revokePlan(int $userId, int $planId, array $context = []): array
    {
        $sourceId = (int) ($context['source_id'] ?? 0);
        $reason = $context['reason'] ?? '';
        $order = $context['order'] ?? null;

        if (array_key_exists('grace_period_days', $context)) {
            $gracePeriodDays = (int) $context['grace_period_days'];
        } else {
            try {
                $plan = (new PlanRepository())->find($planId);
            } catch (\Throwable) {
                return $this->stableEntitlementFailure('plan_policy_read_failed');
            }
            $gracePeriodDays = (int) ($plan['grace_period_days'] ?? 0);
        }
        if ($this->entitlements !== null && $this->providerOperations !== null) {
            return $this->revokePlanFromEntitlements(
                $userId,
                $planId,
                $context,
                $gracePeriodDays
            );
        }

        $grants = $this->grants->getByUserId($userId, ['plan_id' => $planId]);
        $revoked = 0;
        $graceStarted = 0;
        $retained = 0;
        $failed = 0;
        $errors = [];
        $revokedGrants = [];
        $graceGrants = [];
        $now = $this->clock->now();
        $requestedAt = $this->clock->storage($now);
        $effectiveAt = $gracePeriodDays > 0
            ? $this->clock->storage($this->clock->plusDays($gracePeriodDays, $now))
            : null;

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

                if (!empty($sourceIds)) {
                    $persistence = $this->persistGrantUpdate($grant['id'], ['source_ids' => $sourceIds]);
                    if (!$persistence['success']) {
                        $failed++;
                        $failure = $this->localFailure($grant, __(
                            'The remaining grant sources could not be persisted.',
                            'fchub-memberships'
                        ));
                        $errors[] = $this->withPersistenceError($failure, $persistence);
                        continue;
                    }

                    $this->sources->removeSource($grant['id'], $grant['source_type'], $sourceId);
                    $retained++;
                    continue;
                }
            }

            if ($gracePeriodDays > 0) {
                $persistence = $this->persistGrantUpdate($grant['id'], [
                    'source_ids' => [],
                    'cancellation_requested_at' => $requestedAt,
                    'cancellation_effective_at' => $effectiveAt,
                    'cancellation_reason' => $reason,
                    'meta' => array_merge($grant['meta'], ['revoke_reason' => $reason]),
                ]);
                if (!$persistence['success']) {
                    $failed++;
                    $failure = $this->localFailure($grant, __(
                        'The grant grace period could not be persisted.',
                        'fchub-memberships'
                    ));
                    $errors[] = $this->withPersistenceError($failure, $persistence);
                    continue;
                }

                $this->sources->removeAllByGrant($grant['id']);

                AuditLogger::logGrantChange($grant['id'], 'grace_period_started', $grant, [
                    'cancellation_effective_at' => $effectiveAt,
                ]);
                $graceGrants[] = $grant;
                $graceStarted++;
            } else {
                $providerRevocation = $this->revokeOwnedProviderAccess(
                    $grant,
                    $userId,
                    ['plan_id' => $planId]
                );
                if (!$providerRevocation['success']) {
                    $failed++;
                    $errors[] = $this->providerFailure($grant, $providerRevocation);
                    continue;
                }

                $persistence = $this->persistGrantUpdate($grant['id'], [
                    'status' => 'revoked',
                    'source_ids' => [],
                    'cancellation_requested_at' => $requestedAt,
                    'cancellation_reason' => $reason,
                    'meta' => array_merge($grant['meta'], ['revoke_reason' => $reason]),
                ]);
                if (!$persistence['success']) {
                    $failed++;
                    $failure = $this->compensateFailedLocalRevocation(
                        $grant,
                        $userId,
                        ['plan_id' => $planId],
                        $providerRevocation
                    );
                    $errors[] = $this->withPersistenceError($failure, $persistence);
                    continue;
                }

                $this->sources->removeAllByGrant($grant['id']);
                $this->drips->deleteByGrantId($grant['id']);

                AuditLogger::logGrantChange($grant['id'], 'revoked', $grant, ['status' => 'revoked']);
                $revokedGrants[] = $grant;
                $revoked++;
            }
        }

        Logger::log(
            'Plan revocation processed',
            sprintf(
                'User #%d plan #%d: %d revoked, %d grace scheduled, %d retained, %d failed',
                $userId,
                $planId,
                $revoked,
                $graceStarted,
                $retained,
                $failed
            ),
            ['module_id' => $sourceId, 'module_name' => 'Order']
        );

        if ($order) {
            $partial = $failed > 0 && ($revoked > 0 || $graceStarted > 0 || $retained > 0);
            $title = match (true) {
                $failed > 0 && $partial => __('Membership plan revocation partially processed', 'fchub-memberships'),
                $failed > 0 => __('Membership plan revocation failed', 'fchub-memberships'),
                $graceStarted > 0 && $revoked === 0 => __('Membership plan revocation scheduled', 'fchub-memberships'),
                $graceStarted > 0 => __('Membership plan revocation processed', 'fchub-memberships'),
                default => __('Membership plan revoked', 'fchub-memberships'),
            };
            Logger::orderLog(
                $order,
                $title,
                sprintf(
                    __('Plan #%d: %d resources revoked, %d scheduled after grace, %d retained, %d failed', 'fchub-memberships'),
                    $planId,
                    $revoked,
                    $graceStarted,
                    $retained,
                    $failed
                ),
                $failed > 0 ? ($partial ? 'warning' : 'error') : 'info'
            );
        }

        if ($graceStarted > 0 && $failed === 0) {
            do_action(
                'fchub_memberships/grace_period_started',
                $graceGrants,
                $planId,
                $userId,
                $reason,
                $effectiveAt
            );
        }

        if ($revoked > 0 && $failed === 0) {
            do_action('fchub_memberships/grant_revoked', $revokedGrants, $planId, $userId, $reason);
            $this->notifications->sendRevoked($userId, $planId, $reason);
        }

        return [
            'success' => $failed === 0,
            'partial' => $failed > 0 && ($revoked > 0 || $graceStarted > 0 || $retained > 0),
            'revoked' => $revoked,
            'grace_started' => $graceStarted,
            'retained' => $retained,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    public function revokeBySource(int $sourceId, string $sourceType = 'order', array $context = []): array
    {
        if ($sourceId <= 0) {
            return [
                'success' => false,
                'partial' => false,
                'revoked' => 0,
                'retained' => 0,
                'pending' => 0,
                'failed' => 1,
                'errors' => [[
                    'reason' => 'invalid_source_id',
                    'message' => __('A positive source ID is required for source revocation.', 'fchub-memberships'),
                ]],
            ];
        }
        if ($this->entitlements !== null && $this->providerOperations !== null) {
            return $this->revokeByTypedEntitlementSource($sourceId, $sourceType, $context);
        }

        $grants = $this->grants->getBySourceId($sourceId, $sourceType);
        $reason = $context['reason'] ?? '';
        $revoked = 0;
        $retained = 0;
        $failed = 0;
        $errors = [];
        $planOutcomes = [];

        foreach ($grants as $grant) {
            $planId = (int) ($grant['plan_id'] ?? 0);
            $userId = (int) $grant['user_id'];
            $planKey = $userId . ':' . $planId;
            if ($planId && !isset($planOutcomes[$planKey])) {
                $planOutcomes[$planKey] = [
                    'user_id' => $userId,
                    'plan_id' => $planId,
                    'revoked_grants' => [],
                    'failed' => 0,
                ];
            }

            if (in_array($grant['status'], ['expired', 'revoked'], true)) {
                continue;
            }

            $sourceIds = array_values(array_filter(
                $grant['source_ids'],
                static fn($id): bool => (int) $id !== $sourceId
            ));

            if (!empty($sourceIds)) {
                $persistence = $this->persistGrantUpdate($grant['id'], ['source_ids' => $sourceIds]);
                if (!$persistence['success']) {
                    $failed++;
                    $failure = $this->localFailure($grant, __(
                        'The remaining grant sources could not be persisted.',
                        'fchub-memberships'
                    ));
                    $errors[] = $this->withPersistenceError($failure, $persistence);
                    if ($planId) {
                        $planOutcomes[$planKey]['failed']++;
                    }
                    continue;
                }

                $this->sources->removeSource($grant['id'], $sourceType, $sourceId);
                $retained++;
                continue;
            }

            $providerRevocation = $this->revokeOwnedProviderAccess(
                $grant,
                (int) $grant['user_id'],
                ['plan_id' => $grant['plan_id'] ?? null]
            );
            if (!$providerRevocation['success']) {
                $failed++;
                $errors[] = $this->providerFailure($grant, $providerRevocation);
                if ($planId) {
                    $planOutcomes[$planKey]['failed']++;
                }
                continue;
            }

            $persistence = $this->persistGrantUpdate($grant['id'], [
                'status' => 'revoked',
                'source_ids' => [],
            ]);
            if (!$persistence['success']) {
                $failed++;
                $failure = $this->compensateFailedLocalRevocation(
                    $grant,
                    (int) $grant['user_id'],
                    ['plan_id' => $grant['plan_id'] ?? null],
                    $providerRevocation
                );
                $errors[] = $this->withPersistenceError($failure, $persistence);
                if ($planId) {
                    $planOutcomes[$planKey]['failed']++;
                }
                continue;
            }

            $this->sources->removeAllByGrant($grant['id']);
            $this->drips->deleteByGrantId($grant['id']);

            AuditLogger::logGrantChange($grant['id'], 'revoked', $grant, ['status' => 'revoked']);

            if ($planId) {
                $planOutcomes[$planKey]['revoked_grants'][] = $grant;
            }

            $revoked++;
        }

        // Fire hooks and notifications per user+plan combination (matching revokePlan behaviour).
        foreach ($planOutcomes as $entry) {
            if ($entry['failed'] > 0 || empty($entry['revoked_grants'])) {
                continue;
            }

            do_action('fchub_memberships/grant_revoked', $entry['revoked_grants'], $entry['plan_id'], $entry['user_id'], $reason);
            $this->notifications->sendRevoked($entry['user_id'], $entry['plan_id'], $reason);
        }

        return [
            'success' => $failed === 0,
            'partial' => $failed > 0 && ($revoked > 0 || $retained > 0),
            'revoked' => $revoked,
            'retained' => $retained,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    private function revokeByTypedEntitlementSource(int $sourceId, string $sourceType, array $context): array
    {
        try {
            $edges = array_merge(
                $this->entitlements->getActiveByTypedSource($sourceId, $sourceType),
                $this->entitlements->getEndedByTypedSource($sourceId, $sourceType)
            );
        } catch (\Throwable) {
            return [
                'success' => false,
                'partial' => false,
                'revoked' => 0,
                'retained' => 0,
                'pending' => 0,
                'failed' => 1,
                'errors' => [[
                    'reason' => 'entitlement_source_read_failed',
                    'message' => __('Membership entitlement could not be processed.', 'fchub-memberships'),
                ]],
            ];
        }

        $groups = [];
        foreach ($edges as $edge) {
            $key = (int) $edge['user_id'] . ':' . (int) $edge['plan_id'];
            $groups[$key] = [
                'user_id' => (int) $edge['user_id'],
                'plan_id' => (int) $edge['plan_id'],
            ];
        }

        $totals = [
            'revoked' => 0,
            'retained' => 0,
            'pending' => 0,
            'failed' => 0,
            'errors' => [],
        ];
        foreach ($groups as $group) {
            $result = $this->revokePlan(
                $group['user_id'],
                $group['plan_id'],
                array_merge($context, [
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'grace_period_days' => 0,
                ])
            );
            foreach (['revoked', 'retained', 'pending', 'failed'] as $field) {
                $totals[$field] += (int) ($result[$field] ?? 0);
            }
            $totals['errors'] = array_merge($totals['errors'], $result['errors'] ?? []);
        }

        return array_merge($totals, [
            'success' => $totals['failed'] === 0 && $totals['pending'] === 0,
            'partial' => ($totals['failed'] > 0 || $totals['pending'] > 0)
                && ($totals['revoked'] > 0 || $totals['retained'] > 0),
        ]);
    }

    public function revokeExpiredGracePeriodGrants(): int
    {
        $grants = $this->grants->getDueGracePeriodGrants();
        if (empty($grants)) {
            return 0;
        }

        if ($this->entitlements !== null && $this->providerOperations !== null) {
            $revoked = 0;
            foreach ($grants as $grant) {
                $meta = is_array($grant['meta'] ?? null) ? $grant['meta'] : [];
                $snapshots = is_array($meta['entitlement_grace_edges'] ?? null)
                    ? $meta['entitlement_grace_edges']
                    : [];
                if ($snapshots === [] || count($snapshots) > 64) {
                    continue;
                }
                $remaining = [];
                $now = $this->clock->storage($this->clock->now());
                foreach ($snapshots as $snapshot) {
                    $edgeId = is_array($snapshot) ? (int) ($snapshot['edge_id'] ?? 0) : 0;
                    $effectiveAt = is_array($snapshot)
                        ? (string) ($snapshot['effective_at'] ?? $grant['cancellation_effective_at'] ?? '')
                        : '';
                    if ($edgeId <= 0 || $effectiveAt === '' || strcmp($effectiveAt, $now) > 0) {
                        $remaining[] = $snapshot;
                        continue;
                    }
                    try {
                        $edge = $this->entitlements->findById($edgeId);
                    } catch (\Throwable) {
                        $remaining[] = $snapshot;
                        continue;
                    }
                    if (!$edge || !$this->graceSnapshotMatchesEdge($snapshot, $edge)) {
                        $remaining[] = $snapshot;
                        continue;
                    }
                    $result = $this->revokePlan(
                        (int) $snapshot['user_id'],
                        (int) $snapshot['plan_id'],
                        [
                        'source_type' => (string) $snapshot['source_type'],
                        'source_id' => (int) $snapshot['source_id'],
                        'feed_id' => (int) $snapshot['feed_id'],
                        'feed_scope' => (string) $snapshot['feed_scope'],
                        'reason' => (string) (
                            $snapshot['reason']
                            ?? ($grant['cancellation_reason'] ?: 'Grace period expired')
                        ),
                        'grace_period_days' => 0,
                        'origin_event' => 'revoke:grace_expired:' . (int) $grant['id'] . ':edge:' . $edgeId,
                        'edge_ids' => [$edgeId],
                        ]
                    );
                    $revoked += (int) ($result['revoked'] ?? 0);
                    if (empty($result['success'])
                        || (int) ($result['pending'] ?? 0) > 0
                        || (int) ($result['failed'] ?? 0) > 0
                    ) {
                        $remaining[] = $snapshot;
                    }
                }
                if ($remaining === []) {
                    unset($meta['entitlement_grace_edges'], $meta['revoke_reason']);
                    try {
                        $persisted = $this->grants->update((int) $grant['id'], [
                            'cancellation_requested_at' => null,
                            'cancellation_effective_at' => null,
                            'cancellation_reason' => null,
                            'meta' => $meta,
                        ]);
                    } catch (\Throwable) {
                        $persisted = false;
                    }
                } else {
                    $meta['entitlement_grace_edges'] = array_values($remaining);
                    $effectiveTimes = array_values(array_filter(array_map(
                        static fn(array $snapshot): string => (string) ($snapshot['effective_at'] ?? ''),
                        array_filter($remaining, 'is_array')
                    ), static fn(string $value): bool => $value !== ''));
                    $requestedTimes = array_values(array_filter(array_map(
                        static fn(array $snapshot): string => (string) ($snapshot['requested_at'] ?? ''),
                        array_filter($remaining, 'is_array')
                    ), static fn(string $value): bool => $value !== ''));
                    try {
                        $persisted = $this->grants->update((int) $grant['id'], [
                            'cancellation_requested_at' => $requestedTimes !== []
                                ? min($requestedTimes)
                                : $grant['cancellation_requested_at'],
                            'cancellation_effective_at' => $effectiveTimes !== []
                                ? min($effectiveTimes)
                                : $grant['cancellation_effective_at'],
                            'cancellation_reason' => is_array($remaining[0] ?? null)
                                ? (string) ($remaining[0]['reason'] ?? $grant['cancellation_reason'])
                                : $grant['cancellation_reason'],
                            'meta' => $meta,
                        ]);
                    } catch (\Throwable) {
                        $persisted = false;
                    }
                }
                if (!$persisted) {
                    throw new \RuntimeException('The entitlement grace completion could not be persisted.');
                }
            }

            return $revoked;
        }

        $revoked = 0;
        $planOutcomes = [];
        foreach ($grants as $grant) {
            $reason = $grant['cancellation_reason'] ?: 'Grace period expired';
            $planId = (int) ($grant['plan_id'] ?? 0);
            $userId = (int) $grant['user_id'];
            $planKey = $userId . ':' . $planId;
            if ($planId && !isset($planOutcomes[$planKey])) {
                $planOutcomes[$planKey] = [
                    'user_id' => $userId,
                    'plan_id' => $planId,
                    'revoked_grants' => [],
                    'failed' => 0,
                    'reason' => $reason,
                ];
            }

            $providerRevocation = $this->revokeOwnedProviderAccess(
                $grant,
                (int) $grant['user_id'],
                ['plan_id' => $grant['plan_id'] ?? null]
            );
            if (!$providerRevocation['success']) {
                Logger::error(
                    'Grace period provider revocation failed',
                    (string) ($providerRevocation['provider_result']['message'] ?? ''),
                    [
                        'grant_id' => (int) $grant['id'],
                        'provider_result' => $providerRevocation['provider_result'],
                        'provider_outcome' => $providerRevocation,
                    ]
                );
                if ($planId) {
                    $planOutcomes[$planKey]['failed']++;
                }
                continue;
            }

            $persistence = $this->persistGrantUpdate($grant['id'], [
                'status' => 'revoked',
                'meta' => array_merge($grant['meta'], ['revoke_reason' => $reason]),
            ]);
            if (!$persistence['success']) {
                $failure = $this->compensateFailedLocalRevocation(
                    $grant,
                    (int) $grant['user_id'],
                    ['plan_id' => $grant['plan_id'] ?? null],
                    $providerRevocation
                );
                $failure = $this->withPersistenceError($failure, $persistence);
                Logger::error(
                    'Grace period local revocation failed',
                    $failure['message'],
                    $failure
                );
                if ($planId) {
                    $planOutcomes[$planKey]['failed']++;
                }
                continue;
            }

            $this->drips->deleteByGrantId($grant['id']);

            AuditLogger::logGrantChange($grant['id'], 'grace_period_revoked', $grant, ['status' => 'revoked']);
            if ($planId) {
                $planOutcomes[$planKey]['revoked_grants'][] = $grant;
            }
            $revoked++;
        }

        foreach ($planOutcomes as $entry) {
            if ($entry['failed'] > 0 || empty($entry['revoked_grants'])) {
                continue;
            }

            do_action(
                'fchub_memberships/grant_revoked',
                $entry['revoked_grants'],
                $entry['plan_id'],
                $entry['user_id'],
                $entry['reason']
            );
            $this->notifications->sendRevoked(
                $entry['user_id'],
                $entry['plan_id'],
                $entry['reason']
            );
        }

        if ($revoked > 0) {
            Logger::log('Grace period', sprintf('%d grants revoked after grace period', $revoked));
        }

        return $revoked;
    }

    private function revokePlanFromEntitlements(
        int $userId,
        int $planId,
        array $context,
        int $gracePeriodDays
    ): array {
        $reason = trim((string) ($context['reason'] ?? 'revoked'));
        if ($reason === '') {
            $reason = 'revoked';
        }
        $terminalStatus = ($context['terminal_status'] ?? 'revoked') === 'expired'
            ? 'expired'
            : 'revoked';
        $criteria = [];
        foreach (['source_type', 'source_id', 'feed_id', 'feed_scope'] as $field) {
            if (array_key_exists($field, $context)) {
                $criteria[$field] = $field === 'source_type' || $field === 'feed_scope'
                    ? trim((string) $context[$field])
                    : (int) $context[$field];
            }
        }
        if (array_key_exists('edge_ids', $context)) {
            $criteria['edge_ids'] = array_values(array_unique(array_map('intval', (array) $context['edge_ids'])));
        }

        try {
            $matched = $this->entitlements->getActiveMatching($userId, $planId, $criteria);
        } catch (\Throwable) {
            return $this->stableEntitlementFailure('entitlement_read_failed');
        }
        if ($matched === []) {
            return $this->rehydrateEndedRevocation($userId, $planId, $criteria, $context, $reason);
        }

        $now = $this->clock->now();
        $requestedAt = $this->clock->storage($now);
        if ($gracePeriodDays > 0) {
            $effectiveAt = $this->clock->storage($this->clock->plusDays($gracePeriodDays, $now));
            try {
                $graceGrants = $this->entitlements->scheduleGrace(
                    $matched,
                    $requestedAt,
                    $effectiveAt,
                    $reason
                );
            } catch (\Throwable) {
                return $this->stableEntitlementFailure('entitlement_grace_persistence_failed');
            }
            do_action(
                'fchub_memberships/grace_period_started',
                $graceGrants,
                $planId,
                $userId,
                $reason,
                $effectiveAt
            );

            return [
                'success' => true,
                'partial' => false,
                'revoked' => 0,
                'grace_started' => count($matched),
                'retained' => 0,
                'pending' => 0,
                'failed' => 0,
                'errors' => [],
            ];
        }

        $ended = [];
        $queued = [];
        $pending = 0;
        $failed = 0;
        $errors = [];
        foreach ($matched as $edge) {
            try {
                $endResult = $this->entitlements->endWithRevokeIntent(
                    $this->edgeIdentity($edge),
                    $reason,
                    $this->originEvent('revoke', $edge, $context),
                    $terminalStatus
                );
            } catch (\Throwable) {
                $failed++;
                $errors[] = [
                    'reason' => 'entitlement_end_failed',
                    'message' => __('Membership entitlement could not be processed.', 'fchub-memberships'),
                ];
                break;
            }
            if (($endResult['action'] ?? '') !== 'ended') {
                continue;
            }

            $endedEdge = $endResult['edge'];
            $ended[] = $endedEdge;
            $operation = $endResult['operation'] ?? null;
            if ($operation !== null) {
                $queued[] = $operation;
            }
        }

        foreach ($queued as $operation) {
            try {
                $this->providerOperations->schedulePersisted((int) $operation['id']);
            } catch (\Throwable) {
                // The durable operation remains recoverable by the five-minute recovery worker.
            }
            try {
                $outcome = $this->providerOperations->process((int) $operation['id']);
            } catch (\Throwable) {
                $pending++;
                continue;
            }
            if (in_array($outcome->status, ['deferred', 'retryable-failure'], true)) {
                $pending++;
            } elseif ($outcome->status === 'terminal-failure') {
                $failed++;
                $errors[] = ['message' => __('Provider operation failed terminally.', 'fchub-memberships')];
            }
        }

        $revoked = count($ended);
        $finalizedNow = false;
        if ($pending === 0 && $failed === 0 && $queued !== []) {
            $coordinatorId = max(array_map(
                static fn(array $operation): int => (int) $operation['id'],
                $queued
            ));
            try {
                $finalizedNow = $this->entitlements->finalizeAppliedRevokeProviderOperation($coordinatorId);
            } catch (\Throwable) {
                $pending++;
            }
        }
        if ($pending === 0
            && $failed === 0
            && $revoked > 0
            && ($queued === [] || $finalizedNow)
        ) {
            if ($terminalStatus === 'expired') {
                foreach ($ended as $edge) {
                    do_action('fchub_memberships/grant_expired', $edge);
                }
            } else {
                do_action('fchub_memberships/grant_revoked', $ended, $planId, $userId, $reason);
                $this->notifications->sendRevoked($userId, $planId, $reason);
            }
        }

        return [
            'success' => $failed === 0 && $pending === 0,
            'partial' => ($failed > 0 || $pending > 0) && $revoked > 0,
            'revoked' => $revoked,
            'grace_started' => 0,
            'retained' => 0,
            'pending' => $pending,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    private function rehydrateEndedRevocation(
        int $userId,
        int $planId,
        array $criteria,
        array $context,
        string $reason
    ): array {
        try {
            $ended = $this->entitlements->getEndedMatching($userId, $planId, $criteria);
        } catch (\Throwable) {
            return $this->stableEntitlementFailure('ended_entitlement_read_failed');
        }
        if ($ended === []) {
            return [
                'success' => true,
                'partial' => false,
                'revoked' => 0,
                'grace_started' => 0,
                'retained' => 0,
                'pending' => 0,
                'failed' => 0,
                'errors' => [],
            ];
        }

        $pending = 0;
        $failed = 0;
        $errors = [];
        $resources = [];
        $operationIds = [];
        foreach ($ended as $edge) {
            $key = implode('|', [
                $edge['user_id'],
                $edge['provider'],
                $edge['resource_type'],
                $edge['resource_id'],
            ]);
            $resources[$key][] = $edge;
        }

        foreach ($resources as $resourceEdges) {
            $representative = $resourceEdges[array_key_last($resourceEdges)];
            $operation = null;
            foreach ($resourceEdges as $edge) {
                try {
                    $candidate = $this->entitlements->findProviderOperation(
                        $edge,
                        'revoke',
                        $this->originEvent('revoke', $edge, $context)
                    );
                } catch (\Throwable) {
                    return $this->stableEntitlementFailure('provider_operation_read_failed');
                }
                if ($candidate !== null) {
                    $operation = $candidate;
                }
            }

            if ($operation === null) {
                try {
                    $intentRequired = ($representative['provider'] ?? '') !== 'wordpress_core'
                        && ($representative['owner'] ?? '') === 'fchub'
                        && ($representative['assignment_provenance'] ?? '') === 'fchub_created'
                        && !$this->entitlements->hasUnsafeAssignmentEvidence($representative);
                } catch (\Throwable) {
                    return $this->stableEntitlementFailure('provider_assignment_evidence_read_failed');
                }
                if ($intentRequired) {
                    $failed++;
                    $errors[] = [
                        'reason' => 'provider_operation_missing',
                        'message' => __('Provider operation could not be found.', 'fchub-memberships'),
                    ];
                }
                continue;
            }

            $state = (string) ($operation['state'] ?? '');
            if ($state === 'applied') {
                $operationIds[] = (int) $operation['id'];
                continue;
            }
            if ($state === 'failed' && empty($operation['retryable'])) {
                $failed++;
                $errors[] = [
                    'reason' => (string) ($operation['last_error_code'] ?? 'provider_operation_terminal'),
                    'message' => __('Provider operation failed terminally.', 'fchub-memberships'),
                ];
                continue;
            }
            $pending++;
        }

        $revoked = count($ended);
        $finalizedNow = false;
        if ($pending === 0 && $failed === 0 && $operationIds !== []) {
            try {
                $finalizedNow = $this->entitlements->finalizeAppliedRevokeProviderOperation(max($operationIds));
            } catch (\Throwable) {
                $pending++;
            }
        }
        if ($pending === 0 && $failed === 0 && $finalizedNow) {
            if (($context['terminal_status'] ?? 'revoked') === 'expired') {
                foreach ($ended as $edge) {
                    do_action('fchub_memberships/grant_expired', $edge);
                }
            } else {
                do_action('fchub_memberships/grant_revoked', $ended, $planId, $userId, $reason);
                $this->notifications->sendRevoked($userId, $planId, $reason);
            }
        }

        return [
            'success' => $pending === 0 && $failed === 0,
            'partial' => $pending > 0 || $failed > 0,
            'revoked' => $revoked,
            'grace_started' => 0,
            'retained' => 0,
            'pending' => $pending,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    private function graceSnapshotMatchesEdge(array $snapshot, array $edge): bool
    {
        if ((int) ($snapshot['edge_id'] ?? 0) !== (int) ($edge['id'] ?? 0)) {
            return false;
        }
        foreach (['user_id', 'plan_id', 'feed_id', 'source_id'] as $field) {
            if ((int) ($snapshot[$field] ?? -1) !== (int) ($edge[$field] ?? -2)) {
                return false;
            }
        }
        foreach (['provider', 'resource_type', 'resource_id', 'feed_scope', 'source_type'] as $field) {
            if ((string) ($snapshot[$field] ?? '') !== (string) ($edge[$field] ?? '')) {
                return false;
            }
        }

        return true;
    }

    private function stableEntitlementFailure(string $reason): array
    {
        return [
            'success' => false,
            'partial' => false,
            'revoked' => 0,
            'grace_started' => 0,
            'retained' => 0,
            'pending' => 0,
            'failed' => 1,
            'errors' => [[
                'reason' => $reason,
                'message' => __('Membership entitlement could not be processed.', 'fchub-memberships'),
            ]],
        ];
    }

    private function originEvent(string $action, array $identity, array $context): string
    {
        if (array_key_exists('origin_event', $context)) {
            $origin = trim((string) $context['origin_event']);
            if ($origin === ''
                || strlen($origin) > 100
                || preg_match('/^[a-zA-Z0-9_.:-]+$/', $origin) !== 1
            ) {
                throw new \InvalidArgumentException('Provider operation origin event is invalid.');
            }
            return $origin;
        }

        $stableIdentity = array_intersect_key($identity, array_flip([
            'user_id', 'provider', 'resource_type', 'resource_id', 'plan_id',
            'feed_id', 'feed_scope', 'source_type', 'source_id',
        ]));
        return $action . ':' . substr(hash('sha256', wp_json_encode($stableIdentity)), 0, 64);
    }

    private function uniqueResources(array $edges): array
    {
        $resources = [];
        foreach ($edges as $edge) {
            $key = implode('|', [
                $edge['user_id'],
                $edge['provider'],
                $edge['resource_type'],
                $edge['resource_id'],
            ]);
            $resources[$key] = $edge;
        }

        return array_values($resources);
    }

    private function edgeIdentity(array $edge): array
    {
        return array_intersect_key($edge, array_flip([
            'user_id',
            'provider',
            'resource_type',
            'resource_id',
            'plan_id',
            'feed_id',
            'feed_scope',
            'source_type',
            'source_id',
        ]));
    }

    private function revokeOwnedProviderAccess(array $grant, int $userId, array $context): array
    {
        $owner = $grant['meta']['provider_access_owner'] ?? 'unknown';
        if ($owner !== 'fchub') {
            return [
                'success' => true,
                'detached' => false,
                'provider_result' => [
                    'success' => true,
                    'message' => __('Provider access is not owned by FCHub and was preserved.', 'fchub-memberships'),
                ],
            ];
        }

        $adapter = $this->adapters->resolve($grant['provider']);
        if (!$adapter) {
            return [
                'success' => false,
                'detached' => false,
                'provider_result' => [
                    'success' => false,
                    'message' => __('No access adapter is available to revoke FCHub-owned provider access.', 'fchub-memberships'),
                ],
            ];
        }

        try {
            $hadAccess = $adapter->check(
                $userId,
                $grant['resource_type'],
                $grant['resource_id']
            );
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'detached' => false,
                'provider_result' => [
                    'success' => false,
                    'message' => $exception->getMessage(),
                    'exception' => get_class($exception),
                    'stage' => 'precheck',
                ],
                'reconciliation' => [
                    'success' => false,
                    'stage' => 'precheck',
                    'message' => __('Provider access state could not be checked before revocation.', 'fchub-memberships'),
                    'exception' => get_class($exception),
                ],
                'compensation' => [
                    'attempted' => false,
                    'success' => false,
                    'message' => __('Provider revocation was not attempted because its initial state is unknown.', 'fchub-memberships'),
                ],
            ];
        }

        if (!$hadAccess) {
            return [
                'success' => true,
                'detached' => false,
                'provider_result' => [
                    'success' => true,
                    'message' => __('Provider access was already absent.', 'fchub-memberships'),
                ],
            ];
        }

        try {
            $providerResult = $adapter->revoke(
                $userId,
                $grant['resource_type'],
                $grant['resource_id'],
                $context
            );
        } catch (\Throwable $exception) {
            $providerResult = [
                'success' => false,
                'message' => $exception->getMessage(),
                'exception' => get_class($exception),
            ];
        }

        if (!is_array($providerResult) || empty($providerResult['success'])) {
            $providerResult = is_array($providerResult) ? $providerResult : [];
            $providerResult['success'] = false;
            $providerResult['message'] = (string) ($providerResult['message'] ?? __(
                'The access provider did not confirm the revocation.',
                'fchub-memberships'
            ));

            return array_merge([
                'success' => false,
                'detached' => false,
                'provider_result' => $providerResult,
            ], $this->reconcileFailedProviderRevocation(
                $adapter,
                $grant,
                $userId,
                $context
            ));
        }

        return [
            'success' => true,
            'detached' => true,
            'provider_result' => $providerResult,
        ];
    }

    private function reconcileFailedProviderRevocation(
        object $adapter,
        array $grant,
        int $userId,
        array $context
    ): array {
        try {
            $postFailureAccess = $adapter->check(
                $userId,
                $grant['resource_type'],
                $grant['resource_id']
            );
        } catch (\Throwable $exception) {
            return [
                'reconciliation' => [
                    'success' => false,
                    'stage' => 'post_failure_check',
                    'pre_access' => true,
                    'post_failure_access' => null,
                    'message' => $exception->getMessage(),
                    'exception' => get_class($exception),
                ],
                'compensation' => [
                    'attempted' => false,
                    'success' => false,
                    'message' => __('Provider access may have changed, but its state could not be verified before restoration.', 'fchub-memberships'),
                ],
            ];
        }

        if ($postFailureAccess) {
            return [
                'reconciliation' => [
                    'success' => true,
                    'stage' => 'post_failure_check',
                    'pre_access' => true,
                    'post_failure_access' => true,
                    'message' => __('The failed provider revocation left access in place.', 'fchub-memberships'),
                ],
                'compensation' => [
                    'attempted' => false,
                    'success' => true,
                    'message' => __('No provider restoration was required.', 'fchub-memberships'),
                ],
            ];
        }

        try {
            $compensation = $adapter->grant(
                $userId,
                $grant['resource_type'],
                $grant['resource_id'],
                $context
            );
        } catch (\Throwable $exception) {
            $compensation = [
                'success' => false,
                'message' => $exception->getMessage(),
                'exception' => get_class($exception),
            ];
        }

        if (!is_array($compensation) || empty($compensation['success'])) {
            $compensation = is_array($compensation) ? $compensation : [];
            $compensation['success'] = false;
            $compensation['message'] = (string) ($compensation['message'] ?? __(
                'The access provider did not confirm revocation compensation.',
                'fchub-memberships'
            ));
        }
        $compensation['attempted'] = true;

        return [
            'reconciliation' => [
                'success' => !empty($compensation['success']),
                'stage' => 'compensation',
                'pre_access' => true,
                'post_failure_access' => false,
                'message' => !empty($compensation['success'])
                    ? __('Provider access removed by the failed revocation was restored.', 'fchub-memberships')
                    : __('Provider access removed by the failed revocation could not be restored.', 'fchub-memberships'),
            ],
            'compensation' => $compensation,
        ];
    }

    private function providerFailure(array $grant, array $providerOutcome): array
    {
        $providerResult = $providerOutcome['provider_result'];
        $failure = [
            'grant_id' => (int) $grant['id'],
            'message' => (string) ($providerResult['message'] ?? __('Provider revocation failed.', 'fchub-memberships')),
            'provider_result' => $providerResult,
        ];
        foreach (['reconciliation', 'compensation'] as $key) {
            if (isset($providerOutcome[$key])) {
                $failure[$key] = $providerOutcome[$key];
            }
        }

        return $failure;
    }

    private function localFailure(array $grant, string $message): array
    {
        return [
            'grant_id' => (int) $grant['id'],
            'message' => $message,
        ];
    }

    private function compensateFailedLocalRevocation(
        array $grant,
        int $userId,
        array $context,
        array $providerRevocation
    ): array {
        $failure = $this->localFailure($grant, __(
            'Provider access was revoked, but the local grant could not be closed.',
            'fchub-memberships'
        ));
        $failure['provider_result'] = $providerRevocation['provider_result'];

        if (!$providerRevocation['detached']) {
            return $failure;
        }

        $adapter = $this->adapters->resolve($grant['provider']);
        if (!$adapter) {
            $failure['compensation'] = [
                'success' => false,
                'message' => __('No access adapter is available to restore provider access.', 'fchub-memberships'),
            ];
            return $failure;
        }

        try {
            $failure['compensation'] = $adapter->grant(
                $userId,
                $grant['resource_type'],
                $grant['resource_id'],
                $context
            );
        } catch (\Throwable $exception) {
            $failure['compensation'] = [
                'success' => false,
                'message' => $exception->getMessage(),
                'exception' => get_class($exception),
            ];
        }

        return $failure;
    }

    private function persistGrantUpdate(int $grantId, array $data): array
    {
        try {
            return [
                'success' => $this->grants->update($grantId, $data),
                'exception' => null,
            ];
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'exception' => $exception,
            ];
        }
    }

    private function withPersistenceError(array $failure, array $persistence): array
    {
        $exception = $persistence['exception'] ?? null;
        if ($exception instanceof \Throwable) {
            $failure['persistence_error'] = [
                'message' => $exception->getMessage(),
                'exception' => get_class($exception),
            ];
        }

        return $failure;
    }
}

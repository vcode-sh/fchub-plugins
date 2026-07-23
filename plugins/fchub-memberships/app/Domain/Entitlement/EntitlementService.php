<?php

namespace FChubMemberships\Domain\Entitlement;

use FChubMemberships\Domain\AuditLogger;
use FChubMemberships\Domain\AccessEvaluator;
use FChubMemberships\Storage\EntitlementEdgeRepository;
use FChubMemberships\Storage\DripScheduleRepository;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\GrantSourceRepository;
use FChubMemberships\Storage\ProviderOperationRepository;
use FChubMemberships\Support\Clock;

defined('ABSPATH') || exit;

class EntitlementService
{
    private const LOCAL_PROJECTION_RECEIPT_LIMIT = 32;
    private const GRACE_EDGE_SNAPSHOT_LIMIT = 64;

    private const IDENTITY_FIELDS = [
        'user_id',
        'provider',
        'resource_type',
        'resource_id',
        'plan_id',
        'feed_id',
        'feed_scope',
        'source_type',
        'source_id',
    ];

    public function __construct(
        private EntitlementEdgeRepository $edges,
        private GrantRepository $grants,
        private ?Clock $clock = null,
        private ?GrantSourceRepository $sources = null,
        private ?DripScheduleRepository $drips = null,
        private ?ProviderOperationRepository $providerOperations = null
    ) {
        $this->clock ??= new Clock();
    }

    public function activate(array $identity, array $attributes = [], bool $projectCompatibility = true): array
    {
        return $this->activateWithinResourceLock($identity, $attributes, $projectCompatibility);
    }

    public function activateFromProviderObservation(
        array $identity,
        array $attributes,
        ?bool $providerHasAccess,
        bool $projectCompatibility = true
    ): array {
        return $this->activateWithinResourceLock(
            $identity,
            $attributes,
            $projectCompatibility,
            $providerHasAccess,
            true
        );
    }

    private function activateWithinResourceLock(
        array $identity,
        array $attributes,
        bool $projectCompatibility,
        ?bool $providerHasAccess = null,
        bool $deriveAssignmentProvenance = false
    ): array
    {
        $dripRuleId = (int) ($attributes['drip_rule_id'] ?? 0);
        $explicitImmutableFields = array_values(array_intersect(
            ['starts_at', 'expires_at', 'drip_available_at', 'policy'],
            array_keys($attributes)
        ));
        $identity = $this->normaliseIdentity($identity);
        $attributes = $this->normaliseAttributes($attributes);
        $now = $this->now();
        $row = array_merge($identity, [
            'owner' => $attributes['owner'],
            'assignment_provenance' => $attributes['assignment_provenance'],
            'lifecycle' => 'active',
            'access_status' => 'active',
            'starts_at' => array_key_exists('starts_at', $attributes) ? $attributes['starts_at'] : $now,
            'expires_at' => $attributes['expires_at'] ?? null,
            'drip_available_at' => $attributes['drip_available_at'] ?? null,
            'ended_at' => null,
            'end_reason' => null,
            'policy' => $attributes['policy'] ?? [],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->edges->resourceTransaction($row, function () use (
            $row,
            $explicitImmutableFields,
            $dripRuleId,
            $attributes,
            $projectCompatibility,
            $providerHasAccess,
            $deriveAssignmentProvenance
        ): array {
            if ($deriveAssignmentProvenance) {
                $existing = $this->edges->findByIdentity($row);
                if ($existing !== null) {
                    $row['owner'] = (string) $existing['owner'];
                    $row['assignment_provenance'] = (string) $existing['assignment_provenance'];
                } else {
                    $row['assignment_provenance'] = $this->assignmentProvenanceFromLockedEvidence(
                        $row,
                        $providerHasAccess
                    );
                }
            }
            $result = $this->edges->createOrReplay($row, array_merge(
                ['owner', 'assignment_provenance'],
                $explicitImmutableFields
            ));
            if ($projectCompatibility && in_array($result['action'], ['created', 'replayed'], true)) {
                $this->syncAggregate($row, 'revoked');
                $this->syncAggregateContext($row, $attributes, $result['action']);
                $this->syncActiveCompatibility($row, $result['action'], $dripRuleId);
            }

            return $result;
        });
    }

    private function assignmentProvenanceFromLockedEvidence(array $resource, ?bool $providerHasAccess): string
    {
        if ($providerHasAccess === null) {
            return 'unknown';
        }
        if (!$providerHasAccess) {
            return 'fchub_created';
        }

        $active = $this->edges->getActiveByResource(
            (int) $resource['user_id'],
            (string) $resource['provider'],
            (string) $resource['resource_type'],
            (string) $resource['resource_id']
        );
        if ($active === []) {
            return 'preexisting';
        }

        return $this->edges->hasUnsafeAssignmentEvidence(
            (int) $resource['user_id'],
            (string) $resource['provider'],
            (string) $resource['resource_type'],
            (string) $resource['resource_id']
        ) ? 'preexisting' : 'fchub_created';
    }

    public function activateLocal(array $identity, array $attributes, string $originEvent): array
    {
        $originEvent = trim($originEvent);
        if ($originEvent === '' || $this->length($originEvent) > 100) {
            throw new \InvalidArgumentException('Local projection origin event is invalid.');
        }

        $result = $this->activate($identity, $attributes, false);
        if (!in_array($result['action'], ['created', 'replayed'], true)) {
            return $result;
        }
        $projection = $this->projectLocalGrant(
            $result['edge'],
            $attributes,
            $originEvent,
            $result['action']
        );

        return array_merge($result, $projection);
    }

    public function projectLifecycleRenewal(array $edge, string $eventReceipt): array
    {
        $this->assertLifecycleReceipt($eventReceipt);

        return $this->projectLocalGrant(
            $edge,
            [],
            'subscription_renewal:' . $eventReceipt,
            'replayed'
        );
    }

    private function projectLocalGrant(
        array $edge,
        array $attributes,
        string $originEvent,
        string $edgeAction
    ): array {
        $attributes = $this->normaliseAttributes($attributes);
        $originHash = hash('sha256', $originEvent);

        return $this->edges->resourceTransaction($edge, function () use (
            $edge,
            $attributes,
            $originHash,
            $edgeAction
        ): array {
            $before = $this->grants->findByGrantKey($this->grantKey($edge));
            $previousRenewalCount = (int) ($before['renewal_count'] ?? 0);
            $previousMeta = is_array($before['meta'] ?? null) ? $before['meta'] : [];
            $originHashes = $this->localProjectionReceipts($previousMeta);
            $originSeen = in_array($originHash, $originHashes, true);
            $renewed = $edgeAction === 'replayed'
                && !$originSeen;
            $renewalCount = $previousRenewalCount + ($renewed ? 1 : 0);
            if (!$originSeen) {
                $originHashes[] = $originHash;
            }
            if (count($originHashes) > self::LOCAL_PROJECTION_RECEIPT_LIMIT) {
                $originHashes = array_slice($originHashes, -self::LOCAL_PROJECTION_RECEIPT_LIMIT);
            }
            $aggregateMeta = is_array($attributes['aggregate_meta'] ?? null)
                ? $attributes['aggregate_meta']
                : [];
            $attributes['aggregate_meta'] = array_merge($aggregateMeta, [
                'local_projection_origin_hash' => $originHash,
                'local_projection_origin_hashes' => $originHashes,
            ]);

            $this->syncAggregate($edge, 'revoked');
            $this->syncAggregateContext($edge, $attributes, 'projected', $renewalCount);
            $this->syncActiveCompatibility(
                $edge,
                $before === null ? 'created' : 'replayed',
                (int) ($attributes['drip_rule_id'] ?? 0)
            );

            $snapshot = $this->grants->findByGrantKey($this->grantKey($edge));
            if (!$snapshot) {
                throw new \RuntimeException('The entitlement aggregate renewal projection could not be read.');
            }

            return [
                'renewed' => $renewed,
                'renewal_count' => $renewalCount,
                'grant' => $snapshot,
            ];
        });
    }

    private function localProjectionReceipts(array $meta): array
    {
        $receipts = [];
        $seen = [];
        $stored = is_array($meta['local_projection_origin_hashes'] ?? null)
            ? $meta['local_projection_origin_hashes']
            : [];
        foreach ($stored as $receipt) {
            if (!is_string($receipt)
                || preg_match('/^[a-f0-9]{64}$/', $receipt) !== 1
                || isset($seen[$receipt])
            ) {
                continue;
            }
            $receipts[] = $receipt;
            $seen[$receipt] = true;
        }

        $legacy = $meta['local_projection_origin_hash'] ?? null;
        if (is_string($legacy)
            && preg_match('/^[a-f0-9]{64}$/', $legacy) === 1
            && !isset($seen[$legacy])
        ) {
            $receipts[] = $legacy;
        }

        return count($receipts) > self::LOCAL_PROJECTION_RECEIPT_LIMIT
            ? array_slice($receipts, -self::LOCAL_PROJECTION_RECEIPT_LIMIT)
            : $receipts;
    }

    public function projectAppliedGrant(array $edge, array $attributes, string $originEvent): array
    {
        if ($this->providerOperations === null) {
            throw new \RuntimeException('Provider operation storage is required for grant projection.');
        }
        $attributes = $this->normaliseAttributes($attributes);
        $operation = $this->findProviderOperation($edge, 'grant', $originEvent);
        if ($operation === null || ($operation['state'] ?? '') !== 'applied') {
            throw new \RuntimeException('Only an applied provider operation can update the grant projection.');
        }
        $appliedCount = $this->providerOperations->countAppliedGrantOperations((int) $edge['id']);
        if ($appliedCount <= 0) {
            throw new \RuntimeException('The applied provider operation count is invalid.');
        }
        $renewalCount = $appliedCount - 1;

        return $this->edges->resourceTransaction($edge, function () use (
            $edge,
            $attributes,
            $renewalCount,
            $appliedCount
        ): array {
            $before = $this->grants->findByGrantKey($this->grantKey($edge));
            $previousRenewalCount = (int) ($before['renewal_count'] ?? 0);
            $this->syncAggregate($edge, 'revoked');
            $this->syncAggregateContext($edge, $attributes, 'projected', $renewalCount);
            $this->syncActiveCompatibility(
                $edge,
                $before === null ? 'created' : 'replayed',
                (int) ($attributes['drip_rule_id'] ?? 0)
            );

            return [
                'renewed' => $renewalCount > $previousRenewalCount,
                'renewal_count' => $renewalCount,
            ];
        });
    }

    public function end(
        array $identity,
        string $reason,
        string $terminalGrantStatus = 'revoked',
        ?string $endedAt = null
    ): array {
        $identity = $this->normaliseIdentity($identity);
        if (!in_array($terminalGrantStatus, ['expired', 'revoked'], true)) {
            throw new \InvalidArgumentException('Terminal grant status must be expired or revoked.');
        }
        $reason = trim($reason);
        if ($reason === '' || $this->length($reason) > 191) {
            throw new \InvalidArgumentException('Entitlement end reason must contain 1 to 191 characters.');
        }
        $endedAt ??= $this->now();

        return $this->edges->resourceTransaction($identity, function () use (
            $identity,
            $reason,
            $endedAt,
            $terminalGrantStatus
        ): array {
            $result = $this->edges->endByIdentity($identity, $endedAt, $reason);
            if ($result['action'] === 'ended') {
                $this->syncAggregate($identity, $terminalGrantStatus);
                $this->syncEndedCompatibility($identity);
            }

            return $result;
        });
    }

    public function getActiveMatching(int $userId, int $planId, array $context = []): array
    {
        return $this->edges->getActiveMatching($userId, $planId, $context);
    }

    public function getEndedMatching(int $userId, int $planId, array $context = []): array
    {
        return $this->edges->getEndedMatching($userId, $planId, $context);
    }

    public function findByIdentity(array $identity): ?array
    {
        return $this->edges->findByIdentity($this->normaliseIdentity($identity));
    }

    public function getActiveByTypedSource(int $sourceId, string $sourceType): array
    {
        return $this->edges->getActiveByTypedSource($sourceId, $sourceType);
    }

    public function getEndedByTypedSource(int $sourceId, string $sourceType): array
    {
        return $this->edges->getEndedByTypedSource($sourceId, $sourceType);
    }

    public function getBySubscriptionCorrelation(int $subscriptionId, string $lifecycle): array
    {
        return $this->edges->getBySubscriptionCorrelation($subscriptionId, $lifecycle);
    }

    public function getDueActive(string $at): array
    {
        $this->normaliseLifecycleDate($at);

        return $this->edges->getDueActive($at);
    }

    public function findById(int $edgeId): ?array
    {
        if ($edgeId <= 0) {
            throw new \InvalidArgumentException('Entitlement edge ID must be positive.');
        }

        return $this->edges->findById($edgeId);
    }

    public function extendActiveExpiry(array $edge, string $newExpiry, string $eventReceipt): array
    {
        $this->assertLifecycleReceipt($eventReceipt);
        $newExpiry = $this->normaliseLifecycleDate($newExpiry);
        $identity = array_intersect_key($edge, array_flip(self::IDENTITY_FIELDS));
        $identity = $this->normaliseIdentity($identity);

        return $this->edges->resourceTransaction($identity, function () use (
            $identity,
            $newExpiry,
            $eventReceipt
        ): array {
            $current = $this->edges->findByIdentity($identity);
            if (!$current || ($current['lifecycle'] ?? '') !== 'active') {
                return ['action' => 'not_active', 'edge' => $current];
            }
            if (AuditLogger::hasRequiredLifecycleReceipt(
                'entitlement_edge',
                (int) $current['id'],
                'renewal_validity_extended',
                $eventReceipt
            )) {
                return ['action' => 'replayed', 'edge' => $current];
            }
            $oldExpiry = $current['expires_at'] ?? null;
            if ($oldExpiry === null || strcmp($newExpiry, (string) $oldExpiry) <= 0) {
                return ['action' => 'unchanged', 'edge' => $current];
            }

            $result = $this->edges->extendActiveExpiryById(
                (int) $current['id'],
                $oldExpiry !== null ? (string) $oldExpiry : null,
                $newExpiry,
                $this->now()
            );
            if ($result['action'] !== 'extended') {
                return $result;
            }

            $this->syncAggregate($result['edge'], 'expired');
            AuditLogger::logRequired(
                'entitlement_edge',
                (int) $current['id'],
                'renewal_validity_extended',
                ['expires_at' => $oldExpiry],
                ['expires_at' => $newExpiry, 'event_receipt' => $eventReceipt],
                $this->clock,
                'subscription_renewal'
            );

            return $result;
        });
    }

    public function createRenewalSuccessor(
        array $predecessor,
        int $renewalOrderId,
        int $subscriptionId,
        ?string $expiresAt,
        string $eventReceipt
    ): array {
        if ($renewalOrderId <= 0 || $subscriptionId <= 0) {
            throw new \InvalidArgumentException('Renewal successor identifiers must be positive.');
        }
        $this->assertLifecycleReceipt($eventReceipt);
        $expiresAt = $expiresAt !== null ? $this->normaliseLifecycleDate($expiresAt) : null;
        $predecessorIdentity = $this->normaliseIdentity(array_intersect_key(
            $predecessor,
            array_flip(self::IDENTITY_FIELDS)
        ));

        return $this->edges->resourceTransaction($predecessorIdentity, function () use (
            $predecessorIdentity,
            $renewalOrderId,
            $subscriptionId,
            $expiresAt,
            $eventReceipt
        ): array {
            $current = $this->edges->findByIdentity($predecessorIdentity);
            if (!$current || ($current['lifecycle'] ?? '') !== 'ended') {
                return ['action' => 'predecessor_not_ended', 'edge' => $current];
            }
            $identity = array_merge($predecessorIdentity, [
                'source_type' => 'order',
                'source_id' => $renewalOrderId,
            ]);
            $policy = is_array($current['policy'] ?? null) ? $current['policy'] : [];
            $policy['subscription_id'] = $subscriptionId;
            $policy['renewal_order_id'] = $renewalOrderId;
            $now = $this->now();
            $row = array_merge($identity, [
                'owner' => (string) $current['owner'],
                'assignment_provenance' => (string) $current['assignment_provenance'],
                'lifecycle' => 'active',
                'access_status' => 'active',
                'starts_at' => $now,
                'expires_at' => $expiresAt,
                'drip_available_at' => null,
                'ended_at' => null,
                'end_reason' => null,
                'policy' => $policy,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $result = $this->edges->createOrReplay($row, [
                'owner',
                'assignment_provenance',
                'starts_at',
                'expires_at',
                'drip_available_at',
                'policy',
            ]);
            if ($result['action'] !== 'created') {
                return $result;
            }

            $this->syncAggregate($result['edge'], 'expired');
            $this->syncActiveCompatibility($result['edge'], 'created', 0);
            AuditLogger::logRequired(
                'entitlement_edge',
                (int) $result['edge']['id'],
                'renewal_successor_created',
                ['predecessor_edge_id' => (int) $current['id']],
                [
                    'source_type' => 'order',
                    'source_id' => $renewalOrderId,
                    'subscription_id' => $subscriptionId,
                    'expires_at' => $expiresAt,
                    'event_receipt' => $eventReceipt,
                ],
                $this->clock,
                'subscription_renewal'
            );

            return $result;
        });
    }

    public function endMatching(
        int $userId,
        int $planId,
        array $context,
        string $reason,
        string $terminalGrantStatus = 'revoked'
    ): array {
        $matched = $this->getActiveMatching($userId, $planId, $context);
        $ended = [];
        foreach ($matched as $edge) {
            $identity = array_intersect_key($edge, array_flip(self::IDENTITY_FIELDS));
            $result = $this->end($identity, $reason, $terminalGrantStatus);
            if ($result['action'] === 'ended') {
                $ended[] = $result['edge'];
            }
        }

        return $ended;
    }

    public function endWithRevokeIntent(
        array $identity,
        string $reason,
        string $originEvent,
        string $terminalGrantStatus = 'revoked'
    ): array {
        if ($this->providerOperations === null) {
            throw new \RuntimeException('Provider operation storage is required for atomic revocation.');
        }
        $identity = $this->normaliseIdentity($identity);
        if (!in_array($terminalGrantStatus, ['expired', 'revoked'], true)) {
            throw new \InvalidArgumentException('Terminal grant status must be expired or revoked.');
        }
        $reason = trim($reason);
        if ($reason === '' || $this->length($reason) > 191) {
            throw new \InvalidArgumentException('Entitlement end reason must contain 1 to 191 characters.');
        }
        $endedAt = $this->now();

        return $this->edges->resourceTransaction($identity, function () use (
            $identity,
            $reason,
            $originEvent,
            $terminalGrantStatus,
            $endedAt
        ): array {
            $result = $this->edges->endByIdentity($identity, $endedAt, $reason);
            $operation = null;
            if ($result['action'] === 'ended') {
                $edge = $result['edge'];
                $this->syncAggregate($identity, $terminalGrantStatus);
                $this->syncEndedCompatibility($identity);
                if ($this->requiresProviderRevokeIntent($edge)) {
                    $operation = $this->providerOperations->createOrFind(
                        (int) $edge['id'],
                        'revoke',
                        $originEvent
                    );
                }
            }

            return [
                'action' => $result['action'],
                'edge' => $result['edge'],
                'operation' => $operation,
            ];
        });
    }

    public function findProviderOperation(array $edge, string $desiredAction, string $originEvent): ?array
    {
        if ($this->providerOperations === null) {
            throw new \RuntimeException('Provider operation storage is unavailable.');
        }
        $operationKey = $this->providerOperations->operationKey(
            (int) $edge['id'],
            $desiredAction,
            $originEvent
        );

        return $this->providerOperations->findByOperationKey($operationKey);
    }

    public function finalizeAppliedRevokeProviderOperation(int $operationId): bool
    {
        if ($this->providerOperations === null) {
            throw new \RuntimeException('Provider operation storage is unavailable.');
        }

        return $this->providerOperations->finalizeAppliedRevoke($operationId);
    }

    public function getActiveByResource(array $edge): array
    {
        return $this->edges->getActiveByResource(
            (int) $edge['user_id'],
            (string) $edge['provider'],
            (string) $edge['resource_type'],
            (string) $edge['resource_id']
        );
    }

    /** @param list<array<string, mixed>> $edges */
    public function setAccessStatus(array $edges, string $accessStatus): int
    {
        if ($edges === [] || !in_array($accessStatus, EntitlementEdgeRepository::ACCESS_STATUSES, true)) {
            throw new \InvalidArgumentException('Entitlement access status change is invalid.');
        }

        $resource = $edges[0];
        $resourceKey = $this->grantKey($resource);
        $edgeIds = [];
        foreach ($edges as $edge) {
            $edgeId = (int) ($edge['id'] ?? 0);
            if (
                $edgeId <= 0
                || ($edge['lifecycle'] ?? '') !== 'active'
                || $this->grantKey($edge) !== $resourceKey
            ) {
                throw new \InvalidArgumentException('Entitlement access status edges are invalid.');
            }
            $edgeIds[] = $edgeId;
        }
        $edgeIds = array_values(array_unique($edgeIds));

        $changed = $this->edges->resourceTransaction($resource, function () use (
            $resource,
            $edgeIds,
            $accessStatus
        ): int {
            $changed = $this->edges->setAccessStatusByIds($edgeIds, $accessStatus, $this->now());
            if ($changed > 0) {
                $this->syncAggregate($resource, 'revoked');
            }

            return $changed;
        });
        if ($changed > 0) {
            AccessEvaluator::clearCache();
        }

        return $changed;
    }

    public function hasUnsafeAssignmentEvidence(array $edge): bool
    {
        return $this->edges->hasUnsafeAssignmentEvidence(
            (int) $edge['user_id'],
            (string) $edge['provider'],
            (string) $edge['resource_type'],
            (string) $edge['resource_id']
        );
    }

    public function scheduleGrace(
        array $edges,
        string $requestedAt,
        string $effectiveAt,
        string $reason
    ): array {
        $scheduled = [];
        $resources = [];
        foreach ($edges as $edge) {
            $resources[$this->grantKey($edge)][] = $edge;
        }
        foreach ($resources as $resourceEdges) {
            $edge = $resourceEdges[0];
            $grant = $this->grants->findByGrantKey($this->grantKey($edge));
            if (!$grant) {
                throw new \RuntimeException('The entitlement aggregate mirror could not be found.');
            }
            $meta = is_array($grant['meta'] ?? null) ? $grant['meta'] : [];
            $snapshots = [];
            foreach ((array) ($meta['entitlement_grace_edges'] ?? []) as $snapshot) {
                $normalised = $this->normaliseGraceSnapshot(
                    $snapshot,
                    (string) ($grant['cancellation_requested_at'] ?? $requestedAt),
                    (string) ($grant['cancellation_effective_at'] ?? $effectiveAt),
                    (string) ($grant['cancellation_reason'] ?? $reason)
                );
                $snapshots[$normalised['edge_id']] = $normalised;
            }
            foreach ($resourceEdges as $snapshot) {
                $normalised = $this->graceSnapshot($snapshot, $requestedAt, $effectiveAt, $reason);
                $snapshots[$normalised['edge_id']] = $normalised;
            }
            if (count($snapshots) > self::GRACE_EDGE_SNAPSHOT_LIMIT) {
                throw new \RuntimeException('The entitlement grace snapshot exceeds its safe bound.');
            }
            ksort($snapshots, SORT_NUMERIC);
            $meta['entitlement_grace_edges'] = array_values($snapshots);
            $meta['revoke_reason'] = $reason;
            $requestedTimes = array_column($meta['entitlement_grace_edges'], 'requested_at');
            $effectiveTimes = array_column($meta['entitlement_grace_edges'], 'effective_at');
            if (!$this->grants->update((int) $grant['id'], [
                'cancellation_requested_at' => min($requestedTimes),
                'cancellation_effective_at' => min($effectiveTimes),
                'cancellation_reason' => $reason,
                'meta' => $meta,
            ])) {
                throw new \RuntimeException('The entitlement grace mirror could not be persisted.');
            }
            $scheduled[] = $grant;
        }

        return $scheduled;
    }

    /**
     * Record a legacy edge exactly as observed without rewriting the aggregate grant.
     */
    public function recordHistoricalEdge(array $identity, array $attributes): array
    {
        $identity = $this->normaliseIdentity($identity);
        $attributes = $this->normaliseAttributes($attributes);
        $lifecycle = trim((string) ($attributes['lifecycle'] ?? 'active'));
        if (!in_array($lifecycle, ['active', 'ended'], true)) {
            throw new \InvalidArgumentException('Historical entitlement lifecycle is invalid.');
        }

        $endedAt = $attributes['ended_at'] ?? null;
        $endReason = isset($attributes['end_reason'])
            ? trim((string) $attributes['end_reason'])
            : null;
        if ($lifecycle === 'ended' && ($endedAt === null || $endReason === null || $endReason === '')) {
            throw new \InvalidArgumentException('Ended historical entitlements require an end timestamp and reason.');
        }
        if ($lifecycle === 'active' && ($endedAt !== null || $endReason !== null)) {
            throw new \InvalidArgumentException('Active historical entitlements cannot contain end metadata.');
        }
        if ($endReason !== null && $this->length($endReason) > 191) {
            throw new \InvalidArgumentException('Entitlement end reason must contain at most 191 characters.');
        }

        $now = $this->now();
        $row = array_merge($identity, [
            'owner' => $attributes['owner'],
            'assignment_provenance' => $attributes['assignment_provenance'],
            'lifecycle' => $lifecycle,
            'access_status' => $attributes['access_status'],
            'starts_at' => $attributes['starts_at'] ?? null,
            'expires_at' => $attributes['expires_at'] ?? null,
            'drip_available_at' => $attributes['drip_available_at'] ?? null,
            'ended_at' => $endedAt,
            'end_reason' => $endReason,
            'policy' => $attributes['policy'] ?? [],
            'created_at' => $attributes['created_at'] ?? $now,
            'updated_at' => $attributes['updated_at'] ?? $now,
        ]);
        $comparisonFields = [
            'owner',
            'assignment_provenance',
            'lifecycle',
            'access_status',
            'starts_at',
            'expires_at',
            'drip_available_at',
            'ended_at',
            'end_reason',
            'policy',
        ];

        return $this->edges->resourceTransaction($row, function () use ($row, $comparisonFields): array {
            $existing = $this->edges->findByIdentity($row);
            if ($existing) {
                foreach ($comparisonFields as $field) {
                    if (($existing[$field] ?? null) !== ($row[$field] ?? null)) {
                        return ['action' => 'immutable_conflict', 'edge' => $existing];
                    }
                }

                return ['action' => 'replayed', 'edge' => $existing];
            }

            return $this->edges->createOrReplay($row, $comparisonFields);
        });
    }

    /** @return array<string, mixed> */
    private function normaliseIdentity(array $identity): array
    {
        foreach (self::IDENTITY_FIELDS as $field) {
            if (!array_key_exists($field, $identity)) {
                throw new \InvalidArgumentException("Entitlement identity field {$field} is required.");
            }
        }

        $normalised = [
            'user_id' => (int) $identity['user_id'],
            'provider' => trim((string) $identity['provider']),
            'resource_type' => trim((string) $identity['resource_type']),
            'resource_id' => trim((string) $identity['resource_id']),
            'plan_id' => (int) $identity['plan_id'],
            'feed_id' => (int) $identity['feed_id'],
            'feed_scope' => trim((string) $identity['feed_scope']),
            'source_type' => trim((string) $identity['source_type']),
            'source_id' => (int) $identity['source_id'],
        ];

        if ($normalised['user_id'] <= 0) {
            throw new \InvalidArgumentException('Entitlement user ID must be greater than zero.');
        }
        foreach (['plan_id', 'feed_id', 'source_id'] as $field) {
            if ($normalised[$field] < 0) {
                throw new \InvalidArgumentException("Entitlement {$field} cannot be negative.");
            }
        }
        foreach (['provider', 'resource_type', 'resource_id', 'source_type'] as $field) {
            if ($normalised[$field] === '') {
                throw new \InvalidArgumentException("Entitlement {$field} cannot be empty.");
            }
        }
        if (!in_array($normalised['feed_scope'], EntitlementEdgeRepository::FEED_SCOPES, true)) {
            throw new \InvalidArgumentException('Entitlement feed scope is invalid.');
        }

        return $normalised;
    }

    /** @return array<string, mixed> */
    private function normaliseAttributes(array $attributes): array
    {
        $attributes['owner'] = trim((string) ($attributes['owner'] ?? 'external_unknown'));
        $attributes['assignment_provenance'] = trim((string) (
            $attributes['assignment_provenance'] ?? 'unknown'
        ));
        $attributes['access_status'] = trim((string) ($attributes['access_status'] ?? 'active'));

        if (!in_array($attributes['owner'], EntitlementEdgeRepository::OWNERS, true)) {
            throw new \InvalidArgumentException('Entitlement owner is invalid.');
        }
        if (!in_array(
            $attributes['assignment_provenance'],
            EntitlementEdgeRepository::ASSIGNMENT_PROVENANCES,
            true
        )) {
            throw new \InvalidArgumentException('Entitlement assignment provenance is invalid.');
        }
        if (!in_array($attributes['access_status'], EntitlementEdgeRepository::ACCESS_STATUSES, true)) {
            throw new \InvalidArgumentException('Entitlement access status is invalid.');
        }
        if (isset($attributes['policy']) && !is_array($attributes['policy'])) {
            throw new \InvalidArgumentException('Entitlement policy must be an array.');
        }
        if (isset($attributes['aggregate_meta']) && !is_array($attributes['aggregate_meta'])) {
            throw new \InvalidArgumentException('Entitlement aggregate metadata must be an array.');
        }

        return $attributes;
    }

    private function syncAggregate(array $resource, string $terminalStatus): void
    {
        $active = $this->edges->getActiveByResource(
            (int) $resource['user_id'],
            (string) $resource['provider'],
            (string) $resource['resource_type'],
            (string) $resource['resource_id']
        );
        $grantKey = GrantRepository::makeGrantKey(
            (int) $resource['user_id'],
            (string) $resource['provider'],
            (string) $resource['resource_type'],
            (string) $resource['resource_id']
        );
        $grant = $this->grants->findByGrantKey($grantKey);

        if ($active === []) {
            if (!$grant) {
                throw new \RuntimeException('The entitlement aggregate mirror could not be found.');
            }
            $meta = $grant['meta'] ?? [];
            $meta['provider_access_owner'] = 'unknown';
            if (!$this->grants->update((int) $grant['id'], [
                'status' => $terminalStatus,
                'source_ids' => [],
                'meta' => $meta,
            ])) {
                throw new \RuntimeException('The entitlement aggregate mirror could not be persisted.');
            }

            return;
        }

        $effective = array_values(array_filter(
            $active,
            static fn(array $edge): bool => ($edge['access_status'] ?? 'active') === 'active'
        ));
        $projection = $effective !== [] ? $effective : $active;

        usort($projection, static function (array $left, array $right): int {
            $startComparison = strcmp((string) ($right['starts_at'] ?? ''), (string) ($left['starts_at'] ?? ''));
            return $startComparison !== 0
                ? $startComparison
                : ((int) $right['id'] <=> (int) $left['id']);
        });
        $representative = $projection[0];
        $sourceIds = [];
        foreach ($projection as $edge) {
            if ($edge['source_type'] === $representative['source_type'] && (int) $edge['source_id'] > 0) {
                $sourceIds[] = (int) $edge['source_id'];
            }
        }
        $sourceIds = array_values(array_unique($sourceIds));
        sort($sourceIds, SORT_NUMERIC);

        $provenances = array_values(array_unique(array_column($projection, 'assignment_provenance')));
        $owners = array_values(array_unique(array_column($projection, 'owner')));
        $providerAccessOwner = match (true) {
            $provenances === ['fchub_created'] && $owners === ['fchub'] => 'fchub',
            $provenances === ['preexisting'] => 'preexisting',
            default => 'unknown',
        };
        $meta = $grant['meta'] ?? [];
        $meta['provider_access_owner'] = $providerAccessOwner;
        $mirror = [
            'status' => $effective !== [] ? 'active' : 'paused',
            'plan_id' => (int) $representative['plan_id'],
            'source_type' => (string) $representative['source_type'],
            'source_id' => (int) $representative['source_id'],
            'feed_id' => (int) $representative['feed_id'],
            'starts_at' => $this->unionWindow($projection, 'starts_at', 'min'),
            'expires_at' => $this->unionWindow($projection, 'expires_at', 'max'),
            'drip_available_at' => $this->unionWindow($projection, 'drip_available_at', 'min'),
            'source_ids' => $sourceIds,
            'meta' => $meta,
        ];

        if ($grant) {
            if (!$this->grants->update((int) $grant['id'], $mirror)) {
                throw new \RuntimeException('The entitlement aggregate mirror could not be persisted.');
            }
            return;
        }

        $grantId = $this->grants->create(array_merge($mirror, [
            'user_id' => (int) $representative['user_id'],
            'provider' => (string) $representative['provider'],
            'resource_type' => (string) $representative['resource_type'],
            'resource_id' => (string) $representative['resource_id'],
            'grant_key' => $grantKey,
        ]));
        if ($grantId <= 0) {
            throw new \RuntimeException('The entitlement aggregate mirror could not be persisted.');
        }
    }

    private function syncAggregateContext(
        array $edge,
        array $attributes,
        string $action,
        ?int $projectedRenewalCount = null
    ): void
    {
        $grant = $this->grants->findByGrantKey($this->grantKey($edge));
        if (!$grant) {
            throw new \RuntimeException('The entitlement aggregate mirror could not be found.');
        }

        $existingMeta = is_array($grant['meta'] ?? null) ? $grant['meta'] : [];
        $contextMeta = is_array($attributes['aggregate_meta'] ?? null)
            ? $attributes['aggregate_meta']
            : [];
        unset($contextMeta['provider_access_owner']);
        $meta = array_merge($existingMeta, $contextMeta);
        $update = [
            'meta' => $meta,
            'renewal_count' => $projectedRenewalCount
                ?? ((int) ($grant['renewal_count'] ?? 0) + ($action === 'replayed' ? 1 : 0)),
        ];
        if (array_key_exists('trial_ends_at', $attributes)) {
            $update['trial_ends_at'] = $attributes['trial_ends_at'];
        }

        $incident = $meta['payment_incident'] ?? null;
        $subscriptionCorrelation = (int) (($edge['policy']['subscription_id'] ?? null)
            ?? (($edge['source_type'] ?? null) === 'subscription' ? ($edge['source_id'] ?? 0) : 0));
        if (($action === 'replayed' || ($projectedRenewalCount !== null
                && $projectedRenewalCount > (int) ($grant['renewal_count'] ?? 0)))
            && is_array($incident)
            && $subscriptionCorrelation > 0
            && $subscriptionCorrelation === (int) ($incident['subscription_id'] ?? 0)
            && empty($incident['recovered_at'])
        ) {
            $update['meta']['payment_incident']['recovered_at'] = $this->now();
            $update['meta']['payment_incident']['recovery_renewal_count'] = $update['renewal_count'];
        }

        if (!$this->grants->update((int) $grant['id'], $update)) {
            throw new \RuntimeException('The entitlement aggregate context could not be persisted.');
        }
    }

    private function syncActiveCompatibility(array $edge, string $action, int $dripRuleId): void
    {
        if ($this->sources === null && $this->drips === null) {
            return;
        }

        $grant = $this->grants->findByGrantKey($this->grantKey($edge));
        if (!$grant) {
            throw new \RuntimeException('The entitlement aggregate mirror could not be found.');
        }
        if ($this->sources !== null && (int) $edge['source_id'] > 0) {
            if (!$this->sources->addSource(
                (int) $grant['id'],
                (string) $edge['source_type'],
                (int) $edge['source_id']
            )) {
                throw new \RuntimeException('The entitlement source mirror could not be persisted.');
            }
        }
        if ($this->drips !== null
            && $action === 'created'
            && $dripRuleId > 0
            && !empty($edge['drip_available_at'])
            && $this->drips->schedule([
                'grant_id' => (int) $grant['id'],
                'plan_rule_id' => $dripRuleId,
                'user_id' => (int) $edge['user_id'],
                'notify_at' => (string) $edge['drip_available_at'],
            ]) <= 0
        ) {
            throw new \RuntimeException('The entitlement drip mirror could not be persisted.');
        }
    }

    private function syncEndedCompatibility(array $edge): void
    {
        if ($this->sources === null && $this->drips === null) {
            return;
        }

        $active = $this->edges->getActiveByResource(
            (int) $edge['user_id'],
            (string) $edge['provider'],
            (string) $edge['resource_type'],
            (string) $edge['resource_id']
        );
        $grant = $this->grants->findByGrantKey($this->grantKey($edge));
        if (!$grant) {
            throw new \RuntimeException('The entitlement aggregate mirror could not be found.');
        }
        $typedSourceSurvives = array_filter(
            $active,
            static fn(array $activeEdge): bool => $activeEdge['source_type'] === $edge['source_type']
                && (int) $activeEdge['source_id'] === (int) $edge['source_id']
        ) !== [];
        if ($this->sources !== null
            && (int) $edge['source_id'] > 0
            && !$typedSourceSurvives
            && !$this->sources->removeSource(
                (int) $grant['id'],
                (string) $edge['source_type'],
                (int) $edge['source_id']
            )
        ) {
            throw new \RuntimeException('The entitlement source mirror could not be removed.');
        }
        if ($this->drips !== null && $active === []) {
            $this->drips->deleteByGrantId((int) $grant['id']);
        }
    }

    private function requiresProviderRevokeIntent(array $edge): bool
    {
        if (($edge['provider'] ?? '') === 'wordpress_core'
            || ($edge['owner'] ?? '') !== 'fchub'
            || ($edge['assignment_provenance'] ?? '') !== 'fchub_created'
        ) {
            return false;
        }
        if ($this->edges->getActiveByResource(
            (int) $edge['user_id'],
            (string) $edge['provider'],
            (string) $edge['resource_type'],
            (string) $edge['resource_id']
        ) !== []) {
            return false;
        }

        return !$this->edges->hasUnsafeAssignmentEvidence(
            (int) $edge['user_id'],
            (string) $edge['provider'],
            (string) $edge['resource_type'],
            (string) $edge['resource_id']
        );
    }

    private function grantKey(array $edge): string
    {
        return GrantRepository::makeGrantKey(
            (int) $edge['user_id'],
            (string) $edge['provider'],
            (string) $edge['resource_type'],
            (string) $edge['resource_id']
        );
    }

    private function uniqueResources(array $edges): array
    {
        $resources = [];
        foreach ($edges as $edge) {
            $resources[$this->grantKey($edge)] = $edge;
        }

        return array_values($resources);
    }

    private function assertLifecycleReceipt(string $eventReceipt): void
    {
        if (preg_match('/^[a-f0-9]{64}$/', $eventReceipt) !== 1) {
            throw new \InvalidArgumentException('Lifecycle event receipt must be a SHA-256 hash.');
        }
    }

    private function graceSnapshot(
        array $edge,
        string $requestedAt,
        string $effectiveAt,
        string $reason
    ): array {
        return $this->normaliseGraceSnapshot(array_merge(
            ['edge_id' => (int) ($edge['id'] ?? 0)],
            array_intersect_key($edge, array_flip(self::IDENTITY_FIELDS)),
            ['requested_at' => $requestedAt, 'effective_at' => $effectiveAt, 'reason' => $reason]
        ), $requestedAt, $effectiveAt, $reason);
    }

    private function normaliseGraceSnapshot(
        mixed $snapshot,
        string $fallbackRequestedAt,
        string $fallbackEffectiveAt,
        string $fallbackReason
    ): array {
        if (!is_array($snapshot) || (int) ($snapshot['edge_id'] ?? 0) <= 0) {
            throw new \RuntimeException('The entitlement grace snapshot is invalid.');
        }
        $identity = $this->normaliseIdentity(array_intersect_key($snapshot, array_flip(self::IDENTITY_FIELDS)));
        $requestedAt = $this->normaliseLifecycleDate((string) ($snapshot['requested_at'] ?? $fallbackRequestedAt));
        $effectiveAt = $this->normaliseLifecycleDate((string) ($snapshot['effective_at'] ?? $fallbackEffectiveAt));
        $reason = trim((string) ($snapshot['reason'] ?? $fallbackReason));
        if ($reason === '' || $this->length($reason) > 191) {
            throw new \RuntimeException('The entitlement grace reason is invalid.');
        }

        return array_merge(['edge_id' => (int) $snapshot['edge_id']], $identity, [
            'requested_at' => $requestedAt,
            'effective_at' => $effectiveAt,
            'reason' => $reason,
        ]);
    }

    private function normaliseLifecycleDate(string $value): string
    {
        $value = trim($value);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $this->clock->now()->getTimezone());
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$parsed || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new \InvalidArgumentException('Lifecycle expiry must use the storage timestamp format.');
        }

        return $value;
    }

    private function now(): string
    {
        return $this->clock->storage($this->clock->now());
    }

    private function unionWindow(array $edges, string $field, string $boundedOperation): ?string
    {
        $values = [];
        foreach ($edges as $edge) {
            if (!isset($edge[$field]) || $edge[$field] === '') {
                return null;
            }
            $values[] = (string) $edge[$field];
        }

        return $boundedOperation === 'max' ? max($values) : min($values);
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }
}

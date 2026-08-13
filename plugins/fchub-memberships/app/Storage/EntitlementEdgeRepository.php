<?php

namespace FChubMemberships\Storage;

use FChubMemberships\Support\Logger;

defined('ABSPATH') || exit;

class EntitlementEdgeRepository
{
    public const FEED_SCOPES = ['product', 'global', 'external_unknown'];
    public const OWNERS = ['fchub', 'preexisting', 'external_unknown'];
    public const ASSIGNMENT_PROVENANCES = ['fchub_created', 'preexisting', 'unknown'];
    public const LIFECYCLES = ['active', 'ended'];
    public const ACCESS_STATUSES = ['active', 'paused'];

    private string $table;
    private string $operationsTable;

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

    public function __construct()
    {
        global $wpdb;
        $this->table = \FChubMemberships\Support\CustomTableDatabase::identifier($wpdb->prefix . 'fchub_membership_entitlement_edges');
        $this->operationsTable = \FChubMemberships\Support\CustomTableDatabase::identifier($wpdb->prefix . 'fchub_membership_provider_operations');
    }

    public function findById(int $id): ?array
    {
        global $wpdb;

        $row = \FChubMemberships\Support\CustomTableDatabase::getRow(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT * FROM {$this->table} WHERE id = %d",
            $id
        ), ARRAY_A);
        if ($this->databaseHasError()) {
            throw new \RuntimeException('Unable to read entitlement edge.');
        }

        return $row ? $this->hydrate($row) : null;
    }

    public function findByIdentity(array $identity): ?array
    {
        global $wpdb;

        $row = \FChubMemberships\Support\CustomTableDatabase::getRow(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT * FROM {$this->table}
             WHERE user_id = %d
               AND provider = %s
               AND resource_type = %s
               AND resource_id = %s
               AND plan_id = %d
               AND feed_id = %d
               AND feed_scope = %s
               AND source_type = %s
               AND source_id = %d",
            (int) $identity['user_id'],
            (string) $identity['provider'],
            (string) $identity['resource_type'],
            (string) $identity['resource_id'],
            (int) $identity['plan_id'],
            (int) $identity['feed_id'],
            (string) $identity['feed_scope'],
            (string) $identity['source_type'],
            (int) $identity['source_id']
        ), ARRAY_A);
        if ($this->databaseHasError()) {
            throw new \RuntimeException('Unable to read entitlement identity.');
        }

        return $row ? $this->hydrate($row) : null;
    }

    public function createOrReplay(array $data, ?array $comparisonFields = null): array
    {
        $existing = $this->findByIdentity($data);
        if ($existing) {
            return $this->replayResult($existing, $data, $comparisonFields);
        }

        global $wpdb;
        $insert = $this->persistedFields($data);
        $insert['policy'] = wp_json_encode($insert['policy'] ?? []);
        $created = \FChubMemberships\Support\CustomTableDatabase::insert($this->table, $insert);
        if ($created === false) {
            $existing = $this->findByIdentity($data);
            if ($existing) {
                return $this->replayResult($existing, $data, $comparisonFields);
            }

            throw new \RuntimeException('The entitlement edge could not be persisted.');
        }

        $insertId = (int) $wpdb->insert_id;
        if ($insertId <= 0) {
            throw new \RuntimeException('The entitlement edge did not return a valid identifier.');
        }
        $insert['id'] = $insertId;

        return [
            'action' => 'created',
            'edge' => $this->hydrate($insert),
        ];
    }

    public function endByIdentity(array $identity, string $endedAt, string $reason): array
    {
        $existing = $this->findByIdentity($identity);
        if (!$existing) {
            return ['action' => 'not_found', 'edge' => null];
        }
        if ($existing['lifecycle'] === 'ended') {
            return ['action' => 'already_ended', 'edge' => $existing];
        }

        global $wpdb;
        $update = [
            'lifecycle' => 'ended',
            'ended_at' => $endedAt,
            'end_reason' => $reason,
            'updated_at' => $endedAt,
        ];
        $updated = \FChubMemberships\Support\CustomTableDatabase::update(
            $this->table,
            $update,
            ['id' => (int) $existing['id'], 'lifecycle' => 'active']
        );
        if ($updated === false) {
            throw new \RuntimeException('The entitlement edge could not be ended.');
        }
        if ($updated === 0) {
            $current = $this->findByIdentity($identity);
            if ($current && $current['lifecycle'] === 'ended') {
                return ['action' => 'already_ended', 'edge' => $current];
            }

            throw new \RuntimeException('The active entitlement edge changed before it could be ended.');
        }

        return [
            'action' => 'ended',
            'edge' => array_merge($existing, $update),
        ];
    }

    public function extendActiveExpiryById(int $edgeId, ?string $currentExpiry, string $newExpiry, string $updatedAt): array
    {
        $existing = $this->findById($edgeId);
        if (!$existing || ($existing['lifecycle'] ?? '') !== 'active') {
            return ['action' => 'not_active', 'edge' => $existing];
        }
        if (($existing['expires_at'] ?? null) !== $currentExpiry) {
            return ['action' => 'changed', 'edge' => $existing];
        }
        if ($currentExpiry === null || strcmp($newExpiry, $currentExpiry) <= 0) {
            return ['action' => 'unchanged', 'edge' => $existing];
        }

        global $wpdb;
        $updated = \FChubMemberships\Support\CustomTableDatabase::update(
            $this->table,
            ['expires_at' => $newExpiry, 'updated_at' => $updatedAt],
            ['id' => $edgeId, 'lifecycle' => 'active', 'expires_at' => $currentExpiry]
        );
        if ($updated === false) {
            throw new \RuntimeException('The entitlement expiry could not be extended.');
        }
        if ($updated === 0) {
            $current = $this->findById($edgeId);
            if ($current && ($current['expires_at'] ?? null) === $newExpiry) {
                return ['action' => 'unchanged', 'edge' => $current];
            }

            return ['action' => 'changed', 'edge' => $current];
        }

        return [
            'action' => 'extended',
            'edge' => array_merge($existing, ['expires_at' => $newExpiry, 'updated_at' => $updatedAt]),
        ];
    }

    public function getActiveByResource(
        int $userId,
        string $provider,
        string $resourceType,
        string $resourceId
    ): array {
        global $wpdb;

        $rows = \FChubMemberships\Support\CustomTableDatabase::getResults(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT * FROM {$this->table}
             WHERE user_id = %d
               AND provider = %s
               AND resource_type = %s
               AND resource_id = %s
               AND lifecycle = 'active'
             ORDER BY (starts_at IS NULL) ASC, starts_at DESC, id DESC",
            $userId,
            $provider,
            $resourceType,
            $resourceId
        ), ARRAY_A);
        if (!is_array($rows) || $this->databaseHasError()) {
            throw new \RuntimeException('Unable to read active entitlement edges.');
        }

        return array_map([$this, 'hydrate'], $rows);
    }

    public function getActiveByUserProvider(int $userId, string $provider): array
    {
        if ($userId <= 0 || trim($provider) === '') {
            throw new \InvalidArgumentException('Entitlement user and provider are required.');
        }

        global $wpdb;

        $rows = \FChubMemberships\Support\CustomTableDatabase::getResults(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT * FROM {$this->table}
             WHERE user_id = %d
               AND provider = %s
               AND lifecycle = 'active'
             ORDER BY resource_type ASC, resource_id ASC, id ASC",
            $userId,
            $provider
        ), ARRAY_A);
        if (!is_array($rows) || $this->databaseHasError()) {
            throw new \RuntimeException('Unable to read active entitlement edges for member.');
        }

        return array_map([$this, 'hydrate'], $rows);
    }

    /**
     * Every active edge a member holds, across providers.
     *
     * @return list<array<string, mixed>>
     */
    public function getActiveByUser(int $userId): array
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('Entitlement user is required.');
        }

        $rows = \FChubMemberships\Support\CustomTableDatabase::getResults(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT * FROM {$this->table}
             WHERE user_id = %d
               AND lifecycle = 'active'
             ORDER BY provider ASC, resource_type ASC, resource_id ASC, id ASC",
            $userId
        ), ARRAY_A);
        if (!is_array($rows) || $this->databaseHasError()) {
            throw new \RuntimeException('Unable to read active entitlement edges for member.');
        }

        return array_map([$this, 'hydrate'], $rows);
    }

    /** @param list<int> $edgeIds */
    public function setAccessStatusByIds(array $edgeIds, string $accessStatus, string $updatedAt): int
    {
        $edgeIds = array_values(array_unique(array_map('intval', $edgeIds)));
        if (
            $edgeIds === []
            || count($edgeIds) > 64
            || min($edgeIds) <= 0
            || !in_array($accessStatus, self::ACCESS_STATUSES, true)
        ) {
            throw new \InvalidArgumentException('Entitlement access status update is invalid.');
        }

        global $wpdb;
        $placeholders = implode(', ', array_fill(0, count($edgeIds), '%d'));
        $updated = \FChubMemberships\Support\CustomTableDatabase::query(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "UPDATE {$this->table}
             SET access_status = %s, updated_at = %s
             WHERE lifecycle = 'active'
               AND access_status <> %s
               AND id IN ({$placeholders})",
            $accessStatus,
            $updatedAt,
            $accessStatus,
            ...$edgeIds
        ));
        if ($updated === false || $this->databaseHasError()) {
            throw new \RuntimeException('The entitlement access status could not be persisted.');
        }

        return (int) $updated;
    }

    public function maxReconciliationEdgeId(): int
    {
        global $wpdb;

        $maximum = \FChubMemberships\Support\CustomTableDatabase::getVar(
            \FChubMemberships\Support\CustomTableDatabase::prepare(
                "SELECT COALESCE(MAX(id), 0) FROM {$this->table} WHERE 1 = %d",
                1,
            ),
        );
        if ($maximum === null || $this->databaseHasError()) {
            throw new \RuntimeException('Unable to read reconciliation watermark.');
        }

        return max(0, (int) $maximum);
    }

    /**
     * @return list<array{
     *     cursor_id: int,
     *     resource: array{user_id: int, provider: string, resource_type: string, resource_id: string},
     *     edges: list<array<string, mixed>>
     * }>
     */
    public function getReconciliationResourcePage(int $afterId, int $throughId, int $limit): array
    {
        global $wpdb;

        if ($afterId < 0 || $throughId < $afterId || $limit <= 0 || $limit > 101) {
            throw new \InvalidArgumentException('Reconciliation page criteria are invalid.');
        }

        $rows = \FChubMemberships\Support\CustomTableDatabase::getResults(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT edge.*, resource_page.cursor_id
             FROM {$this->table} edge
             INNER JOIN (
                 SELECT MIN(id) AS cursor_id, user_id, provider, resource_type, resource_id
                 FROM {$this->table}
                 WHERE id <= %d
                 GROUP BY user_id, provider, resource_type, resource_id
                 HAVING MIN(id) > %d
                 ORDER BY cursor_id ASC LIMIT {$limit}
             ) resource_page
               ON resource_page.user_id = edge.user_id
              AND resource_page.provider = edge.provider
              AND resource_page.resource_type = edge.resource_type
              AND resource_page.resource_id = edge.resource_id
             WHERE edge.id <= %d
             ORDER BY resource_page.cursor_id ASC, edge.id ASC",
            $throughId,
            $afterId,
            $throughId
        ), ARRAY_A);
        if (!is_array($rows) || $this->databaseHasError()) {
            throw new \RuntimeException('Unable to read reconciliation resource page.');
        }

        $unions = [];
        foreach ($rows as $row) {
            $cursorId = (int) ($row['cursor_id'] ?? 0);
            unset($row['cursor_id']);
            $edge = $this->hydrate($row);
            if (!isset($unions[$cursorId])) {
                $unions[$cursorId] = [
                    'cursor_id' => $cursorId,
                    'resource' => [
                        'user_id' => (int) $edge['user_id'],
                        'provider' => (string) $edge['provider'],
                        'resource_type' => (string) $edge['resource_type'],
                        'resource_id' => (string) $edge['resource_id'],
                    ],
                    'edges' => [],
                ];
            }
            $unions[$cursorId]['edges'][] = $edge;
        }

        return array_values($unions);
    }

    /** @return list<array<string, mixed>> */
    public function getByResource(
        int $userId,
        string $provider,
        string $resourceType,
        string $resourceId
    ): array {
        global $wpdb;

        if ($userId <= 0 || $provider === '' || $resourceType === '' || $resourceId === '') {
            throw new \InvalidArgumentException('Entitlement resource identity is invalid.');
        }
        $rows = \FChubMemberships\Support\CustomTableDatabase::getResults(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT * FROM {$this->table}
             WHERE user_id = %d
               AND provider = %s
               AND resource_type = %s
               AND resource_id = %s
             ORDER BY id ASC",
            $userId,
            $provider,
            $resourceType,
            $resourceId
        ), ARRAY_A);
        if (!is_array($rows) || $this->databaseHasError()) {
            throw new \RuntimeException('Unable to read entitlement resource edges.');
        }

        return array_map([$this, 'hydrate'], $rows);
    }

    public function getActiveMatching(int $userId, int $planId, array $context = []): array
    {
        global $wpdb;

        $hasSourceType = array_key_exists('source_type', $context);
        $hasSourceId = array_key_exists('source_id', $context);
        if ($userId <= 0 || $planId < 0 || $hasSourceType !== $hasSourceId) {
            throw new \InvalidArgumentException('Typed entitlement revocation criteria are invalid.');
        }

        $where = [
            'user_id = %d',
            'plan_id = %d',
            "lifecycle = 'active'",
        ];
        $params = [$userId, $planId];
        if ($hasSourceType) {
            $sourceType = trim((string) $context['source_type']);
            $sourceId = (int) $context['source_id'];
            if ($sourceType === '' || $sourceId < 0) {
                throw new \InvalidArgumentException('Typed entitlement source criteria are invalid.');
            }
            $where[] = 'source_type = %s';
            $where[] = 'source_id = %d';
            $params[] = $sourceType;
            $params[] = $sourceId;
        }
        if (array_key_exists('feed_id', $context)) {
            $feedId = (int) $context['feed_id'];
            if ($feedId < 0) {
                throw new \InvalidArgumentException('Entitlement feed ID cannot be negative.');
            }
            $where[] = 'feed_id = %d';
            $params[] = $feedId;
        }
        if (array_key_exists('feed_scope', $context)) {
            $feedScope = trim((string) $context['feed_scope']);
            if (!in_array($feedScope, self::FEED_SCOPES, true)) {
                throw new \InvalidArgumentException('Entitlement feed scope is invalid.');
            }
            $where[] = 'feed_scope = %s';
            $params[] = $feedScope;
        }
        $this->appendEdgeIdCriteria($context, $where, $params);

        $rows = \FChubMemberships\Support\CustomTableDatabase::getResults(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT * FROM {$this->table} WHERE " . implode(' AND ', $where) . ' ORDER BY id ASC',
            ...$params
        ), ARRAY_A);
        if (!is_array($rows) || $this->databaseHasError()) {
            throw new \RuntimeException('Unable to read matching entitlement edges.');
        }

        return array_map([$this, 'hydrate'], $rows);
    }

    public function getEndedMatching(int $userId, int $planId, array $context = []): array
    {
        global $wpdb;

        $hasSourceType = array_key_exists('source_type', $context);
        $hasSourceId = array_key_exists('source_id', $context);
        if ($userId <= 0 || $planId < 0 || $hasSourceType !== $hasSourceId) {
            throw new \InvalidArgumentException('Typed entitlement revocation criteria are invalid.');
        }

        $where = [
            'user_id = %d',
            'plan_id = %d',
            "lifecycle = 'ended'",
        ];
        $params = [$userId, $planId];
        if ($hasSourceType) {
            $sourceType = trim((string) $context['source_type']);
            $sourceId = (int) $context['source_id'];
            if ($sourceType === '' || $sourceId < 0) {
                throw new \InvalidArgumentException('Typed entitlement source criteria are invalid.');
            }
            $where[] = 'source_type = %s';
            $where[] = 'source_id = %d';
            $params[] = $sourceType;
            $params[] = $sourceId;
        }
        if (array_key_exists('feed_id', $context)) {
            $feedId = (int) $context['feed_id'];
            if ($feedId < 0) {
                throw new \InvalidArgumentException('Entitlement feed ID cannot be negative.');
            }
            $where[] = 'feed_id = %d';
            $params[] = $feedId;
        }
        if (array_key_exists('feed_scope', $context)) {
            $feedScope = trim((string) $context['feed_scope']);
            if (!in_array($feedScope, self::FEED_SCOPES, true)) {
                throw new \InvalidArgumentException('Entitlement feed scope is invalid.');
            }
            $where[] = 'feed_scope = %s';
            $params[] = $feedScope;
        }
        $this->appendEdgeIdCriteria($context, $where, $params);

        $rows = \FChubMemberships\Support\CustomTableDatabase::getResults(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT * FROM {$this->table} WHERE " . implode(' AND ', $where) . ' ORDER BY id ASC',
            ...$params
        ), ARRAY_A);
        if (!is_array($rows) || $this->databaseHasError()) {
            throw new \RuntimeException('Unable to read ended matching entitlement edges.');
        }

        return array_map([$this, 'hydrate'], $rows);
    }

    public function getActiveByTypedSource(int $sourceId, string $sourceType): array
    {
        return $this->getByTypedSourceLifecycle($sourceId, $sourceType, 'active');
    }

    public function getEndedByTypedSource(int $sourceId, string $sourceType): array
    {
        return $this->getByTypedSourceLifecycle($sourceId, $sourceType, 'ended');
    }

    public function getBySubscriptionCorrelation(int $subscriptionId, string $lifecycle): array
    {
        if ($subscriptionId <= 0 || !in_array($lifecycle, self::LIFECYCLES, true)) {
            throw new \InvalidArgumentException('Subscription entitlement correlation is invalid.');
        }

        global $wpdb;
        $rows = \FChubMemberships\Support\CustomTableDatabase::getResults(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT * FROM {$this->table}
             WHERE lifecycle = %s
               AND (
                    (source_type = 'subscription' AND source_id = %d)
                    OR (
                        JSON_TYPE(JSON_EXTRACT(policy, '$.subscription_id')) = 'INTEGER'
                        AND JSON_UNQUOTE(JSON_EXTRACT(policy, '$.subscription_id')) = %s
                    )
               )
             ORDER BY user_id ASC, plan_id ASC, id ASC",
            $lifecycle,
            $subscriptionId,
            (string) $subscriptionId
        ), ARRAY_A);
        if (!is_array($rows) || $this->databaseHasError()) {
            throw new \RuntimeException('Unable to read subscription entitlement correlation.');
        }

        return array_map([$this, 'hydrate'], $rows);
    }

    public function getDueActive(string $at): array
    {
        global $wpdb;

        $rows = \FChubMemberships\Support\CustomTableDatabase::getResults(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT * FROM {$this->table}
             WHERE lifecycle = 'active'
               AND (
                    (expires_at IS NOT NULL AND expires_at <= %s)
                    OR (
                        JSON_TYPE(JSON_EXTRACT(policy, '$.membership_term_ends_at')) = 'STRING'
                        AND JSON_UNQUOTE(JSON_EXTRACT(policy, '$.membership_term_ends_at'))
                            REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$'
                        AND JSON_UNQUOTE(JSON_EXTRACT(policy, '$.membership_term_ends_at')) <= %s
                    )
               )
             ORDER BY user_id ASC, plan_id ASC, id ASC",
            $at,
            $at
        ), ARRAY_A);
        if (!is_array($rows) || $this->databaseHasError()) {
            throw new \RuntimeException('Unable to read due entitlement edges.');
        }

        return array_map([$this, 'hydrate'], $rows);
    }

    private function getByTypedSourceLifecycle(int $sourceId, string $sourceType, string $lifecycle): array
    {
        global $wpdb;

        $sourceType = trim($sourceType);
        if ($sourceId < 0 || $sourceType === '' || !in_array($lifecycle, self::LIFECYCLES, true)) {
            throw new \InvalidArgumentException('Typed entitlement source is invalid.');
        }
        $rows = \FChubMemberships\Support\CustomTableDatabase::getResults(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT * FROM {$this->table}
             WHERE source_type = %s
               AND source_id = %d
               AND lifecycle = %s
             ORDER BY user_id ASC, plan_id ASC, id ASC",
            $sourceType,
            $sourceId,
            $lifecycle
        ), ARRAY_A);
        if (!is_array($rows) || $this->databaseHasError()) {
            throw new \RuntimeException('Unable to read typed entitlement source.');
        }

        return array_map([$this, 'hydrate'], $rows);
    }

    private function appendEdgeIdCriteria(array $context, array &$where, array &$params): void
    {
        if (!array_key_exists('edge_ids', $context)) {
            return;
        }
        $edgeIds = array_values(array_unique(array_map('intval', (array) $context['edge_ids'])));
        if ($edgeIds === [] || count($edgeIds) > 64 || min($edgeIds) <= 0) {
            throw new \InvalidArgumentException('Entitlement edge ID criteria are invalid.');
        }
        $where[] = 'id IN (' . implode(', ', array_fill(0, count($edgeIds), '%d')) . ')';
        array_push($params, ...$edgeIds);
    }

    public function hasUnsafeAssignmentEvidence(
        int $userId,
        string $provider,
        string $resourceType,
        string $resourceId
    ): bool {
        global $wpdb;

        $count = \FChubMemberships\Support\CustomTableDatabase::getVar(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT CASE WHEN
                EXISTS (
                    SELECT 1 FROM {$this->table} unsafe_edge
                    WHERE unsafe_edge.user_id = %d
                      AND unsafe_edge.provider = %s
                      AND unsafe_edge.resource_type = %s
                      AND unsafe_edge.resource_id = %s
                      AND (
                          unsafe_edge.owner <> 'fchub'
                          OR unsafe_edge.assignment_provenance <> 'fchub_created'
                      )
                )
                OR (
                    %s <> 'wordpress_core'
                    AND COALESCE((
                        SELECT latest_operation.desired_action
                        FROM {$this->operationsTable} latest_operation
                        INNER JOIN {$this->table} mutation_edge
                            ON mutation_edge.id = latest_operation.edge_id
                        WHERE mutation_edge.user_id = %d
                          AND mutation_edge.provider = %s
                          AND mutation_edge.resource_type = %s
                          AND mutation_edge.resource_id = %s
                          AND mutation_edge.owner = 'fchub'
                          AND mutation_edge.assignment_provenance = 'fchub_created'
                          AND latest_operation.state = 'applied'
                          AND (
                              latest_operation.last_error_code = 'provider_operation_applied'
                              OR (
                                  latest_operation.desired_action = 'revoke'
                                  AND latest_operation.last_error_code = 'provider_operation_finalized'
                              )
                          )
                        ORDER BY latest_operation.id DESC LIMIT 1
                    ), '') NOT IN ('grant', 'resume')
                )
                THEN 1 ELSE 0 END",
            $userId,
            $provider,
            $resourceType,
            $resourceId,
            $provider,
            $userId,
            $provider,
            $resourceType,
            $resourceId
        ));
        if ($count === null || $this->databaseHasError()) {
            throw new \RuntimeException('Unable to read provider assignment evidence.');
        }

        return (int) $count > 0;
    }

    public function transaction(callable $callback): mixed
    {
        global $wpdb;

        if (\FChubMemberships\Support\CustomTableDatabase::beginTransaction() === false) {
            throw new \RuntimeException('The entitlement transaction could not be started.');
        }

        try {
            $result = $callback();
            if (\FChubMemberships\Support\CustomTableDatabase::commit() === false) {
                throw new \RuntimeException('The entitlement transaction could not be committed.');
            }

            return $result;
        } catch (\Throwable $exception) {
            \FChubMemberships\Support\CustomTableDatabase::rollBack();
            throw $exception;
        }
    }

    public function resourceTransaction(array $resource, callable $callback): mixed
    {
        global $wpdb;

        $lockName = $this->resourceLockName($resource);
        $acquired = (int) \FChubMemberships\Support\CustomTableDatabase::getVar(\FChubMemberships\Support\CustomTableDatabase::prepare(
            'SELECT GET_LOCK(%s, %d)',
            $lockName,
            10
        ));
        if ($acquired !== 1) {
            throw new \RuntimeException('The entitlement resource lock could not be acquired.');
        }

        $result = null;
        $failure = null;
        try {
            $result = $this->transaction($callback);
        } catch (\Throwable $exception) {
            $failure = $exception;
        }

        $released = (int) \FChubMemberships\Support\CustomTableDatabase::getVar(\FChubMemberships\Support\CustomTableDatabase::prepare(
            'SELECT RELEASE_LOCK(%s)',
            $lockName
        ));
        if ($released !== 1) {
            if ($failure) {
                Logger::error('The entitlement resource lock could not be released.', $failure->getMessage());
            }
            throw new \RuntimeException('The entitlement resource lock could not be released.');
        }
        if ($failure) {
            throw $failure;
        }

        return $result;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): array
    {
        foreach (['id', 'user_id', 'plan_id', 'feed_id', 'source_id'] as $column) {
            $row[$column] = (int) $row[$column];
        }
        $row['access_status'] = (string) ($row['access_status'] ?? 'active');

        if (!is_array($row['policy'] ?? null)) {
            $row['policy'] = json_decode((string) ($row['policy'] ?? '{}'), true) ?: [];
        }

        return $row;
    }

    /** @return array{action: string, edge: array<string, mixed>} */
    private function replayResult(array $existing, array $requested, ?array $comparisonFields = null): array
    {
        if ($existing['lifecycle'] === 'ended') {
            return ['action' => 'ended_conflict', 'edge' => $existing];
        }

        $comparisonFields ??= [
            'owner',
            'assignment_provenance',
            'starts_at',
            'expires_at',
            'drip_available_at',
            'policy',
        ];
        $comparisonFields = array_values(array_unique(array_merge(
            ['owner', 'assignment_provenance'],
            $comparisonFields
        )));
        foreach ($comparisonFields as $field) {
            if (($existing[$field] ?? null) !== ($requested[$field] ?? null)) {
                return ['action' => 'immutable_conflict', 'edge' => $existing];
            }
        }

        return ['action' => 'replayed', 'edge' => $existing];
    }

    /** @return array<string, mixed> */
    private function persistedFields(array $data): array
    {
        $data['access_status'] = (string) ($data['access_status'] ?? 'active');
        if (!in_array($data['access_status'], self::ACCESS_STATUSES, true)) {
            throw new \InvalidArgumentException('Entitlement access status is invalid.');
        }
        $fields = array_merge(self::IDENTITY_FIELDS, [
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
            'created_at',
            'updated_at',
        ]);

        return array_intersect_key($data, array_flip($fields));
    }

    private function resourceLockName(array $resource): string
    {
        $resourceKey = implode("\0", [
            (string) ($resource['user_id'] ?? ''),
            (string) ($resource['provider'] ?? ''),
            (string) ($resource['resource_type'] ?? ''),
            (string) ($resource['resource_id'] ?? ''),
        ]);

        return 'fchub_ent_' . substr(hash('sha256', $resourceKey), 0, 54);
    }

    private function databaseHasError(): bool
    {
        global $wpdb;

        return trim((string) ($wpdb->last_error ?? '')) !== '';
    }
}

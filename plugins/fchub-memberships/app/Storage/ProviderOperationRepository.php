<?php

declare(strict_types=1);

namespace FChubMemberships\Storage;

use FChubMemberships\Domain\ProviderOperationClaimResult;
use FChubMemberships\Domain\ProviderOperationOutcome;
use FChubMemberships\Support\Clock;
use FChubMemberships\Support\Logger;

defined('ABSPATH') || exit;

class ProviderOperationRepository
{
    public const STATES = ['pending', 'processing', 'applied', 'failed', 'deferred'];

    private string $table;
    private string $edgeTable;

    public function __construct(private ?Clock $clock = null)
    {
        global $wpdb;
        $this->table = \FChubMemberships\Support\CustomTableDatabase::identifier($wpdb->prefix . 'fchub_membership_provider_operations');
        $this->edgeTable = \FChubMemberships\Support\CustomTableDatabase::identifier($wpdb->prefix . 'fchub_membership_entitlement_edges');
        $this->clock ??= new Clock();
    }

    public function operationKey(int $edgeId, string $desiredAction, string $originEvent): string
    {
        $this->assertIdentity($edgeId, $desiredAction, $originEvent);

        return hash('sha256', $edgeId . '|' . $desiredAction . '|' . $originEvent);
    }

    public function createOrFind(
        int $edgeId,
        string $desiredAction,
        string $originEvent,
        ?\DateTimeImmutable $eligibleAt = null
    ): array {
        global $wpdb;

        $operationKey = $this->operationKey($edgeId, $desiredAction, $originEvent);
        $existing = $this->findByOperationKey($operationKey);
        if ($existing !== null) {
            return $existing;
        }

        $now = $this->storageNow();
        $eligibility = $eligibleAt ?? $this->clock->now();
        $inserted = \FChubMemberships\Support\CustomTableDatabase::insert($this->table, [
            'edge_id' => $edgeId,
            'operation_key' => $operationKey,
            'desired_action' => $desiredAction,
            'origin_event' => $originEvent,
            'state' => $eligibility > $this->clock->now() ? 'deferred' : 'pending',
            'attempt_count' => 0,
            'retryable' => 1,
            'eligible_at' => $this->clock->storage($eligibility),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted !== false) {
            $created = $this->findByOperationKey($operationKey);
            if ($created !== null) {
                return $created;
            }
        } else {
            $winner = $this->findByOperationKey($operationKey);
            if ($winner !== null) {
                return $winner;
            }
        }

        throw new \RuntimeException('Unable to persist provider operation.');
    }

    public function findById(int $id): ?array
    {
        global $wpdb;

        if ($id <= 0) {
            return null;
        }

        $row = \FChubMemberships\Support\CustomTableDatabase::getRow(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT * FROM {$this->table} WHERE id = %d",
            $id
        ), ARRAY_A);
        if ($this->databaseHasError()) {
            throw new \RuntimeException('Unable to read provider operation.');
        }

        return $row ? $this->hydrate($row) : null;
    }

    public function findByOperationKey(string $operationKey): ?array
    {
        global $wpdb;

        $row = \FChubMemberships\Support\CustomTableDatabase::getRow(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT * FROM {$this->table} WHERE operation_key = %s",
            $operationKey
        ), ARRAY_A);
        if ($this->databaseHasError()) {
            throw new \RuntimeException('Unable to read provider operation.');
        }

        return $row ? $this->hydrate($row) : null;
    }

    public function findLatestForResource(array $resource): ?array
    {
        global $wpdb;

        $userId = (int) ($resource['user_id'] ?? 0);
        $provider = trim((string) ($resource['provider'] ?? ''));
        $resourceType = trim((string) ($resource['resource_type'] ?? ''));
        $resourceId = trim((string) ($resource['resource_id'] ?? ''));
        if ($userId <= 0 || $provider === '' || $resourceType === '' || $resourceId === '') {
            throw new \InvalidArgumentException('Provider operation resource identity is invalid.');
        }

        $row = \FChubMemberships\Support\CustomTableDatabase::getRow(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT operation.*
             FROM {$this->table} operation
             INNER JOIN {$this->edgeTable} edge ON edge.id = operation.edge_id
             WHERE edge.user_id = %d
               AND edge.provider = %s
               AND edge.resource_type = %s
               AND edge.resource_id = %s
             ORDER BY operation.id DESC LIMIT 1",
            $userId,
            $provider,
            $resourceType,
            $resourceId
        ), ARRAY_A);
        if ($this->databaseHasError()) {
            throw new \RuntimeException('Unable to read provider operation for resource.');
        }

        return $row ? $this->hydrate($row) : null;
    }

    /** @param list<int> $edgeIds
     *  @return array<int, array<string, mixed>>
     */
    public function findLatestForEdgeIds(array $edgeIds): array
    {
        global $wpdb;

        if ($edgeIds === []) {
            return [];
        }
        if (count($edgeIds) > 1000) {
            throw new \InvalidArgumentException('Provider operation edge lookup exceeds its safe bound.');
        }
        foreach ($edgeIds as $edgeId) {
            if (!is_int($edgeId) || $edgeId <= 0) {
                throw new \InvalidArgumentException('Provider operation edge IDs must be positive integers.');
            }
        }
        $edgeIds = array_values(array_unique($edgeIds));
        sort($edgeIds, SORT_NUMERIC);
        $placeholders = implode(', ', array_fill(0, count($edgeIds), '%d'));
        $rows = \FChubMemberships\Support\CustomTableDatabase::getResults(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT operation.*
             FROM {$this->table} operation
             INNER JOIN (
                 SELECT edge_id, MAX(id) AS latest_id
                 FROM {$this->table}
                 WHERE edge_id IN ({$placeholders})
                 GROUP BY edge_id
             ) latest ON latest.latest_id = operation.id
             ORDER BY operation.edge_id ASC",
            ...$edgeIds
        ), ARRAY_A);
        if ($this->databaseHasError()) {
            throw new \RuntimeException('Unable to read latest provider operations for edges.');
        }

        $latest = [];
        foreach ($rows as $row) {
            $hydrated = $this->hydrate($row);
            $latest[(int) $hydrated['edge_id']] = $hydrated;
        }
        ksort($latest, SORT_NUMERIC);

        return $latest;
    }

    /** @return array<string, array{pending_operations:int, failed_operations:int}> */
    public function summarizeByProvider(): array
    {
        global $wpdb;

        $rows = \FChubMemberships\Support\CustomTableDatabase::getResults(
            \FChubMemberships\Support\CustomTableDatabase::prepare("SELECT edge.provider,
                    SUM(CASE WHEN operation.state IN (%s, %s, %s) THEN 1 ELSE 0 END)
                        AS pending_operations,
                    SUM(CASE WHEN operation.state = %s THEN 1 ELSE 0 END) AS failed_operations
             FROM {$this->table} operation
             INNER JOIN {$this->edgeTable} edge ON edge.id = operation.edge_id
             GROUP BY edge.provider
             ORDER BY edge.provider ASC",
                'pending',
                'processing',
                'deferred',
                'failed',
            ),
            ARRAY_A
        );
        if ($this->databaseHasError()) {
            throw new \RuntimeException('Unable to summarize provider operations.');
        }

        $summary = [];
        foreach ($rows as $row) {
            $provider = trim((string) ($row['provider'] ?? ''));
            if ($provider === '') {
                continue;
            }
            $summary[$provider] = [
                'pending_operations' => max(0, (int) ($row['pending_operations'] ?? 0)),
                'failed_operations' => max(0, (int) ($row['failed_operations'] ?? 0)),
            ];
        }

        return $summary;
    }

    public function countAppliedGrantOperations(int $edgeId): int
    {
        global $wpdb;

        if ($edgeId <= 0) {
            throw new \InvalidArgumentException('Provider operation edge ID must be greater than zero.');
        }
        $count = \FChubMemberships\Support\CustomTableDatabase::getVar(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT COUNT(*) FROM {$this->table}
             WHERE edge_id = %d
               AND desired_action = 'grant'
               AND state = 'applied'",
            $edgeId
        ));
        if ($count === null || $this->databaseHasError()) {
            throw new \RuntimeException('Unable to read applied provider operations.');
        }

        return (int) $count;
    }

    public function finalizeAppliedRevoke(int $id): bool
    {
        global $wpdb;

        if ($id <= 0) {
            throw new \InvalidArgumentException('Provider operation ID must be greater than zero.');
        }
        $updated = \FChubMemberships\Support\CustomTableDatabase::query(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "UPDATE {$this->table}
             SET last_error_code = 'provider_operation_finalized', updated_at = %s
             WHERE id = %d
               AND state = 'applied'
               AND desired_action = 'revoke'
               AND last_error_code IN ('provider_operation_applied', 'provider_state_already_applied')",
            $this->storageNow(),
            $id
        ));
        if ($updated === false) {
            throw new \RuntimeException('Unable to finalize provider operation.');
        }

        return $updated === 1;
    }

    public function claim(int $id, string $owner, int $leaseSeconds = 300): ProviderOperationClaimResult
    {
        global $wpdb;

        if ($id <= 0 || $owner === '' || strlen($owner) > 64 || $leaseSeconds <= 0) {
            throw new \InvalidArgumentException('Invalid provider operation claim.');
        }

        $now = $this->storageNow();
        $leaseExpires = $this->clock->storage($this->clock->now()->modify("+{$leaseSeconds} seconds"));
        $updated = \FChubMemberships\Support\CustomTableDatabase::query(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "UPDATE {$this->table}
             SET state = 'processing', lease_owner = %s, lease_expires_at = %s,
                 updated_at = %s
             WHERE id = %d
               AND eligible_at <= %s
               AND (
                    state = 'pending'
                    OR (state = 'processing' AND (lease_expires_at IS NULL OR lease_expires_at <= %s))
                    OR (state = 'failed' AND retryable = 1 AND next_retry_at IS NOT NULL AND next_retry_at <= %s)
               )",
            $owner,
            $leaseExpires,
            $now,
            $id,
            $now,
            $now,
            $now
        ));
        if ($updated === false) {
            throw new \RuntimeException('Unable to claim provider operation.');
        }

        $operation = $this->findById($id);
        if ($updated === 1 && $operation !== null) {
            return ProviderOperationClaimResult::acquired($operation);
        }
        if ($operation === null) {
            return ProviderOperationClaimResult::missing();
        }

        return match ($operation['state']) {
            'processing' => ProviderOperationClaimResult::inProgress($operation),
            'applied' => ProviderOperationClaimResult::applied($operation),
            'deferred' => ProviderOperationClaimResult::deferred($operation),
            'failed' => ($operation['retryable'] ?? false)
                ? ProviderOperationClaimResult::notDue($operation)
                : ProviderOperationClaimResult::terminal($operation),
            default => ProviderOperationClaimResult::notDue($operation),
        };
    }

    public function beginAttempt(int $id, string $owner, int $attemptCount): ?int
    {
        global $wpdb;

        if ($id <= 0 || $owner === '' || strlen($owner) > 64 || $attemptCount < 0) {
            throw new \InvalidArgumentException('Invalid provider operation attempt.');
        }

        $now = $this->storageNow();
        $updated = \FChubMemberships\Support\CustomTableDatabase::query(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "UPDATE {$this->table}
             SET attempt_count = attempt_count + 1, updated_at = %s
             WHERE id = %d
               AND state = 'processing'
               AND lease_owner = %s
               AND lease_expires_at > %s
               AND attempt_count < 4
               AND attempt_count = %d",
            $now,
            $id,
            $owner,
            $now,
            $attemptCount
        ));
        if ($updated === false) {
            throw new \RuntimeException('Unable to begin provider operation attempt.');
        }
        if ($updated !== 1) {
            return null;
        }

        return $attemptCount + 1;
    }

    public function recordOutcome(int $id, string $owner, ProviderOperationOutcome $outcome): bool
    {
        global $wpdb;

        $operation = $this->findById($id);
        if ($operation === null) {
            return false;
        }

        $now = $this->storageNow();
        $state = 'applied';
        $retryable = 0;
        $nextRetry = null;
        $completedAt = $now;

        if ($outcome->status === 'deferred') {
            $state = 'deferred';
            $completedAt = null;
        } elseif ($outcome->status === 'retryable-failure') {
            $state = 'failed';
            $attempt = (int) $operation['attempt_count'];
            $retryable = $attempt < 4 ? 1 : 0;
            $completedAt = $retryable === 1 ? null : $now;
            if ($retryable === 1) {
                $minutes = [0 => 5, 1 => 5, 2 => 30, 3 => 120][$attempt] ?? 120;
                $nextRetry = $this->clock->storage($this->clock->now()->modify("+{$minutes} minutes"));
            }
        } elseif ($outcome->status === 'terminal-failure') {
            $state = 'failed';
        }

        $query = \FChubMemberships\Support\CustomTableDatabase::prepare(
            "UPDATE {$this->table}
             SET state = %s, retryable = %d, next_retry_at = " . ($nextRetry === null ? 'NULL' : '%s') . ",
                 last_error_code = %s, last_error_message = %s,
                 lease_owner = NULL, lease_expires_at = NULL, updated_at = %s,
                 completed_at = " . ($completedAt === null ? 'NULL' : '%s') . "
             WHERE id = %d AND state = 'processing' AND lease_owner = %s AND lease_expires_at > %s",
            ...array_values(array_filter([
                $state,
                $retryable,
                $nextRetry,
                $outcome->code,
                $outcome->message,
                $now,
                $completedAt,
                $id,
                $owner,
                $now,
            ], static fn(mixed $value): bool => $value !== null))
        );

        return \FChubMemberships\Support\CustomTableDatabase::query($query) === 1;
    }

    /** @return list<int> */
    public function findRecoverableIds(int $limit = 50): array
    {
        global $wpdb;

        $limit = max(1, min(50, $limit));
        $now = $this->storageNow();
        $ids = \FChubMemberships\Support\CustomTableDatabase::getCol(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT id FROM {$this->table}
             WHERE eligible_at <= %s AND (
                 state = 'pending'
                 OR (state = 'failed' AND retryable = 1 AND next_retry_at IS NOT NULL AND next_retry_at <= %s)
                 OR (state = 'processing' AND (lease_expires_at IS NULL OR lease_expires_at <= %s))
             )
             ORDER BY id ASC LIMIT {$limit}",
            $now,
            $now,
            $now
        ));
        if (!is_array($ids) || $this->databaseHasError()) {
            throw new \RuntimeException('Unable to read recoverable provider operations.');
        }

        return array_map('intval', $ids);
    }

    public function recoverStaleProcessing(int $operationId): bool
    {
        global $wpdb;

        if ($operationId <= 0) {
            throw new \InvalidArgumentException('Provider operation ID must be greater than zero.');
        }
        $now = $this->storageNow();
        $updated = \FChubMemberships\Support\CustomTableDatabase::query(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "UPDATE {$this->table}
             SET state = 'pending', lease_owner = NULL, lease_expires_at = NULL, updated_at = %s
             WHERE id = %d
               AND state = 'processing'
               AND (lease_expires_at IS NULL OR lease_expires_at <= %s)",
            $now,
            $operationId,
            $now
        ));
        if ($updated === false) {
            throw new \RuntimeException('Unable to recover stale provider operation.');
        }

        return $updated === 1;
    }

    /** @return list<int> */
    public function findDueDeferredIds(int $limit = 50): array
    {
        global $wpdb;

        $limit = max(1, min(50, $limit));
        $now = $this->storageNow();
        $ids = \FChubMemberships\Support\CustomTableDatabase::getCol(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT id FROM {$this->table}
             WHERE state = 'deferred' AND eligible_at <= %s
             ORDER BY id ASC LIMIT {$limit}",
            $now
        ));
        if (!is_array($ids) || $this->databaseHasError()) {
            throw new \RuntimeException('Unable to read due deferred provider operations.');
        }

        return array_map('intval', $ids);
    }

    public function makeEligible(int $id): bool
    {
        global $wpdb;

        if ($id <= 0) {
            throw new \InvalidArgumentException('Provider operation ID must be greater than zero.');
        }

        $now = $this->storageNow();
        $updated = \FChubMemberships\Support\CustomTableDatabase::query(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "UPDATE {$this->table}
             SET state = 'pending', updated_at = %s
             WHERE id = %d AND state = 'deferred' AND eligible_at <= %s",
            $now,
            $id,
            $now
        ));
        if ($updated === false) {
            throw new \RuntimeException('Unable to make provider operation eligible.');
        }

        return $updated === 1;
    }

    /** @return list<int> */
    public function findGrantOperationIdsForResource(
        array $grant,
        string $eligibleAt,
        int $limit = 50
    ): array
    {
        global $wpdb;

        $userId = (int) ($grant['user_id'] ?? 0);
        $provider = trim((string) ($grant['provider'] ?? ''));
        $resourceType = trim((string) ($grant['resource_type'] ?? ''));
        $resourceId = trim((string) ($grant['resource_id'] ?? ''));
        $eligibleAt = trim($eligibleAt);
        if ($userId <= 0
            || $provider === ''
            || $resourceType === ''
            || $resourceId === ''
            || $eligibleAt === ''
        ) {
            throw new \InvalidArgumentException('Invalid provider operation grant resource.');
        }
        try {
            $eligibleAt = $this->clock->storage($this->clock->parseLocal($eligibleAt));
        } catch (\Throwable $exception) {
            Logger::error('Invalid provider operation eligibility time.', $exception->getMessage());
            throw new \InvalidArgumentException('Invalid provider operation eligibility time.');
        }

        $limit = max(1, min(50, $limit));
        $ids = \FChubMemberships\Support\CustomTableDatabase::getCol(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT operation.id
             FROM {$this->table} operation
             JOIN {$this->edgeTable} edge ON edge.id = operation.edge_id
             WHERE edge.user_id = %d
               AND edge.provider = %s
               AND edge.resource_type = %s
               AND edge.resource_id = %s
               AND edge.lifecycle = 'active'
               AND edge.drip_available_at = %s
               AND operation.desired_action = 'grant'
               AND operation.eligible_at = %s
             ORDER BY operation.id ASC LIMIT {$limit}",
            $userId,
            $provider,
            $resourceType,
            $resourceId,
            $eligibleAt,
            $eligibleAt
        ));
        if (!is_array($ids) || $this->databaseHasError()) {
            throw new \RuntimeException('Unable to read provider operations for grant resource.');
        }

        return array_map('intval', $ids);
    }

    public function hasOlderActionableAssignment(array $operation): bool
    {
        global $wpdb;

        $id = (int) ($operation['id'] ?? 0);
        $edgeId = (int) ($operation['edge_id'] ?? 0);
        if ($id <= 0 || $edgeId <= 0) {
            return false;
        }

        $now = $this->storageNow();
        $count = \FChubMemberships\Support\CustomTableDatabase::getVar(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT COUNT(*)
             FROM {$this->table} older
             JOIN {$this->edgeTable} older_edge ON older_edge.id = older.edge_id
             JOIN {$this->edgeTable} current_edge ON current_edge.id = %d
             WHERE older.id < %d
               AND older_edge.user_id = current_edge.user_id
               AND older_edge.provider = current_edge.provider
               AND older_edge.resource_type = current_edge.resource_type
               AND older_edge.resource_id = current_edge.resource_id
               AND (
                    older.state IN ('pending', 'processing')
                    OR (older.state = 'failed' AND older.retryable = 1
                        AND older.next_retry_at IS NOT NULL AND older.next_retry_at <= %s)
                    OR (older.state = 'deferred' AND older.eligible_at <= %s)
               )",
            $edgeId,
            $id,
            $now,
            $now
        ));
        if ($count === null || $this->databaseHasError()) {
            throw new \RuntimeException('Unable to read provider operation ordering.');
        }

        return (int) $count > 0;
    }

    public function hasNewerAssignmentIntent(array $operation): bool
    {
        global $wpdb;

        $id = (int) ($operation['id'] ?? 0);
        $edgeId = (int) ($operation['edge_id'] ?? 0);
        if ($id <= 0 || $edgeId <= 0) {
            return false;
        }

        $now = $this->storageNow();
        $count = \FChubMemberships\Support\CustomTableDatabase::getVar(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT COUNT(*)
             FROM {$this->table} newer
             JOIN {$this->edgeTable} newer_edge ON newer_edge.id = newer.edge_id
             JOIN {$this->edgeTable} current_edge ON current_edge.id = %d
             WHERE newer.id > %d
               AND newer_edge.user_id = current_edge.user_id
               AND newer_edge.provider = current_edge.provider
               AND newer_edge.resource_type = current_edge.resource_type
               AND newer_edge.resource_id = current_edge.resource_id
               AND (
                    newer.state IN ('pending', 'processing', 'applied', 'failed')
                    OR (newer.state = 'deferred' AND newer.eligible_at <= %s)
               )",
            $edgeId,
            $id,
            $now
        ));
        if ($count === null || $this->databaseHasError()) {
            throw new \RuntimeException('Unable to read newer provider operation intent.');
        }

        return (int) $count > 0;
    }

    public function releaseDeferred(int $limit = 50): int
    {
        global $wpdb;

        $limit = max(1, min(50, $limit));
        $now = $this->storageNow();
        $result = \FChubMemberships\Support\CustomTableDatabase::query(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "UPDATE {$this->table}
             SET state = 'pending', updated_at = %s
             WHERE state = 'deferred' AND eligible_at <= %s
             ORDER BY id ASC LIMIT {$limit}",
            $now,
            $now
        ));

        if ($result === false) {
            throw new \RuntimeException('Unable to release deferred provider operations.');
        }

        return $result;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): array
    {
        foreach (['id', 'edge_id', 'attempt_count'] as $column) {
            $row[$column] = (int) $row[$column];
        }
        if (array_key_exists('retryable', $row)) {
            $row['retryable'] = (bool) $row['retryable'];
        }

        return $row;
    }

    private function assertIdentity(int $edgeId, string $desiredAction, string $originEvent): void
    {
        if ($edgeId <= 0
            || !in_array($desiredAction, ['grant', 'revoke', 'suspend', 'resume'], true)
            || trim($originEvent) === ''
        ) {
            throw new \InvalidArgumentException('Invalid provider operation identity.');
        }
    }

    private function storageNow(): string
    {
        return $this->clock->storage($this->clock->now());
    }

    private function databaseHasError(): bool
    {
        global $wpdb;

        return trim((string) ($wpdb->last_error ?? '')) !== '';
    }
}

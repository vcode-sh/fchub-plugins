<?php

declare(strict_types=1);

namespace FChubMemberships\Storage;

use FChubMemberships\Support\Clock;

defined('ABSPATH') || exit;

final class WebhookDeliveryRepository
{
    private const STATUSES = ['pending', 'processing', 'retrying', 'succeeded', 'failed', 'cancelled'];

    private string $table;
    private string $eventTable;
    private Clock $clock;

    public function __construct(?Clock $clock = null)
    {
        global $wpdb;

        $this->table = $wpdb->prefix . 'fchub_membership_webhook_deliveries';
        $this->eventTable = $wpdb->prefix . 'fchub_membership_webhook_events';
        $this->clock = $clock ?? new Clock(null, new \DateTimeZone('UTC'));
    }

    /** @param list<string> $destinations @return list<array<string, mixed>> */
    public function createMany(string $eventId, array $destinations): array
    {
        $this->assertEventId($eventId);

        global $wpdb;

        $persistedEventId = $wpdb->get_var($wpdb->prepare(
            "SELECT event_id FROM {$this->eventTable} WHERE event_id = %s",
            $eventId
        ));
        if ($wpdb->last_error !== '') {
            throw new \RuntimeException('Unable to verify webhook event.');
        }
        if (!is_string($persistedEventId) || !hash_equals($eventId, $persistedEventId)) {
            throw new \RuntimeException('Webhook event does not exist.');
        }

        $canonical = [];
        foreach ($destinations as $destination) {
            $destination = trim((string) $destination);
            if ($destination === '' || strlen($destination) > 2048) {
                throw new \InvalidArgumentException('Invalid webhook destination.');
            }
            $canonical[$destination] = $destination;
        }

        $now = $this->storageNow();
        $rows = [];
        foreach ($canonical as $destination) {
            $hash = hash('sha256', $destination);
            $inserted = $this->insert([
                'event_id' => $eventId,
                'destination_url' => $destination,
                'destination_hash' => $hash,
                'status' => 'pending',
                'attempt_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $row = $this->findByIdentity($eventId, $hash);
            if ($row === null) {
                throw new \RuntimeException('Unable to persist webhook delivery.');
            }
            if (!$inserted && !hash_equals($destination, (string) ($row['destination_url'] ?? ''))) {
                throw new \RuntimeException('Webhook delivery identity conflict.');
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** @return array<string, mixed>|null */
    public function find(int $deliveryId): ?array
    {
        if ($deliveryId <= 0) {
            return null;
        }

        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d",
            $deliveryId
        ), ARRAY_A);
        if ($wpdb->last_error !== '') {
            throw new \RuntimeException('Unable to read webhook delivery.');
        }

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @return array<string, mixed>|null */
    public function acquire(
        int $deliveryId,
        string $owner,
        string $attemptedAt,
        string $leaseExpiresAt
    ): ?array {
        $this->assertWorkerIdentity($deliveryId, $owner, $attemptedAt, $leaseExpiresAt);

        global $wpdb;

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table}
             SET attempt_count = CASE
                    WHEN status = 'processing' THEN attempt_count
                    ELSE attempt_count + 1
                 END,
                 status = 'processing',
                 lease_owner = %s,
                 lease_expires_at = %s,
                 last_attempt_at = %s,
                 updated_at = %s
             WHERE id = %d
               AND (
                    (
                        status IN ('pending', 'retrying')
                        AND (next_attempt_at IS NULL OR next_attempt_at <= %s)
                    )
                    OR (
                        status = 'processing'
                        AND (lease_expires_at IS NULL OR lease_expires_at <= %s)
                    )
               )",
            $owner,
            $leaseExpiresAt,
            $attemptedAt,
            $attemptedAt,
            $deliveryId,
            $attemptedAt,
            $attemptedAt
        ));
        if ($updated === false) {
            throw new \RuntimeException('Unable to acquire webhook delivery.');
        }
        if ($updated !== 1) {
            return null;
        }

        $claimed = $this->find($deliveryId);
        if ($claimed === null
            || ($claimed['status'] ?? '') !== 'processing'
            || !hash_equals($owner, (string) ($claimed['lease_owner'] ?? ''))
        ) {
            return null;
        }

        return $claimed;
    }

    public function markSucceeded(
        int $id,
        string $owner,
        int $attempt,
        int $code,
        string $body,
        string $at
    ): bool {
        return $this->complete(
            $id,
            $owner,
            $attempt,
            'succeeded',
            $code,
            $body,
            null,
            null,
            $at,
            $at
        );
    }

    public function markRetrying(
        int $id,
        string $owner,
        int $attempt,
        ?int $code,
        string $body,
        string $error,
        string $nextAt
    ): bool {
        $now = $this->storageNow();

        return $this->complete(
            $id,
            $owner,
            $attempt,
            'retrying',
            $code,
            $body,
            $error,
            $nextAt,
            null,
            $now
        );
    }

    public function markFailed(
        int $id,
        string $owner,
        int $attempt,
        ?int $code,
        string $body,
        string $error,
        string $at
    ): bool {
        return $this->complete(
            $id,
            $owner,
            $attempt,
            'failed',
            $code,
            $body,
            $error,
            null,
            null,
            $at
        );
    }

    public function cancel(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        global $wpdb;

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table}
             SET status = 'cancelled',
                 lease_owner = NULL, lease_expires_at = NULL, next_attempt_at = NULL,
                 updated_at = %s
             WHERE id = %d AND status IN ('pending', 'retrying')",
            $this->storageNow(),
            $id
        ));
        if ($updated === false) {
            throw new \RuntimeException('Unable to cancel webhook delivery.');
        }

        return $updated === 1;
    }

    public function markCancelled(int $id, string $owner, int $attempt, string $at): bool
    {
        return $this->complete(
            $id,
            $owner,
            $attempt,
            'cancelled',
            null,
            '',
            'webhook_delivery_cancelled',
            null,
            null,
            $at
        );
    }

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    public function recent(array $filters): array
    {
        global $wpdb;

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;
        $conditions = [];

        $status = (string) ($filters['status'] ?? '');
        if ($status !== '' && !in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Invalid webhook delivery status filter.');
        }
        if ($status !== '') {
            $conditions[] = $wpdb->prepare('delivery.status = %s', $status);
        }
        $eventType = trim((string) ($filters['event_type'] ?? ''));
        if ($eventType !== '') {
            if (strlen($eventType) > 64) {
                throw new \InvalidArgumentException('Invalid webhook event type filter.');
            }
            $conditions[] = $wpdb->prepare('event.event_type = %s', $eventType);
        }

        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
        $rows = $wpdb->get_results(
            "SELECT delivery.*, event.event_type, event.schema_version, event.occurred_at
             FROM {$this->table} delivery
             INNER JOIN {$this->eventTable} event ON event.event_id = delivery.event_id
             {$where}
             ORDER BY delivery.created_at DESC, delivery.id DESC
             LIMIT {$perPage} OFFSET {$offset}",
            ARRAY_A
        );
        if ($wpdb->last_error !== '') {
            throw new \RuntimeException('Unable to list webhook deliveries.');
        }

        return array_map(fn(array $row): array => $this->hydrate($row), $rows);
    }

    /** @return array<string, int|string|null> */
    public function summary(): array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            "SELECT
                SUM(status = 'pending') pending,
                SUM(status = 'processing') processing,
                SUM(status = 'retrying') retrying,
                SUM(status = 'succeeded') succeeded,
                SUM(status = 'failed') failed,
                MAX(CASE WHEN status = 'succeeded' THEN delivered_at END) last_success_at
             FROM {$this->table}",
            ARRAY_A
        );
        if ($wpdb->last_error !== '') {
            throw new \RuntimeException('Unable to summarise webhook deliveries.');
        }

        $summary = [];
        foreach (self::STATUSES as $status) {
            $summary[$status] = (int) ($row[$status] ?? 0);
        }
        $summary['active'] = $summary['pending'] + $summary['processing'] + $summary['retrying'];
        $summary['last_success_at'] = $row['last_success_at'] ?? null;

        return $summary;
    }

    /** @return list<array<string, mixed>> */
    public function retryableDue(string $now, int $limit = 100): array
    {
        $this->assertDate($now);
        $limit = max(1, min(100, $limit));

        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE (
                    status IN ('pending', 'retrying')
                    AND (next_attempt_at IS NULL OR next_attempt_at <= %s)
                 ) OR (
                    status = 'processing'
                    AND (lease_expires_at IS NULL OR lease_expires_at <= %s)
                 )
             ORDER BY COALESCE(next_attempt_at, created_at) ASC, id ASC
             LIMIT {$limit}",
            $now,
            $now
        ), ARRAY_A);
        if ($wpdb->last_error !== '') {
            throw new \RuntimeException('Unable to list retryable webhook deliveries.');
        }

        return array_map(fn(array $row): array => $this->hydrate($row), $rows);
    }

    public function resetForManualRetry(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        global $wpdb;

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table}
             SET status = 'pending', attempt_count = 0,
                 lease_owner = NULL, lease_expires_at = NULL,
                 response_code = NULL, response_body = NULL, error_message = NULL,
                 next_attempt_at = NULL, last_attempt_at = NULL, delivered_at = NULL,
                 updated_at = %s
             WHERE id = %d AND status = 'failed'",
            $this->storageNow(),
            $id
        ));
        if ($updated === false) {
            throw new \RuntimeException('Unable to reset webhook delivery.');
        }

        return $updated === 1;
    }

    public function purge(string $successCutoff, string $failureCutoff): int
    {
        $this->assertDate($successCutoff);
        $this->assertDate($failureCutoff);

        global $wpdb;

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table}
             WHERE (status = 'succeeded' AND delivered_at IS NOT NULL AND delivered_at < %s)
                OR (status = 'failed' AND updated_at < %s)",
            $successCutoff,
            $failureCutoff
        ));
        if ($deleted === false) {
            throw new \RuntimeException('Unable to purge webhook deliveries.');
        }

        return $deleted;
    }

    /** @param array<string, mixed> $record */
    private function insert(array $record): bool
    {
        global $wpdb;

        $previousSuppression = $wpdb->suppress_errors(true);
        try {
            return $wpdb->insert($this->table, $record) !== false;
        } finally {
            $wpdb->suppress_errors($previousSuppression);
        }
    }

    /** @return array<string, mixed>|null */
    private function findByIdentity(string $eventId, string $destinationHash): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE event_id = %s AND destination_hash = %s",
            $eventId,
            $destinationHash
        ), ARRAY_A);
        if ($wpdb->last_error !== '') {
            throw new \RuntimeException('Unable to read webhook delivery.');
        }

        return is_array($row) ? $this->hydrate($row) : null;
    }

    private function complete(
        int $id,
        string $owner,
        int $attempt,
        string $status,
        ?int $code,
        string $body,
        ?string $error,
        ?string $nextAt,
        ?string $deliveredAt,
        string $at
    ): bool {
        $this->assertCompletionIdentity($id, $owner, $attempt, $at);
        if ($nextAt !== null) {
            $this->assertDate($nextAt);
        }

        global $wpdb;

        $codeSql = $code === null ? 'NULL' : (string) max(0, min(65535, $code));
        $nextSql = $nextAt === null ? 'NULL' : $wpdb->prepare('%s', $nextAt);
        $deliveredSql = $deliveredAt === null ? 'NULL' : $wpdb->prepare('%s', $deliveredAt);
        $errorSql = $error === null ? 'NULL' : $wpdb->prepare('%s', $error);
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table}
             SET status = %s,
                 response_code = {$codeSql},
                 response_body = %s,
                 error_message = {$errorSql},
                 next_attempt_at = {$nextSql},
                 delivered_at = {$deliveredSql},
                 lease_owner = NULL,
                 lease_expires_at = NULL,
                 updated_at = %s
             WHERE id = %d
               AND status = 'processing'
               AND lease_owner = %s
               AND attempt_count = %d
               AND lease_expires_at > %s",
            $status,
            $body,
            $at,
            $id,
            $owner,
            $attempt,
            $at
        ));
        if ($updated === false) {
            throw new \RuntimeException('Unable to update webhook delivery.');
        }

        return $updated === 1;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function hydrate(array $row): array
    {
        foreach (['id', 'attempt_count'] as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = (int) $row[$field];
            }
        }
        if (array_key_exists('response_code', $row)) {
            $row['response_code'] = $row['response_code'] === null ? null : (int) $row['response_code'];
        }

        return $row;
    }

    private function assertEventId(string $eventId): void
    {
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $eventId) !== 1) {
            throw new \InvalidArgumentException('Invalid webhook event ID.');
        }
    }

    private function assertWorkerIdentity(int $id, string $owner, string $attemptedAt, string $leaseExpiresAt): void
    {
        if ($id <= 0 || $owner === '' || strlen($owner) > 64) {
            throw new \InvalidArgumentException('Invalid webhook delivery lease identity.');
        }
        $this->assertDate($attemptedAt);
        $this->assertDate($leaseExpiresAt);
        if ($leaseExpiresAt <= $attemptedAt) {
            throw new \InvalidArgumentException('Webhook delivery lease must expire after acquisition.');
        }
    }

    private function assertCompletionIdentity(int $id, string $owner, int $attempt, string $at): void
    {
        if ($id <= 0 || $owner === '' || strlen($owner) > 64 || $attempt <= 0) {
            throw new \InvalidArgumentException('Invalid webhook delivery completion identity.');
        }
        $this->assertDate($at);
    }

    private function assertDate(string $date): void
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date, new \DateTimeZone('UTC'));
        if ($parsed === false || $parsed->format('Y-m-d H:i:s') !== $date) {
            throw new \InvalidArgumentException('Invalid webhook delivery timestamp.');
        }
    }

    private function storageNow(): string
    {
        return $this->clock->storage($this->clock->now());
    }
}

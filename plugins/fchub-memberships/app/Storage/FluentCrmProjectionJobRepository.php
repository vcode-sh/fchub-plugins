<?php

declare(strict_types=1);

namespace FChubMemberships\Storage;

use FChubMemberships\Support\Clock;

defined('ABSPATH') || exit;

class FluentCrmProjectionJobRepository
{
    public const STATUSES = ['pending', 'processing', 'succeeded', 'failed'];

    public const ERROR_CODES = [
        'projection_invalid_user',
        'projection_load_failed',
        'projection_contact_failed',
        'projection_tag_failed',
        'projection_relation_read_failed',
        'projection_relation_write_failed',
        'projection_state_commit_failed',
        'projection_compensation_failed',
        'projection_custom_fields_failed',
        'projection_postflight_failed',
        'projection_unexpected_failure',
        'projection_retry_exhausted',
    ];

    private string $table;

    public function __construct(private ?Clock $clock = null)
    {
        global $wpdb;

        $this->table = $wpdb->prefix . 'fchub_membership_crm_projection_jobs';
        $this->clock ??= new Clock();
    }

    /** @return array<string, mixed> */
    public function request(int $userId): array
    {
        global $wpdb;

        if ($userId <= 0) {
            throw new \InvalidArgumentException('CRM projection user ID must be greater than zero.');
        }

        $now = $this->storageNow();
        $updated = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$this->table}
                (user_id, status, request_version, attempt_count, created_at, updated_at)
             VALUES (%d, 'pending', 1, 0, %s, %s)
             ON DUPLICATE KEY UPDATE
                status = 'pending',
                request_version = request_version + 1,
                lease_owner = NULL,
                lease_expires_at = NULL,
                attempt_count = 0,
                next_retry_at = NULL,
                last_error_code = NULL,
                last_attempt_at = NULL,
                updated_at = %s",
            $userId,
            $now,
            $now,
            $now
        ));
        if ($updated === false) {
            throw new \RuntimeException('Unable to persist CRM projection request.');
        }

        $row = $this->find($userId);
        if ($row === null) {
            throw new \RuntimeException('Unable to read persisted CRM projection request.');
        }

        return $row;
    }

    /** @return array<string, mixed>|null */
    public function find(int $userId): ?array
    {
        global $wpdb;

        if ($userId <= 0) {
            return null;
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE user_id = %d",
            $userId
        ), ARRAY_A);
        if ($this->databaseHasError()) {
            throw new \RuntimeException('Unable to read CRM projection job.');
        }

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @return array<string, mixed>|null */
    public function claim(
        int $userId,
        int $requestVersion,
        int $attempt,
        string $owner,
        int $leaseSeconds = 300
    ): ?array {
        global $wpdb;

        $this->assertWorkerIdentity($userId, $requestVersion, $attempt, $owner, $leaseSeconds);

        $now = $this->storageNow();
        $leaseExpiresAt = $this->clock->storage(
            $this->clock->now()->modify("+{$leaseSeconds} seconds")
        );
        $previousAttempt = $attempt - 1;
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table}
             SET status = 'processing', lease_owner = %s, lease_expires_at = %s,
                 attempt_count = %d, last_attempt_at = %s, updated_at = %s
             WHERE user_id = %d
               AND request_version = %d
               AND (
                    (
                        status = 'pending'
                        AND attempt_count = %d
                        AND (next_retry_at IS NULL OR next_retry_at <= %s)
                    )
                    OR (
                        status = 'processing'
                        AND attempt_count = %d
                        AND (
                            lease_expires_at IS NULL
                            OR lease_expires_at = '0000-00-00 00:00:00'
                            OR lease_expires_at <= %s
                        )
                    )
               )",
            $owner,
            $leaseExpiresAt,
            $attempt,
            $now,
            $now,
            $userId,
            $requestVersion,
            $previousAttempt,
            $now,
            $attempt,
            $now
        ));
        if ($updated === false) {
            throw new \RuntimeException('Unable to claim CRM projection job.');
        }
        if ($updated !== 1) {
            return null;
        }

        return $this->find($userId);
    }

    public function completeSuccess(int $userId, int $requestVersion, int $attempt, string $owner): bool
    {
        global $wpdb;

        $this->assertWorkerIdentity($userId, $requestVersion, $attempt, $owner, 300);
        $now = $this->storageNow();
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table}
             SET status = 'succeeded', lease_owner = NULL, lease_expires_at = NULL,
                 next_retry_at = NULL, last_error_code = NULL,
                 last_success_at = %s, updated_at = %s
             WHERE user_id = %d
               AND status = 'processing'
               AND request_version = %d
               AND attempt_count = %d
               AND lease_owner = %s
               AND lease_expires_at > %s",
            $now,
            $now,
            $userId,
            $requestVersion,
            $attempt,
            $owner,
            $now
        ));
        if ($updated === false) {
            throw new \RuntimeException('Unable to complete CRM projection job.');
        }

        return $updated === 1;
    }

    /** @return array<string, mixed>|null */
    public function completeFailure(
        int $userId,
        int $requestVersion,
        int $attempt,
        string $owner,
        string $errorCode
    ): ?array {
        global $wpdb;

        $this->assertWorkerIdentity($userId, $requestVersion, $attempt, $owner, 300);
        $errorCode = in_array($errorCode, self::ERROR_CODES, true)
            ? $errorCode
            : 'projection_unexpected_failure';
        $now = $this->storageNow();
        $terminal = $attempt >= 4;
        $status = $terminal ? 'failed' : 'pending';
        $nextRetryAt = null;
        if ($terminal) {
            $errorCode = 'projection_retry_exhausted';
        } else {
            $minutes = [1 => 5, 2 => 30, 3 => 120][$attempt];
            $nextRetryAt = $this->clock->storage($this->clock->plusMinutes($minutes));
        }

        $nextRetrySql = $nextRetryAt === null ? 'NULL' : '%s';
        $arguments = [$status, $errorCode];
        if ($nextRetryAt !== null) {
            $arguments[] = $nextRetryAt;
        }
        array_push(
            $arguments,
            $now,
            $userId,
            $requestVersion,
            $attempt,
            $owner,
            $now
        );
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table}
             SET status = %s, lease_owner = NULL, lease_expires_at = NULL,
                 last_error_code = %s, next_retry_at = {$nextRetrySql}, updated_at = %s
             WHERE user_id = %d
               AND status = 'processing'
               AND request_version = %d
               AND attempt_count = %d
               AND lease_owner = %s
               AND lease_expires_at > %s",
            ...$arguments
        ));
        if ($updated === false) {
            throw new \RuntimeException('Unable to record CRM projection failure.');
        }
        if ($updated !== 1) {
            return null;
        }

        return $this->find($userId);
    }

    /** @return list<array<string, mixed>> */
    public function findRecoverable(int $limit = 50): array
    {
        global $wpdb;

        $limit = max(1, min(50, $limit));
        $now = $this->storageNow();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE (
                    status = 'pending'
                    AND (next_retry_at IS NULL OR next_retry_at <= %s)
               ) OR (
                    status = 'processing'
                    AND (
                        lease_expires_at IS NULL
                        OR lease_expires_at = '0000-00-00 00:00:00'
                        OR lease_expires_at <= %s
                    )
               )
             ORDER BY updated_at ASC, user_id ASC
             LIMIT {$limit}",
            $now,
            $now
        ), ARRAY_A);
        if (!is_array($rows) || $this->databaseHasError()) {
            throw new \RuntimeException('Unable to read recoverable CRM projection jobs.');
        }

        return array_values(array_map(
            fn(array $row): array => $this->hydrate($row),
            $rows
        ));
    }

    /** @return array{pending:int, failed:int, last_success_at:?string} */
    public function summary(): array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            "SELECT
                SUM(status IN ('pending', 'processing')) AS pending,
                SUM(status = 'failed') AS failed,
                MAX(last_success_at) AS last_success_at
             FROM {$this->table}",
            ARRAY_A
        );
        if ($this->databaseHasError()) {
            throw new \RuntimeException('Unable to read CRM projection health.');
        }
        $row = is_array($row) ? $row : [];
        $lastSuccess = $row['last_success_at'] ?? null;

        return [
            'pending' => max(0, (int) ($row['pending'] ?? 0)),
            'failed' => max(0, (int) ($row['failed'] ?? 0)),
            'last_success_at' => is_string($lastSuccess) && $lastSuccess !== '' ? $lastSuccess : null,
        ];
    }

    private function assertWorkerIdentity(
        int $userId,
        int $requestVersion,
        int $attempt,
        string $owner,
        int $leaseSeconds
    ): void {
        if ($userId <= 0 || $requestVersion <= 0 || $attempt < 1 || $attempt > 4
            || $owner === '' || strlen($owner) > 64 || $leaseSeconds <= 0
        ) {
            throw new \InvalidArgumentException('Invalid CRM projection worker identity.');
        }
    }

    /** @return array<string, mixed> */
    private function hydrate(array $row): array
    {
        $row['user_id'] = max(0, (int) ($row['user_id'] ?? 0));
        $row['request_version'] = max(0, (int) ($row['request_version'] ?? 0));
        $row['attempt_count'] = max(0, (int) ($row['attempt_count'] ?? 0));
        $status = (string) ($row['status'] ?? 'failed');
        $row['status'] = in_array($status, self::STATUSES, true) ? $status : 'failed';

        return $row;
    }

    private function storageNow(): string
    {
        return $this->clock->storage($this->clock->now());
    }

    private function databaseHasError(): bool
    {
        global $wpdb;

        return isset($wpdb->last_error) && trim((string) $wpdb->last_error) !== '';
    }
}

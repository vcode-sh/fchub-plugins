<?php

namespace FChubMemberships\Storage;

use FChubMemberships\Support\Clock;

defined('ABSPATH') || exit;

class MutationRequestRepository
{
    private const LEASE_MINUTES = 5;

    private string $table;
    private Clock $clock;

    public function __construct(?Clock $clock = null)
    {
        global $wpdb;

        $this->table = \FChubMemberships\Support\CustomTableDatabase::identifier($wpdb->prefix . 'fchub_membership_mutation_requests');
        $this->clock = $clock ?? new Clock(null, new \DateTimeZone('UTC'));
    }

    public function find(string $key): ?array
    {
        global $wpdb;

        $row = \FChubMemberships\Support\CustomTableDatabase::getRow(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT * FROM {$this->table} WHERE request_key = %s",
            $key
        ), ARRAY_A);

        if (!$row) {
            return null;
        }

        $row['user_id'] = (int) $row['user_id'];
        $row['attempt_count'] = max(1, (int) ($row['attempt_count'] ?? 1));
        $row['response_status'] = isset($row['response_status']) ? (int) $row['response_status'] : null;
        $row['response_body'] = !array_key_exists('response_body', $row) || $row['response_body'] === null
            ? null
            : json_decode((string) $row['response_body'], true);

        return $row;
    }

    public function reserve(string $key, string $fingerprint, int $userId): ?string
    {
        $this->validateIdentity($key, $fingerprint, $userId);

        global $wpdb;

        $now = $this->storageNow();
        $leaseExpiresAt = $this->clock->storage($this->clock->plusMinutes(self::LEASE_MINUTES));
        $leaseToken = bin2hex(random_bytes(32));
        $canSuppressErrors = is_callable([$wpdb, 'suppress_errors']);
        $previousSuppression = false;
        if ($canSuppressErrors) {
            $previousSuppression = (bool) $wpdb->suppress_errors(true);
        }
        try {
            $inserted = \FChubMemberships\Support\CustomTableDatabase::insert($this->table, [
                'request_key' => $key,
                'fingerprint' => $fingerprint,
                'user_id' => $userId,
                'state' => 'reserved',
                'lease_token' => $leaseToken,
                'lease_expires_at' => $leaseExpiresAt,
                'attempt_count' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } finally {
            if ($canSuppressErrors) {
                $wpdb->suppress_errors($previousSuppression);
            }
        }
        if ($inserted !== false) {
            return $leaseToken;
        }

        $existing = $this->find($key);
        if (!$this->isReclaimableBy($existing, $fingerprint, $userId, $now)) {
            return null;
        }

        $oldToken = $existing['lease_token'] ?? null;
        $oldLease = $existing['lease_expires_at'] ?? null;
        $tokenCondition = is_string($oldToken) && $oldToken !== ''
            ? \FChubMemberships\Support\CustomTableDatabase::prepare('lease_token = %s', $oldToken)->sql()
            : 'lease_token IS NULL';
        if ($oldLease === null || $oldLease === '') {
            $leaseCondition = 'lease_expires_at IS NULL';
        } else {
            $leaseCondition = \FChubMemberships\Support\CustomTableDatabase::prepare(
                'lease_expires_at = %s AND lease_expires_at <= %s',
                (string) $oldLease,
                $now
            )->sql();
        }

        $updated = \FChubMemberships\Support\CustomTableDatabase::query(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "UPDATE {$this->table}
             SET lease_token = %s,
                 lease_expires_at = %s,
                 attempt_count = attempt_count + 1,
                 updated_at = %s,
                 completed_at = NULL
             WHERE request_key = %s
               AND fingerprint = %s
               AND user_id = %d
               AND state = 'reserved'
               AND attempt_count = %d
               AND {$tokenCondition}
               AND {$leaseCondition}",
            $leaseToken,
            $leaseExpiresAt,
            $now,
            $key,
            $fingerprint,
            $userId,
            (int) $existing['attempt_count']
        ));

        return $updated === 1 ? $leaseToken : null;
    }

    public function complete(string $key, string $leaseToken, int $status, mixed $body): bool
    {
        return $this->persistResponse($key, $leaseToken, 'complete', $status, $body);
    }

    public function fail(string $key, string $leaseToken, int $status, mixed $body): bool
    {
        return $this->persistResponse($key, $leaseToken, 'failed', $status, $body);
    }

    public function purgeTerminalOlderThan(int $days = 30, int $limit = 100): int
    {
        if ($days <= 0) {
            throw new \InvalidArgumentException('Mutation receipt retention days must be greater than zero.');
        }

        global $wpdb;

        $limit = max(1, min(100, $limit));
        $cutoff = $this->clock->storage($this->clock->plusDays(-$days));
        $deleted = \FChubMemberships\Support\CustomTableDatabase::query(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "DELETE FROM {$this->table}
             WHERE id IN (
                SELECT id FROM (
                    SELECT id FROM {$this->table}
                    WHERE state IN ('complete', 'failed')
                      AND completed_at IS NOT NULL
                      AND completed_at < %s
                    ORDER BY completed_at ASC, id ASC
                    LIMIT {$limit}
                ) terminal_receipts
             )",
            $cutoff
        ));
        if ($deleted === false) {
            throw new \RuntimeException('Unable to purge terminal mutation receipts.');
        }

        return $deleted;
    }

    private function persistResponse(
        string $key,
        string $leaseToken,
        string $state,
        int $status,
        mixed $body
    ): bool {
        $this->validateLeaseIdentity($key, $leaseToken);

        global $wpdb;

        $encodedBody = wp_json_encode($body);
        if ($encodedBody === false) {
            return false;
        }

        $now = $this->storageNow();
        $updated = \FChubMemberships\Support\CustomTableDatabase::query(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "UPDATE {$this->table}
             SET state = %s,
                 response_status = %d,
                 response_body = %s,
                 lease_token = NULL,
                 lease_expires_at = NULL,
                 completed_at = %s,
                 updated_at = %s
             WHERE request_key = %s
               AND state = 'reserved'
               AND lease_token = %s
               AND lease_expires_at > %s",
            $state,
            $status,
            $encodedBody,
            $now,
            $now,
            $key,
            $leaseToken,
            $now
        ));

        return $updated === 1;
    }

    /** @param array<string, mixed>|null $row */
    private function isReclaimableBy(?array $row, string $fingerprint, int $userId, string $now): bool
    {
        if ($row === null
            || ($row['state'] ?? '') !== 'reserved'
            || !hash_equals((string) ($row['fingerprint'] ?? ''), $fingerprint)
            || (int) ($row['user_id'] ?? 0) !== $userId
        ) {
            return false;
        }

        $leaseExpiresAt = $row['lease_expires_at'] ?? null;
        if ($leaseExpiresAt === null || $leaseExpiresAt === '' || $leaseExpiresAt === '0000-00-00 00:00:00') {
            return true;
        }

        return is_string($leaseExpiresAt) && $leaseExpiresAt <= $now;
    }

    private function validateIdentity(string $key, string $fingerprint, int $userId): void
    {
        if ($key === '' || strlen($key) > 191
            || preg_match('/^[a-f0-9]{64}$/', $fingerprint) !== 1
            || $userId <= 0
        ) {
            throw new \InvalidArgumentException('Invalid mutation receipt identity.');
        }
    }

    private function validateLeaseIdentity(string $key, string $leaseToken): void
    {
        if ($key === '' || strlen($key) > 191 || $leaseToken === '' || strlen($leaseToken) > 64) {
            throw new \InvalidArgumentException('Invalid mutation receipt lease identity.');
        }
    }

    private function storageNow(): string
    {
        return $this->clock->storage($this->clock->now());
    }
}

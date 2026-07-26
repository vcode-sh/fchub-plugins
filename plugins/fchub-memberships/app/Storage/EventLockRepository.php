<?php

namespace FChubMemberships\Storage;

use FChubMemberships\Domain\Event\EventClaimResult;
use FChubMemberships\Support\Clock;

defined('ABSPATH') || exit;

class EventLockRepository
{
    private string $table;
    private Clock $clock;

    public function __construct(?Clock $clock = null)
    {
        global $wpdb;
        $this->table = \FChubMemberships\Support\CustomTableDatabase::identifier($wpdb->prefix . 'fchub_membership_event_locks');
        $this->clock = $clock ?? new Clock();
    }

    /**
     * Generate event hash for idempotency.
     */
    public static function makeEventHash(int $orderId, int $feedId, string $trigger, ?int $subscriptionId = null): string
    {
        return md5($orderId . '|' . $feedId . '|' . $trigger . '|' . ($subscriptionId ?? 0));
    }

    /**
     * Check if an event has already been processed.
     */
    public function isProcessed(string $eventHash): bool
    {
        global $wpdb;
        return (bool) \FChubMemberships\Support\CustomTableDatabase::getVar(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE event_hash = %s AND state = 'succeeded'",
            $eventHash
        ));
    }

    public function claim(
        string $eventHash,
        array $context,
        string $ownerToken,
        int $leaseSeconds = 300
    ): EventClaimResult
    {
        $this->validateClaim($eventHash, $context, $ownerToken, $leaseSeconds);

        global $wpdb;
        $now = $this->clock->now();
        $nowStorage = $this->clock->storage($now);
        $leaseStorage = $this->clock->storage($now->modify("+{$leaseSeconds} seconds"));
        $subscriptionId = isset($context['subscription_id']) ? (int) $context['subscription_id'] : null;
        $params = [
            $eventHash,
            (int) ($context['order_id'] ?? 0),
        ];
        if ($subscriptionId === null) {
            $subscriptionPlaceholder = 'NULL';
        } else {
            $subscriptionPlaceholder = '%d';
            $params[] = $subscriptionId;
        }
        $params[] = (int) ($context['feed_id'] ?? 0);
        $params[] = (string) ($context['trigger'] ?? '');
        $params[] = $nowStorage;
        $params[] = $ownerToken;
        $params[] = $leaseStorage;
        $params[] = $nowStorage;

        $insertQuery = \FChubMemberships\Support\CustomTableDatabase::prepare(
            "INSERT INTO {$this->table}
             (event_hash, order_id, subscription_id, feed_id, trigger_name, processed_at,
              state, owner_token, lease_expires_at, attempt_count, retryable, next_retry_at,
              updated_at, completed_at, last_error, result, error)
             VALUES (%s, %d, {$subscriptionPlaceholder}, %d, %s, %s,
                     'processing', %s, %s, 1, 1, NULL, %s, NULL, NULL, 'processing', NULL)",
            ...$params
        );
        $canSuppressErrors = is_callable([$wpdb, 'suppress_errors']);
        $previousSuppression = false;
        if ($canSuppressErrors) {
            $previousSuppression = (bool) $wpdb->suppress_errors(true);
        }
        try {
            $inserted = \FChubMemberships\Support\CustomTableDatabase::query($insertQuery);
        } finally {
            if ($canSuppressErrors) {
                $wpdb->suppress_errors($previousSuppression);
            }
        }
        if ($inserted !== false && $wpdb->rows_affected === 1) {
            return EventClaimResult::acquired();
        }

        $row = $this->findByHash($eventHash);
        if ($row === null) {
            throw new \RuntimeException(sprintf('Unable to claim event lock %s.', esc_html($eventHash)));
        }

        return $this->classifyClaim($row, $eventHash, $ownerToken, $now, $nowStorage, $leaseStorage, true);
    }

    public function succeed(string $eventHash, string $ownerToken): bool
    {
        $this->validateOwnershipInput($eventHash, $ownerToken);

        global $wpdb;
        $now = $this->clock->now();
        $nowStorage = $this->clock->storage($now);
        $updated = \FChubMemberships\Support\CustomTableDatabase::query(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "UPDATE {$this->table}
             SET state = 'succeeded',
                 owner_token = NULL,
                 lease_expires_at = NULL,
                 retryable = 0,
                 next_retry_at = NULL,
                 updated_at = %s,
                 completed_at = %s,
                 last_error = NULL,
                 result = 'success',
                 error = NULL
             WHERE event_hash = %s
               AND state = 'processing'
               AND owner_token = %s
               AND lease_expires_at > %s",
            $nowStorage,
            $nowStorage,
            $eventHash,
            $ownerToken,
            $nowStorage
        ));

        return $updated !== false && $wpdb->rows_affected === 1;
    }

    public function fail(
        string $eventHash,
        string $ownerToken,
        string $error,
        bool $retryable = true
    ): bool
    {
        $this->validateOwnershipInput($eventHash, $ownerToken);

        global $wpdb;
        $now = $this->clock->now();
        $nowStorage = $this->clock->storage($now);
        $retryableValue = $retryable ? 1 : 0;
        $nextRetry = $retryable
            ? \FChubMemberships\Support\CustomTableDatabase::prepare('%s', $nowStorage)->sql()
            : 'NULL';
        $completedAt = $retryable
            ? 'NULL'
            : \FChubMemberships\Support\CustomTableDatabase::prepare('%s', $nowStorage)->sql();
        $updated = \FChubMemberships\Support\CustomTableDatabase::query(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "UPDATE {$this->table}
             SET state = 'failed',
                 owner_token = NULL,
                 lease_expires_at = NULL,
                 retryable = {$retryableValue},
                 next_retry_at = {$nextRetry},
                 updated_at = %s,
                 completed_at = {$completedAt},
                 last_error = %s,
                 result = 'failed',
                 error = %s
             WHERE event_hash = %s
               AND state = 'processing'
               AND owner_token = %s
               AND lease_expires_at > %s",
            $nowStorage,
            $error,
            $error,
            $eventHash,
            $ownerToken,
            $nowStorage
        ));

        return $updated !== false && $wpdb->rows_affected === 1;
    }

    /**
     * Acquire a lock for processing an event. Returns true if lock was acquired.
     */
    public function acquire(array $data): bool
    {
        global $wpdb;
        $now = $this->clock->storage($this->clock->now());

        $insert = [
            'event_hash'      => $data['event_hash'],
            'order_id'        => (int) ($data['order_id'] ?? 0),
            'subscription_id' => isset($data['subscription_id']) ? (int) $data['subscription_id'] : null,
            'feed_id'         => (int) ($data['feed_id'] ?? 0),
            'trigger_name'    => $data['trigger'] ?? '',
            'processed_at'    => $now,
        ];

        // Use INSERT IGNORE to handle race conditions
        $subId = $insert['subscription_id'];
        $params = [
            $insert['event_hash'],
            $insert['order_id'],
        ];

        if ($subId !== null) {
            $subPlaceholder = '%d';
            $params[] = $subId;
        } else {
            $subPlaceholder = 'NULL';
        }

        $params[] = $insert['feed_id'];
        $params[] = $insert['trigger_name'];
        $params[] = $insert['processed_at'];
        $params[] = $now;
        $params[] = $now;

        $result = \FChubMemberships\Support\CustomTableDatabase::query(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "INSERT IGNORE INTO {$this->table}
             (event_hash, order_id, subscription_id, feed_id, trigger_name, processed_at,
              result, error, state, owner_token, lease_expires_at, attempt_count, retryable,
              next_retry_at, updated_at, completed_at, last_error)
             VALUES (%s, %d, {$subPlaceholder}, %d, %s, %s,
                     'success', NULL, 'succeeded', NULL, NULL, 1, 0, NULL, %s, %s, NULL)",
            ...$params
        ));

        return $result !== false && $wpdb->rows_affected > 0;
    }

    /**
     * Record a failed event.
     */
    public function recordFailure(string $eventHash, string $error): void
    {
        global $wpdb;
        $now = $this->clock->storage($this->clock->now());

        \FChubMemberships\Support\CustomTableDatabase::update(
            $this->table,
            [
                'state' => 'failed',
                'owner_token' => null,
                'lease_expires_at' => null,
                'retryable' => 1,
                'next_retry_at' => $now,
                'updated_at' => $now,
                'completed_at' => null,
                'last_error' => $error,
                'result' => 'failed',
                'error' => $error,
            ],
            ['event_hash' => $eventHash]
        );
    }

    /**
     * Get processing history for an order.
     */
    public function getByOrderId(int $orderId): array
    {
        global $wpdb;
        $rows = \FChubMemberships\Support\CustomTableDatabase::getResults(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT * FROM {$this->table} WHERE order_id = %d ORDER BY processed_at DESC",
            $orderId
        ), ARRAY_A);

        return $rows ?: [];
    }

    /**
     * Clean up old event locks.
     */
    public function purgeOlderThan(int $days): int
    {
        if ($days <= 0) {
            throw new \InvalidArgumentException('Retention days must be greater than zero.');
        }

        global $wpdb;
        $cutoff = $this->clock->storage($this->clock->plusDays(-$days));

        return (int) \FChubMemberships\Support\CustomTableDatabase::query(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "DELETE FROM {$this->table}
             WHERE (state = 'succeeded' OR (state = 'failed' AND retryable = 0))
               AND completed_at IS NOT NULL
               AND completed_at < %s",
            $cutoff
        ));
    }

    /** @return array<string, mixed>|null */
    private function findByHash(string $eventHash): ?array
    {
        global $wpdb;
        $row = \FChubMemberships\Support\CustomTableDatabase::getRow(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT * FROM {$this->table} WHERE event_hash = %s LIMIT 1",
            $eventHash
        ), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function classifyClaim(
        array $row,
        string $eventHash,
        string $ownerToken,
        \DateTimeImmutable $now,
        string $nowStorage,
        string $leaseStorage,
        bool $allowTakeover
    ): EventClaimResult
    {
        $state = (string) ($row['state'] ?? '');
        if ($state === 'succeeded') {
            return EventClaimResult::duplicateSucceeded();
        }

        if ($state === 'processing') {
            $leaseExpiresAt = $this->parseOptionalTime($row['lease_expires_at'] ?? null);
            if ($leaseExpiresAt !== null && $leaseExpiresAt > $now) {
                return EventClaimResult::inProgress();
            }
            if (!$allowTakeover) {
                return EventClaimResult::inProgress();
            }

            return $this->takeOver($row, $eventHash, $ownerToken, $now, $nowStorage, $leaseStorage);
        }

        if ($state === 'failed') {
            if ((int) ($row['retryable'] ?? 0) !== 1) {
                return EventClaimResult::terminalFailed();
            }

            $nextRetryAt = $this->parseOptionalTime($row['next_retry_at'] ?? null);
            if ($nextRetryAt === null || $nextRetryAt > $now) {
                return EventClaimResult::retryableFailed();
            }
            if (!$allowTakeover) {
                return EventClaimResult::retryableFailed();
            }

            return $this->takeOver($row, $eventHash, $ownerToken, $now, $nowStorage, $leaseStorage);
        }

        return EventClaimResult::terminalFailed();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function takeOver(
        array $row,
        string $eventHash,
        string $ownerToken,
        \DateTimeImmutable $now,
        string $nowStorage,
        string $leaseStorage
    ): EventClaimResult
    {
        global $wpdb;
        $state = (string) $row['state'];
        $attemptCount = max(1, (int) ($row['attempt_count'] ?? 1));
        $previousOwner = $row['owner_token'] ?? null;
        $ownerCondition = $previousOwner === null
            ? 'owner_token IS NULL'
            : \FChubMemberships\Support\CustomTableDatabase::prepare(
                'owner_token = %s',
                (string) $previousOwner,
            )->sql();

        if ($state === 'processing') {
            $previousTiming = (string) ($row['lease_expires_at'] ?? '');
            $timingCondition = \FChubMemberships\Support\CustomTableDatabase::prepare(
                'lease_expires_at = %s AND lease_expires_at <= %s',
                $previousTiming,
                $nowStorage
            )->sql();
        } else {
            $previousTiming = (string) ($row['next_retry_at'] ?? '');
            $timingCondition = \FChubMemberships\Support\CustomTableDatabase::prepare(
                'retryable = 1 AND next_retry_at = %s AND next_retry_at <= %s',
                $previousTiming,
                $nowStorage
            )->sql();
        }

        $updated = \FChubMemberships\Support\CustomTableDatabase::query(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "UPDATE {$this->table}
             SET state = 'processing',
                 owner_token = %s,
                 lease_expires_at = %s,
                 attempt_count = attempt_count + 1,
                 retryable = 1,
                 next_retry_at = NULL,
                 updated_at = %s,
                 completed_at = NULL,
                 last_error = NULL,
                 processed_at = %s,
                 result = 'processing',
                 error = NULL
             WHERE event_hash = %s
               AND state = %s
               AND {$ownerCondition}
               AND attempt_count = %d
               AND {$timingCondition}",
            $ownerToken,
            $leaseStorage,
            $nowStorage,
            $nowStorage,
            $eventHash,
            $state,
            $attemptCount
        ));
        if ($updated === false) {
            throw new \RuntimeException(sprintf('Unable to reclaim event lock %s.', esc_html($eventHash)));
        }
        if ($wpdb->rows_affected === 1) {
            return EventClaimResult::acquired();
        }

        $current = $this->findByHash($eventHash);
        if ($current === null) {
            throw new \RuntimeException(
                sprintf('Unable to classify event lock %s after a lost race.', esc_html($eventHash))
            );
        }

        return $this->classifyClaim($current, $eventHash, $ownerToken, $now, $nowStorage, $leaseStorage, false);
    }

    private function parseOptionalTime(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $this->clock->parseLocal($value);
    }

    private function validateClaim(
        string $eventHash,
        array $context,
        string $ownerToken,
        int $leaseSeconds
    ): void
    {
        $this->validateOwnershipInput($eventHash, $ownerToken);
        if ($leaseSeconds <= 0) {
            throw new \InvalidArgumentException('Lease seconds must be greater than zero.');
        }
        foreach (['order_id', 'subscription_id', 'feed_id'] as $key) {
            if (!isset($context[$key])) {
                continue;
            }
            $value = $context[$key];
            if (!$this->isStorageSafeInteger($value)) {
                throw new \InvalidArgumentException(sprintf('%s must be a non-negative integer.', esc_html($key)));
            }
        }
        if (isset($context['trigger']) && !is_string($context['trigger'])) {
            throw new \InvalidArgumentException('Event trigger must be a string.');
        }
        if (isset($context['trigger']) && $this->characterLength($context['trigger']) > 100) {
            throw new \InvalidArgumentException('Event trigger must contain at most 100 characters.');
        }
    }

    private function validateOwnershipInput(string $eventHash, string $ownerToken): void
    {
        if (trim($eventHash) === '' || $this->characterLength($eventHash) > 64) {
            throw new \InvalidArgumentException('Event hash must contain between 1 and 64 characters.');
        }
        if (trim($ownerToken) === '' || $this->characterLength($ownerToken) > 64) {
            throw new \InvalidArgumentException('Owner token must contain between 1 and 64 characters.');
        }
    }

    private function isStorageSafeInteger(mixed $value): bool
    {
        if (is_int($value)) {
            return $value >= 0;
        }
        if (!is_string($value) || $value === '' || !ctype_digit($value)) {
            return false;
        }

        $normalised = ltrim($value, '0');
        if ($normalised === '') {
            return true;
        }

        $maximum = (string) PHP_INT_MAX;
        $length = strlen($normalised);
        $maximumLength = strlen($maximum);

        return $length < $maximumLength
            || ($length === $maximumLength && strcmp($normalised, $maximum) <= 0);
    }

    private function characterLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }

        $length = preg_match_all('/./us', $value);

        return $length === false ? strlen($value) : $length;
    }
}

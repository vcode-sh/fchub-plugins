<?php

namespace FChubMemberships\Storage;

defined('ABSPATH') || exit;

class EventLockRepository
{
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'fchub_membership_event_locks';
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
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE event_hash = %s AND result = 'success'",
            $eventHash
        ));
    }

    /**
     * Acquire a lock for processing an event. Returns true if lock was acquired.
     */
    public function acquire(array $data): bool
    {
        global $wpdb;

        $insert = [
            'event_hash'      => $data['event_hash'],
            'order_id'        => (int) ($data['order_id'] ?? 0),
            'subscription_id' => isset($data['subscription_id']) ? (int) $data['subscription_id'] : null,
            'feed_id'         => (int) ($data['feed_id'] ?? 0),
            'trigger_name'    => $data['trigger'] ?? '',
            'processed_at'    => current_time('mysql'),
            'result'          => 'success',
            'error'           => null,
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
        $params[] = $insert['result'];

        $result = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$this->table}
             (event_hash, order_id, subscription_id, feed_id, trigger_name, processed_at, result, error)
             VALUES (%s, %d, {$subPlaceholder}, %d, %s, %s, %s, NULL)",
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

        $wpdb->update(
            $this->table,
            ['result' => 'failed', 'error' => $error],
            ['event_hash' => $eventHash]
        );
    }

    /**
     * Get processing history for an order.
     */
    public function getByOrderId(int $orderId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
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
        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table} WHERE processed_at < %s",
            $cutoff
        ));
    }
}

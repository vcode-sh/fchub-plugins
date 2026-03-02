<?php

namespace FChubMemberships\Storage;

defined('ABSPATH') || exit;

class DripScheduleRepository
{
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'fchub_membership_drip_notifications';
    }

    public function find(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d",
            $id
        ), ARRAY_A);

        return $row ? $this->hydrate($row) : null;
    }

    /**
     * Schedule a drip notification.
     */
    public function schedule(array $data): int
    {
        global $wpdb;

        $insert = [
            'grant_id'     => (int) $data['grant_id'],
            'plan_rule_id' => (int) $data['plan_rule_id'],
            'user_id'      => (int) $data['user_id'],
            'notify_at'    => $data['notify_at'],
            'sent_at'      => null,
            'status'       => 'pending',
        ];

        $wpdb->insert($this->table, $insert);
        return (int) $wpdb->insert_id;
    }

    /**
     * Get pending notifications ready to send.
     *
     * Includes both new pending notifications and failed ones eligible for retry
     * (retry_count < 3 and next_retry_at has passed).
     */
    public function getPendingNotifications(int $limit = 50): array
    {
        global $wpdb;
        $now = current_time('mysql');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE (status = 'pending' AND notify_at <= %s)
                OR (status = 'failed' AND retry_count < 3 AND next_retry_at IS NOT NULL AND next_retry_at <= %s)
             ORDER BY notify_at ASC
             LIMIT %d",
            $now,
            $now,
            $limit
        ), ARRAY_A);

        return array_map([$this, 'hydrate'], $rows ?: []);
    }

    /**
     * Mark notification as sent.
     */
    public function markSent(int $id): bool
    {
        global $wpdb;
        return $wpdb->update(
            $this->table,
            ['status' => 'sent', 'sent_at' => current_time('mysql')],
            ['id' => $id]
        ) !== false;
    }

    /**
     * Mark notification as failed with exponential backoff.
     *
     * Backoff schedule: retry 1 → +5min, retry 2 → +30min, retry 3 → +2hr.
     * After 3 retries the notification stays failed with no next_retry_at.
     */
    public function markFailed(int $id): bool
    {
        global $wpdb;

        $row = $this->find($id);
        if (!$row) {
            return false;
        }

        $retryCount = ((int) ($row['retry_count'] ?? 0)) + 1;
        $backoffMinutes = [1 => 5, 2 => 30, 3 => 120];
        $maxRetries = 3;

        $update = [
            'status'      => 'failed',
            'retry_count' => $retryCount,
        ];

        if ($retryCount <= $maxRetries && isset($backoffMinutes[$retryCount])) {
            $update['next_retry_at'] = gmdate(
                'Y-m-d H:i:s',
                time() + ($backoffMinutes[$retryCount] * 60)
            );
        } else {
            $update['next_retry_at'] = null;
        }

        return $wpdb->update($this->table, $update, ['id' => $id]) !== false;
    }

    /**
     * Get notifications for a specific grant.
     */
    public function getByGrantId(int $grantId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE grant_id = %d ORDER BY notify_at ASC",
            $grantId
        ), ARRAY_A);

        return array_map([$this, 'hydrate'], $rows ?: []);
    }

    /**
     * Get notifications for a user.
     */
    public function getByUserId(int $userId, array $filters = []): array
    {
        global $wpdb;

        $where = ['user_id = %d'];
        $params = [$userId];

        if (!empty($filters['status'])) {
            $where[] = 'status = %s';
            $params[] = $filters['status'];
        }

        $sql = "SELECT * FROM {$this->table} WHERE " . implode(' AND ', $where) . " ORDER BY notify_at ASC";

        if (!empty($filters['per_page'])) {
            $page = max(1, (int) ($filters['page'] ?? 1));
            $perPage = (int) $filters['per_page'];
            $offset = ($page - 1) * $perPage;
            $sql .= $wpdb->prepare(' LIMIT %d OFFSET %d', $perPage, $offset);
        }

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
        return array_map([$this, 'hydrate'], $rows ?: []);
    }

    /**
     * Delete notifications for a grant (used when grant is revoked).
     */
    public function deleteByGrantId(int $grantId): int
    {
        global $wpdb;
        return (int) $wpdb->delete($this->table, ['grant_id' => $grantId]);
    }

    /**
     * Count pending notifications.
     */
    public function countPending(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table} WHERE status = 'pending'"
        );
    }

    /**
     * Count sent notifications.
     */
    public function countSent(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table} WHERE status = 'sent'"
        );
    }

    /**
     * Get all notifications with pagination and filters (for admin).
     */
    public function all(array $filters = []): array
    {
        global $wpdb;

        $where = ['1=1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'status = %s';
            $params[] = $filters['status'];
        }

        if (!empty($filters['user_id'])) {
            $where[] = 'user_id = %d';
            $params[] = (int) $filters['user_id'];
        }

        $sql = "SELECT * FROM {$this->table} WHERE " . implode(' AND ', $where) . " ORDER BY notify_at DESC";

        if (!empty($filters['per_page'])) {
            $page = max(1, (int) ($filters['page'] ?? 1));
            $perPage = (int) $filters['per_page'];
            $offset = ($page - 1) * $perPage;
            $sql .= $wpdb->prepare(' LIMIT %d OFFSET %d', $perPage, $offset);
        }

        if ($params) {
            $sql = $wpdb->prepare($sql, ...$params);
        }

        $rows = $wpdb->get_results($sql, ARRAY_A);
        return array_map([$this, 'hydrate'], $rows ?: []);
    }

    /**
     * Get upcoming drip unlocks (calendar data).
     */
    public function getUpcomingUnlocks(string $from, string $to): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT dn.*, g.resource_type, g.resource_id, g.plan_id
             FROM {$this->table} dn
             LEFT JOIN {$wpdb->prefix}fchub_membership_grants g ON dn.grant_id = g.id
             WHERE dn.status = 'pending'
               AND dn.notify_at >= %s
               AND dn.notify_at <= %s
             ORDER BY dn.notify_at ASC",
            $from,
            $to
        ), ARRAY_A);

        return array_map([$this, 'hydrate'], $rows ?: []);
    }

    private function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['grant_id'] = (int) $row['grant_id'];
        $row['plan_rule_id'] = (int) $row['plan_rule_id'];
        $row['user_id'] = (int) $row['user_id'];
        return $row;
    }
}

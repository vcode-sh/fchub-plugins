<?php

namespace FChubMemberships\Storage;

defined('ABSPATH') || exit;

class GrantRepository
{
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'fchub_membership_grants';
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

    public function findByGrantKey(string $grantKey): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE grant_key = %s",
            $grantKey
        ), ARRAY_A);

        return $row ? $this->hydrate($row) : null;
    }

    /**
     * Generate the grant key (unique per user + resource combination).
     */
    public static function makeGrantKey(int $userId, string $provider, string $resourceType, string $resourceId): string
    {
        return md5($userId . $provider . $resourceType . $resourceId);
    }

    public function getByUserId(int $userId, array $filters = []): array
    {
        global $wpdb;

        $where = ['user_id = %d'];
        $params = [$userId];

        if (!empty($filters['status'])) {
            $where[] = 'status = %s';
            $params[] = $filters['status'];
        }

        if (!empty($filters['plan_id'])) {
            $where[] = 'plan_id = %d';
            $params[] = (int) $filters['plan_id'];
        }

        if (!empty($filters['provider'])) {
            $where[] = 'provider = %s';
            $params[] = $filters['provider'];
        }

        $sql = "SELECT * FROM {$this->table} WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC";

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
        return array_map([$this, 'hydrate'], $rows ?: []);
    }

    public function getByPlanId(int $planId, array $filters = []): array
    {
        global $wpdb;

        $where = ['plan_id = %d'];
        $params = [$planId];

        if (!empty($filters['status'])) {
            $where[] = 'status = %s';
            $params[] = $filters['status'];
        }

        $orderBy = 'created_at';
        $order = 'DESC';

        $sql = "SELECT * FROM {$this->table} WHERE " . implode(' AND ', $where) . " ORDER BY {$orderBy} {$order}";

        if (!empty($filters['per_page'])) {
            $page = max(1, (int) ($filters['page'] ?? 1));
            $perPage = (int) $filters['per_page'];
            $offset = ($page - 1) * $perPage;
            $sql .= $wpdb->prepare(' LIMIT %d OFFSET %d', $perPage, $offset);
        }

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
        return array_map([$this, 'hydrate'], $rows ?: []);
    }

    public function create(array $data): int
    {
        global $wpdb;

        $now = current_time('mysql');
        $insert = [
            'user_id'          => (int) $data['user_id'],
            'plan_id'          => isset($data['plan_id']) ? (int) $data['plan_id'] : null,
            'provider'         => $data['provider'] ?? 'wordpress_core',
            'resource_type'    => $data['resource_type'],
            'resource_id'      => (string) $data['resource_id'],
            'source_type'      => $data['source_type'] ?? 'manual',
            'source_id'        => (int) ($data['source_id'] ?? 0),
            'feed_id'          => isset($data['feed_id']) ? (int) $data['feed_id'] : null,
            'grant_key'        => $data['grant_key'],
            'status'           => $data['status'] ?? 'active',
            'starts_at'        => $data['starts_at'] ?? null,
            'expires_at'       => $data['expires_at'] ?? null,
            'drip_available_at'         => $data['drip_available_at'] ?? null,
            'trial_ends_at'             => $data['trial_ends_at'] ?? null,
            'cancellation_requested_at' => $data['cancellation_requested_at'] ?? null,
            'cancellation_effective_at' => $data['cancellation_effective_at'] ?? null,
            'cancellation_reason'       => $data['cancellation_reason'] ?? null,
            'renewal_count'             => (int) ($data['renewal_count'] ?? 0),
            'source_ids'       => wp_json_encode($data['source_ids'] ?? []),
            'meta'             => wp_json_encode($data['meta'] ?? []),
            'created_at'       => $now,
            'updated_at'       => $now,
        ];

        $wpdb->insert($this->table, $insert);
        return (int) $wpdb->insert_id;
    }

    public function update(int $id, array $data): bool
    {
        global $wpdb;

        $update = ['updated_at' => current_time('mysql')];

        $directFields = ['status', 'starts_at', 'expires_at', 'drip_available_at', 'source_type', 'trial_ends_at', 'cancellation_requested_at', 'cancellation_effective_at', 'cancellation_reason'];
        foreach ($directFields as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field];
            }
        }

        $intFields = ['plan_id', 'source_id', 'feed_id', 'renewal_count'];
        foreach ($intFields as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field] !== null ? (int) $data[$field] : null;
            }
        }

        $jsonFields = ['source_ids', 'meta'];
        foreach ($jsonFields as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = wp_json_encode($data[$field]);
            }
        }

        return $wpdb->update($this->table, $update, ['id' => $id]) !== false;
    }

    public function delete(int $id): bool
    {
        global $wpdb;
        return $wpdb->delete($this->table, ['id' => $id]) !== false;
    }

    /**
     * Check if a user has an active grant for a specific resource.
     */
    public function hasActiveGrant(int $userId, string $provider, string $resourceType, string $resourceId): bool
    {
        global $wpdb;
        $now = current_time('mysql');

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table}
             WHERE user_id = %d
               AND provider = %s
               AND resource_type = %s
               AND resource_id = %s
               AND status = 'active'
               AND (starts_at IS NULL OR starts_at <= %s)
               AND (expires_at IS NULL OR expires_at > %s)",
            $userId,
            $provider,
            $resourceType,
            $resourceId,
            $now,
            $now
        ));
    }

    /**
     * Check if a user has an active grant for a resource, including drip availability.
     */
    public function hasAccessibleGrant(int $userId, string $provider, string $resourceType, string $resourceId): bool
    {
        global $wpdb;
        $now = current_time('mysql');

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table}
             WHERE user_id = %d
               AND provider = %s
               AND resource_type = %s
               AND resource_id = %s
               AND status = 'active'
               AND (starts_at IS NULL OR starts_at <= %s)
               AND (expires_at IS NULL OR expires_at > %s)
               AND (drip_available_at IS NULL OR drip_available_at <= %s)",
            $userId,
            $provider,
            $resourceType,
            $resourceId,
            $now,
            $now,
            $now
        ));
    }

    /**
     * Get a user's active grant for a resource (returns the most recent).
     */
    public function getActiveGrant(int $userId, string $provider, string $resourceType, string $resourceId): ?array
    {
        global $wpdb;
        $now = current_time('mysql');

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE user_id = %d
               AND provider = %s
               AND resource_type = %s
               AND resource_id = %s
               AND status = 'active'
               AND (starts_at IS NULL OR starts_at <= %s)
               AND (expires_at IS NULL OR expires_at > %s)
             ORDER BY created_at DESC
             LIMIT 1",
            $userId,
            $provider,
            $resourceType,
            $resourceId,
            $now,
            $now
        ), ARRAY_A);

        return $row ? $this->hydrate($row) : null;
    }

    /**
     * Get grants that contain a specific source ID.
     * Tries the junction table first, falls back to JSON search.
     */
    public function getBySourceId(int $sourceId, string $sourceType = 'order'): array
    {
        // Try junction table first
        $junctionResults = $this->getBySourceIdFromJunction($sourceId, $sourceType);
        if (!empty($junctionResults)) {
            return $junctionResults;
        }

        global $wpdb;

        // Fall back to JSON source_ids search
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE source_type = %s
               AND (source_id = %d OR source_ids LIKE %s)",
            $sourceType,
            $sourceId,
            '%' . $wpdb->esc_like('"' . $sourceId . '"') . '%'
        ), ARRAY_A);

        // Filter in PHP for exact JSON match
        return array_map([$this, 'hydrate'], array_filter($rows ?: [], function ($row) use ($sourceId) {
            $sourceIds = json_decode($row['source_ids'] ?? '[]', true) ?: [];
            return in_array($sourceId, $sourceIds, false);
        }));
    }

    /**
     * Get grants by source ID using the junction table.
     */
    public function getBySourceIdFromJunction(int $sourceId, string $sourceType = 'order'): array
    {
        global $wpdb;
        $sourcesTable = $wpdb->prefix . 'fchub_membership_grant_sources';

        // Check if junction table exists
        if (!$wpdb->get_var("SHOW TABLES LIKE '{$sourcesTable}'")) {
            return [];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT g.* FROM {$this->table} g
             INNER JOIN {$sourcesTable} gs ON g.id = gs.grant_id
             WHERE gs.source_id = %d AND gs.source_type = %s",
            $sourceId,
            $sourceType
        ), ARRAY_A);

        return array_map([$this, 'hydrate'], $rows ?: []);
    }

    /**
     * Get all active grants for a user grouped by plan.
     */
    public function getActiveByUserGroupedByPlan(int $userId): array
    {
        global $wpdb;
        $now = current_time('mysql');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE user_id = %d
               AND status = 'active'
               AND (starts_at IS NULL OR starts_at <= %s)
               AND (expires_at IS NULL OR expires_at > %s)
             ORDER BY plan_id ASC, created_at ASC",
            $userId,
            $now,
            $now
        ), ARRAY_A);

        $grouped = [];
        foreach ($rows ?: [] as $row) {
            $hydrated = $this->hydrate($row);
            $planId = $hydrated['plan_id'] ?? 0;
            $grouped[$planId][] = $hydrated;
        }

        return $grouped;
    }

    /**
     * Get grants expiring within X days.
     */
    public function getExpiringSoon(int $days = 7, int $limit = 50): array
    {
        global $wpdb;
        $now = current_time('mysql');
        $future = gmdate('Y-m-d H:i:s', strtotime("+{$days} days"));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE status = 'active'
               AND expires_at IS NOT NULL
               AND expires_at > %s
               AND expires_at <= %s
             ORDER BY expires_at ASC
             LIMIT %d",
            $now,
            $future,
            $limit
        ), ARRAY_A);

        return array_map([$this, 'hydrate'], $rows ?: []);
    }

    /**
     * Get recently created or modified grants.
     */
    public function getRecentActivity(int $limit = 20): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} ORDER BY updated_at DESC LIMIT %d",
            $limit
        ), ARRAY_A);

        return array_map([$this, 'hydrate'], $rows ?: []);
    }

    /**
     * Count unique active members (users with at least one active grant).
     */
    public function countActiveMembers(?int $planId = null): int
    {
        global $wpdb;
        $now = current_time('mysql');

        $sql = "SELECT COUNT(DISTINCT user_id) FROM {$this->table}
                WHERE status = 'active'
                  AND (starts_at IS NULL OR starts_at <= %s)
                  AND (expires_at IS NULL OR expires_at > %s)";
        $params = [$now, $now];

        if ($planId !== null) {
            $sql .= ' AND plan_id = %d';
            $params[] = $planId;
        }

        return (int) $wpdb->get_var($wpdb->prepare($sql, ...$params));
    }

    /**
     * Count new members in a date range.
     */
    public function countNewMembers(string $from, string $to, ?int $planId = null): int
    {
        global $wpdb;

        $sql = "SELECT COUNT(DISTINCT user_id) FROM {$this->table}
                WHERE created_at >= %s AND created_at <= %s";
        $params = [$from, $to];

        if ($planId !== null) {
            $sql .= ' AND plan_id = %d';
            $params[] = $planId;
        }

        return (int) $wpdb->get_var($wpdb->prepare($sql, ...$params));
    }

    /**
     * Count churned members (revoked/expired) in a date range.
     */
    public function countChurnedMembers(string $from, string $to, ?int $planId = null): int
    {
        global $wpdb;

        $sql = "SELECT COUNT(DISTINCT user_id) FROM {$this->table}
                WHERE status IN ('revoked', 'expired')
                  AND updated_at >= %s AND updated_at <= %s";
        $params = [$from, $to];

        if ($planId !== null) {
            $sql .= ' AND plan_id = %d';
            $params[] = $planId;
        }

        return (int) $wpdb->get_var($wpdb->prepare($sql, ...$params));
    }

    /**
     * Paginated member list with filters.
     */
    public function getMembers(array $filters = []): array
    {
        global $wpdb;
        $now = current_time('mysql');

        $where = ['1=1'];
        $params = [];

        if (!empty($filters['status'])) {
            if ($filters['status'] === 'active') {
                $where[] = "g.status = 'active' AND (g.starts_at IS NULL OR g.starts_at <= %s) AND (g.expires_at IS NULL OR g.expires_at > %s)";
                $params[] = $now;
                $params[] = $now;
            } elseif ($filters['status'] === 'paused') {
                $where[] = "g.status = 'paused'";
            } else {
                $where[] = 'g.status = %s';
                $params[] = $filters['status'];
            }
        }

        if (!empty($filters['plan_id'])) {
            $where[] = 'g.plan_id = %d';
            $params[] = (int) $filters['plan_id'];
        }

        if (!empty($filters['search'])) {
            $where[] = '(u.user_email LIKE %s OR u.display_name LIKE %s)';
            $like = '%' . $wpdb->esc_like($filters['search']) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        if (!empty($filters['source_type'])) {
            $where[] = 'g.source_type = %s';
            $params[] = $filters['source_type'];
        }

        $perPage = (int) ($filters['per_page'] ?? 20);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT g.*, u.user_email, u.display_name
                FROM {$this->table} g
                LEFT JOIN {$wpdb->users} u ON g.user_id = u.ID
                WHERE " . implode(' AND ', $where) . "
                ORDER BY g.created_at DESC";

        $sql .= $wpdb->prepare(' LIMIT %d OFFSET %d', $perPage, $offset);

        if ($params) {
            $sql = $wpdb->prepare($sql, ...$params);
        }

        $rows = $wpdb->get_results($sql, ARRAY_A);
        return array_map([$this, 'hydrate'], $rows ?: []);
    }

    public function countMembers(array $filters = []): int
    {
        global $wpdb;
        $now = current_time('mysql');

        $where = ['1=1'];
        $params = [];

        if (!empty($filters['status'])) {
            if ($filters['status'] === 'active') {
                $where[] = "g.status = 'active' AND (g.starts_at IS NULL OR g.starts_at <= %s) AND (g.expires_at IS NULL OR g.expires_at > %s)";
                $params[] = $now;
                $params[] = $now;
            } elseif ($filters['status'] === 'paused') {
                $where[] = "g.status = 'paused'";
            } else {
                $where[] = 'g.status = %s';
                $params[] = $filters['status'];
            }
        }

        if (!empty($filters['plan_id'])) {
            $where[] = 'g.plan_id = %d';
            $params[] = (int) $filters['plan_id'];
        }

        if (!empty($filters['search'])) {
            $where[] = '(u.user_email LIKE %s OR u.display_name LIKE %s)';
            $like = '%' . $wpdb->esc_like($filters['search']) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $sql = "SELECT COUNT(*)
                FROM {$this->table} g
                LEFT JOIN {$wpdb->users} u ON g.user_id = u.ID
                WHERE " . implode(' AND ', $where);

        if ($params) {
            $sql = $wpdb->prepare($sql, ...$params);
        }

        return (int) $wpdb->get_var($sql);
    }

    /**
     * Get active grants whose expires_at has passed (overdue for expiration).
     * Excludes anchor grants — those get paused, not expired.
     */
    public function getOverdueGrants(): array
    {
        global $wpdb;
        $now = current_time('mysql');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE status = 'active'
               AND expires_at IS NOT NULL
               AND expires_at <= %s
               AND (meta IS NULL OR meta NOT LIKE %s)",
            $now,
            '%"billing_anchor_day"%'
        ), ARRAY_A);

        return array_map([$this, 'hydrate'], $rows ?: []);
    }

    /**
     * Bulk expire grants whose expires_at has passed.
     * Excludes anchor grants — those get paused, not expired.
     */
    public function expireOverdueGrants(): int
    {
        global $wpdb;
        $now = current_time('mysql');

        return (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table}
             SET status = 'expired', updated_at = %s
             WHERE status = 'active'
               AND expires_at IS NOT NULL
               AND expires_at <= %s
               AND (meta IS NULL OR meta NOT LIKE %s)",
            $now,
            $now,
            '%"billing_anchor_day"%'
        ));
    }

    /**
     * Get active anchor grants whose expires_at has passed.
     * These should be paused (recoverable), not expired (terminal).
     */
    public function getOverdueAnchorGrants(): array
    {
        global $wpdb;
        $now = current_time('mysql');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE status = 'active'
               AND expires_at IS NOT NULL
               AND expires_at <= %s
               AND meta LIKE %s",
            $now,
            '%"billing_anchor_day"%'
        ), ARRAY_A);

        return array_map([$this, 'hydrate'], $rows ?: []);
    }

    /**
     * Get all user IDs with active grants for a specific plan.
     */
    public function getUserIdsForPlan(int $planId): array
    {
        global $wpdb;
        $now = current_time('mysql');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT user_id FROM {$this->table}
             WHERE plan_id = %d
               AND status = 'active'
               AND (starts_at IS NULL OR starts_at <= %s)
               AND (expires_at IS NULL OR expires_at > %s)",
            $planId,
            $now,
            $now
        ), ARRAY_A);

        return array_column($rows ?: [], 'user_id');
    }

    public function getPausedGrants(int $userId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE user_id = %d AND status = 'paused' ORDER BY updated_at DESC",
            $userId
        ), ARRAY_A);
        return array_map([$this, 'hydrate'], $rows ?: []);
    }

    /**
     * @return int[]
     */
    public function getActiveSubscriptionSourceIds(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT DISTINCT source_id
             FROM {$this->table}
             WHERE status = 'active'
               AND source_type = 'subscription'
               AND source_id > 0",
            ARRAY_A
        );

        return array_map('intval', array_column($rows ?: [], 'source_id'));
    }

    public function getDueGracePeriodGrants(int $limit = 100): array
    {
        global $wpdb;
        $now = current_time('mysql');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE status = 'active'
               AND cancellation_effective_at IS NOT NULL
               AND cancellation_effective_at <= %s
             LIMIT %d",
            $now,
            $limit
        ), ARRAY_A);

        return array_map([$this, 'hydrate'], $rows ?: []);
    }

    /**
     * Get all active resource IDs for a user, grouped by resource_type.
     *
     * @return array<string, string[]> e.g. ['post' => ['1','2'], 'page' => ['5'], ...]
     */
    public function getAllUserResourceIds(int $userId): array
    {
        global $wpdb;
        $now = current_time('mysql');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT resource_type, resource_id FROM {$this->table}
             WHERE user_id = %d
               AND status = 'active'
               AND (starts_at IS NULL OR starts_at <= %s)
               AND (expires_at IS NULL OR expires_at > %s)",
            $userId,
            $now,
            $now
        ), ARRAY_A);

        $grouped = [];
        foreach ($rows ?: [] as $row) {
            $grouped[$row['resource_type']][] = $row['resource_id'];
        }

        return $grouped;
    }

    /**
     * Get distinct plan IDs where user has active grants (not expired/revoked).
     *
     * @return int[]
     */
    public function getUserActivePlanIds(int $userId): array
    {
        global $wpdb;
        $now = current_time('mysql');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT plan_id FROM {$this->table}
             WHERE user_id = %d
               AND plan_id IS NOT NULL
               AND status = 'active'
               AND (starts_at IS NULL OR starts_at <= %s)
               AND (expires_at IS NULL OR expires_at > %s)",
            $userId,
            $now,
            $now
        ), ARRAY_A);

        return array_map('intval', array_column($rows ?: [], 'plan_id'));
    }

    /**
     * Get the highest plan level among user's active grants.
     */
    public function getHighestActivePlanLevel(int $userId): int
    {
        $planIds = $this->getUserActivePlanIds($userId);
        if (empty($planIds)) {
            return 0;
        }

        $planRepo = new PlanRepository();
        $maxLevel = 0;
        foreach ($planIds as $planId) {
            $plan = $planRepo->find($planId);
            if ($plan) {
                $maxLevel = max($maxLevel, (int) ($plan['level'] ?? 0));
            }
        }

        return $maxLevel;
    }

    public function countByStatus(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM {$this->table} GROUP BY status",
            ARRAY_A
        );
        $counts = [];
        foreach ($rows ?: [] as $row) {
            $counts[$row['status']] = (int) $row['count'];
        }
        return $counts;
    }

    private function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['user_id'] = (int) $row['user_id'];
        $row['plan_id'] = $row['plan_id'] !== null ? (int) $row['plan_id'] : null;
        $row['source_id'] = (int) $row['source_id'];
        $row['feed_id'] = $row['feed_id'] !== null ? (int) $row['feed_id'] : null;
        $row['trial_ends_at'] = $row['trial_ends_at'] ?? null;
        $row['cancellation_requested_at'] = $row['cancellation_requested_at'] ?? null;
        $row['cancellation_effective_at'] = $row['cancellation_effective_at'] ?? null;
        $row['cancellation_reason'] = $row['cancellation_reason'] ?? null;
        $row['renewal_count'] = (int) ($row['renewal_count'] ?? 0);
        $row['source_ids'] = json_decode($row['source_ids'] ?? '[]', true) ?: [];
        $row['meta'] = json_decode($row['meta'] ?? '{}', true) ?: [];
        return $row;
    }
}

<?php

namespace FChubMemberships\Storage;

defined('ABSPATH') || exit;

class PlanRepository
{
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'fchub_membership_plans';
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

    public function findBySlug(string $slug): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE slug = %s",
            $slug
        ), ARRAY_A);

        return $row ? $this->hydrate($row) : null;
    }

    public function all(array $filters = []): array
    {
        global $wpdb;

        $where = ['1=1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'status = %s';
            $params[] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            $where[] = 'title LIKE %s';
            $params[] = '%' . $wpdb->esc_like($filters['search']) . '%';
        }

        $orderBy = $filters['order_by'] ?? 'level';
        $order = ($filters['order'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
        $allowedOrderBy = ['id', 'title', 'level', 'status', 'created_at'];
        if (!in_array($orderBy, $allowedOrderBy, true)) {
            $orderBy = 'level';
        }

        $sql = "SELECT * FROM {$this->table} WHERE " . implode(' AND ', $where) . " ORDER BY {$orderBy} {$order}";

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

    public function count(array $filters = []): int
    {
        global $wpdb;

        $where = ['1=1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'status = %s';
            $params[] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            $where[] = 'title LIKE %s';
            $params[] = '%' . $wpdb->esc_like($filters['search']) . '%';
        }

        $sql = "SELECT COUNT(*) FROM {$this->table} WHERE " . implode(' AND ', $where);

        if ($params) {
            $sql = $wpdb->prepare($sql, ...$params);
        }

        return (int) $wpdb->get_var($sql);
    }

    public function create(array $data): int
    {
        global $wpdb;

        $now = current_time('mysql');
        $insert = [
            'title'               => $data['title'],
            'slug'                => $data['slug'],
            'description'         => $data['description'] ?? null,
            'status'              => $data['status'] ?? 'active',
            'level'               => (int) ($data['level'] ?? 0),
            'duration_type'       => $data['duration_type'] ?? 'lifetime',
            'duration_days'       => $data['duration_days'] ?? null,
            'trial_days'          => (int) ($data['trial_days'] ?? 0),
            'grace_period_days'   => (int) ($data['grace_period_days'] ?? 0),
            'includes_plan_ids'   => wp_json_encode($data['includes_plan_ids'] ?? []),
            'restriction_message' => $data['restriction_message'] ?? null,
            'redirect_url'        => $data['redirect_url'] ?? null,
            'settings'            => wp_json_encode($data['settings'] ?? []),
            'meta'                => wp_json_encode($data['meta'] ?? []),
            'created_at'          => $now,
            'updated_at'          => $now,
        ];

        $wpdb->insert($this->table, $insert);
        return (int) $wpdb->insert_id;
    }

    public function update(int $id, array $data): bool
    {
        global $wpdb;

        $update = ['updated_at' => current_time('mysql')];

        $directFields = ['title', 'slug', 'description', 'status', 'duration_type', 'restriction_message', 'redirect_url'];
        foreach ($directFields as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field];
            }
        }

        $intFields = ['level', 'duration_days', 'trial_days', 'grace_period_days'];
        foreach ($intFields as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field] !== null ? (int) $data[$field] : null;
            }
        }

        $jsonFields = ['includes_plan_ids', 'settings', 'meta'];
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

    public function getActivePlans(): array
    {
        return $this->all(['status' => 'active', 'order_by' => 'level', 'order' => 'ASC']);
    }

    public function getMemberCount(int $planId): int
    {
        global $wpdb;
        $grantsTable = $wpdb->prefix . 'fchub_membership_grants';
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT user_id) FROM {$grantsTable} WHERE plan_id = %d AND status = 'active'",
            $planId
        ));
    }

    public function getRuleCount(int $planId): int
    {
        global $wpdb;
        $rulesTable = $wpdb->prefix . 'fchub_membership_plan_rules';
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$rulesTable} WHERE plan_id = %d",
            $planId
        ));
    }

    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        global $wpdb;

        if ($excludeId) {
            return (bool) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table} WHERE slug = %s AND id != %d",
                $slug,
                $excludeId
            ));
        }

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE slug = %s",
            $slug
        ));
    }

    public function generateUniqueSlug(string $title, ?int $excludeId = null): string
    {
        $slug = sanitize_title($title);
        $baseSlug = $slug;
        $counter = 1;

        while ($this->slugExists($slug, $excludeId)) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    public function updateSchedule(int $id, ?string $scheduledStatus, ?string $scheduledAt): bool
    {
        global $wpdb;

        return $wpdb->update(
            $this->table,
            [
                'scheduled_status' => $scheduledStatus,
                'scheduled_at'     => $scheduledAt,
                'updated_at'       => current_time('mysql'),
            ],
            ['id' => $id]
        ) !== false;
    }

    public function getDueScheduledPlans(): array
    {
        global $wpdb;

        $now = current_time('mysql');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE scheduled_status IS NOT NULL AND scheduled_at IS NOT NULL AND scheduled_at <= %s",
            $now
        ), ARRAY_A);

        return array_map([$this, 'hydrate'], $rows ?: []);
    }

    private function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['level'] = (int) $row['level'];
        $row['duration_type'] = $row['duration_type'] ?? 'lifetime';
        $row['duration_days'] = isset($row['duration_days']) ? (int) $row['duration_days'] : null;
        $row['trial_days'] = (int) ($row['trial_days'] ?? 0);
        $row['grace_period_days'] = (int) ($row['grace_period_days'] ?? 0);
        $row['includes_plan_ids'] = json_decode($row['includes_plan_ids'] ?? '[]', true) ?: [];
        $row['settings'] = json_decode($row['settings'] ?? '{}', true) ?: [];
        $row['meta'] = json_decode($row['meta'] ?? '{}', true) ?: [];
        $row['scheduled_status'] = $row['scheduled_status'] ?? null;
        $row['scheduled_at'] = $row['scheduled_at'] ?? null;
        return $row;
    }
}

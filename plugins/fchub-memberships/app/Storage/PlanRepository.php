<?php

namespace FChubMemberships\Storage;

defined('ABSPATH') || exit;

use FChubMemberships\Domain\AccessEvaluator;
use FChubMemberships\Domain\Plan\PlanRuleResolver;
use FChubMemberships\Support\PlanStatus;

class PlanRepository
{
    private string $table;

    /** @var array<int, array<string, mixed>|null> */
    private static array $byId = [];

    /** @var array<string, array<string, mixed>|null> */
    private static array $bySlug = [];

    /** @var array<int, array<string, mixed>>|null */
    private static ?array $activePlans = null;

    private static ?object $cacheContext = null;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'fchub_membership_plans';
    }

    public function find(int $id): ?array
    {
        $this->ensureCacheContext();
        if (array_key_exists($id, self::$byId)) {
            return self::$byId[$id];
        }

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d",
            $id
        ), ARRAY_A);
        if (!empty($wpdb->last_error)) {
            throw new \RuntimeException('Unable to read membership plan.');
        }

        $plan = $row ? $this->hydrate($row) : null;
        self::$byId[$id] = $plan;
        if ($plan !== null) {
            self::$bySlug[(string) $plan['slug']] = $plan;
        }

        return $plan;
    }

    /**
     * Return plans keyed by ID in the caller's first-seen order.
     *
     * @param int[] $ids
     * @return array<int, array<string, mixed>>
     */
    public function findMany(array $ids): array
    {
        $this->ensureCacheContext();
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn(int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return [];
        }

        $missing = array_values(array_filter(
            $ids,
            static fn(int $id): bool => !array_key_exists($id, self::$byId)
        ));
        if ($missing !== []) {
            global $wpdb;
            $placeholders = implode(', ', array_fill(0, count($missing), '%d'));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE id IN ({$placeholders})",
                ...$missing
            ), ARRAY_A);
            if (!empty($wpdb->last_error)) {
                throw new \RuntimeException('Unable to read membership plans.');
            }

            foreach ($missing as $id) {
                self::$byId[$id] = null;
            }
            foreach ($rows ?: [] as $row) {
                $plan = $this->hydrate($row);
                self::$byId[(int) $plan['id']] = $plan;
                self::$bySlug[(string) $plan['slug']] = $plan;
            }
        }

        $plans = [];
        foreach ($ids as $id) {
            if (self::$byId[$id] !== null) {
                $plans[$id] = self::$byId[$id];
            }
        }

        return $plans;
    }

    public function findBySlug(string $slug): ?array
    {
        $this->ensureCacheContext();
        if (array_key_exists($slug, self::$bySlug)) {
            return self::$bySlug[$slug];
        }

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE slug = %s",
            $slug
        ), ARRAY_A);
        if (!empty($wpdb->last_error)) {
            throw new \RuntimeException('Unable to read membership plan.');
        }

        $plan = $row ? $this->hydrate($row) : null;
        self::$bySlug[$slug] = $plan;
        if ($plan !== null) {
            self::$byId[(int) $plan['id']] = $plan;
        }

        return $plan;
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
        if (!empty($wpdb->last_error)) {
            throw new \RuntimeException('Unable to read membership plans.');
        }

        return $this->hydrateMany($rows ?: []);
    }

    /**
     * Return the paginated admin list with operational counts in one query.
     */
    public function allForAdmin(array $filters = []): array
    {
        global $wpdb;

        $grantsTable = $wpdb->prefix . 'fchub_membership_grants';
        $rulesTable = $wpdb->prefix . 'fchub_membership_plan_rules';
        $now = current_time('mysql');
        $where = ['1=1'];
        $params = [$now, $now];

        if (!empty($filters['status'])) {
            $where[] = 'p.status = %s';
            $params[] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            $where[] = '(p.title LIKE %s OR p.slug LIKE %s)';
            $like = '%' . $wpdb->esc_like($filters['search']) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $orderBy = $filters['order_by'] ?? 'level';
        $order = ($filters['order'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
        $allowedOrderBy = ['id', 'title', 'level', 'status', 'created_at'];
        if (!in_array($orderBy, $allowedOrderBy, true)) {
            $orderBy = 'level';
        }

        $sql = "SELECT p.*,
                    (SELECT COUNT(DISTINCT g.user_id)
                     FROM {$grantsTable} g
                     WHERE g.plan_id = p.id
                       AND g.status = 'active'
                       AND (g.starts_at IS NULL OR g.starts_at <= %s)
                       AND (g.expires_at IS NULL OR g.expires_at > %s)) AS members_count,
                    (SELECT COUNT(*) FROM {$rulesTable} r WHERE r.plan_id = p.id) AS rules_count,
                    (SELECT COUNT(*) FROM {$rulesTable} r WHERE r.plan_id = p.id AND r.drip_type != 'immediate') AS drip_count,
                    (SELECT COUNT(*) FROM {$grantsTable} gh WHERE gh.plan_id = p.id) AS history_count
                FROM {$this->table} p
                WHERE " . implode(' AND ', $where) . "
                ORDER BY p.{$orderBy} {$order}";

        if (!empty($filters['per_page'])) {
            $page = max(1, (int) ($filters['page'] ?? 1));
            $perPage = (int) $filters['per_page'];
            $offset = ($page - 1) * $perPage;
            $sql .= ' LIMIT %d OFFSET %d';
            $params[] = $perPage;
            $params[] = $offset;
        }

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
        if (!empty($wpdb->last_error)) {
            throw new \RuntimeException('Unable to read membership plans.');
        }

        return $this->hydrateMany($rows ?: []);
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
            $where[] = '(title LIKE %s OR slug LIKE %s)';
            $like = '%' . $wpdb->esc_like($filters['search']) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $sql = "SELECT COUNT(*) FROM {$this->table} WHERE " . implode(' AND ', $where);

        if ($params) {
            $sql = $wpdb->prepare($sql, ...$params);
        }

        $count = $wpdb->get_var($sql);
        if (!empty($wpdb->last_error)) {
            throw new \RuntimeException('Unable to count membership plans.');
        }

        return (int) $count;
    }

    public function create(array $data): int
    {
        global $wpdb;

        $now = current_time('mysql');
        $insert = [
            'title'               => $data['title'],
            'slug'                => $data['slug'],
            'description'         => $data['description'] ?? null,
            'status'              => PlanStatus::normalize($data['status'] ?? null, PlanStatus::ACTIVE),
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

        $created = $wpdb->insert($this->table, $insert);
        if ($created !== false) {
            self::invalidateAfterWrite();
        }

        return (int) $wpdb->insert_id;
    }

    public function update(int $id, array $data): bool
    {
        global $wpdb;

        $update = ['updated_at' => current_time('mysql')];

        $directFields = ['title', 'slug', 'description', 'duration_type', 'restriction_message', 'redirect_url'];
        foreach ($directFields as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field];
            }
        }

        if (array_key_exists('status', $data)) {
            $update['status'] = PlanStatus::normalize($data['status'], PlanStatus::ACTIVE);
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

        $updated = $wpdb->update($this->table, $update, ['id' => $id]) !== false;
        if ($updated) {
            self::invalidateAfterWrite();
        }

        return $updated;
    }

    public function delete(int $id): bool
    {
        global $wpdb;
        $deleted = $wpdb->delete($this->table, ['id' => $id]) !== false;
        if ($deleted) {
            self::invalidateAfterWrite();
        }

        return $deleted;
    }

    public function getActivePlans(): array
    {
        $this->ensureCacheContext();
        if (self::$activePlans === null) {
            self::$activePlans = $this->all(['status' => 'active', 'order_by' => 'level', 'order' => 'ASC']);
        }

        return self::$activePlans;
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

    public function countGrantHistory(int $planId): int
    {
        global $wpdb;
        $grantsTable = $wpdb->prefix . 'fchub_membership_grants';

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$grantsTable} WHERE plan_id = %d",
            $planId
        ));
    }

    /**
     * Return unfiltered plan health totals for the admin operations strip.
     *
     * @return array{total:int, active:int, needs_content:int, scheduled:int}
     */
    public function getAdminSummary(): array
    {
        global $wpdb;
        $rulesTable = $wpdb->prefix . 'fchub_membership_plan_rules';
        $row = $wpdb->get_row(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN p.status = 'active' THEN 1 ELSE 0 END) AS active,
                SUM(CASE WHEN p.status = 'active' AND NOT EXISTS (
                    SELECT 1 FROM {$rulesTable} r WHERE r.plan_id = p.id
                ) THEN 1 ELSE 0 END) AS needs_content,
                SUM(CASE WHEN p.scheduled_status IS NOT NULL AND p.scheduled_at IS NOT NULL THEN 1 ELSE 0 END) AS scheduled
             FROM {$this->table} p",
            ARRAY_A
        ) ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'active' => (int) ($row['active'] ?? 0),
            'needs_content' => (int) ($row['needs_content'] ?? 0),
            'scheduled' => (int) ($row['scheduled'] ?? 0),
        ];
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

        $updated = $wpdb->update(
            $this->table,
            [
                'scheduled_status' => $scheduledStatus,
                'scheduled_at'     => $scheduledAt,
                'updated_at'       => current_time('mysql'),
            ],
            ['id' => $id]
        ) !== false;
        if ($updated) {
            self::invalidateAfterWrite();
        }

        return $updated;
    }

    public function getDueScheduledPlans(): array
    {
        global $wpdb;

        $now = current_time('mysql');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE scheduled_status IS NOT NULL AND scheduled_at IS NOT NULL AND scheduled_at <= %s",
            $now
        ), ARRAY_A);

        return $this->hydrateMany($rows ?: []);
    }

    public static function clearCache(): void
    {
        self::$byId = [];
        self::$bySlug = [];
        self::$activePlans = null;
        self::$cacheContext = null;
    }

    private static function invalidateAfterWrite(): void
    {
        self::clearCache();
        PlanRuleResolver::invalidateSharedCache();
        AccessEvaluator::clearCache();
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function hydrateMany(array $rows): array
    {
        $this->ensureCacheContext();
        $plans = [];
        foreach ($rows as $row) {
            $plan = $this->hydrate($row);
            self::$byId[(int) $plan['id']] = $plan;
            self::$bySlug[(string) $plan['slug']] = $plan;
            $plans[] = $plan;
        }

        return $plans;
    }

    private function ensureCacheContext(): void
    {
        global $wpdb;
        if (self::$cacheContext === $wpdb) {
            return;
        }

        self::$byId = [];
        self::$bySlug = [];
        self::$activePlans = null;
        self::$cacheContext = $wpdb;
    }

    private function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['level'] = (int) $row['level'];
        $row['status'] = PlanStatus::normalize($row['status'] ?? null, PlanStatus::ACTIVE);
        $row['duration_type'] = $row['duration_type'] ?? 'lifetime';
        $row['duration_days'] = isset($row['duration_days']) ? (int) $row['duration_days'] : null;
        $row['trial_days'] = (int) ($row['trial_days'] ?? 0);
        $row['grace_period_days'] = (int) ($row['grace_period_days'] ?? 0);
        $row['includes_plan_ids'] = json_decode($row['includes_plan_ids'] ?? '[]', true) ?: [];
        $row['settings'] = json_decode($row['settings'] ?? '{}', true) ?: [];
        $row['meta'] = json_decode($row['meta'] ?? '{}', true) ?: [];
        $row['scheduled_status'] = $row['scheduled_status'] ?? null;
        $row['scheduled_at'] = $row['scheduled_at'] ?? null;
        foreach (['members_count', 'rules_count', 'drip_count', 'history_count'] as $countField) {
            if (array_key_exists($countField, $row)) {
                $row[$countField] = (int) $row[$countField];
            }
        }
        return $row;
    }
}

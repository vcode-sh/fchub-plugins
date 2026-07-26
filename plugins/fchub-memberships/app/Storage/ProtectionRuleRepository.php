<?php

namespace FChubMemberships\Storage;

defined('ABSPATH') || exit;

use FChubMemberships\Support\Constants;
use FChubMemberships\Domain\AccessEvaluator;

class ProtectionRuleRepository
{
    private const CACHE_GROUP = 'fchub_memberships';
    private const ANY_RULE_CACHE_KEY = 'protection_rules:any';

    private string $table;

    /** @var array<int, array<string, mixed>|null> */
    private static array $byId = [];

    /** @var array<string, array<string, mixed>|null> */
    private static array $byResource = [];

    private static ?object $cacheContext = null;

    public function __construct()
    {
        global $wpdb;
        $this->table = \FChubMemberships\Support\CustomTableDatabase::identifier($wpdb->prefix . 'fchub_membership_protection_rules');
    }

    public function find(int $id): ?array
    {
        $this->ensureCacheContext();
        if (array_key_exists($id, self::$byId)) {
            return self::$byId[$id];
        }

        global $wpdb;
        $row = \FChubMemberships\Support\CustomTableDatabase::getRow(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT * FROM {$this->table} WHERE id = %d",
            $id
        ), ARRAY_A);
        if (!empty($wpdb->last_error)) {
            throw new \RuntimeException('Unable to read protection rule.');
        }

        $rule = $row ? $this->hydrate($row) : null;
        self::$byId[$id] = $rule;
        if ($rule !== null) {
            self::$byResource[$this->resourceKey($rule['resource_type'], $rule['resource_id'])] = $rule;
        }

        return $rule;
    }

    public function findByResource(string $resourceType, string $resourceId): ?array
    {
        $this->ensureCacheContext();
        $cacheKey = $this->resourceKey($resourceType, $resourceId);
        if (array_key_exists($cacheKey, self::$byResource)) {
            return self::$byResource[$cacheKey];
        }

        global $wpdb;
        $row = \FChubMemberships\Support\CustomTableDatabase::getRow(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT * FROM {$this->table} WHERE resource_type = %s AND resource_id = %s",
            $resourceType,
            $resourceId
        ), ARRAY_A);
        if (!empty($wpdb->last_error)) {
            throw new \RuntimeException('Unable to read protection rule.');
        }

        $rule = $row ? $this->hydrate($row) : null;
        self::$byResource[$cacheKey] = $rule;
        if ($rule !== null) {
            self::$byId[(int) $rule['id']] = $rule;
        }

        return $rule;
    }

    public function all(array $filters = []): array
    {
        global $wpdb;

        $where = ['1=%d'];
        $params = [1];

        if (!empty($filters['resource_type'])) {
            $where[] = 'resource_type = %s';
            $params[] = $filters['resource_type'];
        }

        if (!empty($filters['protection_mode'])) {
            $where[] = 'protection_mode = %s';
            $params[] = $filters['protection_mode'];
        }

        if (!empty($filters['plan_id'])) {
            $where[] = "(
                plan_ids IS NULL
                OR plan_ids = ''
                OR plan_ids = '[]'
                OR (
                    JSON_VALID(plan_ids)
                    AND JSON_CONTAINS(plan_ids, %s) = 1
                )
            )";
            $params[] = wp_json_encode((int) $filters['plan_id']);
        }

        if (!empty($filters['search'])) {
            $where[] = 'resource_id LIKE %s';
            $params[] = '%' . $wpdb->esc_like($filters['search']) . '%';
        }

        $sql = "SELECT * FROM {$this->table} WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC";

        if (!empty($filters['per_page'])) {
            $page = max(1, (int) ($filters['page'] ?? 1));
            $perPage = (int) $filters['per_page'];
            $offset = ($page - 1) * $perPage;
            $sql .= \FChubMemberships\Support\CustomTableDatabase::prepare(' LIMIT %d OFFSET %d', $perPage, $offset)->sql();
        }

        $query = \FChubMemberships\Support\CustomTableDatabase::prepare($sql, ...$params);

        $rows = \FChubMemberships\Support\CustomTableDatabase::getResults($query, ARRAY_A);
        if (!empty($wpdb->last_error)) {
            throw new \RuntimeException('Unable to read protection rules.');
        }

        return $this->hydrateMany($rows ?: []);
    }

    public function count(array $filters = []): int
    {
        global $wpdb;

        $where = ['1=%d'];
        $params = [1];

        if (!empty($filters['resource_type'])) {
            $where[] = 'resource_type = %s';
            $params[] = $filters['resource_type'];
        }

        if (!empty($filters['protection_mode'])) {
            $where[] = 'protection_mode = %s';
            $params[] = $filters['protection_mode'];
        }

        if (!empty($filters['plan_id'])) {
            $where[] = "(
                plan_ids IS NULL
                OR plan_ids = ''
                OR plan_ids = '[]'
                OR (
                    JSON_VALID(plan_ids)
                    AND JSON_CONTAINS(plan_ids, %s) = 1
                )
            )";
            $params[] = wp_json_encode((int) $filters['plan_id']);
        }

        if (!empty($filters['search'])) {
            $where[] = 'resource_id LIKE %s';
            $params[] = '%' . $wpdb->esc_like($filters['search']) . '%';
        }

        $sql = "SELECT COUNT(*) FROM {$this->table} WHERE " . implode(' AND ', $where);

        $query = \FChubMemberships\Support\CustomTableDatabase::prepare($sql, ...$params);

        $count = \FChubMemberships\Support\CustomTableDatabase::getVar($query);
        if (!empty($wpdb->last_error)) {
            throw new \RuntimeException('Unable to count protection rules.');
        }

        return (int) $count;
    }

    /**
     * Return workspace-wide operational counts without loading individual rules.
     *
     * @return array{
     *     total_rules: int,
     *     resource_types: int,
     *     teaser_rules: int,
     *     unassigned_rules: int,
     *     type_counts: array<string, int>
     * }
     */
    public function summary(): array
    {
        global $wpdb;

        $rows = \FChubMemberships\Support\CustomTableDatabase::getResults(
            \FChubMemberships\Support\CustomTableDatabase::prepare("SELECT resource_type,
                    COUNT(*) AS total_rules,
                    SUM(CASE WHEN show_teaser = %s THEN 1 ELSE 0 END) AS teaser_rules,
                    SUM(CASE WHEN plan_ids IS NULL OR plan_ids = '' OR plan_ids = '[]' THEN 1 ELSE 0 END) AS unassigned_rules
             FROM {$this->table}
             GROUP BY resource_type",
                'yes',
            ),
            ARRAY_A
        );
        if (!empty($wpdb->last_error)) {
            throw new \RuntimeException('Unable to summarise protection rules.');
        }

        $summary = [
            'total_rules' => 0,
            'resource_types' => 0,
            'teaser_rules' => 0,
            'unassigned_rules' => 0,
            'type_counts' => [],
        ];

        foreach ($rows ?: [] as $row) {
            $resourceType = (string) ($row['resource_type'] ?? '');
            if ($resourceType === '') {
                continue;
            }

            $typeTotal = (int) ($row['total_rules'] ?? 0);
            $summary['type_counts'][$resourceType] = $typeTotal;
            $summary['total_rules'] += $typeTotal;
            $summary['teaser_rules'] += (int) ($row['teaser_rules'] ?? 0);
            $summary['unassigned_rules'] += (int) ($row['unassigned_rules'] ?? 0);
        }

        $summary['resource_types'] = count($summary['type_counts']);

        return $summary;
    }

    public function create(array $data): int
    {
        global $wpdb;

        // Bug #11: Validate protection_mode against allowed values
        $protectionMode = $data['protection_mode'] ?? Constants::PROTECTION_MODE_EXPLICIT;
        if (!in_array($protectionMode, Constants::ALLOWED_PROTECTION_MODES, true)) {
            $protectionMode = Constants::PROTECTION_MODE_EXPLICIT;
        }

        $now = current_time('mysql');
        $insert = [
            'resource_type'       => $data['resource_type'],
            'resource_id'         => (string) $data['resource_id'],
            // Bug #6: Always encode plan_ids as JSON; use [] for empty instead of null
            'plan_ids'            => wp_json_encode($data['plan_ids'] ?? []),
            'protection_mode'     => $protectionMode,
            'restriction_message' => $data['restriction_message'] ?? null,
            'redirect_url'        => $data['redirect_url'] ?? null,
            'show_teaser'         => $data['show_teaser'] ?? 'no',
            'meta'                => wp_json_encode($data['meta'] ?? []),
            'created_at'          => $now,
            'updated_at'          => $now,
        ];

        $created = \FChubMemberships\Support\CustomTableDatabase::insert($this->table, $insert);
        if ($created !== false) {
            self::invalidateAfterWrite();
        }

        return (int) $wpdb->insert_id;
    }

    public function update(int $id, array $data): bool
    {
        global $wpdb;

        $update = ['updated_at' => current_time('mysql')];

        $directFields = ['resource_type', 'resource_id', 'restriction_message', 'redirect_url', 'show_teaser'];
        foreach ($directFields as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field];
            }
        }

        // Bug #11: Validate protection_mode against allowed values
        if (array_key_exists('protection_mode', $data)) {
            $mode = $data['protection_mode'];
            $update['protection_mode'] = in_array($mode, Constants::ALLOWED_PROTECTION_MODES, true)
                ? $mode
                : Constants::PROTECTION_MODE_EXPLICIT;
        }

        // Bug #6: Always encode plan_ids as JSON; use [] for empty instead of null
        if (array_key_exists('plan_ids', $data)) {
            $update['plan_ids'] = wp_json_encode($data['plan_ids'] ?? []);
        }

        if (array_key_exists('meta', $data)) {
            $update['meta'] = wp_json_encode($data['meta'] ?? []);
        }

        $updated = \FChubMemberships\Support\CustomTableDatabase::update($this->table, $update, ['id' => $id]) !== false;
        if ($updated) {
            self::invalidateAfterWrite();
        }

        return $updated;
    }

    public function delete(int $id): bool
    {
        global $wpdb;
        $deleted = \FChubMemberships\Support\CustomTableDatabase::delete($this->table, ['id' => $id]) !== false;
        if ($deleted) {
            self::invalidateAfterWrite();
        }

        return $deleted;
    }

    public function createOrUpdate(string $resourceType, string $resourceId, array $data): int
    {
        $existing = $this->findByResource($resourceType, $resourceId);

        if ($existing) {
            $this->update($existing['id'], $data);
            return $existing['id'];
        }

        $data['resource_type'] = $resourceType;
        $data['resource_id'] = $resourceId;
        return $this->create($data);
    }

    /**
     * Check if a resource is explicitly protected.
     */
    public function isProtected(string $resourceType, string $resourceId): bool
    {
        return $this->findByResource($resourceType, $resourceId) !== null;
    }

    public function hasAnyRules(): bool
    {
        $cached = wp_cache_get(self::ANY_RULE_CACHE_KEY, self::CACHE_GROUP);
        if ($cached !== false) {
            return (bool) $cached;
        }

        global $wpdb;
        $hasRules = (bool) \FChubMemberships\Support\CustomTableDatabase::getVar(
            \FChubMemberships\Support\CustomTableDatabase::prepare(
                "SELECT EXISTS(SELECT 1 FROM {$this->table} WHERE 1 = %d LIMIT 1)",
                1,
            ),
        );
        if (!empty($wpdb->last_error)) {
            throw new \RuntimeException('Unable to determine whether protection rules exist.');
        }

        wp_cache_set(self::ANY_RULE_CACHE_KEY, $hasRules ? 1 : 0, self::CACHE_GROUP);
        return $hasRules;
    }

    /**
     * Get all protected resource IDs of a given type.
     */
    public function getProtectedResourceIds(string $resourceType): array
    {
        global $wpdb;
        $rows = \FChubMemberships\Support\CustomTableDatabase::getResults(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT resource_id FROM {$this->table} WHERE resource_type = %s",
            $resourceType
        ), ARRAY_A);

        return array_column($rows ?: [], 'resource_id');
    }

    /**
     * Get post IDs that are protected via taxonomy term inheritance.
     * Finds taxonomy terms with protection rules where inheritance_mode=all_posts,
     * then returns all post IDs assigned to those terms.
     *
     * @param string $postType The post type to check.
     * @return string[] Post IDs protected via taxonomy inheritance.
     */
    public function getPostIdsProtectedByTaxonomy(string $postType): array
    {
        global $wpdb;

        // Get all taxonomies associated with this post type
        $taxonomies = get_object_taxonomies($postType, 'names');
        if (empty($taxonomies)) {
            return [];
        }

        // Find protection rules for these taxonomies with inheritance_mode=all_posts
        $placeholders = implode(',', array_fill(0, count($taxonomies), '%s'));
        $params = $taxonomies;

        $rules = \FChubMemberships\Support\CustomTableDatabase::getResults(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT resource_type, resource_id, meta FROM {$this->table} WHERE resource_type IN ({$placeholders})",
            ...$params
        ), ARRAY_A);

        if (empty($rules)) {
            return [];
        }

        // Filter to only rules with inheritance_mode=all_posts
        $inheritedTerms = [];
        foreach ($rules as $rule) {
            $meta = json_decode($rule['meta'] ?? '{}', true) ?: [];
            if (($meta['inheritance_mode'] ?? 'none') === 'all_posts') {
                $inheritedTerms[] = [
                    'taxonomy' => $rule['resource_type'],
                    'term_id'  => (int) $rule['resource_id'],
                ];
            }
        }

        if (empty($inheritedTerms)) {
            return [];
        }

        // Resolve taxonomy relationships first, then filter the resulting IDs by post type and status.
        $candidateIds = [];
        foreach ($inheritedTerms as $termInfo) {
            $termObjectIds = get_objects_in_term(
                $termInfo['term_id'],
                $termInfo['taxonomy'],
            );
            if (is_wp_error($termObjectIds)) {
                continue;
            }

            $candidateIds = array_merge($candidateIds, array_map('intval', $termObjectIds));
        }

        $candidateIds = array_values(array_unique(array_filter($candidateIds)));
        if ($candidateIds === []) {
            return [];
        }

        $postIds = get_posts([
            'post_type'      => $postType,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'post__in'       => $candidateIds,
        ]);

        return array_map('strval', $postIds);
    }

    public static function clearCache(): void
    {
        self::$byId = [];
        self::$byResource = [];
        self::$cacheContext = null;
        wp_cache_delete(self::ANY_RULE_CACHE_KEY, self::CACHE_GROUP);
    }

    private static function invalidateAfterWrite(): void
    {
        self::clearCache();
        AccessEvaluator::clearCache();
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function hydrateMany(array $rows): array
    {
        $this->ensureCacheContext();
        $rules = [];
        foreach ($rows as $row) {
            $rule = $this->hydrate($row);
            self::$byId[(int) $rule['id']] = $rule;
            self::$byResource[$this->resourceKey($rule['resource_type'], $rule['resource_id'])] = $rule;
            $rules[] = $rule;
        }

        return $rules;
    }

    private function resourceKey(string $resourceType, string $resourceId): string
    {
        return $resourceType . "\0" . $resourceId;
    }

    private function ensureCacheContext(): void
    {
        global $wpdb;
        if (self::$cacheContext === $wpdb) {
            return;
        }

        self::$byId = [];
        self::$byResource = [];
        self::$cacheContext = $wpdb;
    }

    /**
     * @param array<string, mixed> $row Raw database row.
     * @return array{id: int, resource_type: string, resource_id: string, plan_ids: int[], protection_mode: string, restriction_message: ?string, redirect_url: ?string, show_teaser: string, meta: array, created_at: string, updated_at: string}
     */
    private function hydrate(array $row): array
    {
        $row['id'] = (int) ($row['id'] ?? 0);
        $row['resource_type'] = (string) ($row['resource_type'] ?? '');
        $row['resource_id'] = (string) ($row['resource_id'] ?? '');
        $planIds = $row['plan_ids'] ?? null;
        $row['plan_ids'] = $planIds !== null ? (json_decode((string) $planIds, true) ?: []) : [];
        $row['protection_mode'] = (string) ($row['protection_mode'] ?? Constants::PROTECTION_MODE_EXPLICIT);
        $row['restriction_message'] = $row['restriction_message'] ?? null;
        $row['redirect_url'] = $row['redirect_url'] ?? null;
        $row['show_teaser'] = (string) ($row['show_teaser'] ?? 'no');
        $row['meta'] = json_decode($row['meta'] ?? '{}', true) ?: [];
        return $row;
    }
}

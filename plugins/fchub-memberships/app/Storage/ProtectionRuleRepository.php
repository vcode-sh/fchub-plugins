<?php

namespace FChubMemberships\Storage;

defined('ABSPATH') || exit;

use FChubMemberships\Support\Constants;

class ProtectionRuleRepository
{
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'fchub_membership_protection_rules';
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

    public function findByResource(string $resourceType, string $resourceId): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE resource_type = %s AND resource_id = %s",
            $resourceType,
            $resourceId
        ), ARRAY_A);

        return $row ? $this->hydrate($row) : null;
    }

    public function all(array $filters = []): array
    {
        global $wpdb;

        $where = ['1=1'];
        $params = [];

        if (!empty($filters['resource_type'])) {
            $where[] = 'resource_type = %s';
            $params[] = $filters['resource_type'];
        }

        if (!empty($filters['protection_mode'])) {
            $where[] = 'protection_mode = %s';
            $params[] = $filters['protection_mode'];
        }

        if (!empty($filters['plan_id'])) {
            $where[] = "(plan_ids IS NULL OR plan_ids LIKE %s)";
            $params[] = '%' . $wpdb->esc_like('"' . $filters['plan_id'] . '"') . '%';
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

        if (!empty($filters['resource_type'])) {
            $where[] = 'resource_type = %s';
            $params[] = $filters['resource_type'];
        }

        if (!empty($filters['protection_mode'])) {
            $where[] = 'protection_mode = %s';
            $params[] = $filters['protection_mode'];
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

        $wpdb->insert($this->table, $insert);
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

        return $wpdb->update($this->table, $update, ['id' => $id]) !== false;
    }

    public function delete(int $id): bool
    {
        global $wpdb;
        return $wpdb->delete($this->table, ['id' => $id]) !== false;
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

    /**
     * Get all protected resource IDs of a given type.
     */
    public function getProtectedResourceIds(string $resourceType): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
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

        $rules = $wpdb->get_results($wpdb->prepare(
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

        // Get all post IDs assigned to these terms
        $postIds = [];
        foreach ($inheritedTerms as $termInfo) {
            $termPostIds = get_posts([
                'post_type'      => $postType,
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'tax_query'      => [
                    [
                        'taxonomy' => $termInfo['taxonomy'],
                        'terms'    => $termInfo['term_id'],
                        'field'    => 'term_id',
                    ],
                ],
            ]);
            $postIds = array_merge($postIds, array_map('strval', $termPostIds));
        }

        return array_unique($postIds);
    }

    /**
     * @param array<string, mixed> $row Raw database row.
     * @return array{id: int, resource_type: string, resource_id: string, plan_ids: int[], protection_mode: string, restriction_message: ?string, redirect_url: ?string, show_teaser: string, meta: array, created_at: string, updated_at: string}
     */
    private function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['resource_type'] = (string) ($row['resource_type'] ?? '');
        $row['resource_id'] = (string) ($row['resource_id'] ?? '');
        $row['plan_ids'] = $row['plan_ids'] !== null ? (json_decode($row['plan_ids'], true) ?: []) : [];
        $row['protection_mode'] = (string) ($row['protection_mode'] ?? Constants::PROTECTION_MODE_EXPLICIT);
        $row['restriction_message'] = $row['restriction_message'] ?? null;
        $row['redirect_url'] = $row['redirect_url'] ?? null;
        $row['show_teaser'] = (string) ($row['show_teaser'] ?? 'no');
        $row['meta'] = json_decode($row['meta'] ?? '{}', true) ?: [];
        return $row;
    }
}

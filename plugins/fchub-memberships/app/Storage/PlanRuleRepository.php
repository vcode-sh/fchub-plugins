<?php

namespace FChubMemberships\Storage;

defined('ABSPATH') || exit;

use FChubMemberships\Domain\AccessEvaluator;
use FChubMemberships\Domain\Plan\PlanRuleResolver;

class PlanRuleRepository
{
    private const CACHE_GROUP = 'fchub_memberships';
    private const ANY_RULE_CACHE_KEY = 'plan_rules:any';

    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'fchub_membership_plan_rules';
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

    public function getByPlanId(int $planId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE plan_id = %d ORDER BY sort_order ASC, id ASC",
            $planId
        ), ARRAY_A);

        return array_map([$this, 'hydrate'], $rows ?: []);
    }

    public function getByPlanIds(array $planIds): array
    {
        if (empty($planIds)) {
            return [];
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($planIds), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE plan_id IN ({$placeholders}) ORDER BY plan_id ASC, sort_order ASC",
            ...$planIds
        ), ARRAY_A);

        return array_map([$this, 'hydrate'], $rows ?: []);
    }

    /** @return list<array<string, mixed>> */
    public function getAllForAccessResolution(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT * FROM {$this->table} ORDER BY plan_id ASC, sort_order ASC, id ASC",
            ARRAY_A
        );
        if (!is_array($rows) || !empty($wpdb->last_error)) {
            throw new \RuntimeException('Unable to read membership plan rules for access resolution.');
        }

        return array_map([$this, 'hydrate'], $rows);
    }

    public function create(array $data): int
    {
        global $wpdb;

        $now = current_time('mysql');
        $insert = [
            'plan_id'         => (int) $data['plan_id'],
            'provider'        => $data['provider'] ?? 'wordpress_core',
            'resource_type'   => $data['resource_type'],
            'resource_id'     => (string) $data['resource_id'],
            'drip_delay_days' => (int) ($data['drip_delay_days'] ?? 0),
            'drip_type'       => $data['drip_type'] ?? 'immediate',
            'drip_date'       => $data['drip_date'] ?? null,
            'sort_order'      => (int) ($data['sort_order'] ?? 0),
            'meta'            => wp_json_encode($data['meta'] ?? []),
            'created_at'      => $now,
            'updated_at'      => $now,
        ];

        if (($insert['drip_type'] === 'fixed_date') && empty($insert['drip_date'])) {
            throw new \InvalidArgumentException('drip_date is required when drip_type is fixed_date');
        }

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

        $directFields = ['provider', 'resource_type', 'resource_id', 'drip_type', 'drip_date'];
        foreach ($directFields as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field];
            }
        }

        $intFields = ['plan_id', 'drip_delay_days', 'sort_order'];
        foreach ($intFields as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = (int) $data[$field];
            }
        }

        if (array_key_exists('meta', $data)) {
            $update['meta'] = wp_json_encode($data['meta']);
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

    public function deleteByPlanId(int $planId): int
    {
        global $wpdb;
        $deleted = (int) $wpdb->delete($this->table, ['plan_id' => $planId]);
        if ($deleted > 0) {
            self::invalidateAfterWrite();
        }
        return $deleted;
    }

    public function bulkCreate(int $planId, array $rules): array
    {
        $ids = [];
        foreach ($rules as $i => $rule) {
            $rule['plan_id'] = $planId;
            $rule['sort_order'] = $rule['sort_order'] ?? $i;
            $ids[] = $this->create($rule);
        }
        return $ids;
    }

    public function syncRules(int $planId, array $rules): void
    {
        $this->deleteByPlanId($planId);
        $this->bulkCreate($planId, $rules);
    }

    public function findPlansWithResource(string $provider, string $resourceType, string $resourceId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT plan_id FROM {$this->table}
             WHERE provider = %s AND resource_type = %s AND (resource_id = %s OR resource_id = '*')",
            $provider,
            $resourceType,
            $resourceId
        ), ARRAY_A);

        return array_column($rows ?: [], 'plan_id');
    }

    public function getDripRules(int $planId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE plan_id = %d AND drip_type != 'immediate' ORDER BY sort_order ASC",
            $planId
        ), ARRAY_A);

        return array_map([$this, 'hydrate'], $rows ?: []);
    }

    public function countByPlanId(int $planId): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE plan_id = %d",
            $planId
        ));
    }

    public function hasAnyRules(): bool
    {
        $cached = wp_cache_get(self::ANY_RULE_CACHE_KEY, self::CACHE_GROUP);
        if ($cached !== false) {
            return (bool) $cached;
        }

        global $wpdb;
        $hasRules = (bool) $wpdb->get_var("SELECT EXISTS(SELECT 1 FROM {$this->table} LIMIT 1)");
        if (!empty($wpdb->last_error)) {
            throw new \RuntimeException('Unable to determine whether membership plan rules exist.');
        }
        wp_cache_set(self::ANY_RULE_CACHE_KEY, $hasRules ? 1 : 0, self::CACHE_GROUP);
        return $hasRules;
    }

    private static function invalidateAfterWrite(): void
    {
        wp_cache_delete(self::ANY_RULE_CACHE_KEY, self::CACHE_GROUP);
        PlanRuleResolver::invalidateSharedCache();
        AccessEvaluator::clearCache();
    }

    private function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['plan_id'] = (int) $row['plan_id'];
        $row['drip_delay_days'] = (int) $row['drip_delay_days'];
        $row['sort_order'] = (int) $row['sort_order'];
        $row['meta'] = json_decode($row['meta'] ?? '{}', true) ?: [];
        return $row;
    }
}

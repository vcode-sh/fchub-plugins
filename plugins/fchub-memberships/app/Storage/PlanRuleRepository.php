<?php

namespace FChubMemberships\Storage;

defined('ABSPATH') || exit;

class PlanRuleRepository
{
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

        $wpdb->insert($this->table, $insert);
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

        return $wpdb->update($this->table, $update, ['id' => $id]) !== false;
    }

    public function delete(int $id): bool
    {
        global $wpdb;
        return $wpdb->delete($this->table, ['id' => $id]) !== false;
    }

    public function deleteByPlanId(int $planId): int
    {
        global $wpdb;
        return (int) $wpdb->delete($this->table, ['plan_id' => $planId]);
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

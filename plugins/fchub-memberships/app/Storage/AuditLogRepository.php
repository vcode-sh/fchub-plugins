<?php

namespace FChubMemberships\Storage;

defined('ABSPATH') || exit;

class AuditLogRepository
{
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'fchub_membership_audit_log';
    }

    /**
     * Get audit log entries for a specific entity.
     */
    public function getByEntity(string $entityType, int $entityId, int $limit = 50): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE entity_type = %s AND entity_id = %d
             ORDER BY created_at DESC
             LIMIT %d",
            $entityType,
            $entityId,
            $limit
        ), ARRAY_A);

        return array_map([$this, 'hydrate'], $rows ?: []);
    }

    /**
     * Get audit log entries by actor.
     */
    public function getByActor(int $actorId, string $actorType = 'admin', int $limit = 50): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE actor_id = %d AND actor_type = %s
             ORDER BY created_at DESC
             LIMIT %d",
            $actorId,
            $actorType,
            $limit
        ), ARRAY_A);

        return array_map([$this, 'hydrate'], $rows ?: []);
    }

    /**
     * Get the most recent audit log entries.
     */
    public function getRecent(int $limit = 50): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table}
             ORDER BY created_at DESC
             LIMIT %d",
            $limit
        ), ARRAY_A);

        return array_map([$this, 'hydrate'], $rows ?: []);
    }

    /**
     * Delete audit log entries older than the retention period.
     *
     * @return int Number of rows deleted.
     */
    public function cleanup(int $retentionDays = 90): int
    {
        global $wpdb;

        $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));

        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table} WHERE created_at < %s",
            $cutoff
        ));
    }

    private function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['entity_id'] = (int) $row['entity_id'];
        $row['actor_id'] = (int) $row['actor_id'];
        $row['old_value'] = json_decode($row['old_value'] ?? '{}', true) ?: [];
        $row['new_value'] = json_decode($row['new_value'] ?? '{}', true) ?: [];
        return $row;
    }
}

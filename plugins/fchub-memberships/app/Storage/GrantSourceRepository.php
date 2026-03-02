<?php

namespace FChubMemberships\Storage;

defined('ABSPATH') || exit;

class GrantSourceRepository
{
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'fchub_membership_grant_sources';
    }

    public function addSource(int $grantId, string $sourceType, int $sourceId): bool
    {
        if ($this->hasSource($grantId, $sourceType, $sourceId)) {
            return true;
        }

        global $wpdb;
        return $wpdb->insert($this->table, [
            'grant_id'    => $grantId,
            'source_type' => $sourceType,
            'source_id'   => $sourceId,
            'created_at'  => current_time('mysql'),
        ]) !== false;
    }

    public function removeSource(int $grantId, string $sourceType, int $sourceId): bool
    {
        global $wpdb;
        return $wpdb->delete($this->table, [
            'grant_id'    => $grantId,
            'source_type' => $sourceType,
            'source_id'   => $sourceId,
        ]) !== false;
    }

    public function getSourcesByGrant(int $grantId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE grant_id = %d ORDER BY created_at ASC",
            $grantId
        ), ARRAY_A);

        return $rows ?: [];
    }

    public function getGrantsBySource(int $sourceId, string $sourceType = 'order'): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE source_id = %d AND source_type = %s",
            $sourceId,
            $sourceType
        ), ARRAY_A);

        return $rows ?: [];
    }

    public function hasSource(int $grantId, string $sourceType, int $sourceId): bool
    {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE grant_id = %d AND source_type = %s AND source_id = %d",
            $grantId,
            $sourceType,
            $sourceId
        ));
    }

    /**
     * Remove all sources for a grant.
     */
    public function removeAllByGrant(int $grantId): bool
    {
        global $wpdb;
        return $wpdb->delete($this->table, ['grant_id' => $grantId]) !== false;
    }

    /**
     * Get grant IDs that have a specific source.
     */
    public function getGrantIdsBySource(int $sourceId, string $sourceType = 'order'): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT grant_id FROM {$this->table} WHERE source_id = %d AND source_type = %s",
            $sourceId,
            $sourceType
        ), ARRAY_A);

        return array_column($rows ?: [], 'grant_id');
    }

    /**
     * Count sources for a grant.
     */
    public function countSourcesByGrant(int $grantId): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE grant_id = %d",
            $grantId
        ));
    }

    /**
     * Create the junction table. Can be called from migrations.
     */
    public static function createTable(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fchub_membership_grant_sources';
        $charset = $wpdb->get_charset_collate();

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'")) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            grant_id BIGINT UNSIGNED NOT NULL,
            source_type VARCHAR(30) NOT NULL,
            source_id BIGINT UNSIGNED NOT NULL,
            created_at TIMESTAMP NULL,
            PRIMARY KEY (id),
            UNIQUE KEY grant_source (grant_id, source_type, source_id),
            KEY source_lookup (source_type, source_id),
            KEY grant_id (grant_id)
        ) {$charset};");
    }

    /**
     * Migrate existing source_ids JSON data into the junction table.
     */
    public static function migrateFromJson(): void
    {
        global $wpdb;
        $grantsTable = $wpdb->prefix . 'fchub_membership_grants';
        $sourcesTable = $wpdb->prefix . 'fchub_membership_grant_sources';

        // Skip if junction table doesn't exist
        if (!$wpdb->get_var("SHOW TABLES LIKE '{$sourcesTable}'")) {
            return;
        }

        // Skip if already migrated (junction table has rows)
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$sourcesTable}");
        if ($count > 0) {
            return;
        }

        $grants = $wpdb->get_results(
            "SELECT id, source_type, source_id, source_ids FROM {$grantsTable}
             WHERE source_ids IS NOT NULL AND source_ids != '[]' AND source_ids != ''",
            ARRAY_A
        );

        if (empty($grants)) {
            return;
        }

        $now = current_time('mysql');
        $migrated = 0;

        foreach ($grants as $grant) {
            $sourceIds = json_decode($grant['source_ids'] ?? '[]', true) ?: [];
            $sourceType = $grant['source_type'] ?: 'order';
            $grantId = (int) $grant['id'];

            foreach ($sourceIds as $sourceId) {
                $sourceId = (int) $sourceId;
                if ($sourceId <= 0) {
                    continue;
                }

                $wpdb->insert($sourcesTable, [
                    'grant_id'    => $grantId,
                    'source_type' => $sourceType,
                    'source_id'   => $sourceId,
                    'created_at'  => $now,
                ]);
                $migrated++;
            }
        }

        if ($migrated > 0) {
            \FChubMemberships\Support\Logger::log(
                'Migration',
                sprintf('Migrated %d source_ids entries to junction table', $migrated)
            );
        }
    }
}

<?php

declare(strict_types=1);

namespace CartShift\Storage;

defined('ABSPATH') || exit;

final class IdMapRepository
{
    private readonly string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'cartshift_id_map';
    }

    /**
     * Store a WC-to-FC ID mapping.
     */
    public function store(
        string $entityType,
        string $wcId,
        int $fcId,
        string $migrationId = '',
        bool $createdByMigration = true,
    ): void {
        global $wpdb;

        $wpdb->insert(
            $this->table,
            [
                'entity_type'          => $entityType,
                'wc_id'                => $wcId,
                'fc_id'                => $fcId,
                'migration_id'         => $migrationId,
                'created_by_migration' => $createdByMigration ? 1 : 0,
                'created_at'           => gmdate('Y-m-d H:i:s'),
            ],
            ['%s', '%s', '%d', '%s', '%d', '%s'],
        );
    }

    /**
     * Get the FluentCart ID for a given WC entity (first match).
     */
    public function getFcId(string $entityType, string $wcId): int|null
    {
        global $wpdb;

        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT fc_id FROM {$this->table} WHERE entity_type = %s AND wc_id = %s LIMIT 1",
            $entityType,
            $wcId,
        ));

        return $result !== null ? (int) $result : null;
    }

    /**
     * Get all FC IDs for an entity type, optionally filtered by migration.
     *
     * @return array<object{wc_id: string, fc_id: int}>
     */
    public function getAllByEntityType(string $entityType, string|null $migrationId = null): array
    {
        global $wpdb;

        if ($migrationId !== null) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT wc_id, fc_id FROM {$this->table} WHERE entity_type = %s AND migration_id = %s",
                $entityType,
                $migrationId,
            ));
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT wc_id, fc_id FROM {$this->table} WHERE entity_type = %s",
            $entityType,
        ));
    }

    /**
     * Get only rows that were created by migration.
     *
     * @return array<object{wc_id: string, fc_id: int}>
     */
    public function getCreatedByMigration(string $entityType, string $migrationId): array
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT wc_id, fc_id FROM {$this->table}
             WHERE entity_type = %s AND migration_id = %s AND created_by_migration = 1",
            $entityType,
            $migrationId,
        ));
    }

    /**
     * Delete all rows for a migration ID.
     */
    public function deleteByMigration(string $migrationId): void
    {
        global $wpdb;

        $wpdb->delete(
            $this->table,
            ['migration_id' => $migrationId],
            ['%s'],
        );
    }

    /**
     * Delete only rows where created_by_migration = 1 for a migration ID.
     */
    public function deleteCreatedByMigration(string $migrationId): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table} WHERE migration_id = %s AND created_by_migration = 1",
            $migrationId,
        ));
    }

    /**
     * Truncate the entire table.
     */
    public function truncate(): void
    {
        global $wpdb;

        $wpdb->query("TRUNCATE TABLE {$this->table}");
    }
}

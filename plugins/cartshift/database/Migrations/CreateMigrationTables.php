<?php

namespace CartShift\Database;

defined('ABSPATH') or die;

class CreateMigrationTables
{
    /**
     * Create migration tables using dbDelta.
     */
    public function up(): void
    {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();

        $idMapTable = $wpdb->prefix . 'cartshift_id_map';
        $logTable   = $wpdb->prefix . 'cartshift_migration_log';

        $sql = "CREATE TABLE {$idMapTable} (
            id BIGINT UNSIGNED AUTO_INCREMENT,
            entity_type VARCHAR(50) NOT NULL,
            wc_id BIGINT UNSIGNED NOT NULL,
            fc_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_mapping (entity_type, wc_id),
            KEY idx_fc_id (entity_type, fc_id)
        ) {$charset};

        CREATE TABLE {$logTable} (
            id BIGINT UNSIGNED AUTO_INCREMENT,
            migration_id VARCHAR(36) NOT NULL,
            entity_type VARCHAR(50) NOT NULL,
            wc_id BIGINT UNSIGNED NULL,
            status VARCHAR(20) NOT NULL,
            message TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_migration (migration_id, entity_type)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Drop migration tables.
     */
    public function drop(): void
    {
        global $wpdb;

        $idMapTable = $wpdb->prefix . 'cartshift_id_map';
        $logTable   = $wpdb->prefix . 'cartshift_migration_log';

        $wpdb->query("DROP TABLE IF EXISTS {$idMapTable}");
        $wpdb->query("DROP TABLE IF EXISTS {$logTable}");
    }
}

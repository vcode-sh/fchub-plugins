<?php

declare(strict_types=1);

namespace CartShift\Support;

defined('ABSPATH') or die;

final class Migrations
{
    private const string DB_VERSION_OPTION = 'cartshift_db_version';
    private const string CURRENT_VERSION = '1';

    /** @var array<string, callable> */
    private const array VERSIONS = [
        '1' => 'v1',
    ];

    public static function run(): void
    {
        $installed = get_option(self::DB_VERSION_OPTION, '0');

        foreach (self::VERSIONS as $version => $method) {
            $version = (string) $version;
            if (version_compare($installed, $version, '>=')) {
                continue;
            }

            self::$method();
            update_option(self::DB_VERSION_OPTION, $version);
        }
    }

    public static function needsUpgrade(): bool
    {
        $installed = get_option(self::DB_VERSION_OPTION, '0');

        return version_compare($installed, self::CURRENT_VERSION, '<');
    }

    public static function dropAll(): void
    {
        global $wpdb;

        $idMapTable = $wpdb->prefix . 'cartshift_id_map';
        $logTable   = $wpdb->prefix . 'cartshift_migration_log';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query("DROP TABLE IF EXISTS {$idMapTable}");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query("DROP TABLE IF EXISTS {$logTable}");

        delete_option(self::DB_VERSION_OPTION);
        delete_option('cartshift_migration_state');
    }

    private static function v1(): void
    {
        global $wpdb;

        $charset    = $wpdb->get_charset_collate();
        $idMapTable = $wpdb->prefix . 'cartshift_id_map';
        $logTable   = $wpdb->prefix . 'cartshift_migration_log';

        $sql = "CREATE TABLE {$idMapTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            entity_type VARCHAR(50) NOT NULL,
            wc_id VARCHAR(100) NOT NULL,
            fc_id BIGINT UNSIGNED NOT NULL,
            migration_id VARCHAR(36) NOT NULL DEFAULT '',
            created_by_migration TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY entity_lookup (entity_type, wc_id),
            KEY migration_lookup (migration_id, entity_type)
        ) {$charset};

        CREATE TABLE {$logTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            migration_id VARCHAR(36) NOT NULL,
            entity_type VARCHAR(50) NOT NULL,
            wc_id VARCHAR(100) NOT NULL,
            status VARCHAR(20) NOT NULL,
            message TEXT,
            details LONGTEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY migration_entity (migration_id, entity_type),
            KEY status_lookup (migration_id, status)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
}

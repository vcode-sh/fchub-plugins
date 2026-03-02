<?php

namespace FChubMemberships\Support;

defined('ABSPATH') || exit;

class MigrationV2
{
    public static function run(): void
    {
        self::addPlanColumns();
        self::addGrantColumns();
        self::addDripNotificationColumns();
        self::createAuditLogTable();
    }

    private static function addPlanColumns(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fchub_membership_plans';

        $columns = [
            'duration_type'    => "VARCHAR(30) NOT NULL DEFAULT 'lifetime' AFTER redirect_url",
            'duration_days'    => "INT UNSIGNED DEFAULT NULL AFTER duration_type",
            'trial_days'       => "INT UNSIGNED NOT NULL DEFAULT 0 AFTER duration_days",
            'grace_period_days' => "INT UNSIGNED NOT NULL DEFAULT 0 AFTER trial_days",
        ];

        foreach ($columns as $column => $definition) {
            if (!self::columnExists($table, $column)) {
                $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
            }
        }
    }

    private static function addGrantColumns(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fchub_membership_grants';

        $columns = [
            'trial_ends_at'             => "DATETIME DEFAULT NULL AFTER drip_available_at",
            'cancellation_requested_at' => "DATETIME DEFAULT NULL AFTER meta",
            'cancellation_effective_at' => "DATETIME DEFAULT NULL AFTER cancellation_requested_at",
            'cancellation_reason'       => "VARCHAR(500) DEFAULT NULL AFTER cancellation_effective_at",
            'renewal_count'             => "INT UNSIGNED NOT NULL DEFAULT 0 AFTER cancellation_reason",
        ];

        foreach ($columns as $column => $definition) {
            if (!self::columnExists($table, $column)) {
                $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
            }
        }
    }

    private static function addDripNotificationColumns(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fchub_membership_drip_notifications';

        $columns = [
            'retry_count'  => "INT UNSIGNED NOT NULL DEFAULT 0 AFTER status",
            'next_retry_at' => "DATETIME DEFAULT NULL AFTER retry_count",
        ];

        foreach ($columns as $column => $definition) {
            if (!self::columnExists($table, $column)) {
                $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
            }
        }
    }

    private static function createAuditLogTable(): void
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix . 'fchub_membership_';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE {$prefix}audit_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            entity_type VARCHAR(30) NOT NULL,
            entity_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(30) NOT NULL,
            actor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            actor_type VARCHAR(20) NOT NULL DEFAULT 'system',
            old_value LONGTEXT NULL,
            new_value LONGTEXT NULL,
            context VARCHAR(255) NULL,
            created_at TIMESTAMP NULL,
            PRIMARY KEY (id),
            KEY entity_lookup (entity_type, entity_id),
            KEY actor_lookup (actor_id, actor_type),
            KEY created_at (created_at)
        ) {$charset};");
    }

    private static function columnExists(string $table, string $column): bool
    {
        global $wpdb;
        $result = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM {$table} LIKE %s",
            $column
        ));
        return !empty($result);
    }
}

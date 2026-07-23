<?php

namespace FChubMemberships\Support;

defined('ABSPATH') || exit;

final class MigrationV8
{
    /** @return list<string> */
    public static function run(): array
    {
        global $wpdb;

        $prefix = $wpdb->prefix . 'fchub_membership_';
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE {$prefix}webhook_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id CHAR(36) NOT NULL,
            event_type VARCHAR(64) NOT NULL,
            schema_version VARCHAR(10) NOT NULL DEFAULT '1.0',
            body LONGTEXT NOT NULL,
            occurred_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY event_id (event_id),
            KEY type_occurred (event_type, occurred_at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$prefix}webhook_deliveries (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id CHAR(36) NOT NULL,
            destination_url VARCHAR(2048) NOT NULL,
            destination_hash CHAR(64) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            lease_owner VARCHAR(64) NULL,
            lease_expires_at DATETIME NULL,
            response_code SMALLINT UNSIGNED NULL,
            response_body TEXT NULL,
            error_message TEXT NULL,
            next_attempt_at DATETIME NULL,
            last_attempt_at DATETIME NULL,
            delivered_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY event_destination (event_id, destination_hash),
            KEY status_next (status, next_attempt_at),
            KEY status_lease (status, lease_expires_at),
            KEY created_at (created_at)
        ) {$charset};");

        $credential = Migrations::migrateAccessApiCredential();
        if (($credential['success'] ?? false) !== true) {
            return ['settings:access_api_credential ' . (string) ($credential['reason'] ?? 'migration_failed')];
        }

        return [];
    }
}

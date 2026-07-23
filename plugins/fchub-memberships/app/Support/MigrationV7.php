<?php

namespace FChubMemberships\Support;

defined('ABSPATH') || exit;

final class MigrationV7
{
    /** @return list<string> */
    public static function run(): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'fchub_membership_mutation_requests';
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            request_key VARCHAR(191) NOT NULL,
            fingerprint CHAR(64) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            state VARCHAR(20) NOT NULL DEFAULT 'reserved',
            response_status SMALLINT UNSIGNED NULL,
            response_body LONGTEXT NULL,
            lease_token VARCHAR(64) NULL,
            lease_expires_at DATETIME NULL,
            attempt_count INT UNSIGNED NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            completed_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY request_key (request_key),
            KEY state_updated (state, updated_at),
            KEY state_lease (state, lease_expires_at),
            KEY retention_completed (completed_at, state, id)
        ) {$charset};");

        return [];
    }
}

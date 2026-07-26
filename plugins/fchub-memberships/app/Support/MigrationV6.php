<?php

declare(strict_types=1);

namespace FChubMemberships\Support;

defined('ABSPATH') || exit;

final class MigrationV6
{
    /** @return list<string> */
    public static function run(): array
    {
        global $wpdb;

        $table = \FChubMemberships\Support\CustomTableDatabase::identifier($wpdb->prefix . 'fchub_membership_crm_projection_jobs');
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE {$table} (
            user_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            request_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
            lease_owner VARCHAR(64) NULL,
            lease_expires_at DATETIME NULL,
            attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
            next_retry_at DATETIME NULL,
            last_error_code VARCHAR(64) NULL,
            last_attempt_at DATETIME NULL,
            last_success_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (user_id),
            KEY status_due (status, next_retry_at),
            KEY status_lease (status, lease_expires_at),
            KEY last_success (last_success_at)
        ) {$charset};");

        return [];
    }
}

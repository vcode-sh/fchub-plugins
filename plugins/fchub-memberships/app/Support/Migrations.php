<?php

namespace FChubMemberships\Support;

defined('ABSPATH') || exit;

class Migrations
{
    public static function run(): void
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix . 'fchub_membership_';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // 1. Plans
        dbDelta("CREATE TABLE {$prefix}plans (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(255) NOT NULL,
            slug VARCHAR(100) NOT NULL,
            description TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            level INT UNSIGNED NOT NULL DEFAULT 0,
            includes_plan_ids LONGTEXT NULL,
            restriction_message TEXT NULL,
            redirect_url VARCHAR(500) NULL,
            settings LONGTEXT NULL,
            meta LONGTEXT NULL,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY status (status),
            KEY level (level)
        ) {$charset};");

        // 2. Plan Rules
        dbDelta("CREATE TABLE {$prefix}plan_rules (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            plan_id BIGINT UNSIGNED NOT NULL,
            provider VARCHAR(50) NOT NULL DEFAULT 'wordpress_core',
            resource_type VARCHAR(50) NOT NULL,
            resource_id VARCHAR(100) NOT NULL,
            drip_delay_days INT UNSIGNED NOT NULL DEFAULT 0,
            drip_type VARCHAR(20) NOT NULL DEFAULT 'immediate',
            drip_date DATETIME NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            meta LONGTEXT NULL,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL,
            PRIMARY KEY (id),
            KEY plan_provider_type (plan_id, provider, resource_type),
            KEY plan_sort (plan_id, sort_order)
        ) {$charset};");

        // 3. Grants
        dbDelta("CREATE TABLE {$prefix}grants (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            plan_id BIGINT UNSIGNED NULL,
            provider VARCHAR(50) NOT NULL DEFAULT 'wordpress_core',
            resource_type VARCHAR(50) NOT NULL,
            resource_id VARCHAR(100) NOT NULL,
            source_type VARCHAR(30) NOT NULL DEFAULT 'order',
            source_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            feed_id BIGINT UNSIGNED NULL,
            grant_key VARCHAR(64) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            starts_at DATETIME NULL,
            expires_at DATETIME NULL,
            drip_available_at DATETIME NULL,
            source_ids LONGTEXT NULL,
            meta LONGTEXT NULL,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL,
            PRIMARY KEY (id),
            UNIQUE KEY grant_key (grant_key),
            KEY user_access (user_id, provider, resource_type, resource_id, status),
            KEY user_plan (user_id, plan_id, status),
            KEY source_lookup (source_type, source_id),
            KEY feed_id (feed_id),
            KEY status_expires (status, expires_at),
            KEY status_drip (status, drip_available_at)
        ) {$charset};");

        // 4. Event Locks (idempotency)
        dbDelta("CREATE TABLE {$prefix}event_locks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_hash VARCHAR(64) NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            subscription_id BIGINT UNSIGNED NULL,
            feed_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            trigger_name VARCHAR(100) NOT NULL DEFAULT '',
            processed_at DATETIME NOT NULL,
            result VARCHAR(20) NOT NULL DEFAULT 'success',
            error TEXT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY event_hash (event_hash)
        ) {$charset};");

        // 5. Protection Rules
        dbDelta("CREATE TABLE {$prefix}protection_rules (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            resource_type VARCHAR(50) NOT NULL,
            resource_id VARCHAR(100) NOT NULL,
            plan_ids LONGTEXT NULL,
            protection_mode VARCHAR(20) NOT NULL DEFAULT 'explicit',
            restriction_message TEXT NULL,
            redirect_url VARCHAR(500) NULL,
            show_teaser VARCHAR(5) NOT NULL DEFAULT 'no',
            meta LONGTEXT NULL,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL,
            PRIMARY KEY (id),
            KEY resource_lookup (resource_type, resource_id)
        ) {$charset};");

        // 6. Validity Log (subscription validity tracking)
        dbDelta("CREATE TABLE {$prefix}validity_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            subscription_id BIGINT UNSIGNED NOT NULL,
            last_valid_at DATETIME NOT NULL,
            expired_at DATETIME NULL,
            dispatched_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY subscription_id (subscription_id)
        ) {$charset};");

        // 7. Drip Notifications
        dbDelta("CREATE TABLE {$prefix}drip_notifications (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            grant_id BIGINT UNSIGNED NOT NULL,
            plan_rule_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            notify_at DATETIME NOT NULL,
            sent_at DATETIME NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            PRIMARY KEY (id),
            KEY pending_notify (status, notify_at),
            KEY grant_id (grant_id),
            KEY user_id (user_id)
        ) {$charset};");

        // 8. Daily Stats (aggregation for reports)
        dbDelta("CREATE TABLE {$prefix}stats_daily (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            stat_date DATE NOT NULL,
            plan_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            active_count INT UNSIGNED NOT NULL DEFAULT 0,
            new_count INT UNSIGNED NOT NULL DEFAULT 0,
            churned_count INT UNSIGNED NOT NULL DEFAULT 0,
            revenue BIGINT NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY date_plan (stat_date, plan_id)
        ) {$charset};");

        // V2: add new columns and audit_log table
        MigrationV2::run();

        // V3: fix subscription source_type, add FK constraints and indexes
        MigrationV3::run();
    }

    /**
     * Drop all plugin tables. Only called if user opts in via settings.
     */
    public static function dropAll(): void
    {
        global $wpdb;
        $prefix = $wpdb->prefix . 'fchub_membership_';

        $tables = [
            'grant_sources',
            'audit_log',
            'stats_daily',
            'drip_notifications',
            'validity_log',
            'protection_rules',
            'event_locks',
            'grants',
            'plan_rules',
            'plans',
        ];

        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$prefix}{$table}");
        }

        delete_option('fchub_memberships_db_version');
        delete_option('fchub_memberships_settings');
    }
}

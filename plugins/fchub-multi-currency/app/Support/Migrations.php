<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Support;

defined('ABSPATH') || exit;

class Migrations
{
    public static function run(): void
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix . 'fchub_mc_';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE {$prefix}rate_history (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            base_currency  CHAR(3)         NOT NULL,
            quote_currency CHAR(3)         NOT NULL,
            rate           DECIMAL(18,8)   NOT NULL,
            provider       VARCHAR(64)     NOT NULL DEFAULT 'manual',
            fetched_at     DATETIME        NOT NULL,
            PRIMARY KEY (id),
            KEY base_quote_fetched (base_currency, quote_currency, fetched_at),
            KEY fetched_at (fetched_at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$prefix}event_log (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event      VARCHAR(128)    NOT NULL,
            user_id    BIGINT UNSIGNED NULL,
            ip_hash    VARCHAR(64)     NULL,
            payload    LONGTEXT        NULL,
            created_at DATETIME        NOT NULL,
            PRIMARY KEY (id),
            KEY event_created (event, created_at),
            KEY user_id (user_id)
        ) {$charset};");
    }

    public static function dropAll(): void
    {
        global $wpdb;
        $prefix = $wpdb->prefix . 'fchub_mc_';

        $tables = [
            $prefix . 'event_log',
            $prefix . 'rate_history',
        ];

        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
        }

        delete_option(Constants::OPTION_DB_VERSION);
        delete_option(Constants::OPTION_SETTINGS);
        delete_option(Constants::OPTION_FEATURE_FLAGS);
    }
}

<?php

declare(strict_types=1);

namespace FChubWishlist\Support;

defined('ABSPATH') || exit;

class Migrations
{
    public static function run(): void
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix . 'fchub_wishlist_';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE {$prefix}lists (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NULL,
            customer_id BIGINT UNSIGNED NULL,
            session_hash VARCHAR(64) NULL,
            title VARCHAR(192) NOT NULL DEFAULT 'Wishlist',
            item_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY user_id (user_id),
            KEY session_hash (session_hash),
            KEY customer_id (customer_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$prefix}items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wishlist_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            variant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            price_at_addition DOUBLE NOT NULL DEFAULT 0,
            note TEXT NULL,
            created_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY wishlist_id (wishlist_id),
            UNIQUE KEY wishlist_product_variant (wishlist_id, product_id, variant_id),
            KEY product_id (product_id)
        ) {$charset};");
    }

    /**
     * Drop all plugin tables. Only called if user opts in via settings.
     */
    public static function dropAll(): void
    {
        global $wpdb;
        $prefix = $wpdb->prefix . 'fchub_wishlist_';

        $tables = [
            $prefix . 'items',
            $prefix . 'lists',
        ];

        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
        }

        delete_option(Constants::OPTION_DB_VERSION);
        delete_option(Constants::OPTION_SETTINGS);
    }
}

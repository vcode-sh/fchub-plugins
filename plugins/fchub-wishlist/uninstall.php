<?php

// If uninstall not called from WordPress, die.
defined('WP_UNINSTALL_PLUGIN') || exit;

$fchub_wishlist_settings = get_option('fchub_wishlist_settings', []);
$fchub_wishlist_remove = isset($fchub_wishlist_settings['uninstall_remove_data']) && $fchub_wishlist_settings['uninstall_remove_data'] === 'yes';

if ($fchub_wishlist_remove) {
    global $wpdb;

    $fchub_wishlist_prefix = $wpdb->prefix . 'fchub_wishlist_';

    // Drop all custom tables
    $fchub_wishlist_tables = [
        $fchub_wishlist_prefix . 'items',
        $fchub_wishlist_prefix . 'lists',
    ];

    foreach ($fchub_wishlist_tables as $fchub_wishlist_table) {
        $wpdb->query("DROP TABLE IF EXISTS {$fchub_wishlist_table}");
    }

    // Delete options
    delete_option('fchub_wishlist_settings');
    delete_option('fchub_wishlist_db_version');

    // Clean up scheduled hooks
    wp_clear_scheduled_hook('fchub_wishlist_cleanup_guests');
    wp_clear_scheduled_hook('fchub_wishlist_cleanup_orphans');
    wp_clear_scheduled_hook('fchub_wishlist_reminder');
}

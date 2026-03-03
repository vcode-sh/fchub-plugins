<?php
// If uninstall not called from WordPress, die.
defined('WP_UNINSTALL_PLUGIN') || exit;

$settings = get_option('fchub_wishlist_settings', []);
$removeData = isset($settings['uninstall_remove_data']) && $settings['uninstall_remove_data'] === 'yes';

if ($removeData) {
    global $wpdb;

    $prefix = $wpdb->prefix . 'fchub_wishlist_';

    // Drop all custom tables
    $tables = [
        $prefix . 'items',
        $prefix . 'lists',
    ];

    foreach ($tables as $table) {
        $wpdb->query("DROP TABLE IF EXISTS {$table}");
    }

    // Delete options
    delete_option('fchub_wishlist_settings');
    delete_option('fchub_wishlist_db_version');

    // Clean up scheduled hooks
    wp_clear_scheduled_hook('fchub_wishlist_cleanup_guests');
    wp_clear_scheduled_hook('fchub_wishlist_cleanup_orphans');
    wp_clear_scheduled_hook('fchub_wishlist_reminder');
}

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
    delete_option('fchub_wishlist_feature_flags');

    // Remove reminder marker user meta from all users.
    if (isset($wpdb->usermeta)) {
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s",
            '_fchub_wishlist_last_reminder'
        ));
    }

    // Clean up Action Scheduler hooks.
    if (function_exists('as_unschedule_all_actions')) {
        as_unschedule_all_actions('fchub_wishlist_cleanup_guests', [], 'fchub-wishlist');
        as_unschedule_all_actions('fchub_wishlist_cleanup_orphans', [], 'fchub-wishlist');
        as_unschedule_all_actions('fchub_wishlist_reminder', [], 'fchub-wishlist');
        as_unschedule_all_actions('fchub_wishlist_send_email', [], 'fchub-wishlist');
    }

    // Clean up scheduled hooks
    wp_clear_scheduled_hook('fchub_wishlist_cleanup_guests');
    wp_clear_scheduled_hook('fchub_wishlist_cleanup_orphans');
    wp_clear_scheduled_hook('fchub_wishlist_reminder');
}

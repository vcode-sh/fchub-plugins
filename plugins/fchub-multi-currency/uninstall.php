<?php

// If uninstall not called from WordPress, die.
defined('WP_UNINSTALL_PLUGIN') || exit;

$fchub_mc_settings = get_option('fchub_mc_settings', []);
$fchub_mc_remove = isset($fchub_mc_settings['uninstall_remove_data']) && $fchub_mc_settings['uninstall_remove_data'] === 'yes';

if ($fchub_mc_remove) {
    global $wpdb;

    $fchub_mc_prefix = $wpdb->prefix . 'fchub_mc_';

    // Drop all custom tables
    $fchub_mc_tables = [
        $fchub_mc_prefix . 'event_log',
        $fchub_mc_prefix . 'rate_history',
    ];

    foreach ($fchub_mc_tables as $fchub_mc_table) {
        $wpdb->query("DROP TABLE IF EXISTS {$fchub_mc_table}");
    }

    // Delete options
    delete_option('fchub_mc_settings');
    delete_option('fchub_mc_db_version');
    delete_option('fchub_mc_feature_flags');

    // Delete user meta
    $wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key = '_fchub_mc_currency'");
    $wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key = '_fcom_preferred_currency'");

    // Clean up scheduled hooks
    wp_clear_scheduled_hook('fchub_mc_refresh_rates');
}

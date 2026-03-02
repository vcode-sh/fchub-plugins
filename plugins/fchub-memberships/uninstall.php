<?php
// If uninstall not called from WordPress, die.
defined('WP_UNINSTALL_PLUGIN') || exit;

$settings = get_option('fchub_memberships_settings', []);
$removeData = isset($settings['uninstall_remove_data']) && $settings['uninstall_remove_data'] === 'yes';

if ($removeData) {
    global $wpdb;

    $prefix = $wpdb->prefix . 'fchub_membership_';

    // Drop all custom tables (order matters for FK constraints)
    $tables = [
        $prefix . 'grant_sources',
        $prefix . 'audit_log',
        $prefix . 'stats_daily',
        $prefix . 'drip_notifications',
        $prefix . 'validity_log',
        $prefix . 'protection_rules',
        $prefix . 'event_locks',
        $prefix . 'grants',
        $prefix . 'plan_rules',
        $prefix . 'plans',
    ];

    foreach ($tables as $table) {
        $wpdb->query("DROP TABLE IF EXISTS {$table}");
    }

    // Delete options
    delete_option('fchub_memberships_settings');
    delete_option('fchub_memberships_db_version');

    // Clean up scheduled hooks
    wp_clear_scheduled_hook('fchub_memberships_validity_check');
    wp_clear_scheduled_hook('fchub_memberships_drip_process');
    wp_clear_scheduled_hook('fchub_memberships_expiry_notify');
    wp_clear_scheduled_hook('fchub_memberships_daily_stats');
    wp_clear_scheduled_hook('fchub_memberships_audit_cleanup');
    wp_clear_scheduled_hook('fchub_memberships_trial_check');
    wp_clear_scheduled_hook('fchub_memberships_plan_schedule');
}

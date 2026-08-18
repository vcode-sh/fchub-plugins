<?php

// If uninstall not called from WordPress, die.
defined('WP_UNINSTALL_PLUGIN') || exit;

$fchub_mc_settings = get_option('fchub_mc_settings', []);
$fchub_mc_remove = isset($fchub_mc_settings['uninstall_remove_data']) && $fchub_mc_settings['uninstall_remove_data'] === 'yes';

if (!$fchub_mc_remove) {
    return;
}

/**
 * Clean up all plugin data for the current blog.
 */
if (!function_exists('fchub_mc_cleanup_blog')) {
    function fchub_mc_cleanup_blog(): void
    {
        global $wpdb;

        $fchub_mc_prefix = $wpdb->prefix . 'fchub_mc_';

        // Drop all custom tables
        $fchub_mc_tables = [
            $fchub_mc_prefix . 'event_log',
            $fchub_mc_prefix . 'rate_history',
        ];

        foreach ($fchub_mc_tables as $fchub_mc_table) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Confirmed uninstall erasure targets only prepared identifiers for plugin-owned tables.
            $wpdb->query($wpdb->prepare("DROP TABLE IF EXISTS %i", $fchub_mc_table));
        }

        // Delete options
        delete_option('fchub_mc_settings');
        delete_option('fchub_mc_db_version');
        delete_option('fchub_mc_feature_flags');
        delete_option('fchub_mc_rate_refresh_lock');

        // Delete rate limiter transients (60-second TTL — self-expire on object-cache backends,
        // SQL cleanup only reaches wp_options-based transient storage)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- WordPress has no wildcard transient-deletion API; both escaped prefixes are plugin-owned and self-expiring.
        $wpdb->query($wpdb->prepare(
            "DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s",
            $wpdb->options,
            $wpdb->esc_like('_transient_fchub_mc_rl_') . '%',
            $wpdb->esc_like('_transient_timeout_fchub_mc_rl_') . '%',
        ));

        // Delete diagnostics transient
        delete_transient('fchub_mc_has_stale_rates');

        // Flush object cache group (Redis/Memcached) — wp_cache_flush_group may not exist
        // on hosts without object-cache backends that support group flushing
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group('fchub_mc_rates');
        }

        // Delete user meta
        delete_metadata('user', 0, '_fchub_mc_currency', '', true);
        delete_metadata('user', 0, '_fcom_preferred_currency', '', true);

        // Clean up scheduled hooks
        wp_clear_scheduled_hook('fchub_mc_refresh_rates');
        wp_clear_scheduled_hook('fchub_mc_daily_maintenance');
    }
}

if (is_multisite()) {
    $fchub_mc_sites = get_sites(['fields' => 'ids']);
    foreach ($fchub_mc_sites as $fchub_mc_blog_id) {
        switch_to_blog($fchub_mc_blog_id);
        fchub_mc_cleanup_blog();
        restore_current_blog();
    }
} else {
    fchub_mc_cleanup_blog();
}

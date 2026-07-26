<?php

// If uninstall not called from WordPress, die.
defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

// Clean up scheduled cron events
wp_clear_scheduled_hook('fchub_fakturownia_check_ksef_status');

// Clean up transients
delete_transient('fchub_github_releases');
delete_transient('fchub_github_rate_limited');

// Delete integration settings from FluentCart's meta table (not wp_options)
$fchub_fakturownia_meta_table = $wpdb->prefix . 'fct_meta';
if (
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall must inspect FluentCart's custom table.
    $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $fchub_fakturownia_meta_table))
    === $fchub_fakturownia_meta_table
) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Uninstall removes the exact plugin-owned setting.
    $wpdb->delete($fchub_fakturownia_meta_table, [
        'meta_key'    => '_integration_api_fakturownia', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Exact plugin-owned setting.
        'object_type' => 'option',
    ]);
}

// Clean up order meta
$fchub_fakturownia_order_meta_table = $wpdb->prefix . 'fct_order_meta';
if (
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall must inspect FluentCart's custom table.
    $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $fchub_fakturownia_order_meta_table))
    === $fchub_fakturownia_order_meta_table
) {
    $fchub_fakturownia_meta_keys = [
        '_fakturownia_invoice_id',
        '_fakturownia_invoice_number',
        '_fakturownia_invoice_url',
        '_fakturownia_client_id',
        '_fakturownia_ksef_status',
        '_fakturownia_ksef_id',
        '_fakturownia_ksef_link',
        '_fakturownia_ksef_retry_count',
        '_fakturownia_correction_id',
        '_fakturownia_correction_number',
        '_fakturownia_correction_ksef_status',
        '_fakturownia_correction_ksef_id',
        '_fakturownia_correction_ksef_link',
        '_fakturownia_correction_ksef_retry_count',
    ];

    foreach ($fchub_fakturownia_meta_keys as $fchub_fakturownia_key) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Uninstall removes exact plugin-owned keys.
        $wpdb->delete(
            $fchub_fakturownia_order_meta_table,
            ['meta_key' => $fchub_fakturownia_key] // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Exact plugin-owned key.
        );
    }
}

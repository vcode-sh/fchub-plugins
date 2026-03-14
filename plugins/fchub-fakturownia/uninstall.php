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
$meta_table = $wpdb->prefix . 'fct_meta';
if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $meta_table)) === $meta_table) {
    $wpdb->delete($meta_table, [
        'meta_key'    => '_integration_api_fakturownia',
        'object_type' => 'option',
    ]);
}

// Clean up order meta
$order_meta_table = $wpdb->prefix . 'fct_order_meta';
if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $order_meta_table)) === $order_meta_table) {
    $meta_keys = [
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

    foreach ($meta_keys as $key) {
        $wpdb->delete($order_meta_table, ['meta_key' => $key]);
    }
}

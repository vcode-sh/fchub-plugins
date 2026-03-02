<?php
// If uninstall not called from WordPress, die.
defined('WP_UNINSTALL_PLUGIN') || exit;

// Delete plugin options
delete_option('_integration_api_fakturownia');

// Clean up order meta (stored in FluentCart's order_meta or similar)
// These are stored via FluentCart's order system, clean up what we can
global $wpdb;
$meta_keys = [
    '_fakturownia_invoice_id',
    '_fakturownia_invoice_number',
    '_fakturownia_invoice_url',
    '_fakturownia_client_id',
    '_fakturownia_ksef_status',
    '_fakturownia_ksef_id',
    '_fakturownia_ksef_link',
    '_fakturownia_correction_id',
    '_fakturownia_correction_number',
    '_fakturownia_correction_ksef_status',
];

$table = $wpdb->prefix . 'fct_order_meta';
if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
    foreach ($meta_keys as $key) {
        $wpdb->delete($table, ['meta_key' => $key]);
    }
}

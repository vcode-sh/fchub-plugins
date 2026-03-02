<?php
/**
 * FCHub - Przelewy24 Uninstall
 *
 * Fired when the plugin is uninstalled.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

// Delete gateway settings from FluentCart's fct_meta table
global $wpdb;
$meta_table = $wpdb->prefix . 'fct_meta';
if ($wpdb->get_var("SHOW TABLES LIKE '{$meta_table}'") === $meta_table) {
    $wpdb->delete($meta_table, [
        'meta_key'    => 'fluent_cart_payment_settings_przelewy24',
        'object_type' => 'option',
    ]);
}

// Delete cached payment methods transients
delete_transient('fchub_p24_methods_pl_test');
delete_transient('fchub_p24_methods_en_test');
delete_transient('fchub_p24_methods_pl_live');
delete_transient('fchub_p24_methods_en_live');

// Clean up Action Scheduler entries
if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('fchub_p24_process_renewal');
}

// Clean up order meta
$meta_table = $wpdb->prefix . 'fct_order_meta';
if ($wpdb->get_var("SHOW TABLES LIKE '{$meta_table}'") === $meta_table) {
    $wpdb->query(
        "DELETE FROM `{$meta_table}` WHERE `meta_key` LIKE '_p24\_%' OR `meta_key` = 'p24_session_id'"
    );
}

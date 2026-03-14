<?php
// If uninstall not called from WordPress, die.
defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

// Drop custom tables
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}cartshift_id_map");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}cartshift_migration_log");

// Delete options
delete_option('cartshift_migration_state');

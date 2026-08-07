<?php

/**
 * Uninstall routine.
 *
 * Destructive and therefore opt-in. CartShift ships no UI toggle for this on purpose:
 * the plugin's whole job is to move data you cannot afford to lose, and a "delete
 * everything" checkbox two clicks from the migration button is an accident waiting to
 * be filed as a support ticket. If you want the tables gone on uninstall, say so
 * deliberately, from somewhere you control:
 *
 *     // wp-cli
 *     wp option update cartshift_delete_data_on_uninstall yes
 *
 *     // or in a must-use plugin / theme functions.php
 *     add_filter('cartshift/delete_data_on_uninstall', '__return_true');
 *
 * Leaving it alone keeps {prefix}cartshift_id_map and {prefix}cartshift_migration_log
 * behind after deletion — the ID map is the only record of which WooCommerce row
 * became which FluentCart row, which is exactly what you want when something surfaces
 * three weeks later. Drop them by hand once you are sure.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

$cartshiftDeleteData = get_option('cartshift_delete_data_on_uninstall') === 'yes';

/**
 * Filter whether uninstalling CartShift drops its database tables and options.
 *
 * @param bool $delete Defaults to the value of the cartshift_delete_data_on_uninstall option.
 */
$cartshiftDeleteData = (bool) apply_filters('cartshift/delete_data_on_uninstall', $cartshiftDeleteData);

if (!$cartshiftDeleteData) {
    return;
}

require_once __DIR__ . '/app/Support/Migrations.php';

// dropAll() is the single source of truth for teardown — every table any schema
// version has ever created must be dropped there, not here.
CartShift\Support\Migrations::dropAll();

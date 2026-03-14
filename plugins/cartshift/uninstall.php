<?php

defined('WP_UNINSTALL_PLUGIN') || exit;

// Opt-in: only drop data if the user explicitly chose to.
if (get_option('cartshift_delete_data_on_uninstall') !== 'yes') {
    return;
}

require_once __DIR__ . '/app/Support/Migrations.php';

CartShift\Support\Migrations::dropAll();

<?php

/**
 * FCHub - Przelewy24 Uninstall
 *
 * Fired when the plugin is uninstalled.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

// Delete gateway settings through FluentCart's data layer.
if (class_exists(\FluentCart\App\Models\Meta::class)) {
    \FluentCart\App\Models\Meta::query()
        ->where('meta_key', 'fluent_cart_payment_settings_przelewy24')
        ->where('object_type', 'option')
        ->delete();
}

// Delete cached payment methods transients.
delete_transient('fchub_p24_methods_pl_test');
delete_transient('fchub_p24_methods_en_test');
delete_transient('fchub_p24_methods_pl_live');
delete_transient('fchub_p24_methods_en_live');

// Clean up Action Scheduler entries.
if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('fchub_p24_process_renewal', [], 'fchub-p24');
}

// Delete plugin-owned order metadata through FluentCart's data layer.
if (class_exists(\FluentCart\App\Models\OrderMeta::class)) {
    \FluentCart\App\Models\OrderMeta::query()
        ->where(function ($query) {
            $query
                ->where('meta_key', 'like', '_p24_%')
                ->orWhere('meta_key', 'p24_session_id');
        })
        ->delete();
}

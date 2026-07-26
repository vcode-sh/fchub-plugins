<?php
// If uninstall not called from WordPress, die.
defined('WP_UNINSTALL_PLUGIN') || exit;

(static function (): void {
    $settings = get_option('fchub_memberships_settings', []);
    $removeData = isset($settings['uninstall_remove_data']) && $settings['uninstall_remove_data'] === 'yes';

    if (!$removeData) {
        return;
    }

    require_once __DIR__ . '/app/Support/Migrations.php';
    \FChubMemberships\Support\Migrations::dropAll();

    $recurringHooks = [
        'fchub_memberships_validity_check',
        'fchub_memberships_drip_process',
        'fchub_memberships_expiry_notify',
        'fchub_memberships_daily_stats',
        'fchub_memberships_audit_cleanup',
        'fchub_memberships_trial_check',
        'fchub_memberships_plan_schedule',
        'fchub_memberships_webhook_reconcile',
        'fchub_memberships_webhook_cleanup',
    ];
    $queuedHooks = [
        'fchub_memberships_process_provider_operation',
        'fchub_memberships_process_crm_projection',
        'fchub_memberships_deliver_webhook',
        'fchub_memberships_send_email',
    ];

    if (function_exists('as_unschedule_all_actions')) {
        foreach ($queuedHooks as $hook) {
            as_unschedule_all_actions($hook);
        }
    }

    if (function_exists('_get_cron_array') && function_exists('wp_unschedule_event')) {
        $queuedHookLookup = array_fill_keys($queuedHooks, true);
        foreach ((array) _get_cron_array() as $timestamp => $events) {
            foreach ((array) $events as $hook => $instances) {
                if (!isset($queuedHookLookup[$hook])) {
                    continue;
                }

                foreach ((array) $instances as $event) {
                    wp_unschedule_event(
                        (int) $timestamp,
                        $hook,
                        (array) ($event['args'] ?? [])
                    );
                }
            }
        }
    }

    foreach (array_merge($recurringHooks, $queuedHooks) as $hook) {
        wp_clear_scheduled_hook($hook);
    }

    delete_option('fchub_memberships_feature_flags');
    delete_option('fchub_memberships_fluentcrm_reconciliation_health');
    delete_transient('fchub_memberships_plan_hierarchy');

    $administrator = get_role('administrator');
    if ($administrator) {
        $administrator->remove_cap('manage_fchub_memberships');
    }
})();

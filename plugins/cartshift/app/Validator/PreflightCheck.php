<?php

namespace CartShift\Validator;

defined('ABSPATH') or die;

class PreflightCheck
{
    /**
     * Run all preflight checks and return structured results.
     *
     * @return array{checks: array, ready: bool}
     */
    public function run(): array
    {
        $checks = [];

        $checks['woocommerce'] = $this->checkWooCommerce();
        $checks['fluentcart']  = $this->checkFluentCart();
        $checks['wc_subscriptions'] = $this->checkWcSubscriptions();
        $checks['php_memory']  = $this->checkPhpMemory();
        $checks['fc_data']     = $this->checkExistingFcData();

        $ready = $checks['woocommerce']['pass'] && $checks['fluentcart']['pass'];

        return [
            'checks' => $checks,
            'ready'  => $ready,
        ];
    }

    private function checkWooCommerce(): array
    {
        $active = class_exists('WooCommerce');
        $version = $active && defined('WC_VERSION') ? WC_VERSION : null;

        return [
            'label'   => 'WooCommerce',
            'pass'    => $active,
            'version' => $version,
            'message' => $active
                ? sprintf('WooCommerce %s is active.', $version)
                : 'WooCommerce is not active. Please activate it before migrating.',
        ];
    }

    private function checkFluentCart(): array
    {
        $active = defined('FLUENTCART_PLUGIN_PATH');
        $version = defined('FLUENTCART_VERSION') ? FLUENTCART_VERSION : null;

        return [
            'label'   => 'FluentCart',
            'pass'    => $active,
            'version' => $version,
            'message' => $active
                ? sprintf('FluentCart %s is active.', $version)
                : 'FluentCart is not active. Please activate it before migrating.',
        ];
    }

    private function checkWcSubscriptions(): array
    {
        $active = class_exists('WC_Subscriptions');
        $version = $active && defined('WCS_VERSION') ? WCS_VERSION : null;

        return [
            'label'    => 'WooCommerce Subscriptions',
            'pass'     => true, // Not required, always passes
            'optional' => true,
            'active'   => $active,
            'version'  => $version,
            'message'  => $active
                ? sprintf('WC Subscriptions %s detected. Subscription migration will be available.', $version)
                : 'WC Subscriptions not detected. Subscription migration will be skipped.',
        ];
    }

    private function checkPhpMemory(): array
    {
        $limit = ini_get('memory_limit');
        $bytes = wp_convert_hr_to_bytes($limit);
        // -1 means unlimited
        $adequate = ($bytes === -1) || ($bytes >= 256 * 1024 * 1024);

        return [
            'label'   => 'PHP Memory',
            'pass'    => $adequate,
            'value'   => $limit,
            'message' => $adequate
                ? sprintf('PHP memory limit is %s (recommended: 256M+).', $limit)
                : sprintf('PHP memory limit is %s. Consider increasing to at least 256M for large migrations.', $limit),
        ];
    }

    private function checkExistingFcData(): array
    {
        global $wpdb;

        $counts = [];
        $tables = [
            'products'      => $wpdb->posts,
            'customers'     => $wpdb->prefix . 'fct_customers',
            'orders'        => $wpdb->prefix . 'fct_orders',
            'subscriptions' => $wpdb->prefix . 'fct_subscriptions',
            'coupons'       => $wpdb->prefix . 'fct_coupons',
        ];

        foreach ($tables as $key => $table) {
            if ($key === 'products') {
                $counts[$key] = (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$table} WHERE post_type = 'fluent-products' AND post_status != 'auto-draft'"
                );
            } else {
                $tableExists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
                $counts[$key] = $tableExists ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}") : 0;
            }
        }

        $hasData = array_sum($counts) > 0;

        return [
            'label'   => 'Existing FluentCart Data',
            'pass'    => true,
            'warning' => $hasData,
            'counts'  => $counts,
            'message' => $hasData
                ? 'FluentCart already contains data. Migration will add new records alongside existing ones.'
                : 'FluentCart database is empty. Ready for clean migration.',
        ];
    }
}

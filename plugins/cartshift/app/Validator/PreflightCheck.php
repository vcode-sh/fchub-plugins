<?php

declare(strict_types=1);

namespace CartShift\Validator;

defined('ABSPATH') || exit;

final class PreflightCheck
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
        $checks['max_execution_time'] = $this->checkMaxExecutionTime();
        $checks['product_types'] = $this->checkProductTypes();
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

    /**
     * F5: Check max_execution_time — warn if too low, note that batched migration handles this.
     */
    private function checkMaxExecutionTime(): array
    {
        $maxTime = (int) ini_get('max_execution_time');
        // 0 means unlimited
        $adequate = ($maxTime === 0) || ($maxTime >= 300);

        $message = match (true) {
            $maxTime === 0 => 'max_execution_time is unlimited.',
            $adequate      => sprintf('max_execution_time is %ds (adequate).', $maxTime),
            default        => sprintf(
                'max_execution_time is %ds (recommended: 300s+). Batched migration mitigates this, but consider increasing for safety.',
                $maxTime,
            ),
        };

        return [
            'label'    => 'Max Execution Time',
            'pass'     => true, // Never blocks — batched migration handles this.
            'warning'  => !$adequate,
            'optional' => true,
            'value'    => $maxTime,
            'message'  => $message,
        ];
    }

    /**
     * F4: Product type breakdown — report counts per WC product type and warn about unsupported types.
     */
    private function checkProductTypes(): array
    {
        if (!class_exists('WooCommerce')) {
            return [
                'label'    => 'Product Types',
                'pass'     => true,
                'optional' => true,
                'types'    => [],
                'message'  => 'WooCommerce not active. Skipping product type check.',
            ];
        }

        global $wpdb;

        $results = $wpdb->get_results(
            "SELECT t.slug, COUNT(*) as count
             FROM {$wpdb->term_relationships} tr
             INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
             INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
             INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
             WHERE tt.taxonomy = 'product_type'
               AND p.post_type = 'product'
               AND p.post_status IN ('publish', 'draft', 'private')
             GROUP BY t.slug
             ORDER BY count DESC",
        );

        $types = [];
        foreach ($results as $row) {
            $types[$row->slug] = (int) $row->count;
        }

        $supported = ['simple', 'variable', 'subscription', 'variable-subscription'];
        $unsupported = array_diff(array_keys($types), $supported);
        $unsupportedCount = 0;
        foreach ($unsupported as $type) {
            $unsupportedCount += $types[$type];
        }

        $hasWarning = $unsupportedCount > 0;

        $parts = [];
        foreach ($types as $slug => $count) {
            $label = ucfirst(str_replace('-', ' ', $slug));
            $marker = in_array($slug, $supported, true) ? '' : ' (unsupported)';
            $parts[] = sprintf('%s: %d%s', $label, $count, $marker);
        }

        $message = empty($parts)
            ? 'No WooCommerce products found.'
            : implode(', ', $parts) . '.';

        if ($hasWarning) {
            $message .= sprintf(' %d product(s) with unsupported types will be skipped.', $unsupportedCount);
        }

        return [
            'label'    => 'Product Types',
            'pass'     => true, // Never blocks migration.
            'warning'  => $hasWarning,
            'optional' => true,
            'types'    => $types,
            'unsupported' => array_values($unsupported),
            'message'  => $message,
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
                    "SELECT COUNT(*) FROM {$table} WHERE post_type = 'fluent-products' AND post_status != 'auto-draft'",
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

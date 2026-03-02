<?php

namespace WcFc\Admin;

defined('ABSPATH') or die;

use WcFc\Validator\PreflightCheck;
use WcFc\State\MigrationState;
use WcFc\State\IdMap;
use WcFc\Migrator\ProductMigrator;
use WcFc\Migrator\CustomerMigrator;
use WcFc\Migrator\CouponMigrator;
use WcFc\Migrator\OrderMigrator;
use WcFc\Migrator\SubscriptionMigrator;

class AdminController
{
    const NAMESPACE = 'wc-fc/v1';

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/preflight', [
            'methods'             => 'GET',
            'callback'            => [$this, 'preflight'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route(self::NAMESPACE, '/counts', [
            'methods'             => 'GET',
            'callback'            => [$this, 'counts'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route(self::NAMESPACE, '/migrate', [
            'methods'             => 'POST',
            'callback'            => [$this, 'migrate'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route(self::NAMESPACE, '/progress', [
            'methods'             => 'GET',
            'callback'            => [$this, 'progress'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route(self::NAMESPACE, '/cancel', [
            'methods'             => 'POST',
            'callback'            => [$this, 'cancel'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route(self::NAMESPACE, '/rollback', [
            'methods'             => 'POST',
            'callback'            => [$this, 'rollback'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route(self::NAMESPACE, '/log', [
            'methods'             => 'GET',
            'callback'            => [$this, 'log'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);
    }

    public function checkPermission(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * GET /preflight - Run pre-migration checks.
     */
    public function preflight(\WP_REST_Request $request): \WP_REST_Response
    {
        $check = new PreflightCheck();
        return new \WP_REST_Response($check->run(), 200);
    }

    /**
     * GET /counts - Return WooCommerce entity counts.
     */
    public function counts(\WP_REST_Request $request): \WP_REST_Response
    {
        $counts = [
            'products'      => 0,
            'categories'    => 0,
            'customers'     => 0,
            'orders'        => 0,
            'subscriptions' => 0,
            'coupons'       => 0,
        ];

        if (function_exists('wc_get_products')) {
            $counts['products'] = count(wc_get_products([
                'limit'  => -1,
                'return' => 'ids',
                'type'   => ['simple', 'variable'],
                'status' => ['publish', 'draft', 'private'],
            ]));

            $wcCats = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
            if (!is_wp_error($wcCats)) {
                // Exclude "Uncategorized".
                $counts['categories'] = count(array_filter($wcCats, function ($t) {
                    return $t->slug !== 'uncategorized';
                }));
            }
        }

        $counts['customers'] = $this->countCustomers();

        if (function_exists('wc_get_orders')) {
            $counts['orders'] = count(wc_get_orders([
                'limit'  => -1,
                'return' => 'ids',
                'status' => 'any',
                'type'   => 'shop_order',
            ]));
        }

        if (function_exists('wcs_get_subscriptions')) {
            $subs = wcs_get_subscriptions([
                'subscriptions_per_page' => -1,
            ]);
            $counts['subscriptions'] = count($subs);
        }

        $counts['coupons'] = (int) wp_count_posts('shop_coupon')->publish
                           + (int) wp_count_posts('shop_coupon')->draft;

        return new \WP_REST_Response($counts, 200);
    }

    /**
     * POST /migrate - Start migration.
     */
    public function migrate(\WP_REST_Request $request): \WP_REST_Response
    {
        $entityTypes = $request->get_param('entity_types');
        if (empty($entityTypes) || !is_array($entityTypes)) {
            return new \WP_REST_Response([
                'error' => 'entity_types parameter is required (array of: products, customers, coupons, orders, subscriptions)',
            ], 400);
        }

        $dryRun = (bool) $request->get_param('dry_run');

        $state = new MigrationState();
        $current = $state->getCurrent();

        if ($current && $current['status'] === 'running') {
            return new \WP_REST_Response([
                'error' => 'A migration is already running.',
            ], 409);
        }

        $state->start($entityTypes, $dryRun);
        $idMap = new IdMap();

        // Run migrators in dependency order.
        $order = ['products', 'customers', 'coupons', 'orders', 'subscriptions'];
        $migrationId = $state->getCurrent()['migration_id'];

        foreach ($order as $entity) {
            if (!in_array($entity, $entityTypes, true)) {
                continue;
            }

            if ($state->getCurrent()['status'] === 'cancelled') {
                break;
            }

            $migrator = $this->getMigrator($entity, $state, $idMap, $migrationId, $dryRun);
            if ($migrator) {
                if ($migrator instanceof ProductMigrator) {
                    $migrator->migrateCategories();
                }
                $migrator->run();
            }
        }

        if ($state->getCurrent()['status'] !== 'cancelled') {
            $state->complete();
        }

        return new \WP_REST_Response($state->getCurrent(), 200);
    }

    /**
     * GET /progress - Return current migration progress.
     */
    public function progress(\WP_REST_Request $request): \WP_REST_Response
    {
        $state = new MigrationState();
        $current = $state->getCurrent();

        if (!$current) {
            return new \WP_REST_Response(['status' => 'idle'], 200);
        }

        return new \WP_REST_Response($current, 200);
    }

    /**
     * POST /cancel - Cancel a running migration.
     */
    public function cancel(\WP_REST_Request $request): \WP_REST_Response
    {
        $state = new MigrationState();
        $state->cancel();

        return new \WP_REST_Response(['status' => 'cancelled'], 200);
    }

    /**
     * POST /rollback - Delete FC records created by migration.
     */
    public function rollback(\WP_REST_Request $request): \WP_REST_Response
    {
        $idMap = new IdMap();
        $deleted = [];

        // Reverse order: subscriptions, orders, coupons, customers, products, categories.
        $entityTypes = ['subscription', 'order_transaction', 'order_address', 'order_item', 'order', 'coupon', 'customer_address', 'customer', 'variation', 'product_detail', 'product', 'category'];

        foreach ($entityTypes as $entityType) {
            $mappings = $idMap->getAllByEntityType($entityType);
            $count = 0;

            foreach ($mappings as $mapping) {
                $this->deleteFluentCartRecord($entityType, $mapping->fc_id);
                $count++;
            }

            if ($count > 0) {
                $deleted[$entityType] = $count;
            }

            $idMap->deleteByEntityType($entityType);
        }

        $state = new MigrationState();
        $state->reset();

        return new \WP_REST_Response([
            'status'  => 'rolled_back',
            'deleted' => $deleted,
        ], 200);
    }

    /**
     * GET /log - Return migration log entries (paginated).
     */
    public function log(\WP_REST_Request $request): \WP_REST_Response
    {
        global $wpdb;

        $page    = max(1, (int) $request->get_param('page'));
        $perPage = min(100, max(10, (int) ($request->get_param('per_page') ?: 50)));
        $offset  = ($page - 1) * $perPage;

        $table = $wpdb->prefix . 'wc_fc_migration_log';
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

        $entries = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
            $perPage,
            $offset
        ));

        return new \WP_REST_Response([
            'entries'  => $entries,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => ceil($total / $perPage),
        ], 200);
    }

    /**
     * Count total unique customers (registered + guests).
     */
    private function countCustomers(): int
    {
        global $wpdb;

        $registered = count_users();
        $customerCount = 0;
        foreach ($registered['avail_roles'] as $role => $count) {
            if ($role === 'customer') {
                $customerCount = $count;
            }
        }

        // Count unique guest emails from orders that have no user_id (HPOS + legacy).
        $guestCount = 0;
        $hposTable = $wpdb->prefix . 'wc_orders';
        $hposExists = $wpdb->get_var("SHOW TABLES LIKE '{$hposTable}'");

        if ($hposExists) {
            $guestCount = (int) $wpdb->get_var(
                "SELECT COUNT(DISTINCT billing_email)
                 FROM {$hposTable}
                 WHERE type = 'shop_order'
                   AND billing_email != ''
                   AND (customer_id IS NULL OR customer_id = 0)"
            );
        } elseif (function_exists('wc_get_orders')) {
            $guestCount = (int) $wpdb->get_var(
                "SELECT COUNT(DISTINCT pm.meta_value)
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE p.post_type = 'shop_order'
                   AND pm.meta_key = '_billing_email'
                   AND pm.post_id NOT IN (
                       SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_customer_user' AND meta_value > 0
                   )"
            );
        }

        return $customerCount + $guestCount;
    }

    /**
     * Instantiate the correct migrator for a given entity type.
     */
    private function getMigrator(string $entity, MigrationState $state, IdMap $idMap, string $migrationId, bool $dryRun = false)
    {
        switch ($entity) {
            case 'products':
                return new ProductMigrator($state, $idMap, $migrationId, $dryRun);
            case 'customers':
                return new CustomerMigrator($state, $idMap, $migrationId, $dryRun);
            case 'coupons':
                return new CouponMigrator($state, $idMap, $migrationId, $dryRun);
            case 'orders':
                return new OrderMigrator($state, $idMap, $migrationId, $dryRun);
            case 'subscriptions':
                if (!class_exists('WC_Subscriptions')) {
                    return null;
                }
                return new SubscriptionMigrator($state, $idMap, $migrationId, $dryRun);
            default:
                return null;
        }
    }

    /**
     * Delete a single FluentCart record by entity type and FC id.
     */
    private function deleteFluentCartRecord(string $entityType, int $fcId): void
    {
        if ($entityType === 'category') {
            wp_delete_term($fcId, 'product-categories');
            return;
        }

        global $wpdb;

        $tableMap = [
            'product'          => $wpdb->posts,
            'product_detail'   => $wpdb->prefix . 'fct_product_details',
            'variation'        => $wpdb->prefix . 'fct_product_variations',
            'customer'         => $wpdb->prefix . 'fct_customers',
            'customer_address' => $wpdb->prefix . 'fct_customer_addresses',
            'order'            => $wpdb->prefix . 'fct_orders',
            'order_item'       => $wpdb->prefix . 'fct_order_items',
            'order_address'    => $wpdb->prefix . 'fct_order_addresses',
            'order_transaction'=> $wpdb->prefix . 'fct_order_transactions',
            'coupon'           => $wpdb->prefix . 'fct_coupons',
            'subscription'     => $wpdb->prefix . 'fct_subscriptions',
        ];

        $table = $tableMap[$entityType] ?? null;
        if (!$table) {
            return;
        }

        $primaryKey = ($entityType === 'product') ? 'ID' : 'id';
        $wpdb->delete($table, [$primaryKey => $fcId], ['%d']);
    }
}

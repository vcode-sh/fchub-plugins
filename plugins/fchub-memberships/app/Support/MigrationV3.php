<?php

namespace FChubMemberships\Support;

defined('ABSPATH') || exit;

class MigrationV3
{
    public static function run(): void
    {
        self::fixSubscriptionSourceType();
        self::addForeignKeys();
        self::addIndexes();
        self::createGrantSourcesTable();
        self::addPlanScheduleColumns();
    }

    /**
     * Fix grants that should have source_type='subscription' but were created with source_type='order'.
     *
     * The integration previously always set source_type='order', but SubscriptionValidityWatcher
     * queries with source_type='subscription'. This migration detects grants linked to orders
     * that have subscriptions and updates them to use the subscription as the primary source.
     */
    private static function fixSubscriptionSourceType(): void
    {
        if (!class_exists('\FluentCart\App\Models\Subscription')) {
            return;
        }

        global $wpdb;

        $grantsTable = $wpdb->prefix . 'fchub_membership_grants';
        $subscriptionsTable = $wpdb->prefix . 'fct_subscriptions';

        // Check if FluentCart subscriptions table exists
        $tableExists = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $subscriptionsTable)
        );
        if (!$tableExists) {
            return;
        }

        // Find grants with source_type='order' where the order has a subscription
        $grants = $wpdb->get_results(
            "SELECT g.id, g.source_id AS order_id, g.source_ids, s.id AS subscription_id
             FROM {$grantsTable} g
             INNER JOIN {$subscriptionsTable} s ON s.order_id = g.source_id
             WHERE g.source_type = 'order'
               AND g.source_id > 0",
            ARRAY_A
        );

        if (empty($grants)) {
            return;
        }

        $updated = 0;
        foreach ($grants as $grant) {
            $sourceIds = json_decode($grant['source_ids'] ?? '[]', true) ?: [];

            // Add subscription ID to source_ids if not present
            $subscriptionId = (int) $grant['subscription_id'];
            $orderId = (int) $grant['order_id'];

            if (!in_array($orderId, $sourceIds, false)) {
                $sourceIds[] = $orderId;
            }
            if (!in_array($subscriptionId, $sourceIds, false)) {
                $sourceIds[] = $subscriptionId;
            }

            $wpdb->update(
                $grantsTable,
                [
                    'source_type' => 'subscription',
                    'source_id'   => $subscriptionId,
                    'source_ids'  => wp_json_encode($sourceIds),
                    'updated_at'  => current_time('mysql'),
                ],
                ['id' => (int) $grant['id']]
            );
            $updated++;
        }

        if ($updated > 0) {
            Logger::log(
                'Migration V3',
                sprintf('Fixed %d grants: source_type changed from order to subscription', $updated)
            );
        }
    }

    private static function addForeignKeys(): void
    {
        global $wpdb;
        $prefix = $wpdb->prefix . 'fchub_membership_';

        $constraints = [
            [
                'table'      => "{$prefix}plan_rules",
                'name'       => 'fk_plan_rules_plan',
                'column'     => 'plan_id',
                'ref_table'  => "{$prefix}plans",
                'ref_column' => 'id',
                'on_delete'  => 'CASCADE',
            ],
            [
                'table'      => "{$prefix}grants",
                'name'       => 'fk_grants_plan',
                'column'     => 'plan_id',
                'ref_table'  => "{$prefix}plans",
                'ref_column' => 'id',
                'on_delete'  => 'SET NULL',
            ],
            [
                'table'      => "{$prefix}drip_notifications",
                'name'       => 'fk_drip_grant',
                'column'     => 'grant_id',
                'ref_table'  => "{$prefix}grants",
                'ref_column' => 'id',
                'on_delete'  => 'CASCADE',
            ],
            [
                'table'      => "{$prefix}drip_notifications",
                'name'       => 'fk_drip_rule',
                'column'     => 'plan_rule_id',
                'ref_table'  => "{$prefix}plan_rules",
                'ref_column' => 'id',
                'on_delete'  => 'CASCADE',
            ],
        ];

        foreach ($constraints as $fk) {
            try {
                $wpdb->query(
                    "ALTER TABLE {$fk['table']}
                     ADD CONSTRAINT {$fk['name']}
                     FOREIGN KEY ({$fk['column']})
                     REFERENCES {$fk['ref_table']}({$fk['ref_column']})
                     ON DELETE {$fk['on_delete']}"
                );
                Logger::log('MigrationV3: FK added', "{$fk['name']} on {$fk['table']}");
            } catch (\Throwable $e) {
                Logger::error('MigrationV3: FK failed', "{$fk['name']} on {$fk['table']}: {$e->getMessage()}");
            }
        }
    }

    /**
     * Create the grant_sources junction table and migrate existing JSON data.
     */
    private static function createGrantSourcesTable(): void
    {
        \FChubMemberships\Storage\GrantSourceRepository::createTable();
        \FChubMemberships\Storage\GrantSourceRepository::migrateFromJson();
    }

    /**
     * Add scheduled_status and scheduled_at columns to plans table for T17.
     */
    private static function addPlanScheduleColumns(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fchub_membership_plans';

        $columns = [
            'scheduled_status' => "VARCHAR(20) DEFAULT NULL AFTER meta",
            'scheduled_at'     => "DATETIME DEFAULT NULL AFTER scheduled_status",
        ];

        foreach ($columns as $column => $definition) {
            $exists = $wpdb->get_results($wpdb->prepare(
                "SHOW COLUMNS FROM {$table} LIKE %s",
                $column
            ));
            if (empty($exists)) {
                $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
            }
        }
    }

    private static function addIndexes(): void
    {
        global $wpdb;
        $prefix = $wpdb->prefix . 'fchub_membership_';

        $indexes = [
            [
                'table' => "{$prefix}grants",
                'name'  => 'idx_trial_ends',
                'cols'  => 'trial_ends_at',
            ],
            [
                'table' => "{$prefix}grants",
                'name'  => 'idx_cancellation_effective',
                'cols'  => 'cancellation_effective_at',
            ],
            [
                'table' => "{$prefix}grants",
                'name'  => 'idx_renewal_count',
                'cols'  => 'plan_id, renewal_count',
            ],
            [
                'table' => "{$prefix}drip_notifications",
                'name'  => 'idx_retry',
                'cols'  => 'status, next_retry_at, retry_count',
            ],
        ];

        foreach ($indexes as $idx) {
            try {
                $wpdb->query(
                    "ALTER TABLE {$idx['table']} ADD INDEX {$idx['name']} ({$idx['cols']})"
                );
                Logger::log('MigrationV3: Index added', "{$idx['name']} on {$idx['table']}");
            } catch (\Throwable $e) {
                Logger::error('MigrationV3: Index failed', "{$idx['name']} on {$idx['table']}: {$e->getMessage()}");
            }
        }
    }
}

<?php

namespace FChubMemberships\Support;

defined('ABSPATH') || exit;

final class MigrationV5
{
    /** @return list<string> */
    public static function run(): array
    {
        global $wpdb;

        $prefix = $wpdb->prefix . 'fchub_membership_';
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE {$prefix}entitlement_edges (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            provider VARCHAR(50) NOT NULL,
            resource_type VARCHAR(50) NOT NULL,
            resource_id VARCHAR(100) NOT NULL,
            plan_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            feed_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            feed_scope VARCHAR(20) NOT NULL DEFAULT 'external_unknown',
            source_type VARCHAR(30) NOT NULL,
            source_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            owner VARCHAR(20) NOT NULL DEFAULT 'external_unknown',
            assignment_provenance VARCHAR(20) NOT NULL DEFAULT 'unknown',
            lifecycle VARCHAR(20) NOT NULL DEFAULT 'active',
            starts_at DATETIME NULL,
            expires_at DATETIME NULL,
            drip_available_at DATETIME NULL,
            ended_at DATETIME NULL,
            end_reason VARCHAR(191) NULL,
            policy LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY entitlement_identity (user_id, provider, resource_type, resource_id, plan_id, feed_id, feed_scope, source_type, source_id),
            KEY active_resource (user_id, provider, resource_type, resource_id, lifecycle),
            KEY source_lifecycle (source_type, source_id, lifecycle),
            KEY plan_feed_lifecycle (plan_id, feed_id, feed_scope, lifecycle),
            KEY lifecycle_expires (lifecycle, expires_at),
            KEY lifecycle_drip (lifecycle, drip_available_at),
            KEY lifecycle_ended (lifecycle, ended_at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$prefix}provider_operations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            edge_id BIGINT UNSIGNED NOT NULL,
            operation_key CHAR(64) NOT NULL,
            desired_action VARCHAR(30) NOT NULL,
            origin_event VARCHAR(100) NOT NULL,
            state VARCHAR(20) NOT NULL DEFAULT 'pending',
            lease_owner VARCHAR(64) NULL,
            lease_expires_at DATETIME NULL,
            attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
            retryable TINYINT(1) NOT NULL DEFAULT 1,
            next_retry_at DATETIME NULL,
            last_error_code VARCHAR(100) NULL,
            last_error_message VARCHAR(500) NULL,
            eligible_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            completed_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY operation_key (operation_key),
            KEY edge_state (edge_id, state),
            KEY state_due (state, retryable, next_retry_at),
            KEY state_lease (state, lease_expires_at),
            KEY state_eligible (state, eligible_at),
            KEY completed_at (completed_at)
        ) {$charset};");

        $edgesTable = $prefix . 'entitlement_edges';
        $operationsTable = $prefix . 'provider_operations';
        if (!self::tableExists($edgesTable) || !self::tableExists($operationsTable)) {
            return [];
        }

        if (self::foreignKeyExists($operationsTable, 'fk_provider_operations_edge')) {
            return [];
        }

        $orphanOperations = (int) \FChubMemberships\Support\CustomTableDatabase::getVar(
            \FChubMemberships\Support\CustomTableDatabase::prepare("SELECT COUNT(*) FROM {$operationsTable} child
             LEFT JOIN {$edgesTable} parent ON parent.id = child.edge_id
             WHERE parent.id IS NULL AND %d = 1",
                1,
            )
        );
        if ($orphanOperations > 0) {
            return ['provider_operations: orphan edge_id rows prevent foreign key'];
        }

        $canSuppressErrors = is_callable([$wpdb, 'suppress_errors']);
        $previousSuppression = false;
        if ($canSuppressErrors) {
            $previousSuppression = (bool) $wpdb->suppress_errors(true);
        }
        try {
            $added = \FChubMemberships\Support\CustomTableDatabase::query(
                \FChubMemberships\Support\CustomTableDatabase::prepare("ALTER TABLE %i
                 ADD CONSTRAINT fk_provider_operations_edge
                 FOREIGN KEY (edge_id) REFERENCES %i(id)
                 ON DELETE RESTRICT",
                    $operationsTable,
                    $edgesTable,
                )
            );
        } finally {
            if ($canSuppressErrors) {
                $wpdb->suppress_errors($previousSuppression);
            }
        }
        if ($added === false) {
            return ['provider_operations: failed adding foreign key fk_provider_operations_edge'];
        }

        return [];
    }

    private static function tableExists(string $table): bool
    {
        global $wpdb;

        $pattern = $wpdb->esc_like($table);
        return \FChubMemberships\Support\CustomTableDatabase::getVar(\FChubMemberships\Support\CustomTableDatabase::prepare('SHOW TABLES LIKE %s', $pattern)) === $table;
    }

    private static function foreignKeyExists(string $table, string $constraint): bool
    {
        global $wpdb;

        $rows = \FChubMemberships\Support\CustomTableDatabase::getResults(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT rc.CONSTRAINT_NAME
             FROM information_schema.REFERENTIAL_CONSTRAINTS rc
             WHERE rc.CONSTRAINT_SCHEMA = %s
               AND rc.TABLE_NAME = %s
               AND rc.CONSTRAINT_NAME = %s",
            $wpdb->dbname,
            $table,
            $constraint
        ), ARRAY_A);

        return $rows !== [];
    }
}

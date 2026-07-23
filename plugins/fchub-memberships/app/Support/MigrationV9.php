<?php

namespace FChubMemberships\Support;

defined('ABSPATH') || exit;

final class MigrationV9
{
    /** @return list<string> */
    public static function run(): array
    {
        global $wpdb;

        $edges = $wpdb->prefix . 'fchub_membership_entitlement_edges';
        $grants = $wpdb->prefix . 'fchub_membership_grants';

        if (!self::columnExists($edges, 'access_status')) {
            if ($wpdb->query(
                "ALTER TABLE {$edges}
                 ADD COLUMN access_status VARCHAR(20) NOT NULL DEFAULT 'active' AFTER lifecycle"
            ) === false) {
                return ['entitlement_edges: failed adding access_status'];
            }
        }

        $backfilled = $wpdb->query(
            "UPDATE {$edges} edge
             INNER JOIN {$grants} aggregate
               ON aggregate.user_id = edge.user_id
              AND aggregate.provider = edge.provider
              AND aggregate.resource_type = edge.resource_type
              AND aggregate.resource_id = edge.resource_id
             SET edge.access_status = 'paused'
             WHERE edge.lifecycle = 'active'
               AND edge.access_status = 'active'
               AND aggregate.status = 'paused'"
        );
        if ($backfilled === false) {
            return ['entitlement_edges: failed backfilling paused access status'];
        }

        if (!self::indexExists($edges, 'plan_access_lifecycle_user')) {
            if ($wpdb->query(
                "ALTER TABLE {$edges}
                 ADD INDEX plan_access_lifecycle_user (plan_id, access_status, lifecycle, user_id)"
            ) === false) {
                return ['entitlement_edges: failed adding plan access index'];
            }
        }

        return [];
    }

    private static function columnExists(string $table, string $column): bool
    {
        global $wpdb;

        return !empty($wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM {$table} LIKE %s",
            $column
        ), ARRAY_A));
    }

    private static function indexExists(string $table, string $index): bool
    {
        global $wpdb;

        return !empty($wpdb->get_results($wpdb->prepare(
            "SHOW INDEX FROM {$table} WHERE Key_name = %s",
            $index
        ), ARRAY_A));
    }
}

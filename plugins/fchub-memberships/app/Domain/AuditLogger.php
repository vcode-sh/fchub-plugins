<?php

namespace FChubMemberships\Domain;

defined('ABSPATH') || exit;

class AuditLogger
{
    /**
     * Log an entity change to the audit trail.
     */
    public static function log(
        string $entityType,
        int $entityId,
        string $action,
        array $oldValue = [],
        array $newValue = [],
        ?string $context = null
    ): void {
        global $wpdb;

        $table = $wpdb->prefix . 'fchub_membership_audit_log';
        $now = current_time('mysql');

        $wpdb->insert($table, [
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'action'      => $action,
            'old_value'   => wp_json_encode($oldValue),
            'new_value'   => wp_json_encode($newValue),
            'context'     => $context,
            'actor_id'    => self::getCurrentActorId(),
            'actor_type'  => self::getCurrentActorType(),
            'created_at'  => $now,
        ]);
    }

    public static function logPlanChange(int $planId, string $action, array $old = [], array $new = []): void
    {
        self::log('plan', $planId, $action, $old, $new);
    }

    public static function logGrantChange(int $grantId, string $action, array $old = [], array $new = [], ?string $context = null): void
    {
        self::log('grant', $grantId, $action, $old, $new, $context);
    }

    public static function logSettingChange(string $action, array $old = [], array $new = []): void
    {
        self::log('setting', 0, $action, $old, $new);
    }

    private static function getCurrentActorId(): int
    {
        $user = wp_get_current_user();
        return $user && $user->ID ? $user->ID : 0;
    }

    private static function getCurrentActorType(): string
    {
        if (defined('DOING_CRON') && DOING_CRON) {
            return 'cron';
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            return 'api';
        }

        if (is_admin()) {
            return 'admin';
        }

        return 'system';
    }
}

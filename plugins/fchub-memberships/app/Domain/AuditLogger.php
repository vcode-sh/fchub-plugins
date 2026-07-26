<?php

namespace FChubMemberships\Domain;

use FChubMemberships\Support\Clock;

defined('ABSPATH') || exit;

class AuditLogger
{
    public static function hasRequiredLifecycleReceipt(
        string $entityType,
        int $entityId,
        string $action,
        string $eventReceipt
    ): bool {
        global $wpdb;

        $alternativeAction = $action === 'renewal_validity_extended'
            ? 'renewal_successor_created'
            : $action;
        $count = \FChubMemberships\Support\CustomTableDatabase::getVar(\FChubMemberships\Support\CustomTableDatabase::prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fchub_membership_audit_log
             WHERE entity_type = %s
               AND entity_id = %d
               AND action IN (%s, %s)
               AND JSON_TYPE(JSON_EXTRACT(new_value, '$.event_receipt')) = 'STRING'
               AND JSON_UNQUOTE(JSON_EXTRACT(new_value, '$.event_receipt')) = %s",
            $entityType,
            $entityId,
            $action,
            $alternativeAction,
            $eventReceipt
        ));
        if (trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException('The required lifecycle audit receipt could not be read.');
        }

        return (int) $count > 0;
    }

    public static function logRequired(
        string $entityType,
        int $entityId,
        string $action,
        array $oldValue,
        array $newValue,
        Clock $clock,
        ?string $context = null
    ): void {
        global $wpdb;

        $inserted = \FChubMemberships\Support\CustomTableDatabase::insert($wpdb->prefix . 'fchub_membership_audit_log', [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'old_value' => wp_json_encode($oldValue),
            'new_value' => wp_json_encode($newValue),
            'context' => $context,
            'actor_id' => self::getCurrentActorId(),
            'actor_type' => self::getCurrentActorType(),
            'created_at' => $clock->storage($clock->now()),
        ]);
        if ($inserted === false) {
            throw new \RuntimeException('The required lifecycle audit entry could not be persisted.');
        }
    }

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

        $table = \FChubMemberships\Support\CustomTableDatabase::identifier($wpdb->prefix . 'fchub_membership_audit_log');
        $now = current_time('mysql');

        \FChubMemberships\Support\CustomTableDatabase::insert($table, [
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

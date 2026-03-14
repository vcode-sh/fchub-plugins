<?php

namespace FChubMemberships\Storage;

defined('ABSPATH') || exit;

final class SubscriptionValidityLogRepository
{
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'fchub_membership_validity_log';
    }

    public function findLatestBySubscriptionId(int $subscriptionId): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE subscription_id = %d ORDER BY id DESC LIMIT 1",
            $subscriptionId
        ), ARRAY_A);

        return $row ?: null;
    }

    public function touchValid(int $subscriptionId): void
    {
        global $wpdb;

        $existing = $this->findLatestBySubscriptionId($subscriptionId);
        if (!$existing) {
            $wpdb->insert($this->table, [
                'subscription_id' => $subscriptionId,
                'last_valid_at'   => current_time('mysql'),
            ]);

            return;
        }

        $wpdb->update($this->table, [
            'last_valid_at' => current_time('mysql'),
        ], ['id' => (int) $existing['id']]);
    }

    public function markDispatched(int $subscriptionId): void
    {
        global $wpdb;

        $existing = $this->findLatestBySubscriptionId($subscriptionId);
        $payload = [
            'subscription_id' => $subscriptionId,
            'last_valid_at'   => current_time('mysql'),
            'expired_at'      => current_time('mysql'),
            'dispatched_at'   => current_time('mysql'),
        ];

        if (!$existing) {
            $wpdb->insert($this->table, $payload);
            return;
        }

        $wpdb->update($this->table, [
            'expired_at'    => current_time('mysql'),
            'dispatched_at' => current_time('mysql'),
        ], ['id' => (int) $existing['id']]);
    }
}

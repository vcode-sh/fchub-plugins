<?php

namespace FChubMemberships\Domain\Member;

use FChubMemberships\Support\CustomTableDatabase;

defined('ABSPATH') || exit;

/**
 * Reads the FluentCart records a grant points at.
 *
 * Absent records return null rather than throwing: a deleted order is a normal
 * state for an old grant, and the profile has to survive it.
 */
class FluentCartSourceGateway
{
    /** @return array{id: int}|null */
    public function order(int $orderId): ?array
    {
        if ($orderId <= 0) {
            return null;
        }

        global $wpdb;
        $table = CustomTableDatabase::identifier($wpdb->prefix . 'fct_orders');
        $row = CustomTableDatabase::getRow(
            CustomTableDatabase::prepare("SELECT id FROM {$table} WHERE id = %d", $orderId),
            ARRAY_A
        );

        return $row ? ['id' => (int) $row['id']] : null;
    }

    /**
     * @return array{
     *     id: int, status: string, next_billing_date: ?string,
     *     canceled_at: ?string, parent_order_id: int
     * }|null
     */
    public function subscription(int $subscriptionId): ?array
    {
        if ($subscriptionId <= 0) {
            return null;
        }

        global $wpdb;
        $table = CustomTableDatabase::identifier($wpdb->prefix . 'fct_subscriptions');
        $row = CustomTableDatabase::getRow(
            CustomTableDatabase::prepare(
                "SELECT id, status, next_billing_date, canceled_at, parent_order_id FROM {$table} WHERE id = %d",
                $subscriptionId
            ),
            ARRAY_A
        );

        if (!$row) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'status' => (string) ($row['status'] ?? ''),
            'next_billing_date' => $row['next_billing_date'] ?: null,
            'canceled_at' => $row['canceled_at'] ?: null,
            'parent_order_id' => (int) ($row['parent_order_id'] ?? 0),
        ];
    }
}

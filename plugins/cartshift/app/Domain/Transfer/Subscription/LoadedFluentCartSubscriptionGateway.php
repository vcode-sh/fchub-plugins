<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Subscription;

use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

final class LoadedFluentCartSubscriptionGateway implements SubscriptionCutoverTargetGateway
{
    /** @param array<string,mixed> $row */
    public function create(array $row): int
    {
        global $wpdb;
        $uuid = $row['uuid'] ?? null;
        if (!is_string($uuid) || trim($uuid) === '') {
            throw new \RuntimeException('target_subscription_identity_invalid');
        }
        $collision = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fct_subscriptions WHERE uuid = %s LIMIT 1",
            $uuid,
        ));
        if (trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException('target_subscription_collision_read_failed');
        }
        if ($collision !== null) {
            throw new \RuntimeException('target_subscription_identity_conflict');
        }
        foreach (['config', 'original_plan', 'vendor_response'] as $field) {
            if (is_array($row[$field] ?? null)) {
                $row[$field] = CanonicalJson::encode($row[$field]);
            }
        }
        $inserted = $wpdb->insert($wpdb->prefix . 'fct_subscriptions', $row);
        $id = (int) ($wpdb->insert_id ?? 0);
        if ($inserted !== 1 || $id <= 0 || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException('target_subscription_insert_failed');
        }
        return $id;
    }

    public function exists(int $subscriptionId): bool
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_subscriptions WHERE id = %d",
            $subscriptionId,
        )) === 1;
    }

    public function snapshot(int $subscriptionId): array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id,uuid,customer_id,parent_order_id,product_id,item_name,quantity,variation_id,
                    billing_interval,signup_fee,initial_tax_total,recurring_amount,recurring_tax_total,
                    recurring_total,bill_times,bill_count,expire_at,trial_ends_at,canceled_at,restored_at,
                    collection_method,next_billing_date,trial_days,vendor_customer_id,vendor_plan_id,
                    vendor_subscription_id,status,original_plan,vendor_response,current_payment_method,
                    config,created_at,updated_at
             FROM {$wpdb->prefix}fct_subscriptions WHERE id = %d LIMIT 1",
            $subscriptionId,
        ), ARRAY_A);
        if (trim((string) ($wpdb->last_error ?? '')) !== '' || !is_array($row)) {
            throw new \RuntimeException('target_subscription_snapshot_missing');
        }
        foreach (['id', 'customer_id', 'parent_order_id', 'product_id', 'quantity', 'variation_id', 'signup_fee',
            'initial_tax_total', 'recurring_amount', 'recurring_tax_total', 'recurring_total', 'bill_times',
            'bill_count', 'trial_days'] as $field) {
            $row[$field] = (int) $row[$field];
        }
        foreach (['config', 'original_plan', 'vendor_response'] as $field) {
            if (is_string($row[$field] ?? null) && $row[$field] !== '') {
                $decoded = json_decode($row[$field], true);
                if (is_array($decoded)) {
                    $row[$field] = CanonicalJson::canonicalise($decoded);
                }
            }
        }
        $links = $wpdb->get_results($wpdb->prepare(
            "SELECT id,subscription_id,order_type FROM {$wpdb->prefix}fct_order_transactions
             WHERE subscription_id = %d ORDER BY id ASC",
            $subscriptionId,
        ), ARRAY_A);
        $metaRows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key,meta_value FROM {$wpdb->prefix}fct_subscription_meta
             WHERE subscription_id = %d AND meta_key IN ('billed_cycles_offset','billed_cycles_deduction')
             ORDER BY meta_key,id",
            $subscriptionId,
        ), ARRAY_A);
        if (trim((string) ($wpdb->last_error ?? '')) !== '' || !is_array($links) || !is_array($metaRows)) {
            throw new \RuntimeException('target_subscription_graph_read_failed');
        }
        $links = array_map(static fn (array $link): array => [
            'id' => (int) $link['id'],
            'subscription_id' => (int) $link['subscription_id'],
            'order_type' => (string) $link['order_type'],
        ], $links);
        $meta = [];
        foreach ($metaRows as $metaRow) {
            $key = (string) $metaRow['meta_key'];
            if (isset($meta[$key])) {
                throw new \RuntimeException('target_subscription_meta_duplicate');
            }
            $meta[$key] = (int) $metaRow['meta_value'];
        }
        ksort($meta, SORT_STRING);
        return ['subscription' => $row, 'transaction_links' => $links, 'meta' => $meta];
    }

    public function linkTransaction(int $transactionId, int $subscriptionId, string $orderType): void
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT subscription_id,order_type FROM {$wpdb->prefix}fct_order_transactions WHERE id = %d LIMIT 1",
            $transactionId,
        ), ARRAY_A);
        if (!is_array($row) || (string) $row['order_type'] !== $orderType) {
            throw new \RuntimeException('target_subscription_transaction_changed');
        }
        $current = $row['subscription_id'] === null ? 0 : (int) $row['subscription_id'];
        if ($current === $subscriptionId) {
            return;
        }
        if ($current !== 0) {
            throw new \RuntimeException('target_subscription_transaction_already_claimed');
        }
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}fct_order_transactions SET subscription_id = %d
             WHERE id = %d AND (subscription_id IS NULL OR subscription_id = 0) AND order_type = %s",
            $subscriptionId,
            $transactionId,
            $orderType,
        ));
        if ($updated !== 1) {
            throw new \RuntimeException('target_subscription_transaction_link_failed');
        }
    }

    public function writeCorrection(int $subscriptionId, string $key, int $value): void
    {
        if (!in_array($key, ['billed_cycles_offset', 'billed_cycles_deduction'], true) || $value < 0) {
            throw new \InvalidArgumentException('target_subscription_correction_invalid');
        }
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,meta_value FROM {$wpdb->prefix}fct_subscription_meta
             WHERE subscription_id = %d AND meta_key = %s ORDER BY id",
            $subscriptionId,
            $key,
        ), ARRAY_A);
        if (!is_array($rows) || count($rows) > 1) {
            throw new \RuntimeException('target_subscription_meta_ambiguous');
        }
        if ($value === 0) {
            if ($rows !== []) {
                throw new \RuntimeException('target_subscription_zero_correction_present');
            }
            return;
        }
        if ($rows !== []) {
            if ((int) $rows[0]['meta_value'] !== $value) {
                throw new \RuntimeException('target_subscription_correction_changed');
            }
            return;
        }
        if ($wpdb->insert($wpdb->prefix . 'fct_subscription_meta', [
            'subscription_id' => $subscriptionId,
            'meta_key' => $key,
            'meta_value' => (string) $value,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]) !== 1) {
            throw new \RuntimeException('target_subscription_correction_write_failed');
        }
    }

    public function activateStatus(int $subscriptionId, string $expectedStatus, string $intendedStatus): void
    {
        global $wpdb;
        $allowed = ['active', 'paused', 'canceled', 'expired', 'expiring', 'pending'];
        if (!in_array($expectedStatus, $allowed, true) || !in_array($intendedStatus, $allowed, true)) {
            throw new \InvalidArgumentException('target_subscription_cutover_status_invalid');
        }
        $current = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}fct_subscriptions WHERE id = %d",
            $subscriptionId,
        ));
        if ($current === $intendedStatus) return;
        if ($current !== $expectedStatus) throw new \RuntimeException('target_subscription_cutover_status_drift');
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}fct_subscriptions SET status = %s, updated_at = %s WHERE id = %d AND status = %s",
            $intendedStatus, gmdate('Y-m-d H:i:s'), $subscriptionId, $expectedStatus,
        ));
        if ($updated !== 1) throw new \RuntimeException('target_subscription_cutover_status_update_failed');
    }
}

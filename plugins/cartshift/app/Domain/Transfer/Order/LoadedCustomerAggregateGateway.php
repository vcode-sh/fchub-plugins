<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

use CartShift\Domain\Transfer\SourceIdentity;

defined('ABSPATH') || exit;

final class LoadedCustomerAggregateGateway implements CustomerAggregateGateway
{
    private const array SUCCESS_STATUSES = ['paid', 'partially_refunded', 'partially_paid'];
    private const string RECEIPT_KIND = 'customer_aggregate';

    public function customerExists(int $customerId): bool
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_customers WHERE id = %d",
            $customerId,
        )) === 1;
    }

    public function orders(int $customerId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT payment_status AS status,currency,total_paid AS paid,total_refund AS refund,rate,created_at
             FROM {$wpdb->prefix}fct_orders WHERE customer_id = %d ORDER BY id ASC",
            $customerId,
        ), ARRAY_A);
        if (trim((string) $wpdb->last_error) !== '') {
            throw new \RuntimeException('customer_aggregate_order_read_failed');
        }
        foreach ($rows as &$row) {
            foreach (['paid', 'refund', 'rate'] as $field) $row[$field] = (int) $row[$field];
        }
        unset($row);
        return array_values($rows);
    }

    public function write(int $customerId, array $aggregate): void
    {
        global $wpdb;
        $updated = $wpdb->update($wpdb->prefix . 'fct_customers', [
            'purchase_value' => wp_json_encode($aggregate['purchase_value']),
            'purchase_count' => $aggregate['purchase_count'],
            'ltv' => $aggregate['ltv'],
            'aov' => $aggregate['aov'],
            'first_purchase_date' => $aggregate['first_purchase_date'],
            'last_purchase_date' => $aggregate['last_purchase_date'],
        ], ['id' => $customerId]);
        if ($updated !== 1) {
            throw new \RuntimeException('customer_aggregate_write_failed');
        }
    }

    public function snapshot(int $customerId): array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT purchase_value,purchase_count,ltv,aov,first_purchase_date,last_purchase_date
             FROM {$wpdb->prefix}fct_customers WHERE id = %d LIMIT 1",
            $customerId,
        ), ARRAY_A);
        if (!is_array($row)) throw new \RuntimeException('customer_aggregate_target_missing');
        $purchaseValue = $row['purchase_value'] ? json_decode((string) $row['purchase_value'], true, 32, JSON_THROW_ON_ERROR) : [];
        ksort($purchaseValue, SORT_STRING);
        return [
            'purchase_value' => array_map('intval', $purchaseValue),
            'purchase_count' => (int) $row['purchase_count'],
            'ltv' => (int) $row['ltv'],
            'aov' => (float) $row['aov'],
            'first_purchase_date' => $row['first_purchase_date'] === null ? null : (string) $row['first_purchase_date'],
            'last_purchase_date' => $row['last_purchase_date'] === null ? null : (string) $row['last_purchase_date'],
        ];
    }

    public function independentProjection(int $customerId): array
    {
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count(self::SUCCESS_STATUSES), '%s'));
        $currencyRows = $wpdb->get_results($wpdb->prepare(
            "SELECT currency,SUM(GREATEST(total_paid-total_refund,0)) AS net_value
             FROM {$wpdb->prefix}fct_orders
             WHERE customer_id = %d AND payment_status IN ({$placeholders})
             GROUP BY currency ORDER BY currency ASC",
            $customerId,
            ...self::SUCCESS_STATUSES,
        ), ARRAY_A);
        $summary = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS purchase_count,
                    COALESCE(SUM(GREATEST(total_paid-total_refund,0)*rate),0) AS ltv,
                    ROUND(COALESCE(SUM(GREATEST(total_paid-total_refund,0)*rate),0)/NULLIF(COUNT(*),0),2) AS aov,
                    MIN(created_at) AS first_purchase_date,MAX(created_at) AS last_purchase_date
             FROM {$wpdb->prefix}fct_orders
             WHERE customer_id = %d AND payment_status IN ({$placeholders})",
            $customerId,
            ...self::SUCCESS_STATUSES,
        ), ARRAY_A);
        if (!is_array($summary) || trim((string) $wpdb->last_error) !== '') {
            throw new \RuntimeException('customer_aggregate_independent_projection_failed');
        }
        $purchaseValue = [];
        foreach ($currencyRows as $row) {
            if ((int) $row['net_value'] > 0) $purchaseValue[(string) $row['currency']] = (int) $row['net_value'];
        }
        ksort($purchaseValue, SORT_STRING);
        return [
            'purchase_value' => $purchaseValue,
            'purchase_count' => (int) $summary['purchase_count'],
            'ltv' => (int) $summary['ltv'],
            'aov' => $summary['aov'] === null ? 0.0 : (float) $summary['aov'],
            'first_purchase_date' => $summary['first_purchase_date'] === null ? null : (string) $summary['first_purchase_date'],
            'last_purchase_date' => $summary['last_purchase_date'] === null ? null : (string) $summary['last_purchase_date'],
        ];
    }

    public function receipt(SourceIdentity $source, string $runId, int $generation): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT run_id,record_kind,source_identity,generation,source_fingerprint,target_fingerprint,
                    action,state,target_ids,before_hash,after_hash,error_code
             FROM {$wpdb->prefix}cartshift_transfer_records
             WHERE run_id = %s AND record_kind = %s AND source_identity = %s AND generation = %d LIMIT 1",
            $runId,
            self::RECEIPT_KIND,
            $source->canonical(),
            $generation,
        ), ARRAY_A);
        if (!is_array($row)) return null;
        $row['generation'] = (int) $row['generation'];
        $row['target_ids'] = json_decode((string) $row['target_ids'], true, 32, JSON_THROW_ON_ERROR);
        return $row;
    }

    public function storeReceipt(array $receipt): void
    {
        global $wpdb;
        $fields = $receipt;
        $fields['target_ids'] = wp_json_encode($fields['target_ids']);
        $fields['created_at'] = gmdate('Y-m-d H:i:s');
        $fields['updated_at'] = $fields['created_at'];
        if ($wpdb->insert($wpdb->prefix . 'cartshift_transfer_records', $fields) !== 1) {
            throw new \RuntimeException('customer_aggregate_receipt_write_failed');
        }
    }
}

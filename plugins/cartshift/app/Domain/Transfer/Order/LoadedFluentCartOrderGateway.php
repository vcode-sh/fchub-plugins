<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

use CartShift\Domain\Transfer\SourceRecordException;

defined('ABSPATH') || exit;

/** Direct wpdb persistence: no Eloquent lifecycle, receipt allocator, integrations or nested transaction. */
final class LoadedFluentCartOrderGateway implements OrderTargetGateway
{
    public function createOrder(array $fields): int
    {
        if (!array_key_exists('receipt_number', $fields) || $fields['receipt_number'] !== null
            || ($fields['payment_method'] ?? null) !== 'wc_migrated'
            || ($fields['config']['cartshift_historical_transfer'] ?? false) !== true) {
            throw new SourceRecordException('target_write_failed', 'Historical order header is not inert or source-scoped.');
        }
        global $wpdb;
        $collision = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fct_orders WHERE invoice_no = %s OR uuid = %s LIMIT 1",
            (string) $fields['invoice_no'],
            (string) $fields['uuid'],
        ));
        if ($collision !== null) {
            throw new SourceRecordException('source_identity_conflict', 'Deterministic order display identity collides with another target row.');
        }
        $fields['config'] = $this->json($fields['config']);
        return $this->insert($wpdb->prefix . 'fct_orders', $fields, 'order header');
    }

    public function createItem(int $orderId, array $fields): int
    {
        global $wpdb;
        $fields['order_id'] = $orderId;
        $fields['other_info'] = $this->json($fields['other_info'] ?? []);
        $fields['line_meta'] = $this->json($fields['line_meta'] ?? []);
        return $this->insert($wpdb->prefix . 'fct_order_items', $fields, 'order item');
    }

    public function createAddress(int $orderId, array $fields): int
    {
        global $wpdb;
        $fields['order_id'] = $orderId;
        $fields['meta'] = $this->nullableJson($fields['meta'] ?? null);
        return $this->insert($wpdb->prefix . 'fct_order_addresses', $fields, 'order address');
    }

    public function createCoupon(int $orderId, array $fields): int
    {
        global $wpdb;
        $fields['order_id'] = $orderId;
        unset($fields['source_identity'], $fields['source_discount_tax']);
        return $this->insert($wpdb->prefix . 'fct_applied_coupons', $fields, 'applied coupon');
    }

    public function createTaxRate(int $orderId, array $fields): int
    {
        global $wpdb;
        $fields['order_id'] = $orderId;
        $fields['meta'] = $this->json($fields['meta'] ?? []);
        return $this->insert($wpdb->prefix . 'fct_order_tax_rate', $fields, 'order tax rate');
    }

    public function createTransaction(int $orderId, array $fields): int
    {
        global $wpdb;
        if (($fields['payment_method'] ?? null) !== 'wc_migrated'
            || ($fields['payment_method_type'] ?? null) !== 'historical_provenance'
            || ($fields['vendor_charge_id'] ?? null) !== '') {
            throw new SourceRecordException('target_write_failed', 'Historical transaction is executable or leaks a provider reference.');
        }
        $fields['order_id'] = $orderId;
        $fields['meta'] = $this->json($fields['meta'] ?? []);
        return $this->insert($wpdb->prefix . 'fct_order_transactions', $fields, 'order transaction');
    }

    public function createMeta(int $orderId, array $fields): int
    {
        global $wpdb;
        $fields['order_id'] = $orderId;
        $fields['meta_value'] = $this->json($fields['meta_value'] ?? []);
        return $this->insert($wpdb->prefix . 'fct_order_meta', $fields, 'order meta');
    }

    public function exists(int $orderId): bool
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders WHERE id = %d",
            $orderId,
        )) === 1;
    }

    public function snapshot(int $orderId): array
    {
        global $wpdb;
        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT id,status,parent_id,receipt_number,invoice_no,fulfillment_type,type,mode,shipping_status,
                    customer_id,payment_method,payment_status,payment_method_title,currency,subtotal,discount_tax,
                    manual_discount_total,coupon_discount_total,shipping_tax,shipping_total,fee_total,tax_total,
                    total_amount,total_paid,total_refund,rate,tax_behavior,note,ip_address,completed_at,refunded_at,
                    uuid,config,created_at,updated_at
             FROM {$wpdb->prefix}fct_orders WHERE id = %d LIMIT 1",
            $orderId,
        ), ARRAY_A);
        if (is_array($order)) {
            $this->integers($order, ['id', 'parent_id', 'receipt_number', 'customer_id', 'subtotal', 'discount_tax',
                'manual_discount_total', 'coupon_discount_total', 'shipping_tax', 'shipping_total', 'fee_total',
                'tax_total', 'total_amount', 'total_paid', 'total_refund', 'tax_behavior'], true);
            $order['rate'] = (int) $order['rate'];
            $order['config'] = $this->decode($order['config']);
        }

        $items = $this->queryRows(
            "SELECT id,order_id,post_id,fulfillment_type,payment_type,post_title,title,object_id,cart_index,
                    quantity,unit_price,cost,subtotal,tax_amount,shipping_charge,discount_total,line_total,
                    refund_total,rate,other_info,line_meta,fulfilled_quantity,created_at,updated_at
             FROM {$wpdb->prefix}fct_order_items WHERE order_id = %d ORDER BY id ASC",
            $orderId,
        );
        foreach ($items as &$row) {
            $this->integers($row, ['id', 'order_id', 'post_id', 'object_id', 'cart_index', 'quantity', 'unit_price',
                'cost', 'subtotal', 'tax_amount', 'shipping_charge', 'discount_total', 'line_total', 'refund_total',
                'rate', 'fulfilled_quantity'], true);
            $row['other_info'] = $this->decode($row['other_info']);
            $row['line_meta'] = $this->decode($row['line_meta']);
        }
        unset($row);

        $addresses = $this->queryRows(
            "SELECT id,order_id,type,name,address_1,address_2,city,state,postcode,country,meta,created_at,updated_at
             FROM {$wpdb->prefix}fct_order_addresses WHERE order_id = %d ORDER BY id ASC",
            $orderId,
        );
        foreach ($addresses as &$row) {
            $this->integers($row, ['id', 'order_id']);
            $row['meta'] = $this->decodeNullable($row['meta']);
        }
        unset($row);

        $coupons = $this->queryRows(
            "SELECT id,order_id,coupon_id,code,amount,created_at,updated_at
             FROM {$wpdb->prefix}fct_applied_coupons WHERE order_id = %d ORDER BY id ASC",
            $orderId,
        );
        foreach ($coupons as &$row) {
            $this->integers($row, ['id', 'order_id', 'coupon_id'], true);
            $row['amount'] = (int) $row['amount'];
        }
        unset($row);

        $taxRates = $this->queryRows(
            "SELECT id,order_id,tax_rate_id,shipping_tax,order_tax,total_tax,meta,filed_at,created_at,updated_at
             FROM {$wpdb->prefix}fct_order_tax_rate WHERE order_id = %d ORDER BY id ASC",
            $orderId,
        );
        foreach ($taxRates as &$row) {
            $this->integers($row, ['id', 'order_id', 'tax_rate_id', 'shipping_tax', 'order_tax', 'total_tax'], true);
            $row['meta'] = $this->decode($row['meta']);
        }
        unset($row);

        $transactions = $this->queryRows(
            "SELECT id,order_id,order_type,transaction_type,subscription_id,vendor_charge_id,payment_method,
                    payment_mode,payment_method_type,status,currency,total,rate,uuid,meta,created_at,updated_at
             FROM {$wpdb->prefix}fct_order_transactions WHERE order_id = %d ORDER BY id ASC",
            $orderId,
        );
        foreach ($transactions as &$row) {
            $this->integers($row, ['id', 'order_id', 'subscription_id', 'total', 'rate'], true);
            $row['meta'] = $this->decode($row['meta']);
        }
        unset($row);

        $meta = $this->queryRows(
            "SELECT id,order_id,meta_key,meta_value,created_at,updated_at
             FROM {$wpdb->prefix}fct_order_meta WHERE order_id = %d ORDER BY id ASC",
            $orderId,
        );
        foreach ($meta as &$row) {
            $this->integers($row, ['id', 'order_id']);
            $row['meta_value'] = $this->decode($row['meta_value']);
        }
        unset($row);

        return [
            'order' => $order,
            'items' => $items,
            'addresses' => $addresses,
            'coupons' => $coupons,
            'tax_rates' => $taxRates,
            'transactions' => $transactions,
            'meta' => $meta,
        ];
    }

    private function insert(string $table, array $fields, string $label): int
    {
        global $wpdb;
        if ($wpdb->insert($table, $fields) !== 1 || (int) $wpdb->insert_id <= 0) {
            throw new SourceRecordException('target_write_failed', 'Checked FluentCart ' . $label . ' insert failed.');
        }
        return (int) $wpdb->insert_id;
    }

    /** @return list<array<string,mixed>> */
    private function queryRows(string $sql, int $orderId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare($sql, $orderId), ARRAY_A);
        if (trim((string) $wpdb->last_error) !== '') {
            throw new SourceRecordException('target_reconciliation_failed', 'FluentCart order graph reload failed.');
        }
        return is_array($rows) ? array_values($rows) : [];
    }

    /** @param array<string,mixed> $row @param list<string> $fields */
    private function integers(array &$row, array $fields, bool $nullable = false): void
    {
        foreach ($fields as $field) {
            if ($nullable && ($row[$field] ?? null) === null) {
                $row[$field] = null;
            } else {
                $row[$field] = (int) ($row[$field] ?? 0);
            }
        }
    }

    private function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
    }

    private function nullableJson(mixed $value): ?string
    {
        return $value === null ? null : $this->json($value);
    }

    /** @return array<mixed> */
    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<mixed>|null */
    private function decodeNullable(mixed $value): ?array
    {
        return $value === null || $value === '' ? null : $this->decode($value);
    }
}

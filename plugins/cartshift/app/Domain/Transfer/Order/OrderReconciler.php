<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

use CartShift\Domain\Transfer\Identity\CheckedMappingStore;
use CartShift\Domain\Transfer\ReconciliationResult;
use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

/** Rebuilds the contract from independently reloaded rows; it never trusts write return values. */
final class OrderReconciler
{
    public function __construct(
        private readonly OrderTargetGateway $gateway,
        private readonly CheckedMappingStore $maps,
        private readonly OrderTargetFingerprint $targetFingerprint = new OrderTargetFingerprint(),
        private readonly FluentCartOrderMoneyContract $moneyContract = new FluentCartOrderMoneyContract(),
    ) {
    }

    public function reconcile(OrderStagePlan $plan, int $orderId, string $expectedFingerprint): ReconciliationResult
    {
        $failures = [];
        $snapshot = $this->gateway->snapshot($orderId);
        $targetMap = [];
        foreach ($plan->sourceIdentities() as $identity) {
            $mapping = $this->maps->get($identity);
            if ($mapping === null || !$mapping->isActive()) {
                $failures[] = 'checked_map_missing';
                continue;
            }
            if (!hash_equals($plan->sourceFingerprint($identity), (string) $mapping->sourceFingerprint)) {
                $failures[] = 'checked_map_source_fingerprint_mismatch';
            }
            if (!hash_equals($expectedFingerprint, (string) $mapping->targetFingerprint)) {
                $failures[] = 'checked_map_target_fingerprint_mismatch';
            }
            $targetMap[$identity->canonical()] = $mapping->targetId;
        }
        $actualFingerprint = $this->targetFingerprint->fingerprint($snapshot, $targetMap);
        if (!hash_equals($expectedFingerprint, $actualFingerprint)) {
            $failures[] = 'target_fingerprint_mismatch';
        }

        $order = is_array($snapshot['order'] ?? null) ? $snapshot['order'] : null;
        if ($order === null || (int) ($order['id'] ?? 0) !== $orderId) {
            $failures[] = 'order_header_missing';
            return $this->result($actualFingerprint, $failures);
        }
        if (($order['customer_id'] ?? null) !== $plan->customerTargetId) {
            $failures[] = 'customer_reference_mismatch';
        }
        if (($order['parent_id'] ?? null) !== $plan->parentTargetId) {
            $failures[] = 'parent_order_reference_mismatch';
        }
        foreach ($plan->header as $field => $expected) {
            if (($order[$field] ?? null) !== $expected) {
                $failures[] = in_array($field, ['status', 'type', 'payment_status', 'mode', 'payment_method',
                    'payment_method_title', 'currency', 'fulfillment_type', 'shipping_status'], true)
                    ? 'order_semantics_mismatch'
                    : 'order_header_mismatch';
            }
        }
        if (($order['receipt_number'] ?? null) !== null) {
            $failures[] = 'historical_receipt_number_created';
        }

        $items = $this->rows($snapshot, 'items');
        $this->reconcileItems($plan, $items, $targetMap, $failures);
        $this->reconcileAddresses($plan, $this->rows($snapshot, 'addresses'), $targetMap, $failures);
        $this->reconcileCoupons($plan, $this->rows($snapshot, 'coupons'), $targetMap, $failures);
        $this->reconcileTaxRates($plan, $this->rows($snapshot, 'tax_rates'), $targetMap, $failures);
        $this->reconcileTransactions($plan, $this->rows($snapshot, 'transactions'), $targetMap, $failures);
        $provenanceId = $this->reconcileMeta($plan, $this->rows($snapshot, 'meta'), $failures);
        $this->reconcileProvenanceMappings($plan, $targetMap, $provenanceId, $failures);
        $this->reconcileMoney($plan, $snapshot, $failures);

        if (($targetMap[$plan->record->identity->canonical()] ?? null) !== $orderId) {
            $failures[] = 'order_checked_map_mismatch';
        }

        return $this->result($actualFingerprint, $failures);
    }

    /** @param list<array<string,mixed>> $items @param array<string,int> $targetMap @param list<string> $failures */
    private function reconcileItems(OrderStagePlan $plan, array $items, array $targetMap, array &$failures): void
    {
        $expected = [];
        foreach ($plan->record->productLines as $index => $line) {
            $expected[$line->identity->canonical()] = $plan->money->productItems[$index];
        }
        foreach ($plan->record->feeLines as $index => $line) {
            $expected[$line->identity->canonical()] = $plan->money->fees[$index]->row;
        }
        if (count($items) !== count($expected)) {
            $failures[] = 'order_item_cardinality_mismatch';
        }
        $seen = [];
        foreach ($items as $row) {
            $identity = (string) ($row['other_info']['source_identity'] ?? '');
            if ($identity === '' || !isset($expected[$identity]) || isset($seen[$identity])) {
                $failures[] = 'line_source_identity_mismatch';
                continue;
            }
            $seen[$identity] = true;
            if (($targetMap[$identity] ?? null) !== (int) ($row['id'] ?? 0)) {
                $failures[] = 'checked_map_target_mismatch';
            }
            $expectedRow = $expected[$identity];
            if (isset($expectedRow['post_id']) && ((int) ($row['post_id'] ?? 0) !== (int) $expectedRow['post_id']
                || (int) ($row['object_id'] ?? 0) !== (int) $expectedRow['object_id'])) {
                $failures[] = 'product_reference_mismatch';
            }
            if (!$this->same($expectedRow, $this->without($row, ['id', 'order_id', 'updated_at']))) {
                $failures[] = ($expectedRow['payment_type'] ?? null) === 'fee'
                    ? 'fee_row_mismatch'
                    : 'product_item_mismatch';
            }
        }
        if (count($seen) !== count($expected)) {
            $failures[] = 'line_source_identity_mismatch';
        }
    }

    /** @param list<array<string,mixed>> $rows @param array<string,int> $targetMap @param list<string> $failures */
    private function reconcileAddresses(OrderStagePlan $plan, array $rows, array $targetMap, array &$failures): void
    {
        if (count($rows) !== count($plan->addresses)) {
            $failures[] = 'address_cardinality_mismatch';
        }
        foreach ($plan->addresses as $entry) {
            $identity = $entry['source']->identity->canonical();
            $id = $targetMap[$identity] ?? 0;
            $row = $this->rowById($rows, $id);
            $expected = $entry['projection']->row;
            unset($expected['source_identity']);
            if ($row === null || !$this->same($expected, $this->without($row, ['id', 'order_id', 'created_at', 'updated_at', 'source_identity']))) {
                $failures[] = 'address_row_mismatch';
            }
        }
    }

    /** @param list<array<string,mixed>> $rows @param array<string,int> $targetMap @param list<string> $failures */
    private function reconcileCoupons(OrderStagePlan $plan, array $rows, array $targetMap, array &$failures): void
    {
        if (count($rows) !== count($plan->money->coupons)) {
            $failures[] = 'coupon_cardinality_mismatch';
        }
        foreach ($plan->record->couponLines as $index => $source) {
            $row = $this->rowById($rows, $targetMap[$source->identity->canonical()] ?? 0);
            $expected = $plan->money->coupons[$index]->row;
            unset($expected['source_identity'], $expected['source_discount_tax']);
            if ($row === null || !$this->same(
                $expected,
                $this->without($row, ['id', 'order_id', 'created_at', 'updated_at']),
            )) {
                $failures[] = 'coupon_row_mismatch';
            }
        }
    }

    /** @param list<array<string,mixed>> $rows @param array<string,int> $targetMap @param list<string> $failures */
    private function reconcileTaxRates(OrderStagePlan $plan, array $rows, array $targetMap, array &$failures): void
    {
        if (count($rows) !== count($plan->money->taxRates)) {
            $failures[] = 'tax_rate_cardinality_mismatch';
        }
        foreach ($plan->money->taxRates as $index => $projection) {
            $source = $plan->record->taxRates[$index] ?? null;
            $row = $source instanceof TaxRateRecord
                ? $this->rowById($rows, $targetMap[$source->identity->canonical()] ?? 0)
                : ($rows[$index] ?? null);
            if ($row === null || !$this->same(
                $projection->row,
                $this->without($row, ['id', 'order_id', 'filed_at', 'created_at', 'updated_at']),
            )) {
                $failures[] = 'tax_row_mismatch';
            }
        }
    }

    /** @param list<array<string,mixed>> $rows @param array<string,int> $targetMap @param list<string> $failures */
    private function reconcileTransactions(OrderStagePlan $plan, array $rows, array $targetMap, array &$failures): void
    {
        $chargeIds = [];
        foreach ($plan->paymentGraph->charges as $charge) {
            if ($charge->status === 'succeeded') {
                $chargeIds[$charge->identity->canonical()] = $targetMap[$charge->identity->canonical()] ?? 0;
            }
        }
        try {
            $projection = $plan->paymentProjection($chargeIds);
        } catch (\Throwable) {
            $failures[] = 'payment_graph_unreadable';
            return;
        }
        $expected = [];
        foreach ([...$projection->charges, ...$projection->refunds] as $row) {
            $identity = (string) ($row['meta']['cartshift_source_payment']['source_event_identity'] ?? '');
            if (($row['status'] ?? null) === 'succeeded' || ($row['status'] ?? null) === 'refunded') {
                $expected[$identity] = $row;
            }
        }
        if (count($rows) !== count($expected)) {
            $failures[] = 'payment_cardinality_mismatch';
        }
        $seen = [];
        foreach ($rows as $row) {
            $identity = (string) ($row['meta']['cartshift_source_payment']['source_event_identity'] ?? '');
            if ($identity === '' || !isset($expected[$identity]) || isset($seen[$identity])) {
                $failures[] = 'payment_source_identity_mismatch';
                continue;
            }
            $seen[$identity] = true;
            if (($targetMap[$identity] ?? null) !== (int) ($row['id'] ?? 0)) {
                $failures[] = 'checked_map_target_mismatch';
            }
            $actual = $this->without($row, ['id', 'order_id', 'subscription_id', 'uuid', 'updated_at']);
            $actual = $this->without($actual, ['rate']);
            if (!$this->same($expected[$identity], $actual)) {
                $failures[] = 'payment_graph_mismatch';
            }
        }
        if (count($seen) !== count($expected)) {
            $failures[] = 'payment_source_identity_mismatch';
        }
    }

    /** @param list<array<string,mixed>> $rows @param list<string> $failures */
    private function reconcileMeta(OrderStagePlan $plan, array $rows, array &$failures): int
    {
        $provenanceId = 0;
        $businessRows = [];
        foreach ($rows as $row) {
            if (($row['meta_key'] ?? null) === 'cartshift_order_provenance') {
                if ($provenanceId !== 0 || !$this->same($plan->provenance, $row['meta_value'] ?? null)) {
                    $failures[] = 'order_provenance_mismatch';
                }
                $provenanceId = (int) ($row['id'] ?? 0);
            } elseif (($row['meta_key'] ?? null) === 'business_info') {
                $businessRows[] = [
                    'meta_key' => 'business_info',
                    'meta_value' => $row['meta_value'] ?? null,
                ];
            } else {
                $failures[] = 'unexpected_order_meta';
            }
        }
        if ($provenanceId <= 0 || !$this->same($plan->metadata->metaRows, $businessRows)) {
            $failures[] = 'order_meta_mismatch';
        }
        return $provenanceId;
    }

    /** @param array<string,int> $targetMap @param list<string> $failures */
    private function reconcileProvenanceMappings(OrderStagePlan $plan, array $targetMap, int $provenanceId, array &$failures): void
    {
        $direct = [$plan->record->identity->canonical() => true];
        foreach ([$plan->record->productLines, $plan->record->feeLines, $plan->record->couponLines,
            $plan->record->taxRates, $plan->record->paymentEvents] as $records) {
            foreach ($records as $record) {
                if (!$record instanceof PaymentEventRecord || $record->status === 'succeeded') {
                    $direct[$record->identity->canonical()] = true;
                }
            }
        }
        foreach ($plan->addresses as $entry) {
            $direct[$entry['source']->identity->canonical()] = true;
        }
        foreach ($plan->sourceIdentities() as $identity) {
            $canonical = $identity->canonical();
            if (!isset($direct[$canonical]) && ($targetMap[$canonical] ?? null) !== $provenanceId) {
                $failures[] = 'provenance_checked_map_mismatch';
            }
        }
    }

    /** @param array<string,mixed> $snapshot @param list<string> $failures */
    private function reconcileMoney(OrderStagePlan $plan, array $snapshot, array &$failures): void
    {
        try {
            $order = (array) $snapshot['order'];
            $header = [];
            foreach (array_keys($plan->money->header) as $field) {
                $header[$field] = $order[$field] ?? null;
            }
            $productRows = [];
            $feeRows = [];
            foreach ($this->rows($snapshot, 'items') as $row) {
                $row = $this->without($row, ['id', 'order_id', 'updated_at']);
                if (($row['payment_type'] ?? null) === 'fee') {
                    $feeRows[] = new FeeProjection($row);
                } else {
                    $productRows[] = $row;
                }
            }
            $coupons = array_map(
                fn (array $row): CouponProjection => new CouponProjection(
                    $this->without($row, ['id', 'order_id', 'created_at', 'updated_at']),
                ),
                $this->rows($snapshot, 'coupons'),
            );
            $taxRates = array_map(
                fn (array $row): TaxProjection => new TaxProjection(
                    $this->without($row, ['id', 'order_id', 'filed_at', 'created_at', 'updated_at']),
                ),
                $this->rows($snapshot, 'tax_rates'),
            );
            $provenance = [];
            foreach ($this->rows($snapshot, 'meta') as $row) {
                if (($row['meta_key'] ?? null) === 'cartshift_order_provenance') {
                    $provenance = (array) ($row['meta_value'] ?? []);
                }
            }
            $target = new OrderMoneyProjection(
                $header,
                $productRows,
                $feeRows,
                $coupons,
                $taxRates,
                (array) ($provenance['shipping_rows'] ?? []),
                $plan->money->taxRoundingAtSubtotal,
            );
            $money = $this->moneyContract->reconcile($plan->record, $target);
            array_push($failures, ...$money->failures);
        } catch (\Throwable) {
            $failures[] = 'order_money_target_unreadable';
        }
    }

    /** @param array<string,mixed> $snapshot @return list<array<string,mixed>> */
    private function rows(array $snapshot, string $key): array
    {
        return array_values(array_filter((array) ($snapshot[$key] ?? []), 'is_array'));
    }

    /** @param list<array<string,mixed>> $rows */
    private function rowById(array $rows, int $id): ?array
    {
        foreach ($rows as $row) {
            if ((int) ($row['id'] ?? 0) === $id && $id > 0) {
                return $row;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $row @param list<string> $keys @return array<string,mixed> */
    private function without(array $row, array $keys): array
    {
        foreach ($keys as $key) {
            unset($row[$key]);
        }
        return $row;
    }

    private function same(mixed $expected, mixed $actual): bool
    {
        return is_array($expected) && is_array($actual)
            ? hash_equals(CanonicalJson::fingerprint($expected), CanonicalJson::fingerprint($actual))
            : $expected === $actual;
    }

    /** @param list<string> $failures */
    private function result(string $fingerprint, array $failures): ReconciliationResult
    {
        $failures = array_values(array_unique($failures));
        return new ReconciliationResult($failures === [], $fingerprint, $failures);
    }
}

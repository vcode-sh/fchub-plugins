<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Support\CanonicalJson;
use CartShift\Support\DatabaseTransaction;

defined('ABSPATH') || exit;

/** Replaces suppressed customer lifecycle recounts with a checked complete-set projection. */
final readonly class CustomerAggregateProjector
{
    private const array SUCCESS_STATUSES = ['paid', 'partially_refunded', 'partially_paid'];

    public function __construct(private CustomerAggregateGateway $gateway) {}

    public function projectCompleteSet(
        SourceIdentity $sourceCustomer,
        int $targetCustomerId,
        string $runId,
        int $generation = 1,
    ): CustomerAggregateResult {
        if ($sourceCustomer->entityType !== 'customer' || $targetCustomerId <= 0
            || preg_match('/\A[a-z0-9][a-z0-9-]{2,35}\z/D', $runId) !== 1 || $generation <= 0) {
            throw new \InvalidArgumentException('Customer aggregate projection identity is invalid.');
        }
        if (!$this->gateway->customerExists($targetCustomerId)) {
            throw new \RuntimeException('customer_aggregate_target_missing');
        }
        $orders = $this->gateway->orders($targetCustomerId);
        $sourceFingerprint = CanonicalJson::fingerprint([
            'target_customer_id' => $targetCustomerId,
            'complete_target_orders' => $orders,
        ]);
        $expected = self::calculate($orders);
        $receipt = $this->gateway->receipt($sourceCustomer, $runId, $generation);
        if ($receipt !== null) {
            if (!hash_equals($sourceFingerprint, (string) ($receipt['source_fingerprint'] ?? ''))) {
                throw new \RuntimeException('customer_aggregate_receipt_stale');
            }
            $actual = $this->gateway->snapshot($targetCustomerId);
            $targetFingerprint = CanonicalJson::fingerprint($actual);
            if (!hash_equals($targetFingerprint, (string) ($receipt['target_fingerprint'] ?? ''))
                || !$this->same($expected, $actual)
                || !$this->same($expected, $this->gateway->independentProjection($targetCustomerId))) {
                throw new \RuntimeException('customer_aggregate_target_drift');
            }
            return new CustomerAggregateResult($targetCustomerId, $sourceFingerprint, $targetFingerprint, true);
        }

        DatabaseTransaction::begin();
        try {
            $beforeHash = CanonicalJson::fingerprint($this->gateway->snapshot($targetCustomerId));
            $this->gateway->write($targetCustomerId, $expected);
            $actual = $this->gateway->snapshot($targetCustomerId);
            $independent = $this->gateway->independentProjection($targetCustomerId);
            if (!$this->same($expected, $actual) || !$this->same($expected, $independent)) {
                throw new \RuntimeException('customer_aggregate_reconciliation_failed');
            }
            $targetFingerprint = CanonicalJson::fingerprint($actual);
            $this->gateway->storeReceipt([
                'run_id' => $runId,
                // Customer staging already owns the `customer` journal key for
                // this run/source/generation. This later complete-set operation
                // needs an independently replayable receipt.
                'record_kind' => 'customer_aggregate',
                'source_identity' => $sourceCustomer->canonical(),
                'generation' => $generation,
                'source_fingerprint' => $sourceFingerprint,
                'target_fingerprint' => $targetFingerprint,
                'action' => 'customer_aggregate',
                'state' => 'reconciled',
                'target_ids' => ['customer_id' => $targetCustomerId],
                'before_hash' => $beforeHash,
                'after_hash' => $targetFingerprint,
                'error_code' => null,
            ]);
            $stored = $this->gateway->receipt($sourceCustomer, $runId, $generation);
            if ($stored === null
                || !hash_equals($sourceFingerprint, (string) ($stored['source_fingerprint'] ?? ''))
                || !hash_equals($targetFingerprint, (string) ($stored['target_fingerprint'] ?? ''))) {
                throw new \RuntimeException('customer_aggregate_receipt_write_failed');
            }
            DatabaseTransaction::commit();
            return new CustomerAggregateResult($targetCustomerId, $sourceFingerprint, $targetFingerprint, false);
        } catch (\Throwable $exception) {
            DatabaseTransaction::rollback($exception);
            throw $exception;
        }
    }

    /**
     * @param list<array{status:string,currency:string,paid:int,refund:int,rate:int,created_at:string}> $orders
     * @return array{purchase_value:array<string,int>,purchase_count:int,ltv:int,aov:float,first_purchase_date:?string,last_purchase_date:?string}
     */
    public static function calculate(array $orders): array
    {
        if (!array_is_list($orders)) {
            throw new \InvalidArgumentException('Customer aggregate orders must be a complete ordered list.');
        }
        $purchaseValue = [];
        $count = 0;
        $ltv = 0;
        $first = null;
        $last = null;
        foreach ($orders as $order) {
            $status = (string) ($order['status'] ?? '');
            $currency = strtoupper((string) ($order['currency'] ?? ''));
            $paid = $order['paid'] ?? null;
            $refund = $order['refund'] ?? null;
            $rate = $order['rate'] ?? null;
            $created = (string) ($order['created_at'] ?? '');
            if ($currency === '' || !is_int($paid) || !is_int($refund) || !is_int($rate)
                || $paid < 0 || $refund < 0 || $refund > $paid || $rate <= 0 || $created === '') {
                throw new \RuntimeException('customer_aggregate_order_unrepresentable');
            }
            if (!in_array($status, self::SUCCESS_STATUSES, true)) {
                continue;
            }
            ++$count;
            $first = $first === null || $created < $first ? $created : $first;
            $last = $last === null || $created > $last ? $created : $last;
            $net = $paid - $refund;
            if ($net <= 0) {
                continue;
            }
            $purchaseValue[$currency] = ($purchaseValue[$currency] ?? 0) + $net;
            $ltv += $net * $rate;
        }
        ksort($purchaseValue, SORT_STRING);
        return [
            'purchase_value' => $purchaseValue,
            'purchase_count' => $count,
            'ltv' => $ltv,
            'aov' => $count === 0 ? 0.0 : round($ltv / $count, 2),
            'first_purchase_date' => $first,
            'last_purchase_date' => $last,
        ];
    }

    private function same(array $expected, array $actual): bool
    {
        return hash_equals(CanonicalJson::fingerprint($expected), CanonicalJson::fingerprint($actual));
    }
}

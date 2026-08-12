<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

use CartShift\Domain\Transfer\Identity\CheckedMappingStore;
use CartShift\Domain\Transfer\Identity\MapState;
use CartShift\Domain\Transfer\Identity\TargetClaimStore;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\StageContext;
use CartShift\Support\DatabaseTransaction;

defined('ABSPATH') || exit;

final class FluentCartOrderWriter implements OrderStageWriter
{
    public function __construct(
        private readonly OrderTargetGateway $gateway,
        private readonly CheckedMappingStore $maps,
        private readonly OrderReconciler $reconciler,
        private readonly TargetClaimStore $claims,
        private readonly OrderTargetFingerprint $targetFingerprint = new OrderTargetFingerprint(),
    ) {
    }

    public function stage(OrderStagePlan $plan, StageContext $context): OrderStageResult
    {
        $this->assertDependencyMappings($plan);
        $existing = $this->maps->get($plan->record->identity);
        DatabaseTransaction::begin();
        try {
            if ($existing !== null) {
                if (!$existing->isActive() || !$this->gateway->exists($existing->targetId)) {
                    throw new \RuntimeException('target_reconciliation_failed: mapped order is absent or inactive');
                }
                $targetMap = $this->targetMapFromMappings($plan);
                $reconciliation = $this->reconciler->reconcile(
                    $plan,
                    $existing->targetId,
                    (string) $existing->targetFingerprint,
                );
                if (!$reconciliation->matches) {
                    throw new \RuntimeException('target_reconciliation_failed: ' . implode(',', $reconciliation->failures));
                }
                $this->claims->claimOrThrow(
                    $plan->record->identity,
                    $existing->targetId,
                    $context->migrationId,
                    $plan->sourceFingerprint($plan->record->identity),
                    $reconciliation->actualFingerprint,
                    MapState::Reconciled,
                );
                DatabaseTransaction::commit();
                return new OrderStageResult(
                    $existing->targetId,
                    $targetMap,
                    $reconciliation->actualFingerprint,
                    true,
                );
            }

            $orderId = $this->positive($this->gateway->createOrder($plan->header), 'order header');
            $targetMap = [];

            foreach ($plan->record->productLines as $index => $line) {
                $row = $plan->money->productItems[$index];
                $targetMap[$line->identity->canonical()] = $this->positive(
                    $this->gateway->createItem($orderId, $row),
                    'product item',
                );
            }
            foreach ($plan->record->feeLines as $index => $fee) {
                $targetMap[$fee->identity->canonical()] = $this->positive(
                    $this->gateway->createItem($orderId, $plan->money->fees[$index]->row),
                    'fee item',
                );
            }

            foreach ($plan->addresses as $entry) {
                $row = $entry['projection']->row;
                unset($row['source_identity']);
                $targetMap[$entry['source']->identity->canonical()] = $this->positive(
                    $this->gateway->createAddress($orderId, $row),
                    'order address',
                );
            }
            foreach ($plan->record->couponLines as $index => $coupon) {
                $row = $plan->money->coupons[$index]->row;
                unset($row['source_identity'], $row['source_discount_tax']);
                $targetMap[$coupon->identity->canonical()] = $this->positive(
                    $this->gateway->createCoupon($orderId, $row),
                    'applied coupon',
                );
            }
            foreach ($plan->money->taxRates as $index => $tax) {
                $targetId = $this->positive(
                    $this->gateway->createTaxRate($orderId, $tax->row),
                    'order tax rate',
                );
                if (isset($plan->record->taxRates[$index])) {
                    $targetMap[$plan->record->taxRates[$index]->identity->canonical()] = $targetId;
                }
            }

            $chargeIds = [];
            $placeholderChargeIds = [];
            foreach ($plan->paymentGraph->charges as $index => $charge) {
                $placeholderChargeIds[$charge->identity->canonical()] = $index + 1;
            }
            $payment = $plan->paymentProjection($placeholderChargeIds);
            foreach ($payment->charges as $row) {
                if (($row['status'] ?? null) !== 'succeeded') {
                    continue;
                }
                $identity = $this->paymentIdentity($row);
                $row = $this->transactionFields($row, $identity, $plan);
                $targetId = $this->positive($this->gateway->createTransaction($orderId, $row), 'historical charge');
                $chargeIds[$identity] = $targetId;
                $targetMap[$identity] = $targetId;
            }
            $payment = $plan->paymentProjection($chargeIds);
            foreach ($payment->refunds as $row) {
                if (($row['status'] ?? null) !== 'refunded') {
                    continue;
                }
                $identity = $this->paymentIdentity($row);
                $row = $this->transactionFields($row, $identity, $plan);
                $targetId = $this->positive($this->gateway->createTransaction($orderId, $row), 'historical refund');
                $targetMap[$identity] = $targetId;
            }

            foreach ($plan->metadata->metaRows as $row) {
                $this->positive($this->gateway->createMeta($orderId, $row), 'approved order meta');
            }
            $provenanceId = $this->positive($this->gateway->createMeta($orderId, [
                'meta_key' => 'cartshift_order_provenance',
                'meta_value' => $plan->provenance,
            ]), 'order provenance');

            foreach ($plan->sourceIdentities() as $identity) {
                $canonical = $identity->canonical();
                if ($canonical === $plan->record->identity->canonical()) {
                    $targetMap[$canonical] = $orderId;
                    continue;
                }
                $targetMap[$canonical] ??= $provenanceId;
            }
            $fingerprint = $this->targetFingerprint->fingerprint($this->gateway->snapshot($orderId), $targetMap);

            foreach ($plan->sourceIdentities() as $identity) {
                $this->maps->storeOrThrow(
                    $identity,
                    $targetMap[$identity->canonical()],
                    $context->migrationId,
                    $plan->sourceFingerprint($identity),
                    $fingerprint,
                    MapState::Staged,
                    true,
                    $context->generation,
                );
            }
            $reconciliation = $this->reconciler->reconcile($plan, $orderId, $fingerprint);
            if (!$reconciliation->matches) {
                throw new \RuntimeException('target_reconciliation_failed: ' . implode(',', $reconciliation->failures));
            }
            $this->claims->claimOrThrow(
                $plan->record->identity,
                $orderId,
                $context->migrationId,
                $plan->sourceFingerprint($plan->record->identity),
                $reconciliation->actualFingerprint,
                MapState::Reconciled,
            );
            foreach ($plan->sourceIdentities() as $identity) {
                $this->maps->transitionOrThrow(
                    $identity,
                    MapState::Staged,
                    MapState::Reconciled,
                    $fingerprint,
                    $reconciliation->actualFingerprint,
                );
            }
            DatabaseTransaction::commit();
            return new OrderStageResult($orderId, $targetMap, $reconciliation->actualFingerprint, false);
        } catch (\Throwable $exception) {
            DatabaseTransaction::rollback($exception);
            throw $exception;
        }
    }

    private function assertDependencyMappings(OrderStagePlan $plan): void
    {
        if ($plan->record->customer !== null) {
            $this->assertDependency($plan->record->customer, $plan->customerTargetId, 'customer');
        }
        if ($plan->record->parentOrder !== null) {
            $this->assertDependency($plan->record->parentOrder, $plan->parentTargetId, 'parent_order');
        }
        foreach ($plan->record->productLines as $line) {
            $target = $plan->projectionContext->productTargets[$line->identity->canonical()] ?? null;
            if (!is_array($target)) {
                throw new \RuntimeException('order_dependency_mapping_missing: product');
            }
            $this->assertDependency($line->product, (int) $target['post_id'], 'product');
            $this->assertDependency($line->variation, (int) $target['object_id'], 'variation');
        }
    }

    private function assertDependency(SourceIdentity $identity, ?int $targetId, string $label): void
    {
        $mapping = $this->maps->get($identity);
        if ($mapping === null || !$mapping->isActive() || $mapping->targetId !== $targetId) {
            throw new \RuntimeException('order_dependency_mapping_missing: ' . $label);
        }
    }

    /** @return array<string, int> */
    private function targetMapFromMappings(OrderStagePlan $plan): array
    {
        $map = [];
        foreach ($plan->sourceIdentities() as $identity) {
            $mapping = $this->maps->get($identity);
            if ($mapping === null || !$mapping->isActive()) {
                throw new \RuntimeException('target_reconciliation_failed: checked_map_missing');
            }
            $map[$identity->canonical()] = $mapping->targetId;
        }
        return $map;
    }

    /** @param array<string, mixed> $row */
    private function paymentIdentity(array $row): string
    {
        $identity = (string) ($row['meta']['cartshift_source_payment']['source_event_identity'] ?? '');
        if ($identity === '') {
            throw new \RuntimeException('target_write_failed: payment source identity');
        }
        return $identity;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function transactionFields(array $row, string $identity, OrderStagePlan $plan): array
    {
        return $row + [
            'subscription_id' => null,
            'rate' => (int) $plan->money->header['rate'],
            'uuid' => md5('cartshift-order-transaction:' . $identity),
        ];
    }

    private function positive(int $id, string $label): int
    {
        if ($id <= 0) {
            throw new \RuntimeException('target_write_failed: ' . $label);
        }
        return $id;
    }
}

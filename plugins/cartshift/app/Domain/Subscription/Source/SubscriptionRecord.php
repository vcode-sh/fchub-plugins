<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription\Source;

use CartShift\Domain\Subscription\SubscriptionOrderReference;
use CartShift\Domain\Subscription\SubscriptionRecord as V1SubscriptionRecord;
use CartShift\Domain\Subscription\SubscriptionRecordFactory;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\RecordKind;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Support\UtcDateTime;

defined('ABSPATH') || exit;

/** Canonical v2 subscription package record, derived from the proven v1 contract. */
final readonly class SubscriptionRecord
{
    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed> $contract
     * @param list<array{identity: string, relationship: string}> $relatedOrders
     * @param array<string, string|null> $schedule
     * @param array<string, mixed> $paymentOwnership
     * @param list<string> $dependencies
     */
    public function __construct(
        public SourceIdentity $identity,
        public SourceIdentity $customerIdentity,
        public string $status,
        public string $currency,
        public array $items,
        public array $contract,
        public array $relatedOrders,
        public array $schedule,
        public array $paymentOwnership,
        public array $dependencies,
    ) {
        $this->assertValid();
    }

    public static function fromV1(V1SubscriptionRecord $record, SourceIdentity $customerIdentity): self
    {
        if ($record->sourceKey !== $customerIdentity->sourceKey
            || $customerIdentity->kind() !== RecordKind::Customer) {
            throw new \InvalidArgumentException('subscription_customer_identity_invalid');
        }
        $identity = new SourceIdentity($record->sourceKey, RecordKind::Subscription->value, (string) $record->sourceSubscriptionId);
        $items = array_map(static function (array $item) use ($record): array {
            $productId = (int) ($item['source_product_id'] ?? 0);
            $variationId = (int) ($item['source_variation_id'] ?? 0);
            $product = new SourceIdentity($record->sourceKey, RecordKind::Product->value, (string) $productId);
            $variation = new SourceIdentity(
                $record->sourceKey,
                RecordKind::Product->value,
                $productId . ':variation:' . ($variationId > 0 ? $variationId : $productId),
            );
            return [
                'source_item_id' => (int) ($item['source_item_id'] ?? 0),
                'product_identity' => $product->canonical(),
                'variation_identity' => $variation->canonical(),
                'name' => (string) ($item['name'] ?? ''),
                'quantity' => (int) ($item['quantity'] ?? 0),
                'line_total' => (int) ($item['line_total'] ?? 0),
                'line_tax' => (int) ($item['line_tax'] ?? 0),
            ];
        }, $record->items);

        $relatedOrders = array_map(static fn (SubscriptionOrderReference $reference): array => [
            'identity' => (new SourceIdentity(
                $record->sourceKey,
                RecordKind::Order->value,
                (string) $reference->sourceOrderId,
            ))->canonical(),
            'relationship' => $reference->relationship,
        ], $record->relatedOrders);
        usort($relatedOrders, static function (array $left, array $right): int {
            $order = array_flip(SubscriptionOrderReference::RELATIONSHIPS);
            return ($order[$left['relationship']] <=> $order[$right['relationship']])
                ?: strnatcmp($left['identity'], $right['identity']);
        });

        $paymentReferences = $record->paymentReferences;
        ksort($paymentReferences, SORT_STRING);
        $schedule = array_map(
            static fn (?string $value): ?string => $value === null ? null : UtcDateTime::canonical($value),
            $record->dates->toArray(),
        );
        ksort($schedule, SORT_STRING);

        $dependencies = [$customerIdentity->canonical()];
        foreach ($items as $item) {
            $dependencies[] = $item['product_identity'];
        }
        foreach ($relatedOrders as $reference) {
            $dependencies[] = $reference['identity'];
        }
        $dependencies = array_values(array_unique($dependencies));
        sort($dependencies, SORT_STRING);

        return new self(
            $identity,
            $customerIdentity,
            $record->status,
            strtoupper($record->currency),
            $items,
            $record->contract->toArray(),
            $relatedOrders,
            $schedule,
            [
                'ownership_state' => 'unassessed',
                'source_gateway' => $record->gateway,
                'source_requires_manual_renewal' => $record->requiresManualRenewal,
                'payment_references' => $paymentReferences,
                'payment_reference_digest' => SubscriptionRecordFactory::digest($paymentReferences),
                'source_payment_count' => $record->sourcePaymentCount,
            ],
            $dependencies,
        );
    }

    public function envelope(int $schemaVersion = 1): RecordEnvelope
    {
        return RecordEnvelope::forPayload($schemaVersion, $this->identity, $this->toArray());
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'identity' => $this->identity->canonical(),
            'customer_identity' => $this->customerIdentity->canonical(),
            'status' => $this->status,
            'currency' => $this->currency,
            'items' => $this->items,
            'contract' => $this->contract,
            'related_orders' => $this->relatedOrders,
            'schedule' => $this->schedule,
            'payment_ownership' => $this->paymentOwnership,
            'dependencies' => $this->dependencies,
        ];
    }

    private function assertValid(): void
    {
        if ($this->identity->kind() !== RecordKind::Subscription
            || $this->customerIdentity->kind() !== RecordKind::Customer
            || $this->identity->sourceKey !== $this->customerIdentity->sourceKey
            || $this->status === '' || preg_match('/\A[A-Z]{3}\z/D', $this->currency) !== 1) {
            throw new \InvalidArgumentException('subscription_record_identity_invalid');
        }
        if (!array_is_list($this->items) || $this->items === []) {
            throw new \InvalidArgumentException('subscription_items_missing');
        }
        foreach ($this->items as $item) {
            $keys = array_keys($item);
            sort($keys, SORT_STRING);
            if ($keys !== ['line_tax', 'line_total', 'name', 'product_identity', 'quantity', 'source_item_id', 'variation_identity']
                || !is_int($item['source_item_id']) || $item['source_item_id'] <= 0
                || !is_int($item['quantity']) || $item['quantity'] <= 0
                || !is_int($item['line_total']) || !is_int($item['line_tax'])
                || $item['line_total'] < 0 || $item['line_tax'] < 0 || trim((string) $item['name']) === '') {
                throw new \InvalidArgumentException('subscription_item_invalid');
            }
            $product = SourceIdentity::fromCanonical((string) $item['product_identity']);
            $variation = SourceIdentity::fromCanonical((string) $item['variation_identity']);
            if ($product->kind() !== RecordKind::Product || $variation->kind() !== RecordKind::Product
                || $product->sourceKey !== $this->identity->sourceKey || $variation->sourceKey !== $this->identity->sourceKey
                || !str_starts_with($variation->sourceId, $product->sourceId . ':variation:')) {
                throw new \InvalidArgumentException('subscription_product_identity_invalid');
            }
        }
        $requiredContract = ['finite_cycles', 'multiplier', 'period', 'recurring_amount', 'recurring_tax', 'recurring_total', 'setup_fee', 'source_plan', 'target_interval', 'trial_length', 'trial_period'];
        $contractKeys = array_keys($this->contract);
        sort($contractKeys, SORT_STRING);
        if ($contractKeys !== $requiredContract
            || !is_int($this->contract['multiplier']) || $this->contract['multiplier'] <= 0
            || !is_string($this->contract['period']) || $this->contract['period'] === ''
            || !is_string($this->contract['target_interval']) || $this->contract['target_interval'] === ''
            || !is_int($this->contract['recurring_amount']) || !is_int($this->contract['recurring_tax'])
            || !is_int($this->contract['recurring_total']) || !is_int($this->contract['setup_fee'])
            || min($this->contract['recurring_amount'], $this->contract['recurring_tax'], $this->contract['recurring_total'], $this->contract['setup_fee']) < 0
            || $this->contract['recurring_amount'] + $this->contract['recurring_tax'] !== $this->contract['recurring_total']
            || !is_int($this->contract['trial_length']) || $this->contract['trial_length'] < 0
            || ($this->contract['finite_cycles'] !== null && (!is_int($this->contract['finite_cycles']) || $this->contract['finite_cycles'] <= 0))
            || !is_array($this->contract['source_plan'])) {
            throw new \InvalidArgumentException('subscription_contract_invalid');
        }
        $seenOrders = [];
        $parentFound = false;
        foreach ($this->relatedOrders as $reference) {
            if (!is_array($reference) || array_keys($reference) !== ['identity', 'relationship']
                || !in_array($reference['relationship'] ?? null, SubscriptionOrderReference::RELATIONSHIPS, true)) {
                throw new \InvalidArgumentException('subscription_order_relationship_invalid');
            }
            $order = SourceIdentity::fromCanonical((string) $reference['identity']);
            if ($order->kind() !== RecordKind::Order || $order->sourceKey !== $this->identity->sourceKey || isset($seenOrders[$order->canonical()])) {
                throw new \InvalidArgumentException('subscription_order_relationship_ambiguous');
            }
            $seenOrders[$order->canonical()] = true;
            if ($reference['relationship'] === SubscriptionOrderReference::PARENT) {
                $parentFound = true;
            }
        }
        if (!$parentFound) {
            throw new \InvalidArgumentException('subscription_parent_relationship_missing');
        }
        if (array_keys($this->schedule) !== ['cancelled_utc', 'end_utc', 'next_payment_utc', 'start_utc', 'trial_end_utc']
            || $this->schedule['start_utc'] === null) {
            throw new \InvalidArgumentException('subscription_schedule_invalid');
        }
        foreach ($this->schedule as $value) {
            if ($value !== null && UtcDateTime::canonical($value) !== $value) {
                throw new \InvalidArgumentException('subscription_schedule_invalid');
            }
        }
        $paymentKeys = array_keys($this->paymentOwnership);
        sort($paymentKeys, SORT_STRING);
        if ($paymentKeys !== ['ownership_state', 'payment_reference_digest', 'payment_references', 'source_gateway', 'source_payment_count', 'source_requires_manual_renewal']
            || $this->paymentOwnership['ownership_state'] !== 'unassessed'
            || !is_array($this->paymentOwnership['payment_references'])
            || !is_int($this->paymentOwnership['source_payment_count']) || $this->paymentOwnership['source_payment_count'] < 0
            || !is_bool($this->paymentOwnership['source_requires_manual_renewal'])
            || !hash_equals(
                SubscriptionRecordFactory::digest($this->paymentOwnership['payment_references']),
                (string) $this->paymentOwnership['payment_reference_digest'],
            )) {
            throw new \InvalidArgumentException('subscription_payment_provenance_invalid');
        }
        if (!array_is_list($this->dependencies) || $this->dependencies !== array_values(array_unique($this->dependencies))) {
            throw new \InvalidArgumentException('subscription_dependencies_invalid');
        }
        $sorted = $this->dependencies;
        sort($sorted, SORT_STRING);
        if ($sorted !== $this->dependencies || !in_array($this->customerIdentity->canonical(), $this->dependencies, true)) {
            throw new \InvalidArgumentException('subscription_dependencies_invalid');
        }
        foreach ($this->dependencies as $dependency) {
            SourceIdentity::fromCanonical($dependency);
        }
    }
}

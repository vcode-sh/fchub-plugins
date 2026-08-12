<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Support\CanonicalJson;
use CartShift\Support\UtcDateTime;

defined('ABSPATH') || exit;

final readonly class OrderStagePlan
{
    /**
     * @param list<array{source: AddressRecord, projection: AddressProjection}> $addresses
     * @param array<string, mixed> $header
     * @param array<string, mixed> $provenance
     */
    private function __construct(
        public OrderRecord $record,
        public OrderProjectionContext $projectionContext,
        public ?int $customerTargetId,
        public ?int $parentTargetId,
        public OrderStatusProjection $status,
        public FulfilmentProjection $fulfilment,
        public OrderMoneyProjection $money,
        public PaymentGraph $paymentGraph,
        public array $addresses,
        public OrderMetadataProjection $metadata,
        public array $header,
        public array $provenance,
        private PaymentGraphProjector $paymentProjector,
    ) {
    }

    public static function build(
        OrderRecord $record,
        OrderProjectionContext $projectionContext,
        ?int $customerTargetId,
        ?int $parentTargetId = null,
        ?OrderStatusPolicy $statusPolicy = null,
        ?FulfilmentPolicy $fulfilmentPolicy = null,
        ?SourceIdentity $canonicalCustomerNote = null,
        ?string $noteDecisionFingerprint = null,
        ?HistoricalPaymentPolicy $paymentPolicy = null,
    ): self {
        self::assertTargetReferences($record, $customerTargetId, $parentTargetId);
        $status = ($statusPolicy ?? new OrderStatusPolicy())->project($record->sourceStatus);
        $fulfilment = ($fulfilmentPolicy ?? new FulfilmentPolicy())->project($record);
        $orderCreated = UtcDateTime::targetFromCanonical($record->createdUtc);
        $moneyContract = new FluentCartOrderMoneyContract();
        $money = $moneyContract->project($record, $projectionContext);
        $moneyItems = [];
        $paymentType = $record->relationshipType === 'checkout' ? 'onetime' : 'subscription';
        foreach ($money->productItems as $row) {
            $identity = (string) ($row['other_info']['source_identity'] ?? '');
            $row['fulfilled_quantity'] = $fulfilment->fulfilledQuantities[$identity]
                ?? throw new SourceRecordException('target_schema_unrepresentable', 'Fulfilment projection omitted an order line.');
            $row['payment_type'] = $paymentType;
            if (isset($row['created_at']) && is_string($row['created_at'])) {
                $row['created_at'] = UtcDateTime::targetFromCanonical($row['created_at']);
            }
            $moneyItems[] = $row;
        }
        $fees = [];
        foreach ($money->fees as $index => $fee) {
            $fees[] = new FeeProjection([
                ...$fee->row,
                'cart_index' => count($moneyItems) + $index,
                'created_at' => $orderCreated,
            ]);
        }
        $money = new OrderMoneyProjection(
            $money->header,
            $moneyItems,
            $fees,
            $money->coupons,
            $money->taxRates,
            $money->shippingRows,
            $money->taxRoundingAtSubtotal,
        );

        $addresses = [];
        $addressProjections = [];
        foreach ($record->addresses as $address) {
            $projection = AddressProjection::project($address);
            if ($projection === null) {
                continue;
            }
            $addresses[] = ['source' => $address, 'projection' => $projection];
            $addressProjections[] = $projection;
        }
        $metadata = OrderMetadataProjection::project($record, $addressProjections);
        $graph = (new PaymentGraphBuilder())->build($record->paymentEvents);
        if ($graph->grossPaid !== (int) $money->header['total_paid']
            || $graph->totalRefunded !== (int) $money->header['total_refund']) {
            throw new SourceRecordException('order_money_mismatch', 'Payment graph totals differ from the order-money projection.');
        }
        $paymentProjector = new PaymentGraphProjector($paymentPolicy ?? new HistoricalPaymentPolicy());
        $placeholderChargeIds = [];
        foreach ($graph->charges as $index => $charge) {
            $placeholderChargeIds[$charge->identity->canonical()] = $index + 1;
        }
        $payment = $paymentProjector->project(
            $graph,
            $placeholderChargeIds,
            self::targetOrderType($record->relationshipType),
            $projectionContext->paymentMode,
        );
        [$note, $noteProvenance] = self::notes($record, $canonicalCustomerNote, $noteDecisionFingerprint);
        $identityDigest = hash('sha256', $record->identity->canonical());
        $invoice = 'CS-' . strtoupper(substr($identityDigest, 0, 16));
        $uuid = strtoupper(substr($identityDigest, 0, 12));
        $created = $orderCreated;
        $updated = $record->modifiedUtc === null ? $created : UtcDateTime::targetFromCanonical($record->modifiedUtc);
        $header = [
            'status' => $status->orderStatus,
            'parent_id' => $parentTargetId,
            'receipt_number' => null,
            'invoice_no' => $invoice,
            'fulfillment_type' => $fulfilment->fulfilmentType,
            'type' => self::targetOrderType($record->relationshipType),
            'mode' => $projectionContext->paymentMode,
            'shipping_status' => $fulfilment->shippingStatus,
            'customer_id' => $customerTargetId,
            ...$money->header,
            'payment_status' => $payment->paymentStatus,
            'note' => $note,
            'ip_address' => '',
            'completed_at' => $record->completedUtc === null ? null : UtcDateTime::targetFromCanonical($record->completedUtc),
            'refunded_at' => $record->refundedUtc === null ? null : UtcDateTime::targetFromCanonical($record->refundedUtc),
            'uuid' => $uuid,
            'config' => [
                'cartshift_historical_transfer' => true,
                'source_identity_digest' => $identityDigest,
                'source_relationship_type' => $record->relationshipType,
                'exchange_rate_evidence' => $record->exchangeRateEvidence,
                ...$metadata->config,
            ],
            'created_at' => $created,
            'updated_at' => $updated,
        ];
        $provenance = [
            'schema' => 1,
            'source_identity' => $record->identity->canonical(),
            'source_record_digest' => $record->envelope()->privateContentDigest,
            'source_order_number_digest' => hash('sha256', $record->identity->sourceId),
            'approved_meta' => $record->approvedMeta,
            'shipping_rows' => $money->shippingRows,
            'shipping_provenance' => $metadata->shippingProvenance,
            'note_history' => $noteProvenance,
            'unpersisted_payment_events' => array_values(array_map(
                static fn (PaymentEventRecord $event): array => $event->toArray(),
                array_filter($record->paymentEvents, static fn (PaymentEventRecord $event): bool => $event->status !== 'succeeded'),
            )),
        ];

        return new self(
            $record,
            $projectionContext,
            $customerTargetId,
            $parentTargetId,
            $status,
            $fulfilment,
            $money,
            $graph,
            $addresses,
            $metadata,
            $header,
            $provenance,
            $paymentProjector,
        );
    }

    /** @param array<string, int> $chargeTargetIds */
    public function paymentProjection(array $chargeTargetIds): PaymentGraphProjection
    {
        return $this->paymentProjector->project(
            $this->paymentGraph,
            $chargeTargetIds,
            (string) $this->header['type'],
            $this->projectionContext->paymentMode,
        );
    }

    /** @return list<SourceIdentity> Owned order and child identities, dependencies excluded. */
    public function sourceIdentities(): array
    {
        $identities = [];
        foreach ([$this->record->productLines, $this->record->feeLines, $this->record->shippingLines,
            $this->record->couponLines, $this->record->taxRates, $this->record->addresses,
            $this->record->paymentEvents, $this->record->notes] as $records) {
            foreach ($records as $record) {
                $identities[$record->identity->canonical()] = $record->identity;
            }
        }
        $identities[$this->record->identity->canonical()] = $this->record->identity;
        return array_values($identities);
    }

    public function sourceFingerprint(SourceIdentity $identity): string
    {
        if ($identity->canonical() === $this->record->identity->canonical()) {
            return $this->record->envelope()->privateContentDigest;
        }
        foreach ([$this->record->productLines, $this->record->feeLines, $this->record->shippingLines,
            $this->record->couponLines, $this->record->taxRates, $this->record->addresses,
            $this->record->paymentEvents, $this->record->notes] as $records) {
            foreach ($records as $record) {
                if ($record->identity->canonical() === $identity->canonical()) {
                    return CanonicalJson::fingerprint($record->toArray());
                }
            }
        }
        throw new \OutOfBoundsException('Source identity is not owned by this order stage plan.');
    }

    private static function assertTargetReferences(OrderRecord $record, ?int $customerTargetId, ?int $parentTargetId): void
    {
        if (($record->customer === null) !== ($customerTargetId === null)) {
            throw new SourceRecordException('customer_mapping_required', 'Order customer reference has no exact target mapping.');
        }
        if ($customerTargetId !== null && $customerTargetId <= 0) {
            throw new SourceRecordException('customer_mapping_required', 'Order customer target ID is invalid.');
        }
        $requiresParent = !in_array($record->relationshipType, ['checkout', 'parent'], true);
        if ($requiresParent !== ($record->parentOrder !== null)
            || $requiresParent !== ($parentTargetId !== null)
            || ($parentTargetId !== null && $parentTargetId <= 0)) {
            throw new SourceRecordException('order_item_parent_missing', 'Typed order has no exact target parent order.');
        }
    }

    private static function targetOrderType(string $relationship): string
    {
        return match ($relationship) {
            'checkout' => 'checkout',
            'parent' => 'subscription',
            'renewal', 'switch', 'resubscribe' => 'renewal',
            default => throw new SourceRecordException('order_item_parent_missing', 'Order relationship type is unsupported.'),
        };
    }

    /** @return array{string, list<array<string, mixed>>} */
    private static function notes(
        OrderRecord $record,
        ?SourceIdentity $canonicalCustomerNote,
        ?string $decisionFingerprint,
    ): array {
        if ($record->notes === []) {
            if ($canonicalCustomerNote !== null || $decisionFingerprint !== null) {
                throw new \InvalidArgumentException('An empty note set cannot carry a note decision.');
            }
            return ['', []];
        }
        if (preg_match('/\A[a-f0-9]{64}\z/D', (string) $decisionFingerprint) !== 1
            || !hash_equals(self::noteDecisionFingerprint($record, $canonicalCustomerNote), (string) $decisionFingerprint)) {
            throw new SourceRecordException('target_schema_unrepresentable', 'Order-note history requires a fingerprint-bound visibility decision.');
        }
        $selected = null;
        $history = [];
        foreach ($record->notes as $note) {
            $history[] = $note->toArray();
            if ($canonicalCustomerNote !== null && $note->identity->canonical() === $canonicalCustomerNote->canonical()) {
                if (!$note->customerVisible || $selected !== null) {
                    throw new SourceRecordException('target_schema_unrepresentable', 'Canonical order note is not one unique customer-visible note.');
                }
                $selected = $note;
            }
        }
        if ($canonicalCustomerNote !== null && !$selected instanceof OrderNoteRecord) {
            throw new SourceRecordException('target_schema_unrepresentable', 'Canonical customer-note decision references no selected note.');
        }
        return [$selected?->content ?? '', [[
            'decision_fingerprint' => $decisionFingerprint,
            'canonical_customer_note' => $selected?->identity->canonical(),
            'records' => $history,
        ]]];
    }

    public static function noteDecisionFingerprint(
        OrderRecord $record,
        ?SourceIdentity $canonicalCustomerNote,
    ): string {
        return CanonicalJson::fingerprint([
            'source_record_digest' => $record->envelope()->privateContentDigest,
            'canonical_customer_note' => $canonicalCustomerNote?->canonical(),
            'note_visibility' => array_map(static fn (OrderNoteRecord $note): array => [
                'identity' => $note->identity->canonical(),
                'customer_visible' => $note->customerVisible,
                'public_identifier' => $note->publicIdentifier,
            ], $record->notes),
        ]);
    }
}

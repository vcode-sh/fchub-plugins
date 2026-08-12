<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\SourceIdentity;

defined('ABSPATH') || exit;

final readonly class OrderRecord
{
    /**
     * @param list<OrderLineRecord> $productLines @param list<FeeLineRecord> $feeLines
     * @param list<ShippingLineRecord> $shippingLines @param list<CouponLineRecord> $couponLines
     * @param list<TaxRateRecord> $taxRates @param list<AddressRecord> $addresses
     * @param list<PaymentEventRecord> $paymentEvents @param list<OrderNoteRecord> $notes
     * @param array<string, scalar|null> $approvedMeta
     */
    public function __construct(
        public SourceIdentity $identity, public ?SourceIdentity $customer, public ?SourceIdentity $parentOrder,
        public string $relationshipType, public string $sourceStatus, public string $sourceStoreCurrency,
        public string $currency, public string $targetBaseCurrency, public string $exchangeRateDecimal,
        public string $exchangeRateEvidence, public bool $pricesIncludeTax, public int $subtotal,
        public int $couponDiscountTotal, public int $manualDiscountTotal, public int $discountTax,
        public int $shippingTotal, public int $shippingTax, public int $feeTotal, public int $feeTax,
        public int $cartTax, public int $grossTotal, public int $refundedTotal, public string $createdUtc,
        public ?string $modifiedUtc, public ?string $paidUtc, public ?string $completedUtc,
        public ?string $refundedUtc, public array $productLines, public array $feeLines,
        public array $shippingLines, public array $couponLines, public array $taxRates,
        public array $addresses, public array $paymentEvents, public array $notes, public array $approvedMeta,
    ) {
        foreach ([$productLines, $feeLines, $shippingLines, $couponLines, $taxRates, $addresses, $paymentEvents, $notes] as $list) {
            if (!array_is_list($list)) {
                throw new \InvalidArgumentException('Order record collections must be lists.');
            }
        }
        if ($createdUtc === '' || $currency === '' || $targetBaseCurrency === '' || $exchangeRateEvidence === '') {
            throw new \InvalidArgumentException('Order identity, currency, rate evidence and creation time are required.');
        }
    }

    public function envelope(int $schemaVersion = 1): RecordEnvelope
    {
        return RecordEnvelope::forPayload($schemaVersion, $this->identity, $this->toArray());
    }

    /** Public audit evidence deliberately excludes note content and content hashes. */
    public function publicEvidence(): array
    {
        return [
            'identity' => $this->identity->canonical(),
            'source_status' => $this->sourceStatus,
            'relationship_type' => $this->relationshipType,
            'product_line_count' => count($this->productLines),
            'fee_line_count' => count($this->feeLines),
            'shipping_line_count' => count($this->shippingLines),
            'coupon_line_count' => count($this->couponLines),
            'tax_rate_count' => count($this->taxRates),
            'payment_event_count' => count($this->paymentEvents),
            'note_count' => count($this->notes),
            'notes' => array_map(static fn (OrderNoteRecord $note): array => $note->publicEvidence(), $this->notes),
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'identity' => $this->identity->canonical(), 'customer' => $this->customer?->canonical(),
            'parent_order' => $this->parentOrder?->canonical(), 'relationship_type' => $this->relationshipType,
            'source_status' => $this->sourceStatus, 'source_store_currency' => $this->sourceStoreCurrency,
            'currency' => $this->currency, 'target_base_currency' => $this->targetBaseCurrency,
            'exchange_rate_decimal' => $this->exchangeRateDecimal, 'exchange_rate_evidence' => $this->exchangeRateEvidence,
            'prices_include_tax' => $this->pricesIncludeTax, 'subtotal' => $this->subtotal,
            'coupon_discount_total' => $this->couponDiscountTotal, 'manual_discount_total' => $this->manualDiscountTotal,
            'discount_tax' => $this->discountTax, 'shipping_total' => $this->shippingTotal,
            'shipping_tax' => $this->shippingTax, 'fee_total' => $this->feeTotal, 'fee_tax' => $this->feeTax,
            'cart_tax' => $this->cartTax, 'gross_total' => $this->grossTotal, 'refunded_total' => $this->refundedTotal,
            'created_utc' => $this->createdUtc, 'modified_utc' => $this->modifiedUtc, 'paid_utc' => $this->paidUtc,
            'completed_utc' => $this->completedUtc, 'refunded_utc' => $this->refundedUtc,
            'product_lines' => array_map(static fn (OrderLineRecord $v): array => $v->toArray(), $this->productLines),
            'fee_lines' => array_map(static fn (FeeLineRecord $v): array => $v->toArray(), $this->feeLines),
            'shipping_lines' => array_map(static fn (ShippingLineRecord $v): array => $v->toArray(), $this->shippingLines),
            'coupon_lines' => array_map(static fn (CouponLineRecord $v): array => $v->toArray(), $this->couponLines),
            'tax_rates' => array_map(static fn (TaxRateRecord $v): array => $v->toArray(), $this->taxRates),
            'addresses' => array_map(static fn (AddressRecord $v): array => $v->toArray(), $this->addresses),
            'payment_events' => array_map(static fn (PaymentEventRecord $v): array => $v->toArray(), $this->paymentEvents),
            'notes' => array_map(static fn (OrderNoteRecord $v): array => $v->toArray(), $this->notes),
            'approved_meta' => $this->approvedMeta,
        ];
    }
}

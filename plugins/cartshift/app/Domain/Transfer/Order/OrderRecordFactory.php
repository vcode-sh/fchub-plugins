<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

use CartShift\Domain\Transfer\RecordKind;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Support\MoneyHelper;
use CartShift\Support\UtcDateTime;

defined('ABSPATH') || exit;

/** Public WooCommerce CRUD extraction into an immutable historical ledger. */
final class OrderRecordFactory
{
    private readonly ?\Closure $notesReader;
    private readonly ?\Closure $relationshipResolver;
    private readonly ?\Closure $currencyRateAdapter;
    private readonly ?\Closure $missingProductResolver;

    /**
     * @param (callable(int): iterable<object>)|null $notesReader
     * @param (callable(int): array<string, mixed>|list<array<string, mixed>>)|null $relationshipResolver
     * @param (callable(string, string, object): array{rate: string, evidence: string})|null $currencyRateAdapter
     * @param (callable(object, object): array{identity: SourceIdentity, fulfilment_type: string})|null $missingProductResolver
     * @param list<string> $approvedMetaKeys
     */
    public function __construct(
        private readonly string $sourceStoreCurrency,
        private readonly string $targetBaseCurrency,
        private readonly string $noteIdentifierKey,
        ?callable $notesReader = null,
        ?callable $relationshipResolver = null,
        ?callable $currencyRateAdapter = null,
        ?callable $missingProductResolver = null,
        private readonly array $approvedMetaKeys = [],
    ) {
        $this->currency($sourceStoreCurrency);
        $this->currency($targetBaseCurrency);
        if ($noteIdentifierKey === '') {
            throw new \InvalidArgumentException('A per-run order-note identifier key is required.');
        }
        $this->notesReader = $notesReader === null ? null : $notesReader(...);
        $this->relationshipResolver = $relationshipResolver === null ? null : $relationshipResolver(...);
        $this->currencyRateAdapter = $currencyRateAdapter === null ? null : $currencyRateAdapter(...);
        $this->missingProductResolver = $missingProductResolver === null ? null : $missingProductResolver(...);
    }

    public function fromWooOrder(object $order, string $sourceKey): OrderRecord
    {
        SourceIdentity::assertValidSourceKey($sourceKey);
        $orderId = $this->positiveInt($this->call($order, 'get_id'), 'order_hydration_failed');
        $identity = new SourceIdentity($sourceKey, RecordKind::Order->value, (string) $orderId);
        $currency = $this->currency((string) $this->call($order, 'get_currency'));
        [$rate, $rateEvidence] = $this->exchangeRate($currency, $order);
        [$relationshipType, $parentOrder] = $this->relationship($orderId, $sourceKey);

        $createdUtc = $this->date($this->call($order, 'get_date_created'));
        if ($createdUtc === null) {
            throw new SourceRecordException('order_hydration_failed', 'A selected order has no canonical creation time.');
        }

        $subtotal = $this->money($this->call($order, 'get_subtotal'));
        $discountHeader = $this->money($this->call($order, 'get_discount_total', '0'));
        $discountTax = $this->money($this->call($order, 'get_discount_tax', '0'));
        $shippingTotal = $this->money($this->call($order, 'get_shipping_total', '0'));
        $shippingTax = $this->money($this->call($order, 'get_shipping_tax', '0'));
        $totalTax = $this->money($this->call($order, 'get_total_tax', '0'));
        $grossTotal = $this->money($this->call($order, 'get_total'));
        $refundedTotal = abs($this->money($this->call($order, 'get_total_refunded', '0')));
        if ($totalTax < $shippingTax || min($subtotal, $discountHeader, $discountTax, $shippingTotal, $shippingTax, $grossTotal, $refundedTotal) < 0) {
            throw new SourceRecordException('order_money_mismatch', 'Order header money is negative or internally impossible.');
        }

        $refundData = $this->refunds($order, $identity, $currency, $refundedTotal);
        $productLines = $this->productLines($order, $identity, $sourceKey, $rate, $refundData['line_allocations']);
        $feeLines = $this->feeLines($order, $identity);
        $shippingLines = $this->shippingLines($order, $identity);
        $couponLines = $this->couponLines($order, $identity);
        $taxRates = $this->taxRates($order, $identity);

        $feeTotal = array_sum(array_map(static fn (FeeLineRecord $line): int => $line->total, $feeLines));
        $feeTax = array_sum(array_map(static fn (FeeLineRecord $line): int => $line->tax, $feeLines));
        $couponDiscount = array_sum(array_map(static fn (CouponLineRecord $line): int => $line->discount, $couponLines));
        $couponDiscountTax = array_sum(array_map(static fn (CouponLineRecord $line): int => $line->discountTax, $couponLines));
        $manualDiscount = $discountHeader - $couponDiscount;
        if ($manualDiscount < 0 || ($manualDiscount > 0 && !$this->hasManualDiscountEvidence(
            $order,
            $productLines,
            $discountHeader,
        ))) {
            throw new SourceRecordException('order_money_mismatch', 'Header discount cannot be attributed to coupon or explicit manual evidence.');
        }
        if ($couponDiscountTax > $discountTax) {
            throw new SourceRecordException('order_money_mismatch', 'Coupon discount tax exceeds the order discount-tax header.');
        }

        $this->assertMoneyInvariants(
            $productLines,
            $feeLines,
            $shippingLines,
            $taxRates,
            $subtotal,
            $discountHeader,
            $discountTax,
            $shippingTotal,
            $shippingTax,
            $feeTotal,
            $feeTax,
            $totalTax,
            $grossTotal,
        );

        $paymentEvents = [$this->chargeEvent($order, $identity, $currency, $grossTotal)];
        array_push($paymentEvents, ...$refundData['events']);
        if ($refundedTotal > $grossTotal) {
            throw new SourceRecordException('order_money_mismatch', 'Refunded total exceeds the only proven source charge.');
        }
        $this->assertProviderReferencesUnique($paymentEvents);

        $customerId = (int) $this->call($order, 'get_customer_id', 0);
        $customer = $customerId > 0
            ? new SourceIdentity($sourceKey, RecordKind::Customer->value, (string) $customerId)
            : (trim((string) $this->call($order, 'get_billing_email', '')) !== ''
                ? new SourceIdentity($sourceKey, RecordKind::Customer->value, $identity->sourceId . ':guest')
                : null);

        return new OrderRecord(
            $identity,
            $customer,
            $parentOrder,
            $relationshipType,
            (string) $this->call($order, 'get_status'),
            $this->sourceStoreCurrency,
            $currency,
            $this->targetBaseCurrency,
            $rate,
            $rateEvidence,
            (bool) $this->call($order, 'get_prices_include_tax', false),
            $subtotal,
            $couponDiscount,
            $manualDiscount,
            $discountTax,
            $shippingTotal,
            $shippingTax,
            $feeTotal,
            $feeTax,
            $totalTax - $shippingTax,
            $grossTotal,
            $refundedTotal,
            $createdUtc,
            $this->date($this->call($order, 'get_date_modified')),
            $this->date($this->call($order, 'get_date_paid')),
            $this->date($this->call($order, 'get_date_completed')),
            $refundData['refunded_utc'],
            $productLines,
            $feeLines,
            $shippingLines,
            $couponLines,
            $taxRates,
            $this->addresses($order, $identity),
            $paymentEvents,
            $this->notes($orderId, $identity),
            $this->approvedMeta($order),
        );
    }

    /** @return array{string, string} */
    private function exchangeRate(string $currency, object $order): array
    {
        if ($currency === $this->targetBaseCurrency) {
            return ['1.0000', 'same_currency:' . $currency];
        }
        if ($this->currencyRateAdapter === null) {
            throw new SourceRecordException('target_schema_unrepresentable', 'Foreign order currency requires named exchange-rate evidence.');
        }
        $result = ($this->currencyRateAdapter)($currency, $this->targetBaseCurrency, $order);
        $rate = is_array($result) ? ($result['rate'] ?? null) : null;
        $evidence = is_array($result) ? ($result['evidence'] ?? null) : null;
        if (!is_string($rate) || preg_match('/\A[0-9]+\.[0-9]{4,18}\z/D', $rate) !== 1
            || (float) $rate <= 0 || !is_string($evidence) || $evidence === '' || $rate === '1.0000') {
            throw new SourceRecordException('target_schema_unrepresentable', 'Exchange-rate adapter returned unrepresentable or unnamed evidence.');
        }
        return [$rate, $evidence];
    }

    /** @return array{string, ?SourceIdentity} */
    private function relationship(int $orderId, string $sourceKey): array
    {
        if ($this->relationshipResolver === null) {
            return ['checkout', null];
        }
        $resolved = ($this->relationshipResolver)($orderId);
        if ($resolved === []) {
            return ['checkout', null];
        }
        $candidates = array_is_list($resolved) ? $resolved : [$resolved];
        if (count($candidates) !== 1 || !is_array($candidates[0])) {
            throw new SourceRecordException('refund_parent_ambiguous', 'Order relationship type is ambiguous.');
        }
        $type = (string) ($candidates[0]['type'] ?? '');
        $parent = (int) ($candidates[0]['parent_order_id'] ?? 0);
        if (!in_array($type, ['checkout', 'parent', 'renewal', 'switch', 'resubscribe'], true)
            || (($type === 'checkout' || $type === 'parent') && $parent !== 0)
            || (!in_array($type, ['checkout', 'parent'], true) && $parent <= 0)) {
            throw new SourceRecordException('refund_parent_ambiguous', 'Order relationship type or parent is invalid.');
        }
        return [$type, $parent > 0 ? new SourceIdentity($sourceKey, RecordKind::Order->value, (string) $parent) : null];
    }

    /** @param array<int, int> $refundAllocations @return list<OrderLineRecord> */
    private function productLines(object $order, SourceIdentity $orderIdentity, string $sourceKey, string $rate, array $refundAllocations): array
    {
        $records = [];
        $seen = [];
        foreach ($this->items($order, 'line_item') as $cartIndex => $item) {
            $lineId = $this->positiveInt($this->call($item, 'get_id'), 'order_item_parent_missing');
            if (isset($seen[$lineId])) {
                throw new SourceRecordException('source_identity_conflict', 'Order source contains a duplicate product-line identity.');
            }
            $seen[$lineId] = true;
            $productId = (int) $this->call($item, 'get_product_id', 0);
            $productObject = $this->call($item, 'get_product');
            $historicalFulfilmentType = null;
            if ($productId <= 0 || !is_object($productObject)) {
                if ($this->missingProductResolver === null) {
                    throw new SourceRecordException('historical_product_missing', 'Order line has no source product identity or approved placeholder.');
                }
                $resolved = ($this->missingProductResolver)($order, $item);
                $product = is_array($resolved) ? ($resolved['identity'] ?? null) : null;
                $historicalFulfilmentType = is_array($resolved) ? ($resolved['fulfilment_type'] ?? null) : null;
                if (!$product instanceof SourceIdentity
                    || $product->sourceKey !== $sourceKey
                    || $product->kind() !== RecordKind::Product
                    || !is_string($historicalFulfilmentType)
                    || !in_array($historicalFulfilmentType, ['physical', 'digital', 'service'], true)) {
                    throw new SourceRecordException('historical_product_missing', 'Missing-product resolver did not return an exact product identity.');
                }
                if ($productId > 0 && $product->sourceId !== (string) $productId) {
                    throw new SourceRecordException('dependency_ambiguous', 'Historical product resolution changed the retained source identity.');
                }
            } else {
                $product = new SourceIdentity($sourceKey, RecordKind::Product->value, (string) $productId);
            }
            $variationId = (int) $this->call($item, 'get_variation_id', 0);
            $variationSourceId = $product->sourceId . ':variation:' . ($variationId > 0 ? $variationId : $product->sourceId);
            $variation = new SourceIdentity($sourceKey, RecordKind::Product->value, $variationSourceId);
            $quantityRaw = $this->call($item, 'get_quantity');
            if (!(is_int($quantityRaw) || (is_string($quantityRaw) && preg_match('/\A[1-9][0-9]*\z/D', $quantityRaw) === 1))) {
                throw new SourceRecordException('target_schema_unrepresentable', 'Fractional or invalid order-line quantity cannot be represented.');
            }
            $quantity = (int) $quantityRaw;
            $subtotal = $this->money($this->call($item, 'get_subtotal'));
            $subtotalTax = $this->money($this->call($item, 'get_subtotal_tax', '0'));
            $total = $this->money($this->call($item, 'get_total'));
            $tax = $this->money($this->call($item, 'get_total_tax', '0'));
            if ($quantity <= 0 || min($subtotal, $subtotalTax, $total, $tax) < 0 || $subtotal < $total || $subtotalTax < $tax) {
                throw new SourceRecordException('order_money_mismatch', 'Order-line amounts or quantity are internally impossible.');
            }
            $lineGross = $total + $tax;
            $unitPrice = $this->displayUnitPrice($subtotal, $quantity);
            $unitPriceRemainder = $subtotal % $quantity;
            $fulfilmentType = is_object($productObject)
                ? match (true) {
                    (bool) $this->call($productObject, 'is_downloadable', false) => 'digital',
                    (bool) $this->call($productObject, 'is_virtual', false) => 'service',
                    default => 'physical',
                }
                : $historicalFulfilmentType;
            $records[] = new OrderLineRecord(
                $this->childIdentity($orderIdentity, 'item', $lineId), $lineId, $product, $variation,
                $this->boundedString($this->call($item, 'get_name', ''), 255, 'target_schema_unrepresentable'),
                $this->boundedString($this->call($item, 'get_sku', $this->call($item, 'get_meta', '', ['_sku', true])), 255, 'target_schema_unrepresentable'),
                $this->metadata($this->call($item, 'get_formatted_meta_data', [], ['']), false),
                $quantity, (int) $cartIndex, $unitPrice, $subtotal, $subtotalTax,
                $subtotal - $total, $subtotalTax - $tax, $tax, $lineGross,
                $refundAllocations[$lineId] ?? 0, 'not_available_from_woo_core', 0, $rate,
                $this->date($this->call($item, 'get_date_created')), $this->taxAllocations($item),
                [
                    'source_line_total_ex_tax' => $total,
                    'source_fulfilment_type' => $fulfilmentType,
                ], $unitPriceRemainder === 0 ? [] : [
                    'source_unit_price_remainder' => $unitPriceRemainder,
                    'source_unit_price_rounding_policy' => 'fluentcart_integer_display_floor',
                ],
            );
        }
        return $records;
    }

    /** @return list<FeeLineRecord> */
    private function feeLines(object $order, SourceIdentity $identity): array
    {
        $records = [];
        foreach ($this->uniqueItems($this->items($order, 'fee'), 'fee') as $id => $item) {
            $records[] = new FeeLineRecord($this->childIdentity($identity, 'fee', $id), $id,
                $this->boundedString($this->call($item, 'get_name', ''), 255, 'target_schema_unrepresentable'),
                $this->money($this->call($item, 'get_total', '0')), $this->money($this->call($item, 'get_total_tax', '0')),
                $this->taxAllocations($item), $this->scalarMeta($this->call($item, 'get_meta_data', [])));
        }
        return $records;
    }

    /** @return list<ShippingLineRecord> */
    private function shippingLines(object $order, SourceIdentity $identity): array
    {
        $records = [];
        foreach ($this->uniqueItems($this->items($order, 'shipping'), 'shipping') as $id => $item) {
            $records[] = new ShippingLineRecord($this->childIdentity($identity, 'shipping', $id), $id,
                (string) $this->call($item, 'get_method_id', ''), max(0, (int) $this->call($item, 'get_instance_id', 0)),
                $this->boundedString($this->call($item, 'get_method_title', ''), 255, 'target_schema_unrepresentable'),
                $this->money($this->call($item, 'get_total', '0')), $this->money($this->call($item, 'get_total_tax', '0')),
                $this->taxAllocations($item), $this->scalarMeta($this->call($item, 'get_meta_data', [])));
        }
        return $records;
    }

    /** @return list<CouponLineRecord> */
    private function couponLines(object $order, SourceIdentity $identity): array
    {
        $records = [];
        foreach ($this->uniqueItems($this->items($order, 'coupon'), 'coupon') as $id => $item) {
            $records[] = new CouponLineRecord($this->childIdentity($identity, 'coupon', $id), $id,
                (string) $this->call($item, 'get_code', $this->call($item, 'get_name', '')),
                $this->money($this->call($item, 'get_discount', '0')),
                $this->money($this->call($item, 'get_discount_tax', '0')));
        }
        return $records;
    }

    /** @return list<TaxRateRecord> */
    private function taxRates(object $order, SourceIdentity $identity): array
    {
        $records = [];
        foreach ($this->uniqueItems($this->items($order, 'tax'), 'tax') as $id => $item) {
            $records[] = new TaxRateRecord($this->childIdentity($identity, 'tax', $id), $id,
                max(0, (int) $this->call($item, 'get_rate_id', 0)), (string) $this->call($item, 'get_rate_code', ''),
                (string) $this->call($item, 'get_label', ''), $this->decimal($this->call($item, 'get_rate_percent', '0')),
                (bool) $this->call($item, 'is_compound', $this->call($item, 'get_compound', false)),
                $this->money($this->call($item, 'get_tax_total', '0')),
                $this->money($this->call($item, 'get_shipping_tax_total', '0')),
                $this->money($this->call($item, 'get_taxable_amount', '0')),
                (bool) $this->call($item, 'get_rate_included', false));
        }
        return $records;
    }

    /** @return array{events: list<PaymentEventRecord>, line_allocations: array<int, int>, refunded_utc: ?string} */
    private function refunds(object $order, SourceIdentity $identity, string $currency, int $headerRefund): array
    {
        $events = [];
        $allocations = [];
        $seen = [];
        $sum = 0;
        $refundedUtc = null;
        $refunds = (array) $this->call($order, 'get_refunds', []);
        usort($refunds, fn (mixed $left, mixed $right): int =>
            (int) (is_object($left) ? $this->call($left, 'get_id', 0) : 0)
            <=> (int) (is_object($right) ? $this->call($right, 'get_id', 0) : 0)
        );
        foreach ($refunds as $refund) {
            if (!is_object($refund)) {
                throw new SourceRecordException('order_hydration_failed', 'Refund did not hydrate through WooCommerce CRUD.');
            }
            $id = $this->positiveInt($this->call($refund, 'get_id'), 'source_identity_conflict');
            if (isset($seen[$id])) {
                throw new SourceRecordException('source_identity_conflict', 'Refund identity occurs more than once.');
            }
            $seen[$id] = true;
            $refundCurrency = $this->currency((string) $this->call($refund, 'get_currency', $currency));
            if ($refundCurrency !== $currency) {
                throw new SourceRecordException('order_money_mismatch', 'Refund currency differs from its source order.');
            }
            $amount = abs($this->money($this->call($refund, 'get_amount')));
            $sum += $amount;
            $date = $this->date($this->call($refund, 'get_date_created'));
            if ($date === null) {
                throw new SourceRecordException('order_hydration_failed', 'Refund has no canonical creation time.');
            }
            $refundedUtc = $refundedUtc === null || $date > $refundedUtc ? $date : $refundedUtc;
            foreach ($this->items($refund, 'line_item') as $refundItem) {
                $parentId = (int) $this->call($refundItem, 'get_refunded_item_id', $this->call($refundItem, 'get_meta', 0, ['_refunded_item_id', true]));
                if ($parentId <= 0) {
                    throw new SourceRecordException('refund_parent_ambiguous', 'Refund line has no exact source product-line parent.');
                }
                $lineAmount = abs($this->money($this->call($refundItem, 'get_total', '0')))
                    + abs($this->money($this->call($refundItem, 'get_total_tax', '0')));
                $allocations[$parentId] = ($allocations[$parentId] ?? 0) + $lineAmount;
            }
            $eventIdentity = $this->childIdentity($identity, 'refund', $id);
            $providerReference = $this->nullableString($this->call($refund, 'get_transaction_id', ''));
            $events[] = new PaymentEventRecord(
                $eventIdentity, 'refund', $amount, $currency, 'succeeded',
                $providerReference === null ? PaymentEvidenceKind::ManualPaidWithoutProvider : PaymentEvidenceKind::ProviderReference,
                (string) $this->call($order, 'get_payment_method', ''),
                (string) $this->call($order, 'get_payment_method_title', ''),
                $providerReference, $this->childIdentity($identity, 'charge', (int) $identity->sourceId), $date,
                ['source_refund_id' => $id, 'reason_present' => $this->nullableString($this->call($refund, 'get_reason', '')) !== null],
            );
        }
        if ($sum !== $headerRefund) {
            throw new SourceRecordException('order_money_mismatch', 'Refund events do not equal the WooCommerce refunded-total header.');
        }
        $lineIds = array_map(fn (object $item): int => (int) $this->call($item, 'get_id', 0), $this->items($order, 'line_item'));
        foreach (array_keys($allocations) as $parentId) {
            if (!in_array($parentId, $lineIds, true)) {
                throw new SourceRecordException('refund_parent_ambiguous', 'Refund line parent is not a product line on this order.');
            }
        }
        return ['events' => $events, 'line_allocations' => $allocations, 'refunded_utc' => $refundedUtc];
    }

    private function chargeEvent(object $order, SourceIdentity $identity, string $currency, int $gross): PaymentEventRecord
    {
        $references = $this->call($order, 'get_meta', [], ['_cartshift_provider_references', true]);
        if (is_array($references) && count(array_filter($references, 'is_string')) > 1) {
            throw new SourceRecordException('charge_parent_missing', 'Multiple source charges require a named payment-ledger adapter.');
        }
        $explicitPaid = $this->call($order, 'get_meta', '', ['_cartshift_paid_amount', true]);
        if ($explicitPaid !== '' && $this->money($explicitPaid) !== $gross) {
            throw new SourceRecordException('order_money_mismatch', 'Explicit paid amount differs from the historical order gross.');
        }
        $status = (string) $this->call($order, 'get_status', '');
        $paidUtc = $this->date($this->call($order, 'get_date_paid'));
        $paid = (bool) $this->call($order, 'is_paid', false) || $paidUtc !== null
            || in_array($status, ['processing', 'completed', 'refunded'], true);
        $provider = $this->nullableString($this->call($order, 'get_transaction_id', ''));
        if ($gross === 0 && $paid) {
            $kind = PaymentEvidenceKind::FreeNoCharge;
            $eventStatus = 'succeeded';
        } elseif (!$paid) {
            $kind = PaymentEvidenceKind::PendingOrFailed;
            $eventStatus = $status === 'failed' ? 'failed' : 'pending';
        } elseif ($provider !== null) {
            $kind = PaymentEvidenceKind::ProviderReference;
            $eventStatus = 'succeeded';
        } else {
            $kind = PaymentEvidenceKind::ManualPaidWithoutProvider;
            $eventStatus = 'succeeded';
        }
        return new PaymentEventRecord(
            $this->childIdentity($identity, 'charge', (int) $identity->sourceId), 'charge', $gross, $currency,
            $eventStatus, $kind, (string) $this->call($order, 'get_payment_method', ''),
            (string) $this->call($order, 'get_payment_method_title', ''), $provider, null,
            $paidUtc ?? $this->date($this->call($order, 'get_date_created')),
            ['source_status' => $status],
        );
    }

    /** @param list<PaymentEventRecord> $events */
    private function assertProviderReferencesUnique(array $events): void
    {
        $seen = [];
        foreach ($events as $event) {
            if ($event->providerReference === null) {
                continue;
            }
            if (isset($seen[$event->providerReference]) && $seen[$event->providerReference] !== $event->toArray()) {
                throw new SourceRecordException('charge_parent_missing', 'Provider reference identifies incompatible financial events.');
            }
            $seen[$event->providerReference] = $event->toArray();
        }
    }

    /** @return list<AddressRecord> */
    private function addresses(object $order, SourceIdentity $identity): array
    {
        $businessTaxId = $this->nullableString($this->call($order, 'get_meta', '', ['_billing_vat_number', true])) ?? '';
        $records = [];
        foreach (['billing', 'shipping'] as $type) {
            $value = fn (string $field): string => (string) $this->call($order, "get_{$type}_{$field}", '');
            $records[] = new AddressRecord(
                $this->childIdentity($identity, 'address', $type === 'billing' ? 1 : 2), $type,
                $value('first_name'), $value('last_name'), $value('company'), $value('address_1'), $value('address_2'),
                $value('city'), $value('state'), $value('postcode'), $value('country'),
                $type === 'billing' ? $value('email') : '', $value('phone'), $type === 'billing' ? $businessTaxId : '',
            );
        }
        return $records;
    }

    /** @return list<OrderNoteRecord> */
    private function notes(int $orderId, SourceIdentity $identity): array
    {
        $notes = $this->notesReader !== null
            ? ($this->notesReader)($orderId)
            : (function_exists('wc_get_order_notes') ? wc_get_order_notes(['order_id' => $orderId]) : []);
        $records = [];
        $seen = [];
        foreach ($notes as $note) {
            if (!is_object($note)) {
                throw new SourceRecordException('order_hydration_failed', 'Order note did not hydrate as a public CRUD object.');
            }
            $id = $this->positiveInt($this->value($note, 'id'), 'source_identity_conflict');
            if (isset($seen[$id])) {
                throw new SourceRecordException('source_identity_conflict', 'Selected note identity occurs more than once.');
            }
            $seen[$id] = true;
            $visible = $this->value($note, 'customer_note');
            if (!is_bool($visible) && !in_array($visible, [0, 1, '0', '1'], true)) {
                throw new SourceRecordException('order_hydration_failed', 'Order-note visibility is ambiguous.');
            }
            $noteIdentity = $this->childIdentity($identity, 'note', $id);
            $records[] = new OrderNoteRecord(
                $noteIdentity, $id, (string) $this->value($note, 'content'),
                $this->date($this->value($note, 'date_created'))
                    ?? throw new SourceRecordException('order_hydration_failed', 'Order note has no canonical creation time.'),
                (bool) $visible, $this->authorKind((string) $this->value($note, 'added_by')),
                hash_hmac('sha256', $noteIdentity->canonical(), $this->noteIdentifierKey),
            );
        }
        usort($records, static fn (OrderNoteRecord $a, OrderNoteRecord $b): int => $a->sourceNoteId <=> $b->sourceNoteId);
        return $records;
    }

    /** @return array<string, scalar|null> */
    private function approvedMeta(object $order): array
    {
        $meta = [];
        foreach ($this->approvedMetaKeys as $key) {
            $value = $this->call($order, 'get_meta', null, [$key, true]);
            if ($value === '' || $value === null) {
                continue;
            }
            if (!is_scalar($value)) {
                throw new SourceRecordException('target_schema_unrepresentable', 'Approved order metadata is not scalar.');
            }
            $meta[$key] = $value;
        }
        ksort($meta);
        return $meta;
    }

    /** @param list<OrderLineRecord> $products @param list<FeeLineRecord> $fees @param list<ShippingLineRecord> $shipping @param list<TaxRateRecord> $taxes */
    private function assertMoneyInvariants(array $products, array $fees, array $shipping, array $taxes, int $subtotal,
        int $discount, int $discountTax, int $shippingTotal, int $shippingTax, int $feeTotal, int $feeTax,
        int $totalTax, int $gross): void
    {
        $checks = [
            'subtotal' => [array_sum(array_map(static fn (OrderLineRecord $line): int => $line->subtotal, $products)), $subtotal],
            'discount' => [array_sum(array_map(static fn (OrderLineRecord $line): int => $line->discountTotal, $products)), $discount],
            'discount_tax' => [array_sum(array_map(static fn (OrderLineRecord $line): int => $line->discountTax, $products)), $discountTax],
            'fee_total' => [array_sum(array_map(static fn (FeeLineRecord $line): int => $line->total, $fees)), $feeTotal],
            'fee_tax' => [array_sum(array_map(static fn (FeeLineRecord $line): int => $line->tax, $fees)), $feeTax],
            'shipping_total' => [array_sum(array_map(static fn (ShippingLineRecord $line): int => $line->total, $shipping)), $shippingTotal],
            'shipping_tax' => [array_sum(array_map(static fn (ShippingLineRecord $line): int => $line->tax, $shipping)), $shippingTax],
        ];
        foreach ($checks as $field => [$actual, $expected]) {
            if ($actual !== $expected) {
                throw new SourceRecordException('order_money_mismatch', "Order child {$field} does not reconcile to its header.");
            }
        }
        $productTax = array_sum(array_map(static fn (OrderLineRecord $line): int => $line->taxTotal, $products));
        $taxLineCart = array_sum(array_map(static fn (TaxRateRecord $line): int => $line->orderTax, $taxes));
        $taxLineShipping = array_sum(array_map(static fn (TaxRateRecord $line): int => $line->shippingTax, $taxes));
        if ($productTax + $feeTax !== $totalTax - $shippingTax
            || ($taxes !== [] && ($taxLineCart !== $totalTax - $shippingTax || $taxLineShipping !== $shippingTax))) {
            throw new SourceRecordException('order_tax_mismatch', 'Order tax allocations do not reconcile exactly once.');
        }
        $allocatedCart = [];
        foreach ([...$products, ...$fees] as $line) {
            foreach ($line->taxAllocations as $rateId => $amount) {
                $allocatedCart[(int) $rateId] = ($allocatedCart[(int) $rateId] ?? 0) + $amount;
            }
        }
        $allocatedShipping = [];
        foreach ($shipping as $line) {
            foreach ($line->taxAllocations as $rateId => $amount) {
                $allocatedShipping[(int) $rateId] = ($allocatedShipping[(int) $rateId] ?? 0) + $amount;
            }
        }
        $reportedCart = [];
        $reportedShipping = [];
        foreach ($taxes as $tax) {
            $reportedCart[$tax->sourceRateId] = $tax->orderTax;
            $reportedShipping[$tax->sourceRateId] = $tax->shippingTax;
        }
        ksort($allocatedCart);
        ksort($allocatedShipping);
        ksort($reportedCart);
        ksort($reportedShipping);
        $allocatedCart = array_filter($allocatedCart, static fn (int $amount): bool => $amount !== 0);
        $allocatedShipping = array_filter($allocatedShipping, static fn (int $amount): bool => $amount !== 0);
        $reportedCart = array_filter($reportedCart, static fn (int $amount): bool => $amount !== 0);
        $reportedShipping = array_filter($reportedShipping, static fn (int $amount): bool => $amount !== 0);
        if ($allocatedCart !== $reportedCart || $allocatedShipping !== $reportedShipping) {
            throw new SourceRecordException('order_tax_mismatch', 'Per-rate child tax allocations differ from WooCommerce tax lines.');
        }
        $computedGross = array_sum(array_map(static fn (OrderLineRecord $line): int => $line->lineTotal, $products))
            + $feeTotal + $feeTax + $shippingTotal + $shippingTax;
        if ($computedGross !== $gross) {
            throw new SourceRecordException('order_money_mismatch', 'Order child ledger does not recompute the historical gross total.');
        }
    }

    /** @return array<int, object> */
    private function uniqueItems(array $items, string $kind): array
    {
        $result = [];
        foreach ($items as $item) {
            if (!is_object($item)) {
                throw new SourceRecordException('order_hydration_failed', "{$kind} line did not hydrate through WooCommerce CRUD.");
            }
            $id = $this->positiveInt($this->call($item, 'get_id'), 'source_identity_conflict');
            if (isset($result[$id])) {
                throw new SourceRecordException('source_identity_conflict', "Duplicate {$kind} line identity.");
            }
            $result[$id] = $item;
        }
        ksort($result, SORT_NUMERIC);
        return $result;
    }

    /** @return list<object> */
    private function items(object $owner, string $type): array
    {
        $items = $this->call($owner, 'get_items', [], [$type]);
        if (!is_array($items)) {
            throw new SourceRecordException('order_hydration_failed', 'WooCommerce item API returned a non-list collection.');
        }
        return array_values($items);
    }

    /** @return array<int|string, int> */
    private function taxAllocations(object $item): array
    {
        $taxes = $this->call($item, 'get_taxes', []);
        $totals = is_array($taxes) && is_array($taxes['total'] ?? null) ? $taxes['total'] : [];
        $result = [];
        foreach ($totals as $rate => $amount) {
            $result[$rate] = $this->money($amount === '' ? '0' : $amount);
        }
        ksort($result);
        return $result;
    }

    /** @return list<array{key: string, value: scalar|null, display_key: string, display_value: scalar|null}>|array<string, scalar|null> */
    private function metadata(mixed $raw, bool $asMap): array
    {
        if (!is_array($raw)) {
            throw new SourceRecordException('target_schema_unrepresentable', 'Order-line metadata is not a collection.');
        }
        $list = [];
        foreach ($raw as $entry) {
            $data = is_array($entry)
                ? $entry
                : (is_object($entry) && is_callable([$entry, 'get_data'])
                    ? $entry->get_data()
                    : (is_object($entry) ? get_object_vars($entry) : []));
            $key = (string) ($data['key'] ?? '');
            $value = $data['value'] ?? null;
            $displayKey = (string) ($data['display_key'] ?? $key);
            $displayValue = $data['display_value'] ?? $value;
            if ($key === '' || (!is_scalar($value) && $value !== null) || (!is_scalar($displayValue) && $displayValue !== null)) {
                throw new SourceRecordException('target_schema_unrepresentable', 'Order-line metadata cannot be represented losslessly.');
            }
            $list[] = ['key' => $key, 'value' => $value, 'display_key' => $displayKey, 'display_value' => $displayValue];
        }
        usort($list, static fn (array $a, array $b): int => $a['key'] <=> $b['key']);
        if (!$asMap) {
            return $list;
        }
        $map = [];
        foreach ($list as $entry) {
            $map[$entry['key']] = $entry['value'];
        }
        return $map;
    }

    /** @return array<string, scalar|null> */
    private function scalarMeta(mixed $raw): array
    {
        return $this->metadata(is_array($raw) ? $raw : [], true);
    }

    /** @param list<OrderLineRecord> $productLines */
    private function hasManualDiscountEvidence(object $order, array $productLines, int $discountHeader): bool
    {
        if ($this->nullableString($this->call($order, 'get_meta', '', ['_cartshift_manual_discount_evidence', true])) !== null) {
            return true;
        }

        return array_sum(array_map(
            static fn (OrderLineRecord $line): int => $line->discountTotal,
            $productLines,
        )) === $discountHeader;
    }

    private function childIdentity(SourceIdentity $order, string $kind, int $id): SourceIdentity
    {
        return new SourceIdentity($order->sourceKey, RecordKind::Order->value, $order->sourceId . ':' . $kind . ':' . $id);
    }

    private function money(mixed $value): int
    {
        // Several Woo CRUD aggregate getters return a float even though their
        // stored children are DECIMAL strings. Convert that already-returned
        // API fact to PHP's shortest round-trip decimal text, then do every
        // conversion and comparison in the strict string parser below. No
        // multiplication, addition or rounding is performed as a PHP float.
        if (is_float($value) && is_finite($value)) {
            $value = json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        }
        if (!is_string($value) && !is_int($value)) {
            throw new SourceRecordException('target_schema_unrepresentable', 'Money is not canonical decimal text.');
        }
        try {
            return MoneyHelper::decimalToCents((string) $value);
        } catch (\InvalidArgumentException|\OverflowException $exception) {
            throw new SourceRecordException('target_schema_unrepresentable', 'Money cannot be represented by the target integer schema.');
        }
    }

    private function displayUnitPrice(int $subtotal, int $quantity): int
    {
        // FluentCart stores the exact line subtotal independently from its
        // integer display unit price and explicitly renders the bounded
        // remainder as a rounding hint. Its own Woo migrator uses this floor
        // convention. The immutable line keeps the remainder as provenance.
        return intdiv($subtotal, $quantity);
    }

    private function currency(string $currency): string
    {
        $currency = strtoupper($currency);
        if (preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1) {
            throw new SourceRecordException('target_schema_unrepresentable', 'Currency is not an ISO-style three-letter code.');
        }
        return $currency;
    }

    private function decimal(mixed $value): string
    {
        if (is_float($value) && is_finite($value)) {
            $value = json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        }
        if (!is_string($value) && !is_int($value)) {
            throw new SourceRecordException('target_schema_unrepresentable', 'Decimal value is not canonical text.');
        }
        $value = (string) $value;
        if (preg_match('/\A-?[0-9]+(?:\.[0-9]+)?\z/D', $value) !== 1) {
            throw new SourceRecordException('target_schema_unrepresentable', 'Decimal value is malformed.');
        }
        return $value;
    }

    private function positiveInt(mixed $value, string $reason): int
    {
        if (!(is_int($value) || (is_string($value) && preg_match('/\A[1-9][0-9]*\z/D', $value) === 1))) {
            throw new SourceRecordException($reason, 'Source identity is not a positive integer.');
        }
        $value = (int) $value;
        if ($value <= 0) {
            throw new SourceRecordException($reason, 'Source identity is outside the target integer range.');
        }
        return $value;
    }

    private function boundedString(mixed $value, int $max, string $reason): string
    {
        if (!is_scalar($value) || strlen((string) $value) > $max) {
            throw new SourceRecordException($reason, 'Source text exceeds the target field contract.');
        }
        return (string) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? (string) $value : null;
    }

    private function date(mixed $value): ?string
    {
        try {
            return UtcDateTime::canonical($value);
        } catch (\InvalidArgumentException) {
        }
        throw new SourceRecordException('order_hydration_failed', 'Source date cannot be normalized to UTC.');
    }

    private function authorKind(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, ['system', 'admin', 'customer'], true) ? $value : 'redacted_external';
    }

    private function value(object $object, string $key): mixed
    {
        $method = 'get_' . $key;
        if (is_callable([$object, $method])) {
            return $object->{$method}();
        }
        if (isset($object->{$key}) || property_exists($object, $key)) {
            return $object->{$key};
        }
        if (isset($object->data) && is_array($object->data) && array_key_exists($key, $object->data)) {
            return $object->data[$key];
        }
        return null;
    }

    /** @param list<mixed> $arguments */
    private function call(object $object, string $method, mixed $default = null, array $arguments = []): mixed
    {
        if (is_callable([$object, $method])) {
            $value = $object->{$method}(...$arguments);
            return $value === null ? $default : $value;
        }
        return $default;
    }
}

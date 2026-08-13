<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

use CartShift\Domain\Transfer\ReconciliationResult;
use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

final class FluentCartOrderMoneyContract
{
    public function project(OrderRecord $record, OrderProjectionContext $context): OrderMoneyProjection
    {
        $rate = $this->integerRate($record->exchangeRateDecimal);
        $taxBehavior = $this->taxBehavior($record);
        $inclusive = $taxBehavior === 2;
        $couponDiscountTax = array_sum(array_map(
            static fn (CouponLineRecord $coupon): int => $coupon->discountTax,
            $record->couponLines,
        ));
        if ($couponDiscountTax > $record->discountTax) {
            $this->block('Coupon discount tax exceeds the order discount-tax evidence.');
        }
        $manualDiscountTax = $record->discountTax - $couponDiscountTax;

        $productItems = [];
        foreach ($record->productLines as $line) {
            $target = $context->productTargets[$line->identity->canonical()] ?? null;
            if (!is_array($target)) {
                $this->block('Order line has no exact target product and variation mapping.');
            }
            $subtotal = $line->subtotal + ($inclusive ? $line->subtotalTax : 0);
            $discount = $line->discountTotal + ($inclusive ? $line->discountTax : 0);
            $sourceRemainder = $line->subtotal % $line->quantity;
            $recordedSourceRemainder = (int) ($line->lineMeta['source_unit_price_remainder'] ?? 0);
            $sourceRoundingPolicy = $line->lineMeta['source_unit_price_rounding_policy'] ?? null;
            if ($subtotal < 0 || $discount < 0 || $discount > $subtotal
                || $line->unitPrice !== intdiv($line->subtotal, $line->quantity)
                || $recordedSourceRemainder !== $sourceRemainder
                || ($sourceRemainder !== 0 && $sourceRoundingPolicy !== 'fluentcart_integer_display_floor')
                || ($sourceRemainder === 0 && $sourceRoundingPolicy !== null)) {
                $this->block('Order line cannot be represented by FluentCart integer unit-price columns.');
            }
            $targetUnitPrice = intdiv($subtotal, $line->quantity);
            $targetUnitPriceRemainder = $subtotal % $line->quantity;
            $this->assertAllocationTotal($line->taxAllocations, $line->taxTotal, 'product line');
            $productItems[] = [
                'post_id' => $target['post_id'],
                'object_id' => $target['object_id'],
                'post_title' => $line->name,
                'title' => $line->name,
                'fulfillment_type' => $target['fulfillment_type'],
                'payment_type' => 'onetime',
                'cart_index' => $line->cartIndex,
                'quantity' => $line->quantity,
                'unit_price' => $targetUnitPrice,
                'cost' => 0,
                'subtotal' => $subtotal,
                'tax_amount' => $line->taxTotal,
                'shipping_charge' => 0,
                'discount_total' => $discount,
                'line_total' => $subtotal - $discount,
                'refund_total' => $line->refundTotal,
                'rate' => $rate,
                'fulfilled_quantity' => $line->fulfilledQuantity,
                'other_info' => [
                    ...$line->otherInfo,
                    'source_identity' => $line->identity->canonical(),
                    'source_product_identity' => $line->product->canonical(),
                    'source_variation_identity' => $line->variation->canonical(),
                    'item_attributes' => $line->attributeSnapshot,
                    'cost_disposition' => $line->costDisposition,
                    ...(($target['historical_variation_unlinked'] ?? false) === true
                        ? ['historical_variation_unlinked' => true]
                        : []),
                ],
                'line_meta' => [
                    ...$line->lineMeta,
                    'source_line_id' => $line->sourceLineId,
                    'tax_allocations' => $line->taxAllocations,
                    'tax_config' => ['inclusive' => $inclusive],
                    ...($targetUnitPriceRemainder === 0 ? [] : [
                        'unit_price_remainder' => $targetUnitPriceRemainder,
                        'unit_price_rounding_policy' => 'fluentcart_integer_display_floor',
                    ]),
                ],
                'created_at' => $line->createdUtc,
            ];
        }

        $fees = [];
        foreach ($record->feeLines as $fee) {
            if ($fee->total < 0 || $fee->tax < 0) {
                $this->block('Negative or credit-like WooCommerce fees have no proved FluentCart parity.');
            }
            $this->assertAllocationTotal($fee->taxAllocations, $fee->tax, 'fee line');
            $storedSubtotal = $fee->total + ($inclusive ? $fee->tax : 0);
            $fees[] = new FeeProjection([
                'post_id' => 0,
                'object_id' => 0,
                'post_title' => '',
                'title' => $fee->name,
                'fulfillment_type' => 'digital',
                'payment_type' => 'fee',
                'quantity' => 1,
                'unit_price' => $storedSubtotal,
                'cost' => 0,
                'subtotal' => $storedSubtotal,
                // CheckoutProcessor stores fee tax on the order/tax-rate
                // contract, not on the fee order-item row.
                'tax_amount' => 0,
                'shipping_charge' => 0,
                'discount_total' => 0,
                'line_total' => $storedSubtotal,
                'refund_total' => 0,
                'rate' => $rate,
                'fulfilled_quantity' => 0,
                'other_info' => [
                    'payment_type' => 'fee',
                    'source_identity' => $fee->identity->canonical(),
                    'source_line_id' => $fee->sourceLineId,
                    'meta' => $fee->meta,
                ],
                'line_meta' => [
                    'source_fee_tax' => $fee->tax,
                    'tax_allocations' => $fee->taxAllocations,
                    'tax_config' => ['inclusive' => $inclusive],
                ],
            ]);
        }

        $shippingRows = [];
        foreach ($record->shippingLines as $shipping) {
            if ($shipping->total < 0 || $shipping->tax < 0) {
                $this->block('Negative shipping has no FluentCart historical-storage contract.');
            }
            $this->assertAllocationTotal($shipping->taxAllocations, $shipping->tax, 'shipping line');
            $shippingRows[] = [
                'source_identity' => $shipping->identity->canonical(),
                'source_line_id' => $shipping->sourceLineId,
                'method_id' => $shipping->methodId,
                'instance_id' => $shipping->instanceId,
                'title' => $shipping->title,
                'total' => $shipping->total + ($inclusive ? $shipping->tax : 0),
                'tax' => $shipping->tax,
                'tax_allocations' => $shipping->taxAllocations,
                'meta' => $shipping->meta,
            ];
        }

        $coupons = [];
        foreach ($record->couponLines as $coupon) {
            $targetId = $context->couponTargets[$coupon->identity->canonical()]
                ?? $context->couponTargets[$coupon->code]
                ?? null;
            $coupons[] = new CouponProjection([
                'coupon_id' => $targetId,
                'code' => $coupon->code,
                'amount' => $coupon->discount + ($inclusive ? $coupon->discountTax : 0),
                'source_identity' => $coupon->identity->canonical(),
                'source_discount_tax' => $coupon->discountTax,
            ]);
        }

        $taxRates = $this->taxRates($record, $context, $inclusive);
        $grossPaid = $this->eventTotal($record, 'charge');
        $totalRefund = $this->eventTotal($record, 'refund');
        $headerSubtotal = array_sum(array_column($productItems, 'subtotal'));
        $headerCoupon = $record->couponDiscountTotal + ($inclusive ? $couponDiscountTax : 0);
        $headerManual = $record->manualDiscountTotal + ($inclusive ? $manualDiscountTax : 0);
        $headerFee = $record->feeTotal + $record->feeTax;
        $headerShipping = $record->shippingTotal + ($inclusive ? $record->shippingTax : 0);
        $totalAmount = $headerSubtotal - $headerCoupon - $headerManual + $headerFee + $headerShipping;
        if ($taxBehavior === 1) {
            $totalAmount += $record->cartTax - $record->feeTax + $record->shippingTax;
        }

        $projection = new OrderMoneyProjection(
            [
                'payment_method' => 'wc_migrated',
                'payment_method_title' => $context->historicalPaymentTitle,
                'mode' => $context->paymentMode,
                'currency' => $record->currency,
                'subtotal' => $headerSubtotal,
                'discount_tax' => $record->discountTax,
                'manual_discount_total' => $headerManual,
                'coupon_discount_total' => $headerCoupon,
                'shipping_tax' => $record->shippingTax,
                'shipping_total' => $headerShipping,
                'fee_total' => $headerFee,
                'tax_total' => $record->cartTax,
                'tax_behavior' => $taxBehavior,
                'total_amount' => $totalAmount,
                'total_paid' => $grossPaid,
                'total_refund' => $totalRefund,
                'rate' => $rate,
            ],
            $productItems,
            $fees,
            $coupons,
            $taxRates,
            $shippingRows,
            $context->taxRoundingAtSubtotal,
        );

        $reconciliation = $this->reconcile($record, $projection);
        if (!$reconciliation->matches) {
            throw new SourceRecordException(
                'order_money_mismatch',
                'Projected FluentCart money does not reconcile: ' . implode(', ', $reconciliation->failures),
            );
        }
        return $projection;
    }

    public function reconcile(OrderRecord $source, OrderMoneyProjection $target): ReconciliationResult
    {
        $header = $target->header;
        $failures = [];
        $inclusive = (int) $header['tax_behavior'] === 2;
        $productSubtotal = array_sum(array_map(static fn (array $row): int => (int) $row['subtotal'], $target->productItems));
        $couponTotal = array_sum(array_map(static fn (CouponProjection $row): int => (int) $row->row['amount'], $target->coupons));
        $feeStored = array_sum(array_map(static function (FeeProjection $fee) use ($header): int {
            $row = $fee->row;
            return (int) $row['subtotal'] + ((int) $header['tax_behavior'] === 1
                ? (int) ($row['line_meta']['source_fee_tax'] ?? 0)
                : 0);
        }, $target->fees));
        $feeTax = array_sum(array_map(
            static fn (FeeProjection $fee): int => (int) ($fee->row['line_meta']['source_fee_tax'] ?? 0),
            $target->fees,
        ));
        $shippingStored = array_sum(array_map(static fn (array $row): int => (int) $row['total'], $target->shippingRows));
        $taxOrder = array_sum(array_map(static fn (TaxProjection $row): int => (int) $row->row['order_tax'], $target->taxRates));
        $taxShipping = array_sum(array_map(static fn (TaxProjection $row): int => (int) $row->row['shipping_tax'], $target->taxRates));

        $this->compare($productSubtotal, (int) $header['subtotal'], 'product_subtotal_mismatch', $failures);
        $this->compare($couponTotal, (int) $header['coupon_discount_total'], 'coupon_discount_mismatch', $failures);
        $this->compare($feeStored, (int) $header['fee_total'], 'fee_total_mismatch', $failures);
        $this->compare($shippingStored, (int) $header['shipping_total'], 'shipping_total_mismatch', $failures);
        $this->compare($taxOrder, (int) $header['tax_total'], 'tax_total_mismatch', $failures);
        $this->compare($taxShipping, (int) $header['shipping_tax'], 'shipping_tax_mismatch', $failures);

        $sourceProductSubtotal = array_sum(array_map(
            static fn (OrderLineRecord $line): int => $line->subtotal + ($inclusive ? $line->subtotalTax : 0),
            $source->productLines,
        ));
        $sourceCouponTax = array_sum(array_map(
            static fn (CouponLineRecord $coupon): int => $coupon->discountTax,
            $source->couponLines,
        ));
        $sourceCouponDiscount = $source->couponDiscountTotal + ($inclusive ? $sourceCouponTax : 0);
        $sourceManualDiscount = $source->manualDiscountTotal
            + ($inclusive ? $source->discountTax - $sourceCouponTax : 0);
        $sourceFee = $source->feeTotal + $source->feeTax;
        $sourceShipping = $source->shippingTotal + ($inclusive ? $source->shippingTax : 0);

        $this->compare($sourceProductSubtotal, (int) $header['subtotal'], 'source_product_subtotal_mismatch', $failures);
        $this->compare($sourceCouponDiscount, (int) $header['coupon_discount_total'], 'source_coupon_discount_mismatch', $failures);
        $this->compare($sourceManualDiscount, (int) $header['manual_discount_total'], 'manual_discount_mismatch', $failures);
        $this->compare(
            $sourceCouponDiscount + $sourceManualDiscount,
            (int) $header['coupon_discount_total'] + (int) $header['manual_discount_total'],
            'source_total_discount_mismatch',
            $failures,
        );
        $this->compare($sourceFee, (int) $header['fee_total'], 'source_fee_total_mismatch', $failures);
        $this->compare($sourceShipping, (int) $header['shipping_total'], 'source_shipping_total_mismatch', $failures);
        $this->compare($source->cartTax, (int) $header['tax_total'], 'source_cart_tax_mismatch', $failures);
        $this->compare($source->shippingTax, (int) $header['shipping_tax'], 'source_shipping_tax_mismatch', $failures);
        $this->compare($this->taxBehavior($source), (int) $header['tax_behavior'], 'tax_behavior_mismatch', $failures);

        foreach ($target->taxRates as $taxRate) {
            $meta = (array) ($taxRate->row['meta'] ?? []);
            $allocations = (array) ($meta['component_allocations'] ?? []);
            $allocatedOrder = array_sum((array) ($allocations['product'] ?? []))
                + array_sum((array) ($allocations['fee'] ?? []));
            $allocatedShipping = array_sum((array) ($allocations['shipping'] ?? []));
            if ($allocatedOrder !== (int) $taxRate->row['order_tax']
                || $allocatedShipping !== (int) $taxRate->row['shipping_tax']) {
                $failures[] = 'per_rate_allocation_mismatch';
            }
        }

        $expectedPaid = $this->eventTotal($source, 'charge');
        $expectedRefund = $this->eventTotal($source, 'refund');
        $this->compare($expectedPaid, (int) $header['total_paid'], 'total_paid_mismatch', $failures);
        $this->compare($expectedRefund, (int) $header['total_refund'], 'total_refund_mismatch', $failures);
        $this->compare($source->discountTax, (int) ($header['discount_tax'] ?? -1), 'discount_tax_mismatch', $failures);

        $computedGross = (int) $header['subtotal']
            - (int) $header['coupon_discount_total']
            - (int) $header['manual_discount_total']
            + (int) $header['fee_total']
            + (int) $header['shipping_total'];
        if ((int) $header['tax_behavior'] === 1) {
            $computedGross += (int) $header['tax_total'] - $feeTax + (int) $header['shipping_tax'];
        }
        $this->compare($computedGross, (int) $header['total_amount'], 'target_formula_mismatch', $failures);
        $this->compare($source->grossTotal, (int) $header['total_amount'], 'gross_total_mismatch', $failures);

        $failures = array_values(array_unique($failures));
        $fingerprint = hash('sha256', CanonicalJson::encode($target->toArray()));
        return new ReconciliationResult($failures === [], $fingerprint, $failures);
    }

    /** @return list<TaxProjection> */
    private function taxRates(OrderRecord $record, OrderProjectionContext $context, bool $inclusive): array
    {
        if ($record->taxRates === []) {
            if ($record->cartTax !== 0 || $record->shippingTax !== 0 || $record->feeTax !== 0) {
                $this->block('A taxed order has no source per-rate evidence.');
            }
            return [new TaxProjection([
                'tax_rate_id' => 0,
                'order_tax' => 0,
                'shipping_tax' => 0,
                'total_tax' => 0,
                'meta' => [
                    'label' => 'No tax',
                    'rate_percent' => 0.0,
                    'is_compound' => false,
                    'taxable_amount' => 0,
                    'inclusive' => false,
                    'source_rate_identity' => null,
                    'component_allocations' => ['product' => [], 'fee' => [], 'shipping' => []],
                ],
            ])];
        }

        $rows = [];
        $targetIds = [];
        foreach ($record->taxRates as $rate) {
            $productAllocations = $this->allocationsForRate($record->productLines, $rate->sourceRateId);
            $feeAllocations = $this->allocationsForRate($record->feeLines, $rate->sourceRateId);
            $shippingAllocations = $this->allocationsForRate($record->shippingLines, $rate->sourceRateId);
            if (array_sum($productAllocations) + array_sum($feeAllocations) !== $rate->orderTax
                || array_sum($shippingAllocations) !== $rate->shippingTax) {
                $this->block('Per-rate product, fee or shipping residual cannot be assigned without changing a source line.');
            }
            $targetId = $context->taxRateTargets[$rate->identity->canonical()] ?? $this->virtualTaxRateId($rate);
            if ($targetId === 0 || isset($targetIds[$targetId])) {
                $this->block('Per-order tax-rate IDs are not unique and representable.');
            }
            $targetIds[$targetId] = true;
            $rows[] = new TaxProjection([
                'tax_rate_id' => $targetId,
                'order_tax' => $rate->orderTax,
                'shipping_tax' => $rate->shippingTax,
                'total_tax' => $rate->orderTax + $rate->shippingTax,
                'meta' => [
                    'label' => $rate->label,
                    'code' => $rate->code,
                    'rate_percent' => (float) $rate->percentage,
                    'is_compound' => $rate->compound,
                    'taxable_amount' => $rate->taxableAmount,
                    'inclusive' => $inclusive,
                    'source_rate_id' => $rate->sourceRateId,
                    'source_rate_identity' => $rate->identity->canonical(),
                    'source_rate_percentage' => $rate->percentage,
                    'round_at_subtotal' => $context->taxRoundingAtSubtotal,
                    'component_allocations' => [
                        'product' => $productAllocations,
                        'fee' => $feeAllocations,
                        'shipping' => $shippingAllocations,
                    ],
                ],
            ]);
        }
        return $rows;
    }

    /** @param list<object> $records @return array<string, int> */
    private function allocationsForRate(array $records, int $sourceRateId): array
    {
        $result = [];
        foreach ($records as $record) {
            $allocations = $record->taxAllocations;
            $amount = $allocations[$sourceRateId] ?? $allocations[(string) $sourceRateId] ?? 0;
            if ($amount !== 0) {
                $result[$record->identity->canonical()] = (int) $amount;
            }
        }
        return $result;
    }

    private function virtualTaxRateId(TaxRateRecord $rate): int
    {
        return -((int) hexdec(substr(hash('sha256', $rate->identity->canonical()), 0, 12)) + 1);
    }

    /** @param array<int|string, int> $allocations */
    private function assertAllocationTotal(array $allocations, int $expected, string $component): void
    {
        foreach ($allocations as $amount) {
            if (!is_int($amount) || $amount < 0) {
                $this->block(ucfirst($component) . ' tax allocation is invalid.');
            }
        }
        if (array_sum($allocations) !== $expected) {
            $this->block(ucfirst($component) . ' tax allocations do not equal its tax total.');
        }
    }

    private function taxBehavior(OrderRecord $record): int
    {
        if ($record->cartTax === 0 && $record->shippingTax === 0 && $record->feeTax === 0) {
            return 0;
        }
        return $record->pricesIncludeTax ? 2 : 1;
    }

    private function integerRate(string $rate): int
    {
        if (preg_match('/\A([1-9][0-9]*)(?:\.0+)?\z/D', $rate, $matches) !== 1) {
            $this->block('Fractional exchange rate cannot be stored consistently in FluentCart BIGINT rate columns.');
        }
        $integer = $matches[1];
        $value = (int) $integer;
        if ((string) $value !== $integer) {
            $this->block('Exchange rate exceeds the installed FluentCart integer range.');
        }
        return $value;
    }

    private function eventTotal(OrderRecord $record, string $type): int
    {
        $total = 0;
        foreach ($record->paymentEvents as $event) {
            if ($event->type === $type && $event->status === 'succeeded') {
                $total += $event->amount;
            }
        }
        return $total;
    }

    /** @param list<string> $failures */
    private function compare(int $actual, int $expected, string $failure, array &$failures): void
    {
        if ($actual !== $expected) {
            $failures[] = $failure;
        }
    }

    private function block(string $message): never
    {
        throw new SourceRecordException('target_schema_unrepresentable', $message);
    }
}

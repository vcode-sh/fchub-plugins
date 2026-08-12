<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

defined('ABSPATH') || exit;

final readonly class OrderMoneyProjection
{
    /**
     * @param array<string, scalar|array|null> $header
     * @param list<array<string, mixed>> $productItems
     * @param list<FeeProjection> $fees
     * @param list<CouponProjection> $coupons
     * @param list<TaxProjection> $taxRates
     * @param list<array<string, mixed>> $shippingRows
     */
    public function __construct(
        public array $header,
        public array $productItems,
        public array $fees,
        public array $coupons,
        public array $taxRates,
        public array $shippingRows,
        public bool $taxRoundingAtSubtotal,
    ) {
        foreach ([$productItems, $fees, $coupons, $taxRates, $shippingRows] as $rows) {
            if (!array_is_list($rows)) {
                throw new \InvalidArgumentException('Order money projection rows must be lists.');
            }
        }
        foreach (['subtotal', 'coupon_discount_total', 'manual_discount_total', 'shipping_total', 'shipping_tax',
            'fee_total', 'tax_total', 'total_amount', 'total_paid', 'total_refund', 'tax_behavior'] as $field) {
            if (!isset($header[$field]) || !is_int($header[$field])) {
                throw new \InvalidArgumentException('Order money projection header is incomplete.');
            }
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'header' => $this->header,
            'product_items' => $this->productItems,
            'fees' => array_map(static fn (FeeProjection $fee): array => $fee->row, $this->fees),
            'coupons' => array_map(static fn (CouponProjection $coupon): array => $coupon->row, $this->coupons),
            'tax_rates' => array_map(static fn (TaxProjection $tax): array => $tax->row, $this->taxRates),
            'shipping_rows' => $this->shippingRows,
            'tax_rounding_at_subtotal' => $this->taxRoundingAtSubtotal,
        ];
    }
}

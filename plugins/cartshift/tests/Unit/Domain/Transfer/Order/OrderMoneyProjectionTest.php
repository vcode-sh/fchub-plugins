<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Order;

use CartShift\Domain\Transfer\Order\AddressRecord;
use CartShift\Domain\Transfer\Order\CouponLineRecord;
use CartShift\Domain\Transfer\Order\FeeLineRecord;
use CartShift\Domain\Transfer\Order\FluentCartOrderMoneyContract;
use CartShift\Domain\Transfer\Order\OrderLineRecord;
use CartShift\Domain\Transfer\Order\OrderMoneyProjection;
use CartShift\Domain\Transfer\Order\OrderProjectionContext;
use CartShift\Domain\Transfer\Order\OrderRecord;
use CartShift\Domain\Transfer\Order\PaymentEvidenceKind;
use CartShift\Domain\Transfer\Order\PaymentEventRecord;
use CartShift\Domain\Transfer\Order\ShippingLineRecord;
use CartShift\Domain\Transfer\Order\TaxRateRecord;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Tests\Unit\PluginTestCase;

final class OrderMoneyProjectionTest extends PluginTestCase
{
    public function testExclusiveTaxSeparatesShippingAndRollsFeeTaxExactlyOnce(): void
    {
        $source = MoneyProjectionFixture::exclusive(exchangeRate: '1.0000');
        $target = $this->contract()->project($source, $this->context());

        self::assertSame(10000, $target->header['subtotal']);
        self::assertSame(2185, $target->header['tax_total']);
        self::assertSame(460, $target->header['shipping_tax']);
        self::assertSame(615, $target->header['fee_total']);
        self::assertSame(14145, $target->header['total_amount']);
        self::assertSame(14145, $target->header['total_paid']);
        self::assertSame(2000, $target->header['total_refund']);
        self::assertSame(1, $target->header['tax_behavior']);
        self::assertSame('wc_migrated', $target->header['payment_method']);
        self::assertSame('Historical WooCommerce provenance', $target->header['payment_method_title']);
        self::assertSame(1, $target->header['rate']);
        self::assertSame(500, $target->fees[0]->row['subtotal']);
        self::assertSame(0, $target->fees[0]->row['tax_amount']);
        self::assertTrue($this->contract()->reconcile($source, $target)->matches);
    }

    public function testInclusiveTaxProjectsGrossStoredValuesWithoutAddingTaxAgain(): void
    {
        $source = MoneyProjectionFixture::inclusive();
        $target = $this->contract()->project($source, $this->context());

        self::assertSame(12300, $target->header['subtotal']);
        self::assertSame(1230, $target->header['coupon_discount_total']);
        self::assertSame(615, $target->header['fee_total']);
        self::assertSame(2460, $target->header['shipping_total']);
        self::assertSame(14145, $target->header['total_amount']);
        self::assertSame(2, $target->header['tax_behavior']);
        self::assertSame(12300, $target->productItems[0]['subtotal']);
        self::assertSame(1230, $target->productItems[0]['discount_total']);
        self::assertSame(11070, $target->productItems[0]['line_total']);
        self::assertSame(615, $target->fees[0]->row['subtotal']);
        self::assertTrue($this->contract()->reconcile($source, $target)->matches);
    }

    public function testZeroTaxUsesSentinelAndUnmappedCouponIdIsNull(): void
    {
        $target = $this->contract()->project(MoneyProjectionFixture::zeroTax(), $this->context());

        self::assertSame(0, $target->header['tax_behavior']);
        self::assertCount(1, $target->taxRates);
        self::assertSame(0, $target->taxRates[0]->row['tax_rate_id']);
        self::assertSame(0.0, $target->taxRates[0]->row['meta']['rate_percent']);
        self::assertFalse($target->taxRates[0]->row['meta']['is_compound']);
        self::assertNull($target->coupons[0]->row['coupon_id']);
        self::assertSame(1000, $target->coupons[0]->row['amount']);
    }

    public function testRoundedDisplayUnitPriceDoesNotReplaceTheExactStoredSubtotal(): void
    {
        $source = MoneyProjectionFixture::roundedDisplayUnitPrice();
        $target = $this->contract()->project($source, $this->context());

        self::assertSame(3333, $target->productItems[0]['unit_price']);
        self::assertSame(10000, $target->productItems[0]['subtotal']);
        self::assertSame(1, $target->productItems[0]['line_meta']['unit_price_remainder']);
        self::assertTrue($this->contract()->reconcile($source, $target)->matches);
    }

    public function testPositiveWooRateIdBecomesVirtualUnlessExactTargetMapExists(): void
    {
        $source = MoneyProjectionFixture::exclusive();
        $virtual = $this->contract()->project($source, $this->context());
        self::assertLessThan(0, $virtual->taxRates[0]->row['tax_rate_id']);
        self::assertNotSame(19, $virtual->taxRates[0]->row['tax_rate_id']);

        $mappedContext = $this->context(taxTargets: [
            $source->taxRates[0]->identity->canonical() => 901,
        ]);
        $mapped = $this->contract()->project($source, $mappedContext);
        self::assertSame(901, $mapped->taxRates[0]->row['tax_rate_id']);
    }

    public function testNegativeFeeRoundingResidualAndFractionalRateEachBlock(): void
    {
        foreach ([
            MoneyProjectionFixture::exclusive(feeTotal: -100),
            MoneyProjectionFixture::exclusive(rateOrderTax: 2184),
            MoneyProjectionFixture::exclusive(exchangeRate: '1.25'),
        ] as $source) {
            try {
                $this->contract()->project($source, $this->context());
                self::fail('Unrepresentable money shape was projected.');
            } catch (SourceRecordException $exception) {
                self::assertSame('target_schema_unrepresentable', $exception->reasonCode);
            }
        }
    }

    public function testTwoRatesCompoundAndBothWooRoundingModesPreserveExactAllocations(): void
    {
        $source = MoneyProjectionFixture::twoRates();
        $lineRounded = $this->contract()->project($source, $this->context(roundAtSubtotal: false));
        $subtotalRounded = $this->contract()->project($source, $this->context(roundAtSubtotal: true));

        self::assertCount(2, $lineRounded->taxRates);
        self::assertSame(2185, array_sum(array_map(
            static fn ($row): int => (int) $row->row['order_tax'],
            $lineRounded->taxRates,
        )));
        self::assertTrue($lineRounded->taxRates[1]->row['meta']['is_compound']);
        self::assertNotSame(
            $lineRounded->taxRates[0]->row['tax_rate_id'],
            $lineRounded->taxRates[1]->row['tax_rate_id'],
        );
        self::assertLessThan(0, $lineRounded->taxRates[0]->row['tax_rate_id']);
        self::assertLessThan(0, $lineRounded->taxRates[1]->row['tax_rate_id']);
        self::assertFalse($lineRounded->taxRates[0]->row['meta']['round_at_subtotal']);
        self::assertTrue($subtotalRounded->taxRates[0]->row['meta']['round_at_subtotal']);
        self::assertSame($lineRounded->header, $subtotalRounded->header);
        self::assertTrue($this->contract()->reconcile($source, $lineRounded)->matches);
        self::assertTrue($this->contract()->reconcile($source, $subtotalRounded)->matches);
    }

    public function testReconciliationRecomputesPersistedRowsRatherThanTrustingHeader(): void
    {
        $source = MoneyProjectionFixture::exclusive();
        $target = $this->contract()->project($source, $this->context());
        $header = $target->header;
        $header['shipping_tax'] = 920;
        $corrupt = new OrderMoneyProjection(
            $header,
            $target->productItems,
            $target->fees,
            $target->coupons,
            $target->taxRates,
            $target->shippingRows,
            $target->taxRoundingAtSubtotal,
        );

        $result = $this->contract()->reconcile($source, $corrupt);

        self::assertFalse($result->matches);
        self::assertContains('shipping_tax_mismatch', $result->failures);
        self::assertContains('target_formula_mismatch', $result->failures);
    }

    public function testReconciliationNamesManualDiscountDriftEvenWhenTheHeaderStillBalances(): void
    {
        $source = MoneyProjectionFixture::exclusive();
        $target = $this->contract()->project($source, $this->context());
        $header = $target->header;
        $header['manual_discount_total'] = 100;
        $header['total_amount'] -= 100;
        $corrupt = new OrderMoneyProjection(
            $header,
            $target->productItems,
            $target->fees,
            $target->coupons,
            $target->taxRates,
            $target->shippingRows,
            $target->taxRoundingAtSubtotal,
        );

        $result = $this->contract()->reconcile($source, $corrupt);

        self::assertFalse($result->matches);
        self::assertContains('manual_discount_mismatch', $result->failures);
    }

    public function testTwoVariationsOfTheSameProductKeepDistinctLineTargets(): void
    {
        $source = MoneyProjectionFixture::twoVariationsSameProduct();
        $context = new OrderProjectionContext(
            productTargets: [
                $source->productLines[0]->identity->canonical() => [
                    'post_id' => 501,
                    'object_id' => 601,
                    'fulfillment_type' => 'digital',
                ],
                $source->productLines[1]->identity->canonical() => [
                    'post_id' => 501,
                    'object_id' => 602,
                    'fulfillment_type' => 'digital',
                ],
            ],
            couponTargets: [],
            taxRateTargets: [],
            paymentMode: 'test',
            historicalPaymentTitle: 'Historical WooCommerce provenance',
            taxRoundingAtSubtotal: true,
        );

        $projection = $this->contract()->project($source, $context);

        self::assertSame([601, 602], array_column($projection->productItems, 'object_id'));
        self::assertSame([501, 501], array_column($projection->productItems, 'post_id'));
    }

    /** @param array<string, int> $taxTargets */
    private function context(array $taxTargets = [], bool $roundAtSubtotal = true): OrderProjectionContext
    {
        return new OrderProjectionContext(
            productTargets: [
                'lapka-web:order:5001:line:11' => [
                    'post_id' => 501,
                    'object_id' => 601,
                    'fulfillment_type' => 'digital',
                ],
            ],
            couponTargets: [],
            taxRateTargets: $taxTargets,
            paymentMode: 'test',
            historicalPaymentTitle: 'Historical WooCommerce provenance',
            taxRoundingAtSubtotal: $roundAtSubtotal,
        );
    }

    private function contract(): FluentCartOrderMoneyContract
    {
        return new FluentCartOrderMoneyContract();
    }
}

final class MoneyProjectionFixture
{
    public static function product(): SourceIdentity
    {
        return new SourceIdentity('lapka-web', 'product', '101');
    }

    public static function exclusive(
        int $feeTotal = 500,
        int $rateOrderTax = 2185,
        string $exchangeRate = '1',
    ): OrderRecord {
        return self::record(false, $feeTotal, $rateOrderTax, $exchangeRate);
    }

    public static function inclusive(): OrderRecord
    {
        return self::record(true, 500, 2185, '1');
    }

    public static function zeroTax(): OrderRecord
    {
        $order = self::record(false, 0, 0, '1', taxed: false);
        return $order;
    }

    public static function roundedDisplayUnitPrice(): OrderRecord
    {
        $base = self::zeroTax();
        $line = self::replace($base->productLines[0], [
            'quantity' => 3,
            'unitPrice' => 3333,
            'lineMeta' => [
                'source_unit_price_remainder' => 1,
                'source_unit_price_rounding_policy' => 'fluentcart_integer_display_floor',
            ],
        ]);

        return self::replace($base, ['productLines' => [$line]]);
    }

    public static function twoVariationsSameProduct(): OrderRecord
    {
        $base = self::zeroTax();
        $first = self::replace($base->productLines[0], [
            'variation' => new SourceIdentity('lapka-web', 'product', '101:variation:102'),
            'otherInfo' => ['source_fulfilment_type' => 'digital'],
        ]);
        $second = self::replace($first, [
            'identity' => new SourceIdentity('lapka-web', 'order', '5001:line:12'),
            'sourceLineId' => 12,
            'variation' => new SourceIdentity('lapka-web', 'product', '101:variation:103'),
            'cartIndex' => 1,
            'discountTotal' => 0,
            'lineTotal' => 10000,
        ]);
        $charge = self::replace($base->paymentEvents[0], ['amount' => 21000]);

        return self::replace($base, [
            'subtotal' => 20000,
            'grossTotal' => 21000,
            'productLines' => [$first, $second],
            'paymentEvents' => [$charge, $base->paymentEvents[1]],
        ]);
    }

    public static function twoRates(): OrderRecord
    {
        $base = self::exclusive();
        $line = self::replace($base->productLines[0], ['taxAllocations' => [19 => 1000, 20 => 1070]]);
        $fee = self::replace($base->feeLines[0], ['taxAllocations' => [20 => 115]]);
        $shipping = self::replace($base->shippingLines[0], ['taxAllocations' => [19 => 200, 20 => 260]]);
        $rates = [
            new TaxRateRecord(
                new SourceIdentity('lapka-web', 'order', '5001:tax_rate:19'),
                51,
                19,
                'PL-BASE-10',
                'Base',
                '10.0000',
                false,
                1000,
                200,
                10000,
                false,
            ),
            new TaxRateRecord(
                new SourceIdentity('lapka-web', 'order', '5001:tax_rate:20'),
                52,
                20,
                'PL-COMPOUND-10',
                'Compound',
                '10.0000',
                true,
                1185,
                260,
                11850,
                false,
            ),
        ];

        return self::replace($base, [
            'productLines' => [$line],
            'feeLines' => [$fee],
            'shippingLines' => [$shipping],
            'taxRates' => $rates,
        ]);
    }

    private static function record(
        bool $inclusive,
        int $feeTotal,
        int $rateOrderTax,
        string $exchangeRate,
        bool $taxed = true,
    ): OrderRecord {
        $orderIdentity = new SourceIdentity('lapka-web', 'order', '5001');
        $rateIdentity = new SourceIdentity('lapka-web', 'order', '5001:tax_rate:19');
        $productSubtotalTax = $taxed ? 2300 : 0;
        $productTax = $taxed ? 2070 : 0;
        $feeTax = $taxed && $feeTotal >= 0 ? 115 : 0;
        $shippingTax = $taxed ? 460 : 0;
        $cartTax = $productTax + $feeTax;
        $discountTax = $taxed ? 230 : 0;
        $gross = $taxed ? 14145 : 11000;

        $line = new OrderLineRecord(
            new SourceIdentity('lapka-web', 'order', '5001:line:11'),
            11,
            self::product(),
            new SourceIdentity('lapka-web', 'product', '101:variation:102'),
            'Course',
            'COURSE',
            [],
            1,
            0,
            10000,
            10000,
            $productSubtotalTax,
            1000,
            $discountTax,
            $productTax,
            9000,
            0,
            'unavailable',
            1,
            $exchangeRate,
            '2026-01-01T10:00:00Z',
            $taxed ? [19 => $productTax] : [],
            [],
            [],
        );
        $fee = $feeTotal === 0 ? [] : [new FeeLineRecord(
            new SourceIdentity('lapka-web', 'order', '5001:fee:21'),
            21,
            'Handling',
            $feeTotal,
            $feeTax,
            $taxed ? [19 => $feeTax] : [],
            [],
        )];
        $shipping = [new ShippingLineRecord(
            new SourceIdentity('lapka-web', 'order', '5001:shipping:31'),
            31,
            'flat_rate',
            2,
            'Courier',
            2000,
            $shippingTax,
            $taxed ? [19 => $shippingTax] : [],
            [],
        )];
        $coupon = [new CouponLineRecord(
            new SourceIdentity('lapka-web', 'order', '5001:coupon:41'),
            41,
            'DOG10',
            1000,
            $discountTax,
        )];
        $rates = $taxed ? [new TaxRateRecord(
            $rateIdentity,
            51,
            19,
            'PL-VAT-23',
            'VAT',
            '23.0000',
            false,
            $rateOrderTax,
            $shippingTax,
            11370,
            $inclusive,
        )] : [];
        $charge = new PaymentEventRecord(
            new SourceIdentity('lapka-web', 'order', '5001:charge:5001'),
            'charge',
            $gross,
            'PLN',
            'succeeded',
            PaymentEvidenceKind::ProviderReference,
            'stripe',
            'Card',
            'ch_private',
            null,
            '2026-01-01T10:00:00Z',
            [],
        );
        $refund = new PaymentEventRecord(
            new SourceIdentity('lapka-web', 'order', '5001:refund:6001'),
            'refund',
            2000,
            'PLN',
            'succeeded',
            PaymentEvidenceKind::ProviderReference,
            'stripe',
            'Card',
            're_private',
            $charge->identity,
            '2026-01-02T10:00:00Z',
            [],
        );

        return new OrderRecord(
            $orderIdentity,
            null,
            null,
            'checkout',
            'completed',
            'PLN',
            'PLN',
            'PLN',
            $exchangeRate,
            'source_currency_equals_target',
            $inclusive,
            10000,
            1000,
            0,
            $discountTax,
            2000,
            $shippingTax,
            $feeTotal,
            $feeTax,
            $cartTax,
            $gross,
            2000,
            '2026-01-01T10:00:00Z',
            null,
            '2026-01-01T10:00:00Z',
            '2026-01-01T10:00:00Z',
            '2026-01-02T10:00:00Z',
            [$line],
            $fee,
            $shipping,
            $coupon,
            $rates,
            [],
            [$charge, $refund],
            [],
            [],
        );
    }

    /** @param array<string, mixed> $replacements */
    private static function replace(object $source, array $replacements): object
    {
        $reflection = new \ReflectionClass($source);
        $constructor = $reflection->getConstructor();
        if (!$constructor) {
            throw new \RuntimeException('Fixture value has no constructor.');
        }
        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();
            $arguments[] = $replacements[$name] ?? $source->{$name};
        }
        return $reflection->newInstanceArgs($arguments);
    }
}

<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Order;

use CartShift\Domain\Transfer\Order\OrderRecordFactory;
use CartShift\Domain\Transfer\Order\PaymentEvidenceKind;
use CartShift\Domain\Transfer\RecordKind;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Tests\Unit\PluginTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class OrderRecordFactoryTest extends PluginTestCase
{
    public function testTaxShippingFeeCouponAddressesNotesAndLinesAreCapturedExactlyOnce(): void
    {
        $order = OrderLedgerFixture::taxed();
        $factory = $this->factory(notes: [OrderLedgerFixture::note(7001, 'Private operational note', false)]);

        $record = $factory->fromWooOrder($order, 'lapka-web');

        self::assertSame('lapka-web:order:5001', $record->identity->canonical());
        self::assertSame(10000, $record->subtotal);
        self::assertSame(1000, $record->couponDiscountTotal);
        self::assertSame(0, $record->manualDiscountTotal);
        self::assertSame(230, $record->discountTax);
        self::assertSame(2000, $record->shippingTotal);
        self::assertSame(460, $record->shippingTax);
        self::assertSame(500, $record->feeTotal);
        self::assertSame(115, $record->feeTax);
        self::assertSame(2185, $record->cartTax);
        self::assertSame(14145, $record->grossTotal);
        self::assertCount(1, $record->productLines);
        self::assertSame(11, $record->productLines[0]->sourceLineId);
        self::assertSame('Frozen course', $record->productLines[0]->name);
        self::assertSame('COURSE-OLD', $record->productLines[0]->sku);
        self::assertSame('digital', $record->productLines[0]->otherInfo['source_fulfilment_type']);
        self::assertSame(1000, $record->productLines[0]->discountTotal);
        self::assertSame(0, $record->productLines[0]->refundTotal);
        self::assertCount(1, $record->feeLines);
        self::assertCount(1, $record->shippingLines);
        self::assertCount(1, $record->couponLines);
        self::assertCount(2, $record->taxRates);
        self::assertSame(['billing', 'shipping'], array_column($record->toArray()['addresses'], 'type'));
        self::assertSame('PL1234567890', $record->addresses[0]->businessTaxId);
        self::assertCount(1, $record->notes);
        self::assertNotSame('', $record->notes[0]->publicIdentifier);
        self::assertSame('Private operational note', $record->notes[0]->content);
    }

    public function testGuestOrderDependsOnItsOrderScopedCanonicalCustomerSnapshot(): void
    {
        $record = $this->factory()->fromWooOrder(OrderLedgerFixture::simple(['customer_id' => 0]), 'lapka-web');

        self::assertSame('lapka-web:customer:5001:guest', $record->customer?->canonical());
    }

    public function testFullRefundKeepsGrossChargeAndSeparateRefund(): void
    {
        $record = $this->factory()->fromWooOrder(OrderLedgerFixture::fullyRefunded(), 'lapka-web');

        self::assertSame(['charge', 'refund'], array_column($record->toArray()['payment_events'], 'type'));
        self::assertSame('succeeded', $record->paymentEvents[0]->status);
        self::assertSame($record->grossTotal, $record->paymentEvents[0]->amount);
        self::assertSame(PaymentEvidenceKind::ProviderReference, $record->paymentEvents[0]->evidenceKind);
        self::assertSame($record->refundedTotal, $record->paymentEvents[1]->amount);
        self::assertSame(11070, $record->productLines[0]->refundTotal);
    }

    public function testFreeManualAndPendingPaymentsRetainHonestEvidenceKinds(): void
    {
        $free = $this->factory()->fromWooOrder(OrderLedgerFixture::simple([
            'total' => '0.00',
            'subtotal' => '0.00',
            'status' => 'completed',
            'transaction_id' => '',
            'items' => [OrderLedgerFixture::item(['subtotal' => '0.00', 'total' => '0.00'])],
        ]), 'lapka-web');
        $manual = $this->factory()->fromWooOrder(OrderLedgerFixture::simple([
            'payment_method' => 'cod',
            'transaction_id' => '',
        ]), 'lapka-web');
        $pending = $this->factory()->fromWooOrder(OrderLedgerFixture::simple([
            'status' => 'pending',
            'transaction_id' => '',
            'date_paid' => null,
        ]), 'lapka-web');

        self::assertSame(PaymentEvidenceKind::FreeNoCharge, $free->paymentEvents[0]->evidenceKind);
        self::assertSame(PaymentEvidenceKind::ManualPaidWithoutProvider, $manual->paymentEvents[0]->evidenceKind);
        self::assertSame(PaymentEvidenceKind::PendingOrFailed, $pending->paymentEvents[0]->evidenceKind);
        self::assertSame('pending', $pending->paymentEvents[0]->status);

        $failed = $this->factory()->fromWooOrder(OrderLedgerFixture::simple([
            'status' => 'failed', 'transaction_id' => '', 'date_paid' => null,
        ]), 'lapka-web');
        self::assertSame('failed', $failed->paymentEvents[0]->status);
    }

    public function testPartialRefundAllocatesOnlyTheExactParentLineAmount(): void
    {
        $record = $this->factory()->fromWooOrder(OrderLedgerFixture::simple([
            'total_refunded' => '50.00',
            'refunds' => [OrderLedgerFixture::refund([
                'amount' => '50.00',
                'items' => [OrderLedgerFixture::refundItem(['total' => '-50.00', 'total_tax' => '0.00'])],
            ])],
        ]), 'lapka-web');

        self::assertSame(5000, $record->refundedTotal);
        self::assertSame(5000, $record->productLines[0]->refundTotal);
        self::assertSame(5000, $record->paymentEvents[1]->amount);
    }

    public function testMissingProductRequiresAnExactHistoricalIdentity(): void
    {
        $order = OrderLedgerFixture::simple(['items' => [OrderLedgerFixture::item(['product_id' => 0, 'variation_id' => 0])]]);
        try {
            $this->factory()->fromWooOrder($order, 'lapka-web');
            self::fail('Missing source product was guessed.');
        } catch (SourceRecordException $exception) {
            self::assertSame('historical_product_missing', $exception->reasonCode);
        }

        $factory = new OrderRecordFactory(
            'PLN', 'PLN', 'unit-test-run-key',
            missingProductResolver: static fn (): array => [
                'identity' => new SourceIdentity('lapka-web', RecordKind::Product->value, '999'),
                'fulfilment_type' => 'digital',
            ],
        );
        $record = $factory->fromWooOrder($order, 'lapka-web');
        self::assertSame('lapka-web:product:999', $record->productLines[0]->product->canonical());
        self::assertSame('lapka-web:product:999:variation:999', $record->productLines[0]->variation->canonical());
        self::assertSame('digital', $record->productLines[0]->otherInfo['source_fulfilment_type']);
    }

    public function testDeletedProductWithRetainedIdRequiresTheExactHistoricalLineResolution(): void
    {
        $order = OrderLedgerFixture::simple(['items' => [OrderLedgerFixture::item([
            'product_id' => 9467,
            'variation_id' => 0,
            'product' => null,
            'name' => 'Testowy webinar',
        ])]]);

        try {
            $this->factory()->fromWooOrder($order, 'lapka-web');
            self::fail('A retained product ID without a loadable product or reviewed placeholder was accepted.');
        } catch (SourceRecordException $exception) {
            self::assertSame('historical_product_missing', $exception->reasonCode);
        }

        $factory = new OrderRecordFactory(
            'PLN', 'PLN', 'unit-test-run-key',
            missingProductResolver: static fn (): array => [
                'identity' => new SourceIdentity('lapka-web', RecordKind::Product->value, '9467'),
                'fulfilment_type' => 'digital',
            ],
        );
        $record = $factory->fromWooOrder($order, 'lapka-web');

        self::assertSame('lapka-web:product:9467', $record->productLines[0]->product->canonical());
        self::assertSame('digital', $record->productLines[0]->otherInfo['source_fulfilment_type']);
    }

    public function testHistoricalLineResolutionCannotReplaceARetainedProductIdentity(): void
    {
        $order = OrderLedgerFixture::simple(['items' => [OrderLedgerFixture::item([
            'product_id' => 9467,
            'variation_id' => 0,
            'product' => null,
        ])]]);
        $factory = new OrderRecordFactory(
            'PLN', 'PLN', 'unit-test-run-key',
            missingProductResolver: static fn (): array => [
                'identity' => new SourceIdentity('lapka-web', RecordKind::Product->value, '9468'),
                'fulfilment_type' => 'digital',
            ],
        );

        try {
            $factory->fromWooOrder($order, 'lapka-web');
            self::fail('A historical resolver replaced the retained source product identity.');
        } catch (SourceRecordException $exception) {
            self::assertSame('dependency_ambiguous', $exception->reasonCode);
        }
    }

    public function testRenewalRelationshipUsesTheTypedIndexRatherThanPostParent(): void
    {
        $record = $this->factory(relationship: static fn (): array => [
            'type' => 'renewal', 'parent_order_id' => 4999,
        ])->fromWooOrder(OrderLedgerFixture::simple(['parent_id' => 123]), 'lapka-web');

        self::assertSame('renewal', $record->relationshipType);
        self::assertSame('lapka-web:order:4999', $record->parentOrder?->canonical());
    }

    public function testExplicitManualDiscountIsSeparatedFromCoupons(): void
    {
        $record = $this->factory()->fromWooOrder(OrderLedgerFixture::simple([
            'discount_total' => '10.00',
            'total' => '90.00',
            'items' => [OrderLedgerFixture::item(['total' => '90.00'])],
        ]), 'lapka-web');

        self::assertSame(0, $record->couponDiscountTotal);
        self::assertSame(1000, $record->manualDiscountTotal);
    }

    public function testRoundedDisplayUnitPricePreservesTheExactLineSubtotalAndRemainder(): void
    {
        $record = $this->factory()->fromWooOrder(OrderLedgerFixture::simple([
            'items' => [OrderLedgerFixture::item(['quantity' => 3])],
        ]), 'lapka-web');

        self::assertSame(3333, $record->productLines[0]->unitPrice);
        self::assertSame(10000, $record->productLines[0]->subtotal);
        self::assertSame(1, $record->productLines[0]->lineMeta['source_unit_price_remainder']);
        self::assertSame('fluentcart_integer_display_floor', $record->productLines[0]->lineMeta['source_unit_price_rounding_policy']);
    }

    public function testManualMarkerCannotInventAChildDiscount(): void
    {
        $this->expectException(SourceRecordException::class);
        $this->expectExceptionMessage('Order child discount does not reconcile');

        $this->factory()->fromWooOrder(OrderLedgerFixture::simple([
            'discount_total' => '10.00',
            'total' => '90.00',
            'meta' => ['_cartshift_manual_discount_evidence' => 'unsupported-marker'],
        ]), 'lapka-web');
    }

    public function testForeignCurrencyRequiresNamedRateEvidence(): void
    {
        $order = OrderLedgerFixture::simple(['currency' => 'EUR']);

        try {
            $this->factory()->fromWooOrder($order, 'lapka-web');
            self::fail('Foreign currency without an adapter was accepted.');
        } catch (SourceRecordException $exception) {
            self::assertSame('target_schema_unrepresentable', $exception->reasonCode);
        }

        $record = $this->factory(rateAdapter: static fn (): array => [
            'rate' => '4.3210',
            'evidence' => 'nbp-table-a-2026-01-02',
        ])->fromWooOrder($order, 'lapka-web');
        self::assertSame('4.3210', $record->exchangeRateDecimal);
        self::assertSame('nbp-table-a-2026-01-02', $record->exchangeRateEvidence);

        try {
            $this->factory(rateAdapter: static fn (): array => ['rate' => '1.0000', 'evidence' => 'guess'])
                ->fromWooOrder($order, 'lapka-web');
            self::fail('Foreign-currency rate 1 was accepted.');
        } catch (SourceRecordException $exception) {
            self::assertSame('target_schema_unrepresentable', $exception->reasonCode);
        }
    }

    #[DataProvider('invalidLedgerProvider')]
    public function testInvalidSourceLedgerBlocks(array $overrides, string $reason): void
    {
        try {
            $this->factory(relationship: $overrides['relationship'] ?? null)
                ->fromWooOrder(OrderLedgerFixture::simple($overrides['order'] ?? $overrides), 'lapka-web');
            self::fail('Invalid source ledger was accepted.');
        } catch (SourceRecordException $exception) {
            self::assertSame($reason, $exception->reasonCode);
        }
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidLedgerProvider(): array
    {
        return [
            'line subtotal drifts from header' => [[
                'items' => [OrderLedgerFixture::item(['subtotal' => '99.99', 'total' => '99.99'])],
            ], 'order_money_mismatch'],
            'tax allocation differs from header' => [[
                'total_tax' => '1.00',
            ], 'order_tax_mismatch'],
            'per-rate allocation drifts while aggregate remains equal' => [[
                'order' => [
                    'items' => [OrderLedgerFixture::item([
                        'subtotal_tax' => '20.70', 'total_tax' => '20.70',
                        'taxes' => ['total' => [19 => '20.69', 20 => '0.01'], 'subtotal' => [19 => '20.70']],
                    ])],
                    'total_tax' => '20.70', 'total' => '120.70',
                    'tax_items' => [new LedgerDouble([
                        'id' => 51, 'rate_id' => 19, 'rate_code' => 'PL-VAT-23', 'label' => 'VAT',
                        'rate_percent' => '23.0000', 'compound' => false, 'tax_total' => '20.70',
                        'shipping_tax_total' => '0.00', 'rate_included' => false,
                    ])],
                ],
            ], 'order_tax_mismatch'],
            'fractional quantity is forbidden' => [[
                'items' => [OrderLedgerFixture::item(['quantity' => '1.5'])],
            ], 'target_schema_unrepresentable'],
            'refund exceeds proven charge' => [[
                'total_refunded' => '101.00',
                'refunds' => [OrderLedgerFixture::refund(['amount' => '101.00'])],
            ], 'order_money_mismatch'],
            'refund line has no parent' => [[
                'total_refunded' => '10.00',
                'refunds' => [OrderLedgerFixture::refund([
                    'amount' => '10.00',
                    'items' => [OrderLedgerFixture::refundItem(['refunded_item_id' => 999])],
                ])],
            ], 'refund_parent_ambiguous'],
            'refund currency differs' => [[
                'total_refunded' => '10.00',
                'refunds' => [OrderLedgerFixture::refund(['amount' => '10.00', 'currency' => 'EUR', 'items' => []])],
            ], 'order_money_mismatch'],
            'duplicate refund identity' => [[
                'total_refunded' => '20.00',
                'refunds' => [
                    OrderLedgerFixture::refund(['amount' => '10.00', 'items' => []]),
                    OrderLedgerFixture::refund(['amount' => '10.00', 'items' => []]),
                ],
            ], 'source_identity_conflict'],
            'ambiguous relationship' => [[
                'relationship' => static fn (): array => [
                    ['type' => 'renewal', 'parent_order_id' => 1],
                    ['type' => 'switch', 'parent_order_id' => 2],
                ],
            ], 'refund_parent_ambiguous'],
            'multiple provider references need an adapter' => [[
                'meta' => ['_cartshift_provider_references' => ['a', 'b']],
            ], 'charge_parent_missing'],
            'explicit paid amount cannot overpay' => [[
                'meta' => ['_cartshift_paid_amount' => '101.00'],
            ], 'order_money_mismatch'],
        ];
    }

    public function testAmbiguousNoteVisibilityBlocks(): void
    {
        $this->expectException(SourceRecordException::class);
        $this->factory(notes: [new LedgerDouble([
            'id' => 1, 'content' => 'note', 'date_created' => new LedgerDate('2026-01-01T00:00:00Z'),
            'customer_note' => null, 'added_by' => 'system',
        ])])->fromWooOrder(OrderLedgerFixture::simple(), 'lapka-web');
    }

    public function testNoteContentChangesOnlyThePrivateDigest(): void
    {
        $order = OrderLedgerFixture::simple();
        $first = $this->factory(notes: [OrderLedgerFixture::note(1, 'first', true)])
            ->fromWooOrder($order, 'lapka-web')->envelope();
        $second = $this->factory(notes: [OrderLedgerFixture::note(1, 'second', true)])
            ->fromWooOrder($order, 'lapka-web')->envelope();

        self::assertSame($first->structuralFingerprint, $second->structuralFingerprint);
        self::assertNotSame($first->privateContentDigest, $second->privateContentDigest);

        $record = $this->factory(notes: [OrderLedgerFixture::note(1, 'never public', true)])
            ->fromWooOrder($order, 'lapka-web');
        self::assertStringNotContainsString('never public', json_encode($record->publicEvidence(), JSON_THROW_ON_ERROR));
    }

    /** @param list<object> $notes */
    private function factory(
        array $notes = [],
        ?callable $rateAdapter = null,
        ?callable $relationship = null,
    ): OrderRecordFactory {
        return new OrderRecordFactory(
            sourceStoreCurrency: 'PLN',
            targetBaseCurrency: 'PLN',
            noteIdentifierKey: 'unit-test-run-key',
            notesReader: static fn () => $notes,
            relationshipResolver: $relationship,
            currencyRateAdapter: $rateAdapter,
            approvedMetaKeys: ['_billing_vat_number'],
        );
    }
}

final class OrderLedgerFixture
{
    public static function simple(array $overrides = []): object
    {
        return new LedgerDouble(array_replace([
            'id' => 5001,
            'status' => 'completed',
            'currency' => 'PLN',
            'prices_include_tax' => false,
            'subtotal' => '100.00',
            'discount_total' => '0.00',
            'discount_tax' => '0.00',
            'shipping_total' => '0.00',
            'shipping_tax' => '0.00',
            'total_tax' => '0.00',
            'total' => '100.00',
            'total_refunded' => '0.00',
            'customer_id' => 77,
            'parent_id' => 0,
            'transaction_id' => 'ch_5001',
            'payment_method' => 'stripe',
            'payment_method_title' => 'Card',
            'date_created' => new LedgerDate('2026-01-02T10:00:00Z'),
            'date_modified' => new LedgerDate('2026-01-02T10:05:00Z'),
            'date_paid' => new LedgerDate('2026-01-02T10:01:00Z'),
            'date_completed' => new LedgerDate('2026-01-02T10:02:00Z'),
            'items' => [self::item()],
            'fee_items' => [],
            'shipping_items' => [],
            'coupon_items' => [],
            'tax_items' => [],
            'refunds' => [],
            'billing' => ['first_name' => 'Tom', 'last_name' => 'Buyer', 'company' => 'Dog Ltd', 'address_1' => 'Main 1', 'address_2' => '', 'city' => 'Warsaw', 'state' => '', 'postcode' => '00-001', 'country' => 'PL', 'email' => 'buyer@example.test', 'phone' => '123'],
            'shipping' => ['first_name' => 'Tom', 'last_name' => 'Buyer', 'company' => '', 'address_1' => 'Main 1', 'address_2' => '', 'city' => 'Warsaw', 'state' => '', 'postcode' => '00-001', 'country' => 'PL', 'email' => '', 'phone' => ''],
            'meta' => ['_billing_vat_number' => 'PL1234567890'],
        ], $overrides));
    }

    public static function taxed(): object
    {
        return self::simple([
            'subtotal' => '100.00',
            'discount_total' => '10.00',
            'discount_tax' => '2.30',
            'shipping_total' => '20.00',
            'shipping_tax' => '4.60',
            'total_tax' => '26.45',
            'total' => '141.45',
            'items' => [self::item(['subtotal' => '100.00', 'subtotal_tax' => '23.00', 'total' => '90.00', 'total_tax' => '20.70', 'taxes' => ['total' => [19 => '20.70'], 'subtotal' => [19 => '23.00']]])],
            'fee_items' => [new LedgerDouble(['id' => 21, 'name' => 'Handling', 'total' => '5.00', 'total_tax' => '1.15', 'taxes' => ['total' => [19 => '1.15']]])],
            'shipping_items' => [new LedgerDouble(['id' => 31, 'method_id' => 'flat_rate', 'instance_id' => 2, 'method_title' => 'Courier', 'total' => '20.00', 'total_tax' => '4.60', 'taxes' => ['total' => [19 => '4.60']], 'meta_data' => []])],
            'coupon_items' => [new LedgerDouble(['id' => 41, 'code' => 'DOG10', 'discount' => '10.00', 'discount_tax' => '2.30'])],
            'tax_items' => [
                new LedgerDouble(['id' => 51, 'rate_id' => 19, 'rate_code' => 'PL-VAT-23', 'label' => 'VAT', 'rate_percent' => '23.0000', 'compound' => false, 'tax_total' => '21.85', 'shipping_tax_total' => '4.60', 'rate_included' => false]),
                new LedgerDouble(['id' => 52, 'rate_id' => 20, 'rate_code' => 'PL-ZERO', 'label' => 'Zero', 'rate_percent' => '0.0000', 'compound' => false, 'tax_total' => '0.00', 'shipping_tax_total' => '0.00', 'rate_included' => false]),
            ],
        ]);
    }

    public static function fullyRefunded(): object
    {
        $base = self::taxed();
        $data = $base->data;
        $data['status'] = 'refunded';
        $data['total_refunded'] = '141.45';
        $data['refunds'] = [self::refund()];
        return new LedgerDouble($data);
    }

    public static function item(array $overrides = []): object
    {
        return new LedgerDouble(array_replace([
            'id' => 11, 'product_id' => 101, 'variation_id' => 102, 'quantity' => 1,
            'subtotal' => '100.00', 'subtotal_tax' => '0.00', 'total' => '100.00', 'total_tax' => '0.00',
            'name' => 'Frozen course', 'sku' => 'COURSE-OLD', 'taxes' => ['total' => [], 'subtotal' => []],
            'product' => new LedgerDouble(['downloadable' => true, 'virtual' => true]),
            'meta_data' => [['key' => 'Level', 'value' => 'Advanced', 'display_key' => 'Level', 'display_value' => 'Advanced']],
        ], $overrides));
    }

    public static function refund(array $overrides = []): object
    {
        return new LedgerDouble(array_replace([
            'id' => 6001, 'amount' => '141.45', 'currency' => 'PLN', 'reason' => 'Customer request',
            'transaction_id' => 're_6001', 'date_created' => new LedgerDate('2026-01-03T10:00:00Z'),
            'items' => [self::refundItem()],
        ], $overrides));
    }

    public static function refundItem(array $overrides = []): object
    {
        return new LedgerDouble(array_replace([
            'id' => 61, 'refunded_item_id' => 11, 'total' => '-90.00', 'total_tax' => '-20.70',
        ], $overrides));
    }

    public static function note(int $id, string $content, bool $customer): object
    {
        return new LedgerDouble([
            'id' => $id,
            'content' => $content,
            'date_created' => new LedgerDate('2026-01-02T11:00:00Z'),
            'customer_note' => $customer,
            'added_by' => 'system',
        ]);
    }
}

final class LedgerDouble
{
    public function __construct(public array $data)
    {
    }

    public function __call(string $name, array $arguments): mixed
    {
        if (!str_starts_with($name, 'get_') && !str_starts_with($name, 'is_')) {
            throw new \BadMethodCallException($name);
        }
        $key = preg_replace('/\A(?:get|is)_/', '', $name);
        if ($key === 'items') {
            $type = $arguments[0] ?? 'line_item';
            return $this->data[match ($type) {
                'fee' => 'fee_items', 'shipping' => 'shipping_items', 'coupon' => 'coupon_items',
                'tax' => 'tax_items', default => 'items',
            }] ?? [];
        }
        if ($key === 'meta') {
            return $this->data['meta'][$arguments[0] ?? ''] ?? '';
        }
        if (array_key_exists((string) $key, $this->data)) {
            return $this->data[$key];
        }
        if (preg_match('/\A(billing|shipping)_(.+)\z/', (string) $key, $matches) === 1) {
            return $this->data[$matches[1]][$matches[2]] ?? null;
        }
        if ($key === 'formatted_meta_data') {
            return $this->data['meta_data'] ?? [];
        }
        return null;
    }
}

final class LedgerDate
{
    public function __construct(private readonly string $value)
    {
    }

    public function date(string $format): string
    {
        return (new \DateTimeImmutable($this->value))->format($format);
    }

    public function getTimestamp(): int
    {
        return (new \DateTimeImmutable($this->value))->getTimestamp();
    }
}

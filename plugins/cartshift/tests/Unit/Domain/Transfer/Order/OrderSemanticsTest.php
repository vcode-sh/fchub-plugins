<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Order;

use CartShift\Domain\Transfer\Order\AddressProjection;
use CartShift\Domain\Transfer\Order\AddressRecord;
use CartShift\Domain\Transfer\Order\FulfilmentPolicy;
use CartShift\Domain\Transfer\Order\OrderLineRecord;
use CartShift\Domain\Transfer\Order\OrderMetadataProjection;
use CartShift\Domain\Transfer\Order\OrderRecord;
use CartShift\Domain\Transfer\Order\OrderStatusPolicy;
use CartShift\Domain\Transfer\Order\ShippingLineRecord;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Support\UtcDateTime;
use CartShift\Tests\Unit\PluginTestCase;

final class OrderSemanticsTest extends PluginTestCase
{
    public function testUnknownCustomStatusBlocksInsteadOfBecomingPending(): void
    {
        try {
            (new OrderStatusPolicy())->project('wc-awaiting-pickup');
            self::fail('Unknown custom status was guessed.');
        } catch (SourceRecordException $exception) {
            self::assertSame('unsupported_order_status', $exception->reasonCode);
            self::assertStringContainsString('unsupported_order_status', $exception->getMessage());
        }
    }

    public function testApprovedCustomStatusDeclaresAllThreeIndependentOutcomes(): void
    {
        $projection = (new OrderStatusPolicy([
            'awaiting-pickup' => [
                'order_status' => 'on-hold',
                'payment_status' => 'paid',
                'fulfilment_implication' => 'unshipped',
            ],
        ]))->project('wc-awaiting-pickup');

        self::assertSame('on-hold', $projection->orderStatus);
        self::assertSame('paid', $projection->paymentStatus);
        self::assertSame('unshipped', $projection->fulfilmentImplication);
        self::assertTrue($projection->custom);
    }

    public function testDigitalOrderHasBlankShippingStatusAndQuantityIsFulfilled(): void
    {
        $order = $this->record(['digital']);
        $projection = (new FulfilmentPolicy())->project($order);

        self::assertSame('digital', $projection->fulfilmentType);
        self::assertSame('', $projection->shippingStatus);
        self::assertSame(
            2,
            $projection->fulfilledQuantities[$order->productLines[0]->identity->canonical()],
        );
    }

    public function testPhysicalOrderRequiresFingerprintBoundPolicyAndMixedOrderBlocks(): void
    {
        foreach ([
            [new FulfilmentPolicy(), $this->record(['physical'])],
            [new FulfilmentPolicy('unshipped', str_repeat('a', 64)), $this->record(['physical', 'digital'])],
        ] as [$policy, $order]) {
            try {
                $policy->project($order);
                self::fail('Unproved fulfilment shape was accepted.');
            } catch (SourceRecordException $exception) {
                self::assertSame('target_schema_unrepresentable', $exception->reasonCode);
            }
        }

        $unshipped = (new FulfilmentPolicy('unshipped', str_repeat('a', 64)))
            ->project($this->record(['physical']));
        self::assertSame('unshipped', $unshipped->shippingStatus);
        self::assertSame(0, array_values($unshipped->fulfilledQuantities)[0]);

        $complete = (new FulfilmentPolicy('historical_complete', str_repeat('b', 64)))
            ->project($this->record(['physical']));
        self::assertSame('delivered', $complete->shippingStatus);
        self::assertSame(2, array_values($complete->fulfilledQuantities)[0]);
    }

    public function testMeaningfulBillingAddressProjectsCanonicalBusinessCopies(): void
    {
        $address = $this->address([
            'firstName' => '',
            'lastName' => '',
            'company' => 'Example Sp. z o.o.',
            'city' => 'Warsaw',
            'country' => 'PL',
            'phone' => '+48 500 000 000',
            'businessTaxId' => 'PL 529-183-11-15',
        ]);
        $projection = AddressProjection::project($address);

        self::assertNotNull($projection);
        self::assertSame('+48 500 000 000', $projection->row['meta']['other_data']['phone']);
        self::assertSame('Example Sp. z o.o.', $projection->row['meta']['other_data']['company_name']);
        self::assertSame('5291831115', $projection->row['meta']['other_data']['vat_number']);
        self::assertSame('5291831115', $projection->row['meta']['other_data']['nip']);
        self::assertSame('5291831115', $projection->businessInfo['tax_number']);
        self::assertTrue($projection->businessInfo['tax_number_validated']);
        self::assertTrue($projection->reconcilesBusinessTaxId());
        self::assertContains('business_tax_id', $projection->sourceFieldPresence);
        self::assertNotContains('5291831115', $projection->sourceFieldPresence);
    }

    public function testAddressWithOnlyCityIsEmittedAndEmptyAddressIsNot(): void
    {
        self::assertNotNull(AddressProjection::project($this->address(['city' => 'Warsaw'])));
        self::assertNull(AddressProjection::project($this->address()));
    }

    public function testInvalidPolishBusinessTaxIdBlocksRatherThanClaimingValidation(): void
    {
        $this->expectException(SourceRecordException::class);
        AddressProjection::project($this->address([
            'country' => 'PL',
            'businessTaxId' => '5291831116',
        ]));
    }

    public function testMetadataUsesCanonicalShippingTitleAndMatchingBusinessInfo(): void
    {
        $order = $this->record(['digital'], [$this->address([
            'company' => 'Example Sp. z o.o.',
            'country' => 'PL',
            'businessTaxId' => '5291831115',
        ])], shipping: true);
        $address = AddressProjection::project($order->addresses[0]);
        self::assertNotNull($address);

        $projection = OrderMetadataProjection::project($order, [$address]);

        self::assertSame('Courier', $projection->config['shipping_method_title']);
        self::assertSame('business_info', $projection->metaRows[0]['meta_key']);
        self::assertSame('5291831115', $projection->metaRows[0]['meta_value']['tax_number']);
        self::assertTrue($projection->reconciles([$address]));
        self::assertArrayNotHasKey('meta', $projection->shippingProvenance[0]);
    }

    public function testUtcDateTimeConvertsThroughEpochAndTargetFormat(): void
    {
        $warsaw = new \DateTimeImmutable('2026-07-10 12:34:56', new \DateTimeZone('Europe/Warsaw'));

        self::assertSame('2026-07-10T10:34:56Z', UtcDateTime::canonical($warsaw));
        self::assertSame('2026-07-10 10:34:56', UtcDateTime::target($warsaw));
        self::assertSame(
            '2026-07-10 10:34:56',
            UtcDateTime::targetFromCanonical('2026-07-10T10:34:56Z'),
        );
    }

    /** @param list<string> $lineTypes @param list<AddressRecord> $addresses */
    private function record(array $lineTypes, array $addresses = [], bool $shipping = false): OrderRecord
    {
        $orderIdentity = new SourceIdentity('lapka-web', 'order', '9001');
        $lines = [];
        foreach ($lineTypes as $index => $type) {
            $lineId = $index + 1;
            $lines[] = new OrderLineRecord(
                new SourceIdentity('lapka-web', 'order', '9001:item:' . $lineId),
                $lineId,
                new SourceIdentity('lapka-web', 'product', (string) (100 + $lineId)),
                new SourceIdentity('lapka-web', 'product', (100 + $lineId) . ':variation:' . (100 + $lineId)),
                'Item',
                '',
                [],
                2,
                $index,
                500,
                1000,
                0,
                0,
                0,
                0,
                1000,
                0,
                'unavailable',
                0,
                '1',
                '2026-01-01T10:00:00Z',
                [],
                ['source_fulfilment_type' => $type],
                [],
            );
        }
        $shippingLines = $shipping ? [new ShippingLineRecord(
            new SourceIdentity('lapka-web', 'order', '9001:shipping:1'),
            1,
            'flat_rate',
            1,
            'Courier',
            0,
            0,
            [],
            ['private_source_value' => 'never projected'],
        )] : [];

        return new OrderRecord(
            $orderIdentity,
            null,
            null,
            'checkout',
            'completed',
            'PLN',
            'PLN',
            'PLN',
            '1',
            'source_currency_equals_target',
            false,
            count($lines) * 1000,
            0,
            0,
            0,
            0,
            0,
            0,
            0,
            0,
            count($lines) * 1000,
            0,
            '2026-01-01T10:00:00Z',
            null,
            '2026-01-01T10:00:00Z',
            '2026-01-01T10:00:00Z',
            null,
            $lines,
            [],
            $shippingLines,
            [],
            [],
            $addresses,
            [],
            [],
            [],
        );
    }

    /** @param array<string, string> $overrides */
    private function address(array $overrides = []): AddressRecord
    {
        $values = array_merge([
            'type' => 'billing',
            'firstName' => '',
            'lastName' => '',
            'company' => '',
            'address1' => '',
            'address2' => '',
            'city' => '',
            'state' => '',
            'postcode' => '',
            'country' => '',
            'email' => '',
            'phone' => '',
            'businessTaxId' => '',
        ], $overrides);

        return new AddressRecord(
            new SourceIdentity('lapka-web', 'order', '9001:address:1'),
            $values['type'],
            $values['firstName'],
            $values['lastName'],
            $values['company'],
            $values['address1'],
            $values['address2'],
            $values['city'],
            $values['state'],
            $values['postcode'],
            $values['country'],
            $values['email'],
            $values['phone'],
            $values['businessTaxId'],
        );
    }
}

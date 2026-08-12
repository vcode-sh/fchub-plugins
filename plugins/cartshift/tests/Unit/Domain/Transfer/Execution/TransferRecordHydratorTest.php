<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Execution;

use CartShift\Domain\Transfer\Customer\CustomerAddressRecord;
use CartShift\Domain\Transfer\Customer\CustomerRecord;
use CartShift\Domain\Transfer\Execution\TransferRecordHydrator;
use CartShift\Domain\Transfer\Order\OrderRecord;
use CartShift\Domain\Transfer\Product\ProductRecord;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Tests\Unit\Domain\Transfer\Product\ProductAssessmentFixture;
use CartShift\Tests\Unit\PluginTestCase;

final class TransferRecordHydratorTest extends PluginTestCase
{
    public function testProductCustomerAndOrderPayloadsRoundTripThroughIndependentTypedConstruction(): void
    {
        $product = ProductAssessmentFixture::product();
        $customerIdentity = new SourceIdentity('shop-alpha', 'customer', '7');
        $customer = CustomerRecord::create(
            $customerIdentity,
            7,
            'registered',
            'Ada',
            'Lovelace',
            'ada@example.test',
            'active',
            [new CustomerAddressRecord(
                new SourceIdentity('shop-alpha', 'customer', '7:address:billing'),
                'billing', true, 'active', 'Billing', 'Ada Lovelace', '', 'One Way', '', 'London', '', 'N1', 'GB', '', 'ada@example.test',
            )],
            '2026-01-01T00:00:00Z',
            null,
            ['origin' => 'source_user'],
            [],
        );
        $orderIdentity = new SourceIdentity('shop-alpha', 'order', '9');
        $order = new OrderRecord(
            $orderIdentity, $customerIdentity, null, 'checkout', 'completed', 'USD', 'USD', 'USD', '1.0000',
            'same_currency:USD', false, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            '2026-01-01T00:00:00Z', null, null, null, null, [], [], [], [], [], [], [], [], [],
        );
        $hydrator = new TransferRecordHydrator();

        self::assertInstanceOf(ProductRecord::class, $hydrator->product($product->envelope()));
        self::assertInstanceOf(CustomerRecord::class, $hydrator->customer($customer->envelope()));
        self::assertInstanceOf(OrderRecord::class, $hydrator->order($order->envelope()));
    }

    public function testExtraOrRekeyedPayloadFieldsCannotSlipPastConstructorRoundTrip(): void
    {
        $product = ProductAssessmentFixture::product();
        $payload = $product->toArray();
        $payload['writer_hint'] = 'trust-me';
        $envelope = RecordEnvelope::forPayload(1, $product->identity, $payload);

        $this->expectExceptionMessage('target_record_payload_roundtrip_mismatch');
        (new TransferRecordHydrator())->product($envelope);
    }
}

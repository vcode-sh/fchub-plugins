<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Subscription;

use CartShift\Domain\Subscription\FluentCartSubscriptionOrderStage;
use CartShift\Domain\Subscription\OrderRecord as LegacyOrderRecord;
use CartShift\Domain\Subscription\SubscriptionRecordFactory;
use CartShift\Domain\Transfer\Order\OrderLineRecord;
use CartShift\Domain\Transfer\Order\OrderProjectionContext;
use CartShift\Domain\Transfer\Order\OrderRecord;
use CartShift\Domain\Transfer\Order\OrderStagePlan;
use CartShift\Domain\Transfer\Order\OrderStageResult;
use CartShift\Domain\Transfer\Order\OrderStageWriter;
use CartShift\Domain\Transfer\Order\PaymentEventRecord;
use CartShift\Domain\Transfer\Order\PaymentEvidenceKind;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Domain\Transfer\StageContext;
use CartShift\Tests\Unit\PluginTestCase;

final class FluentCartSubscriptionOrderStageTest extends PluginTestCase
{
    public function testExactTypedCanonicalRecordIsBuiltIntoTheSharedWriterPlan(): void
    {
        $legacy = $this->legacyRecord();
        $canonical = $this->canonicalRecord('parent');
        $writer = new RecordingOrderStageWriter();
        $context = new StageContext(dirname(__DIR__, 4), 'subscription-order-run', str_repeat('a', 64));
        $stage = new FluentCartSubscriptionOrderStage(
            $writer,
            $context,
            static fn (LegacyOrderRecord $source, string $relationship): OrderRecord => $canonical,
            fn (OrderRecord $record): OrderProjectionContext => $this->projection($record),
            static fn (OrderRecord $record): array => [
                'canonical_customer_note' => null,
                'decision_fingerprint' => null,
            ],
        );

        $result = $stage->stage($legacy, 'parent', 501, null, 'subscription-order-run');

        self::assertSame(92_001, $result->targetId);
        self::assertCount(1, $writer->calls);
        self::assertSame('subscription', $writer->calls[0]['plan']->header['type']);
        self::assertSame(501, $writer->calls[0]['plan']->header['customer_id']);
        self::assertNull($writer->calls[0]['plan']->header['parent_id']);
        self::assertSame('subscription-order-run', $writer->calls[0]['context']->migrationId);
    }

    public function testRelationshipDriftBlocksBeforeTheSharedWriterCanMutate(): void
    {
        $writer = new RecordingOrderStageWriter();
        $stage = new FluentCartSubscriptionOrderStage(
            $writer,
            new StageContext(dirname(__DIR__, 4), 'subscription-order-run', str_repeat('a', 64)),
            fn (LegacyOrderRecord $source, string $relationship): OrderRecord => $this->canonicalRecord('checkout'),
            fn (OrderRecord $record): OrderProjectionContext => $this->projection($record),
            static fn (OrderRecord $record): array => [
                'canonical_customer_note' => null,
                'decision_fingerprint' => null,
            ],
        );

        try {
            $stage->stage($this->legacyRecord(), 'parent', 501, null, 'subscription-order-run');
            self::fail('A relationship-changing v1 conversion reached the target writer.');
        } catch (SourceRecordException $exception) {
            self::assertSame('blocked_subscription_v1_conversion', $exception->reasonCode);
        }
        self::assertSame([], $writer->calls);
    }

    private function legacyRecord(): LegacyOrderRecord
    {
        $record = (new SubscriptionRecordFactory())->orderFromPayload('lapka', [
            'source_order_id' => 880_001,
            'status' => 'completed',
            'currency' => 'PLN',
            'source_customer_ref' => 'customer:660001',
            'billing_email' => 'subscriber@example.invalid',
            'addresses' => [],
            'items' => [[
                'source_item_id' => 41_000,
                'source_product_id' => 440_001,
                'source_variation_id' => 0,
                'pseudo_variation_key' => '440001',
                'name' => 'Membership',
                'quantity' => 1,
                'line_total' => 2900,
                'line_tax' => 0,
            ]],
            'transactions' => [[
                'source_transaction_id' => 'txn-880001',
                'type' => 'charge',
                'status' => 'succeeded',
                'total' => 2900,
                'currency' => 'PLN',
                'gateway' => 'stripe',
                'paid_at_utc' => '2023-04-11 09:15:00',
            ]],
            'totals' => ['subtotal' => 2900, 'tax' => 0, 'total' => 2900, 'refunded' => 0],
            'dates' => ['created_utc' => '2023-04-11 09:15:00', 'paid_utc' => '2023-04-11 09:15:00'],
        ]);
        self::assertInstanceOf(LegacyOrderRecord::class, $record);
        return $record;
    }

    private function canonicalRecord(string $relationship): OrderRecord
    {
        $order = new SourceIdentity('lapka', 'order', '880001');
        $customer = new SourceIdentity('lapka', 'customer', '660001');
        $product = new SourceIdentity('lapka', 'product', '440001');
        $variation = new SourceIdentity('lapka', 'product', '440001:variation:440001');
        return new OrderRecord(
            $order,
            $customer,
            null,
            $relationship,
            'completed',
            'PLN',
            'PLN',
            'PLN',
            '1.0000',
            'same_currency:PLN',
            false,
            2900,
            0,
            0,
            0,
            0,
            0,
            0,
            0,
            0,
            2900,
            0,
            '2023-04-11T09:15:00Z',
            null,
            '2023-04-11T09:15:00Z',
            '2023-04-11T09:15:00Z',
            null,
            [new OrderLineRecord(
                new SourceIdentity('lapka', 'order', '880001:item:41000'),
                41_000,
                $product,
                $variation,
                'Membership',
                '',
                [],
                1,
                0,
                2900,
                2900,
                0,
                0,
                0,
                0,
                2900,
                0,
                'not_available_from_woo_core',
                0,
                '1.0000',
                '2023-04-11T09:15:00Z',
                [],
                ['source_fulfilment_type' => 'digital'],
                [],
            )],
            [],
            [],
            [],
            [],
            [],
            [new PaymentEventRecord(
                new SourceIdentity('lapka', 'order', '880001:charge:880001'),
                'charge',
                2900,
                'PLN',
                'succeeded',
                PaymentEvidenceKind::ProviderReference,
                'stripe',
                'Card',
                'txn-880001',
                null,
                '2023-04-11T09:15:00Z',
                [],
            )],
            [],
            [],
        );
    }

    private function projection(OrderRecord $record): OrderProjectionContext
    {
        return new OrderProjectionContext([
            $record->productLines[0]->identity->canonical() => [
                'post_id' => 701,
                'object_id' => 801,
                'fulfillment_type' => 'digital',
            ],
        ], [], [], 'test', 'Historical WooCommerce provenance', true);
    }
}

final class RecordingOrderStageWriter implements OrderStageWriter
{
    /** @var list<array{plan:OrderStagePlan,context:StageContext}> */
    public array $calls = [];

    public function stage(OrderStagePlan $plan, StageContext $context): OrderStageResult
    {
        $this->calls[] = compact('plan', 'context');
        return new OrderStageResult(92_001, [$plan->record->identity->canonical() => 92_001], str_repeat('b', 64), false);
    }
}

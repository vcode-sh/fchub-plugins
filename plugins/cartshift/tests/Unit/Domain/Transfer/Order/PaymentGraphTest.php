<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Order;

use CartShift\Domain\Transfer\Order\HistoricalPaymentPolicy;
use CartShift\Domain\Transfer\Order\PaymentEvidenceKind;
use CartShift\Domain\Transfer\Order\PaymentEventRecord;
use CartShift\Domain\Transfer\Order\PaymentGraphBuilder;
use CartShift\Domain\Transfer\Order\PaymentGraphProjector;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Tests\Unit\PluginTestCase;

final class PaymentGraphTest extends PluginTestCase
{
    public function testSingleProvenChargeOwnsAnUnparentedRefund(): void
    {
        $charge = $this->event('charge', 1, 10000);
        $refund = $this->event('refund', 2, 2500);

        $graph = (new PaymentGraphBuilder())->build([$refund, $charge]);

        self::assertSame([$charge], $graph->charges);
        self::assertSame([$refund], $graph->refundsByChargeSourceId[$charge->identity->canonical()]);
        self::assertSame(10000, $graph->grossPaid);
        self::assertSame(2500, $graph->totalRefunded);
    }

    public function testExplicitSourceParentWinsWithMultipleCharges(): void
    {
        $chargeOne = $this->event('charge', 1, 6000);
        $chargeTwo = $this->event('charge', 2, 4000);
        $refund = $this->event('refund', 3, 1000, parent: $chargeTwo->identity);

        $graph = (new PaymentGraphBuilder())->build([$chargeOne, $refund, $chargeTwo]);

        self::assertSame([], $graph->refundsByChargeSourceId[$chargeOne->identity->canonical()]);
        self::assertSame([$refund], $graph->refundsByChargeSourceId[$chargeTwo->identity->canonical()]);
    }

    public function testRefundWithoutUniqueParentBlocks(): void
    {
        $this->expectException(SourceRecordException::class);
        $this->expectExceptionMessage('refund_parent_ambiguous');

        (new PaymentGraphBuilder())->build([
            $this->event('charge', 1, 5000),
            $this->event('charge', 2, 5000),
            $this->event('refund', 3, 1000),
        ]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidGraphProvider')]
    public function testDuplicateBrokenParentCurrencyAndOverRefundEachBlock(array $events, string $reason): void
    {
        try {
            (new PaymentGraphBuilder())->build($events);
            self::fail('Invalid payment graph was accepted.');
        } catch (SourceRecordException $exception) {
            self::assertSame($reason, $exception->reasonCode);
        }
    }

    public static function invalidGraphProvider(): iterable
    {
        $identity = static fn (int $id): SourceIdentity => new SourceIdentity(
            'lapka-web',
            'order',
            '5001:event:' . $id,
        );
        $event = static fn (
            string $type,
            int $id,
            int $amount,
            string $currency = 'PLN',
            ?SourceIdentity $parent = null,
        ): PaymentEventRecord => new PaymentEventRecord(
            $identity($id),
            $type,
            $amount,
            $currency,
            'succeeded',
            PaymentEvidenceKind::ProviderReference,
            'stripe',
            'Card',
            null,
            $parent,
            sprintf('2026-01-%02dT10:00:00Z', $id),
            [],
        );
        $charge = $event('charge', 1, 5000);

        yield 'duplicate immutable source identity' => [[$charge, $charge], 'source_identity_conflict'];
        yield 'explicit parent does not exist' => [[
            $charge,
            $event('refund', 2, 1000, parent: $identity(999)),
        ], 'refund_parent_ambiguous'];
        yield 'refund currency differs from parent' => [[
            $charge,
            $event('refund', 2, 1000, currency: 'EUR', parent: $charge->identity),
        ], 'refund_parent_ambiguous'];
        yield 'successful refunds exceed their charge' => [[
            $charge,
            $event('refund', 2, 5001, parent: $charge->identity),
        ], 'order_money_mismatch'];
    }

    public function testRefundCarriesTargetParentAndChargeCarriesRefundedTotal(): void
    {
        $charge = $this->event('charge', 1, 10000, providerReference: 'ch_private_12345');
        $refund = $this->event(
            'refund',
            2,
            2500,
            parent: $charge->identity,
            providerReference: 're_private_98765',
        );
        $graph = (new PaymentGraphBuilder())->build([$charge, $refund]);
        $projection = (new PaymentGraphProjector(new HistoricalPaymentPolicy()))->project(
            $graph,
            [$charge->identity->canonical() => 501],
            'renewal',
            'test',
        );

        self::assertSame(501, $projection->refunds[0]['meta']['parent_id']);
        self::assertSame(2500, $projection->charges[0]['meta']['refunded_total']);
        self::assertSame('succeeded', $projection->charges[0]['status']);
        self::assertSame('refunded', $projection->refunds[0]['status']);
        self::assertSame('renewal', $projection->refunds[0]['order_type']);
        self::assertSame('PLN', $projection->refunds[0]['currency']);
        self::assertSame('wc_migrated', $projection->refunds[0]['payment_method']);
        self::assertSame('historical_provenance', $projection->refunds[0]['payment_method_type']);
        self::assertSame('', $projection->refunds[0]['vendor_charge_id']);
        self::assertSame('partially_refunded', $projection->paymentStatus);
        self::assertSame(
            're_private_98765',
            $projection->refunds[0]['meta']['cartshift_source_payment']['provider_reference'],
        );
    }

    public function testFullRefundStatusComesFromSuccessfulRefundSum(): void
    {
        $charge = $this->event('charge', 1, 10000);
        $refund = $this->event('refund', 2, 10000);
        $projection = (new PaymentGraphProjector(new HistoricalPaymentPolicy()))->project(
            (new PaymentGraphBuilder())->build([$charge, $refund]),
            [$charge->identity->canonical() => 501],
            'order',
            'live',
        );

        self::assertSame('refunded', $projection->paymentStatus);
        self::assertSame(10000, $projection->grossPaid);
        self::assertSame(10000, $projection->totalRefunded);
    }

    private function event(
        string $type,
        int $id,
        int $amount,
        string $currency = 'PLN',
        ?SourceIdentity $parent = null,
        ?string $providerReference = null,
    ): PaymentEventRecord {
        return new PaymentEventRecord(
            $this->identity($id),
            $type,
            $amount,
            $currency,
            'succeeded',
            PaymentEvidenceKind::ProviderReference,
            'stripe',
            'Card',
            $providerReference,
            $parent,
            sprintf('2026-01-%02dT10:00:00Z', $id),
            [],
        );
    }

    private function identity(int $id): SourceIdentity
    {
        return new SourceIdentity('lapka-web', 'order', '5001:event:' . $id);
    }
}

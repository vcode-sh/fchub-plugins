<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Order;

use CartShift\Domain\Transfer\Order\HistoricalPaymentGuard;
use CartShift\Domain\Transfer\Order\HistoricalPaymentPolicy;
use CartShift\Domain\Transfer\Order\PaymentEventRecord;
use CartShift\Domain\Transfer\Order\PaymentEvidenceKind;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Tests\Unit\PluginTestCase;

final class HistoricalPaymentPolicyTest extends PluginTestCase
{
    public function testStripeReferenceBecomesInertProvenance(): void
    {
        $projection = (new HistoricalPaymentPolicy())->project($this->charge(), 'live');

        self::assertSame('wc_migrated', $projection->paymentMethod);
        self::assertSame('historical_provenance', $projection->paymentMethodType);
        self::assertSame('', $projection->vendorChargeId);
        self::assertSame('stripe', $projection->provenance['gateway']);
        self::assertSame('ch_source_123', $projection->provenance['provider_reference']);
        self::assertSame('provider_reference', $projection->provenance['evidence_kind']);
        self::assertSame('2026-01-02 10:00:00', $projection->createdUtc);
    }

    public function testUnknownModeBlocksUnlessExactCohortDecisionIsFingerprintBound(): void
    {
        try {
            (new HistoricalPaymentPolicy())->project($this->charge(), null, str_repeat('a', 64));
            self::fail('Unknown historical mode was guessed.');
        } catch (SourceRecordException $exception) {
            self::assertSame('target_schema_unrepresentable', $exception->reasonCode);
        }

        $policy = new HistoricalPaymentPolicy([str_repeat('a', 64) => 'test']);
        self::assertSame('test', $policy->project($this->charge(), null, str_repeat('a', 64))->paymentMode);
    }

    public function testHistoricalTransactionIsNeverRefundableAndReferenceIsRedacted(): void
    {
        $guard = new HistoricalPaymentGuard();
        $transaction = ['payment_method' => 'wc_migrated', 'payment_method_type' => 'historical_provenance'];

        self::assertSame(0, $guard->maxRefundableAmount(10000, $transaction));
        self::assertSame(10000, $guard->maxRefundableAmount(10000, ['payment_method' => 'stripe']));
        self::assertSame('••••e_123', $guard->redactReference('ch_source_123'));

        self::assertSame(
            ['status' => 'yes', 'source' => 'cartshift_historical_provenance'],
            $guard->forceManualRefund(['status' => 'no', 'source' => ''], [
                'transaction' => $transaction,
            ]),
        );
        self::assertSame(
            ['status' => 'no', 'source' => ''],
            $guard->forceManualRefund(['status' => 'no', 'source' => ''], [
                'transaction' => ['payment_method' => 'stripe'],
            ]),
        );
    }

    public function testOrderDetailRedactsHistoricalReferenceWithoutChangingStoredProvenance(): void
    {
        $guard = new HistoricalPaymentGuard();
        $order = [
            'transactions' => [[
                'payment_method' => 'wc_migrated',
                'payment_method_type' => 'historical_provenance',
                'meta' => ['cartshift_source_payment' => [
                    'gateway' => 'stripe',
                    'provider_reference' => 'ch_source_123',
                ]],
            ]],
        ];

        $display = $guard->redactOrderView($order);

        self::assertSame(
            '••••e_123',
            $display['transactions'][0]['meta']['cartshift_source_payment']['provider_reference'],
        );
        self::assertSame(
            'ch_source_123',
            $order['transactions'][0]['meta']['cartshift_source_payment']['provider_reference'],
        );
    }

    private function charge(): PaymentEventRecord
    {
        return new PaymentEventRecord(
            new SourceIdentity('lapka-web', 'order', '5001:charge:5001'),
            'charge', 10000, 'PLN', 'succeeded', PaymentEvidenceKind::ProviderReference,
            'stripe', 'Card', 'ch_source_123', null, '2026-01-02T10:00:00Z', ['source_status' => 'completed'],
        );
    }
}

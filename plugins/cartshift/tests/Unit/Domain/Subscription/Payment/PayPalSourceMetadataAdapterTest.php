<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Subscription\Payment;

use CartShift\Domain\Subscription\Payment\PayPalSourceMetadataAdapter;
use CartShift\Domain\Subscription\SubscriptionRecord;

/**
 * Four kinds of PayPal identifier, four slots, and an empty contract table.
 *
 * The empty table is the finding. The WooCommerce PayPal Payments plugin is
 * absent from the Lapka restore, the restored payment-token table holds Stripe
 * rows only, and Task 0's probe reports `source_paypal_adapter_unknown`. So
 * this adapter knows no version, resolves nothing, and the 71 PPCP records take
 * the deliberate manual route.
 *
 * A guessed meta key would have been worse than the gap: a payer ID landing in
 * the vault slot produces a `system` subscription whose first renewal fails, or
 * charges a mandate belonging to a different contract. The tests below register
 * a synthetic contract to prove the separation holds once a real one exists —
 * which is how a real one would arrive, as a contract entry and its tests.
 */
final class PayPalSourceMetadataAdapterTest extends PaymentStrategyTestCase
{
    private const string ADAPTER = 'woocommerce-paypal-payments:9.9.9-synthetic';

    // ── the Lapka reality ──────────────────────────────────

    public function testAnUnknownAdapterResolvesNothing(): void
    {
        $extracted = (new PayPalSourceMetadataAdapter(null))->extract($this->payPalRecord());

        $this->assertFalse($extracted['resolved']);
        $this->assertNull($extracted['vault_id']);
        $this->assertNull($extracted['payer_id']);
        $this->assertNull($extracted['subscription_id']);
        $this->assertNull($extracted['token_row_id']);
        $this->assertNull($extracted['adapter']);

        // Not `provider_method_missing`, which means a lookup ran and came back
        // empty. Nobody looked here, and the operator action is different:
        // supply the source plugin's metadata contract and re-audit, versus
        // accept deliberate manual per section 8.3 C.
        $this->assertSame(
            [PayPalSourceMetadataAdapter::REASON_CONTRACT_UNKNOWN],
            $extracted['reason_codes'],
        );
    }

    /**
     * The failure this class exists to prevent: references are present, so a
     * lazier adapter would pick whichever looked most vault-shaped.
     */
    public function testAnUnknownAdapterResolvesNothingEvenWhenReferencesArePresent(): void
    {
        $record = $this->payPalRecord([
            'paypal_vault_id' => 'VAULT-SYNTHETIC-FIXTURE-0001',
            'ppcp_something'  => 'PAYER-SYNTHETIC-FIXTURE-0001',
        ]);

        $extracted = (new PayPalSourceMetadataAdapter(null))->extract($record);

        $this->assertFalse($extracted['resolved']);
        $this->assertNull($extracted['vault_id']);
    }

    /**
     * An adapter registered for one version does not answer for another. PPCP
     * has moved its subscription metadata between minor releases, and "close
     * enough" is how a payer ID ends up in a vault field.
     */
    public function testAContractIsBoundToOneExactVersion(): void
    {
        $adapter = (new PayPalSourceMetadataAdapter('woocommerce-paypal-payments:2.9.0'))
            ->register(self::ADAPTER, [PayPalSourceMetadataAdapter::KEY_VAULT => ['paypal_vault_id']]);

        $this->assertFalse($adapter->extract($this->payPalRecord())['resolved']);
    }

    // ── separation, once a contract exists ─────────────────

    public function testEachIdentifierKindLandsInItsOwnSlot(): void
    {
        $extracted = $this->fullContract()->extract($this->payPalRecord());

        $this->assertTrue($extracted['resolved']);
        $this->assertSame('VAULT-SYNTHETIC-FIXTURE-0001', $extracted['vault_id']);
        $this->assertSame('PAYER-SYNTHETIC-FIXTURE-0001', $extracted['payer_id']);
        $this->assertSame('I-SYNTHETICFIXTURE0001', $extracted['subscription_id']);
        $this->assertSame('4471', $extracted['token_row_id']);
        $this->assertSame([], $extracted['reason_codes']);
    }

    /**
     * A local `woocommerce_payment_tokens` primary key means nothing to PayPal,
     * and a payer identifies a person rather than a mandate. Neither may be
     * promoted into the vault slot for want of anything better.
     */
    public function testNeitherATokenRowNorAPayerIsPromotedIntoTheVaultSlot(): void
    {
        $extracted = $this->fullContract()->extract($this->payPalRecord([
            'woo_payment_token_id' => '4471',
            'paypal_payer_id'      => 'PAYER-SYNTHETIC-FIXTURE-0001',
        ]));

        $this->assertTrue($extracted['resolved']);
        $this->assertNull($extracted['vault_id']);
        $this->assertNull($extracted['subscription_id']);
        $this->assertSame('4471', $extracted['token_row_id']);
        $this->assertSame('PAYER-SYNTHETIC-FIXTURE-0001', $extracted['payer_id']);
        $this->assertSame(
            ['provider_method_missing'],
            $extracted['reason_codes'],
            'A payer and a local token row cannot bill anybody between them.',
        );
    }

    public function testAnUnknownIdentifierKindIsRefusedAtRegistration(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new PayPalSourceMetadataAdapter(self::ADAPTER))->register(self::ADAPTER, ['agreement' => ['whatever']]);
    }

    // ── helpers ────────────────────────────────────────────

    private function fullContract(): PayPalSourceMetadataAdapter
    {
        return (new PayPalSourceMetadataAdapter(self::ADAPTER))->register(self::ADAPTER, [
            PayPalSourceMetadataAdapter::KEY_VAULT        => ['paypal_vault_id'],
            PayPalSourceMetadataAdapter::KEY_PAYER        => ['paypal_payer_id'],
            PayPalSourceMetadataAdapter::KEY_SUBSCRIPTION => ['paypal_subscription_id'],
            PayPalSourceMetadataAdapter::KEY_TOKEN_ROW    => ['woo_payment_token_id'],
        ]);
    }

    /**
     * @param array<string, string> $references
     */
    private function payPalRecord(array $references = []): SubscriptionRecord
    {
        $references = $references === [] ? [
            'paypal_vault_id'        => 'VAULT-SYNTHETIC-FIXTURE-0001',
            'paypal_payer_id'        => 'PAYER-SYNTHETIC-FIXTURE-0001',
            'paypal_subscription_id' => 'I-SYNTHETICFIXTURE0001',
            'woo_payment_token_id'   => '4471',
        ] : $references;

        $record = $this->record('paypalGateway');

        return new SubscriptionRecord(
            $record->sourceKey,
            $record->sourceRef,
            $record->sourceSubscriptionId,
            $record->status,
            $record->currency,
            $record->sourceCustomerRef,
            $record->sourceCustomerId,
            $record->billingEmail,
            $record->billingIdentity,
            $record->parentOrderId,
            $record->items,
            $record->contract,
            $record->gateway,
            $record->requiresManualRenewal,
            $references,
            $record->dates,
            $record->relatedOrders,
            $record->sourcePaymentCount,
            $record->fingerprint,
        );
    }
}

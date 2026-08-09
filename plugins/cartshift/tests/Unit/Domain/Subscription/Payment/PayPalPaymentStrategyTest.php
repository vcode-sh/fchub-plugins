<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Subscription\Payment;

use CartShift\Domain\Subscription\Payment\PaymentEnvironment;
use CartShift\Domain\Subscription\Payment\PaymentMigrationDecision;
use CartShift\Domain\Subscription\Payment\PayPalPaymentStrategy;
use CartShift\Domain\Subscription\Payment\PayPalReferenceVerifier;
use CartShift\Domain\Subscription\Payment\PayPalSourceMetadataAdapter;
use CartShift\Domain\Subscription\SubscriptionRecord;

/**
 * PayPal's three outcomes, and the field separation that keeps them honest.
 *
 * The restored Lapka snapshot lands in C every time: the PPCP plugin source is
 * absent, so no metadata contract can be pinned and no reference can be
 * classified. The A and B branches are exercised against a contract a test
 * registers, which is exactly how a real one would arrive — a registry entry
 * and its tests, changing no strategy.
 */
final class PayPalPaymentStrategyTest extends PaymentStrategyTestCase
{
    private const string ADAPTER = 'woocommerce-paypal-payments:9.9.9-synthetic';

    private const string VAULT = 'VAULT-SYNTHETIC-FIXTURE-0001';
    private const string PAYER = 'PAYER-SYNTHETIC-FIXTURE-0001';
    private const string REMOTE = 'I-SYNTHETICFIXTURE0001';
    private const string TOKEN_ROW = '4471';

    // ── C: the Lapka reality ───────────────────────────────

    /**
     * 71 PPCP records, an absent plugin source, and a payment-token table with
     * no PayPal rows. The correct answer is a stated gap, not a guessed vault
     * key.
     */
    public function testAnUnknownSourceAdapterTakesTheDeliberateManualRoute(): void
    {
        $decision = $this->assess($this->record('paypalGateway'), $this->environment());

        $this->assertSame(PaymentMigrationDecision::STRATEGY_PAYPAL, $decision->strategy);
        $this->assertSame(PaymentMigrationDecision::OUTCOME_CONFIRMATION_REQUIRED, $decision->outcome);
        $this->assertSame(PaymentMigrationDecision::COLLECTION_MANUAL, $decision->collectionMethod);
        $this->assertSame(PaymentMigrationDecision::OWNER_TARGET_MANUAL, $decision->nextActionOwner);
        $this->assertContains(
            PayPalSourceMetadataAdapter::REASON_CONTRACT_UNKNOWN,
            $decision->reasonCodes,
        );
    }

    /**
     * The gap has to be visible in what an operator reads, not only in the
     * source.
     *
     * All 71 PPCP records used to surface as `provider_method_missing` —
     * byte-identical to a Stripe record whose customer genuinely has no saved
     * card. Two different absences, one code, and two different operator
     * actions: supply the source plugin's metadata contract and re-audit,
     * versus accept deliberate manual per section 8.3 C.
     */
    public function testTheUnknownContractIsDistinguishableFromALookupThatFoundNothing(): void
    {
        $unknownContract = $this->assess($this->record('paypalGateway'), $this->environment());

        $lookupFoundNothing = $this->assess(
            $this->payPalRecord(['paypal_vault_id' => 'VAULT-SYNTHETIC-NOT-AT-THIS-MERCHANT']),
            $this->environmentFor(
                $this->adapter([PayPalSourceMetadataAdapter::KEY_VAULT => ['paypal_vault_id']]),
                [],
            ),
        );

        $this->assertContains(
            PayPalSourceMetadataAdapter::REASON_CONTRACT_UNKNOWN,
            $unknownContract->reasonCodes,
        );
        $this->assertNotContains('provider_method_missing', $unknownContract->reasonCodes);

        $this->assertContains('provider_method_missing', $lookupFoundNothing->reasonCodes);
        $this->assertNotContains(
            PayPalSourceMetadataAdapter::REASON_CONTRACT_UNKNOWN,
            $lookupFoundNothing->reasonCodes,
        );
    }

    /**
     * And the adapter identity reaches the receipt, so the decision agrees with
     * Task 0's `source_paypal_adapter_unknown` instead of restating it as a
     * bare reason code.
     */
    public function testTheSourceAdapterIdentityReachesTheReceipt(): void
    {
        $unknown = $this->assess($this->record('paypalGateway'), $this->environment());
        $known   = $this->assess($this->payPalRecord(), $this->vaultEnvironment());

        $this->assertNull($unknown->sourceMetadataAdapter);
        $this->assertArrayHasKey('source_metadata_adapter', $unknown->toArray());
        $this->assertNull($unknown->toArray()['source_metadata_adapter']);

        $this->assertSame(self::ADAPTER, $known->sourceMetadataAdapter);
        $this->assertSame(self::ADAPTER, $known->toArray()['source_metadata_adapter']);
    }

    /**
     * The distinction the whole PayPal branch turns on. Task 2 deliberately
     * does not extract PayPal references, so a PPCP record arrives with an
     * empty set. That is "nobody looked", not "there is no vault, therefore
     * manual is proven safe" — and it must never be read as proof of anything.
     */
    public function testAnEmptyReferenceSetIsAnUnperformedLookupNotAProof(): void
    {
        $record = $this->record('paypalGateway');

        $this->assertSame([], $record->paymentReferences);

        $decision = $this->assess($record, $this->environment());

        $this->assertContains(
            PayPalSourceMetadataAdapter::REASON_CONTRACT_UNKNOWN,
            $decision->reasonCodes,
        );
        $this->assertNotSame(
            PaymentMigrationDecision::OUTCOME_READY,
            $decision->outcome,
            'Nothing was verified, so nothing is ready.',
        );
    }

    public function testTheManualFallbackKeepsThePayPalCheckoutPath(): void
    {
        $decision = $this->assess($this->record('paypalGateway'), $this->environment());

        $this->assertSame('paypal', $decision->currentPaymentMethod);
        $this->assertSame([], $decision->activePaymentMethod);
        $this->assertNull($decision->vendorSubscriptionId);
        $this->assertNull($decision->vendorCustomerId);
    }

    /**
     * With no PayPal gateway registered in the target, even the manual
     * fallback's checkout path is gone — so the gateway-neutral empty string,
     * not a slug FluentCart cannot resolve.
     */
    public function testAnUnregisteredTargetPayPalFallsBackToTheNeutralSlug(): void
    {
        $this->seedGateways('stripe');

        $decision = $this->assess($this->record('paypalGateway'), $this->environment());

        $this->assertSame('', $decision->currentPaymentMethod);
    }

    // ── A: verified target-system PayPal ───────────────────

    /**
     * `PayPal::chargeRenewal()` reaches `Processor::chargeVaultedRenewal()`,
     * which reads the vault from `active_payment_method.vendor_method_id` at
     * fire time. A verified vault is therefore a legitimate `system`
     * subscription, and modelling PayPal as remote-schedule-only was wrong.
     */
    public function testAVerifiedVaultBecomesASystemSubscription(): void
    {
        $decision = $this->assess($this->payPalRecord(), $this->vaultEnvironment());

        $this->assertSame(PaymentMigrationDecision::OUTCOME_READY, $decision->outcome);
        $this->assertSame(PaymentMigrationDecision::COLLECTION_SYSTEM, $decision->collectionMethod);
        $this->assertSame(PaymentMigrationDecision::OWNER_TARGET_SYSTEM, $decision->nextActionOwner);
        $this->assertSame(
            self::VAULT,
            $decision->activePaymentMethod[PaymentMigrationDecision::ACTIVE_METHOD_ID],
        );
        $this->assertNull(
            $decision->vendorSubscriptionId,
            'A system PayPal subscription is billed by FluentCart. No vendor subscription ID is written.',
        );
        $this->assertSame(self::PAYER, $decision->vendorCustomerId);
    }

    public function testAnInactiveVaultCannotBeChargedOffSession(): void
    {
        $decision = $this->assess($this->payPalRecord(), $this->vaultEnvironment([
            'v3/vault/payment-tokens/' . self::VAULT => [
                'id'          => self::VAULT,
                'status'      => 'REVOKED',
                'merchant_id' => 'MERCHANT-SYNTHETIC-TARGET',
            ],
        ]));

        $this->assertSame(PaymentMigrationDecision::COLLECTION_MANUAL, $decision->collectionMethod);
        $this->assertContains('provider_method_unsupported', $decision->reasonCodes);
    }

    public function testAVaultBelongingToAnotherMerchantIsRefused(): void
    {
        $decision = $this->assess($this->payPalRecord(), $this->vaultEnvironment([
            'v3/vault/payment-tokens/' . self::VAULT => [
                'id'          => self::VAULT,
                'status'      => 'ACTIVE',
                'merchant_id' => 'MERCHANT-SOMEBODY-ELSE',
            ],
        ]));

        $this->assertSame(PaymentMigrationDecision::COLLECTION_MANUAL, $decision->collectionMethod);
        $this->assertContains('provider_account_mismatch', $decision->reasonCodes);
    }

    public function testAnUnapprovedTargetCannotProduceASystemPayPalDecision(): void
    {
        $environment = $this->vaultEnvironment([], ['approvedSettingsFingerprint' => null]);

        $decision = $this->assess($this->payPalRecord(), $environment);

        $this->assertSame(PaymentMigrationDecision::COLLECTION_MANUAL, $decision->collectionMethod);
        $this->assertContains('system_store_mode_not_approved', $decision->reasonCodes);
    }

    // ── B: verified remote schedule ────────────────────────

    public function testAVerifiedRemoteScheduleBecomesAnAutomaticSubscription(): void
    {
        $decision = $this->assess($this->payPalRecord(), $this->remoteEnvironment());

        $this->assertSame(PaymentMigrationDecision::OUTCOME_READY, $decision->outcome);
        $this->assertSame(PaymentMigrationDecision::COLLECTION_AUTOMATIC, $decision->collectionMethod);
        $this->assertSame(PaymentMigrationDecision::OWNER_REMOTE_SCHEDULE, $decision->nextActionOwner);
        $this->assertSame(self::REMOTE, $decision->vendorSubscriptionId);
        $this->assertSame(
            [],
            $decision->activePaymentMethod,
            'The remote schedule charges its own mandate. Vault metadata here invites a second charge.',
        );
    }

    public function testARemoteScheduleWithoutWebhookOwnershipIsNotAdopted(): void
    {
        $decision = $this->assess(
            $this->payPalRecord(),
            $this->remoteEnvironment(['verifiedWebhookOwners' => []]),
        );

        $this->assertSame(PaymentMigrationDecision::COLLECTION_MANUAL, $decision->collectionMethod);
        $this->assertContains('provider_webhook_unverified', $decision->reasonCodes);
    }

    public function testACancelledRemoteScheduleIsNotAnOwner(): void
    {
        $decision = $this->assess($this->payPalRecord(), $this->remoteEnvironment([], [
            'v1/billing/subscriptions/' . self::REMOTE => [
                'id'          => self::REMOTE,
                'status'      => 'CANCELLED',
                'merchant_id' => 'MERCHANT-SYNTHETIC-TARGET',
            ],
        ]));

        $this->assertSame(PaymentMigrationDecision::COLLECTION_MANUAL, $decision->collectionMethod);
        $this->assertContains('provider_schedule_mismatch', $decision->reasonCodes);
    }

    // ── field separation ───────────────────────────────────

    /**
     * The confirmed defect, pinned. `SubscriptionMapper.php:223-228` assigned a
     * PayPal subscription ID as the customer ID. A source customer reference, a
     * vault ID and a remote subscription ID are three different things, and
     * each has exactly one field.
     */
    public function testAPayPalSubscriptionIdNeverLandsInACustomerOrVaultField(): void
    {
        $decision = $this->assess($this->payPalRecord(), $this->remoteEnvironment());

        // Bites: the payer ID is genuinely present and could have been the
        // schedule ID, which is precisely the substitution that shipped.
        $this->assertSame(self::PAYER, $decision->vendorCustomerId);
        $this->assertNotSame(self::REMOTE, $decision->vendorCustomerId);
        $this->assertSame(self::REMOTE, $decision->vendorSubscriptionId);

        // The remote branch writes no vault metadata at all, which is the
        // invariant rather than an absence of one particular value.
        $this->assertSame([], $decision->activePaymentMethod);
    }

    /**
     * And the field separation is structural, not merely observed: the
     * decision refuses to be constructed with a schedule ID in the vault slot,
     * whatever assembled it.
     */
    public function testAScheduleIdInTheVaultSlotIsUnconstructible(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PaymentMigrationDecision(
            strategy: PaymentMigrationDecision::STRATEGY_PAYPAL,
            outcome: PaymentMigrationDecision::OUTCOME_READY,
            collectionMethod: PaymentMigrationDecision::COLLECTION_SYSTEM,
            currentPaymentMethod: 'paypal',
            nextActionOwner: PaymentMigrationDecision::OWNER_TARGET_SYSTEM,
            vendorCustomerId: self::PAYER,
            vendorPlanId: null,
            vendorSubscriptionId: self::REMOTE,
            activePaymentMethod: [PaymentMigrationDecision::ACTIVE_METHOD_ID => self::REMOTE],
            reasonCodes: [],
        );
    }

    public function testAWooTokenRowIdIsNeverMistakenForAVault(): void
    {
        $decision = $this->assess($this->payPalRecord(), $this->vaultEnvironment());

        $this->assertNotSame(
            self::TOKEN_ROW,
            $decision->activePaymentMethod[PaymentMigrationDecision::ACTIVE_METHOD_ID],
            'A local woocommerce_payment_tokens primary key means nothing to PayPal.',
        );
    }

    public function testAPayerIdIsNeverMistakenForAVault(): void
    {
        $decision = $this->assess($this->payPalRecord(), $this->vaultEnvironment());

        $this->assertNotSame(
            self::PAYER,
            $decision->activePaymentMethod[PaymentMigrationDecision::ACTIVE_METHOD_ID],
        );
    }

    /**
     * A payer ID with no vault and no schedule cannot bill anybody, so it is
     * not a mandate and does not become one.
     */
    public function testAPayerIdAloneIsNotAMandate(): void
    {
        $environment = $this->environmentFor(
            $this->adapter([PayPalSourceMetadataAdapter::KEY_PAYER => ['paypal_payer_id']]),
            [],
        );

        $decision = $this->assess($this->payPalRecord(['paypal_payer_id' => self::PAYER]), $environment);

        $this->assertSame(PaymentMigrationDecision::COLLECTION_MANUAL, $decision->collectionMethod);
        $this->assertContains('provider_method_missing', $decision->reasonCodes);
        $this->assertNull($decision->vendorCustomerId);
    }

    // ── environments ───────────────────────────────────────

    /**
     * @param array<string, array<string, mixed>> $objectOverrides
     * @param array<string, mixed>                $environmentOverrides
     */
    private function vaultEnvironment(array $objectOverrides = [], array $environmentOverrides = []): PaymentEnvironment
    {
        $adapter = $this->adapter([
            PayPalSourceMetadataAdapter::KEY_VAULT     => ['paypal_vault_id'],
            PayPalSourceMetadataAdapter::KEY_PAYER     => ['paypal_payer_id'],
            PayPalSourceMetadataAdapter::KEY_TOKEN_ROW => ['woo_payment_token_id'],
        ]);

        $objects = array_merge([
            'v3/vault/payment-tokens/' . self::VAULT => [
                'id'          => self::VAULT,
                'status'      => 'ACTIVE',
                'merchant_id' => 'MERCHANT-SYNTHETIC-TARGET',
                'mode'        => 'live',
                'customer'    => ['id' => self::PAYER],
            ],
        ], $objectOverrides);

        return $this->environmentFor($adapter, $objects, $environmentOverrides);
    }

    /**
     * @param array<string, mixed>                $environmentOverrides
     * @param array<string, array<string, mixed>> $objectOverrides
     */
    private function remoteEnvironment(
        array $environmentOverrides = [],
        array $objectOverrides = [],
    ): PaymentEnvironment {
        $adapter = $this->adapter([
            PayPalSourceMetadataAdapter::KEY_SUBSCRIPTION => ['paypal_subscription_id'],
            PayPalSourceMetadataAdapter::KEY_PAYER        => ['paypal_payer_id'],
        ]);

        $objects = array_merge([
            'v1/billing/subscriptions/' . self::REMOTE => [
                'id'          => self::REMOTE,
                'status'      => 'ACTIVE',
                'merchant_id' => 'MERCHANT-SYNTHETIC-TARGET',
                'mode'        => 'live',
            ],
        ], $objectOverrides);

        return $this->environmentFor(
            $adapter,
            $objects,
            array_merge(['verifiedWebhookOwners' => ['paypal']], $environmentOverrides),
        );
    }

    /**
     * @param array<string, array<string, mixed>> $objects
     * @param array<string, mixed>                $environmentOverrides
     */
    private function environmentFor(
        PayPalSourceMetadataAdapter $adapter,
        array $objects,
        array $environmentOverrides = [],
    ): PaymentEnvironment {
        return $this->environment(array_merge([
            'verifiers' => [
                'paypal' => new PayPalReferenceVerifier(
                    $this->recordingRetrieve($objects),
                    $adapter,
                    expectedMerchantId: 'MERCHANT-SYNTHETIC-TARGET',
                    expectedMode: 'live',
                ),
            ],
        ], $environmentOverrides));
    }

    /**
     * @param array<string, list<string>> $contract
     */
    private function adapter(array $contract): PayPalSourceMetadataAdapter
    {
        return (new PayPalSourceMetadataAdapter(self::ADAPTER))->register(self::ADAPTER, $contract);
    }

    /**
     * A PPCP record carrying the references the registered contract names.
     *
     * The Lapka fixture has none, because Task 2 refused to guess PPCP meta
     * keys. These are supplied here explicitly so the A and B branches have
     * something to verify.
     *
     * @param array<string, string> $references
     */
    private function payPalRecord(array $references = []): SubscriptionRecord
    {
        $references = $references === [] ? [
            'paypal_vault_id'        => self::VAULT,
            'paypal_payer_id'        => self::PAYER,
            'paypal_subscription_id' => self::REMOTE,
            'woo_payment_token_id'   => self::TOKEN_ROW,
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

    private function assess(SubscriptionRecord $record, PaymentEnvironment $environment): PaymentMigrationDecision
    {
        return (new PayPalPaymentStrategy())->assess($record, $environment);
    }
}

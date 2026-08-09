<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Subscription\Payment;

use CartShift\Domain\Subscription\Payment\PaymentMigrationDecision;
use CartShift\Tests\Unit\PluginTestCase;

/**
 * The invariants, exercised directly.
 *
 * Every one of these is a defect that has already happened somewhere in this
 * codebase or in the plan's confirmed findings, so each is refused at
 * construction rather than documented and hoped about.
 */
final class PaymentMigrationDecisionTest extends PluginTestCase
{
    public function testACollectionMethodImpliesExactlyOneOwner(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exactly one owner');

        $this->decision([
            'collectionMethod' => PaymentMigrationDecision::COLLECTION_AUTOMATIC,
            'nextActionOwner'  => PaymentMigrationDecision::OWNER_TARGET_MANUAL,
        ]);
    }

    /**
     * `Stripe::chargeRenewal()` returns `missing_token` without it
     * (Stripe.php:213-221); so does `Processor::chargeVaultedRenewal()`
     * (Processor.php:823-825). A system decision without one is a subscription
     * that fails its first renewal in front of a customer.
     */
    public function testASystemDecisionWithoutAMethodIdIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('missing_token');

        $this->decision([
            'collectionMethod'     => PaymentMigrationDecision::COLLECTION_SYSTEM,
            'nextActionOwner'      => PaymentMigrationDecision::OWNER_TARGET_SYSTEM,
            'currentPaymentMethod' => 'stripe',
            'activePaymentMethod'  => [],
        ]);
    }

    public function testASystemDecisionCarryingARemoteScheduleIdIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->decision([
            'collectionMethod'     => PaymentMigrationDecision::COLLECTION_SYSTEM,
            'nextActionOwner'      => PaymentMigrationDecision::OWNER_TARGET_SYSTEM,
            'currentPaymentMethod' => 'paypal',
            'vendorSubscriptionId' => 'I-SYNTHETICFIXTURE0001',
            'activePaymentMethod'  => ['vendor_method_id' => 'VAULT-SYNTHETIC-FIXTURE-0001'],
        ]);
    }

    public function testAnAutomaticDecisionWithoutAScheduleIdIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->decision([
            'collectionMethod'     => PaymentMigrationDecision::COLLECTION_AUTOMATIC,
            'nextActionOwner'      => PaymentMigrationDecision::OWNER_REMOTE_SCHEDULE,
            'currentPaymentMethod' => 'paypal',
            'vendorSubscriptionId' => null,
        ]);
    }

    public function testAnAutomaticDecisionCarryingVaultMetadataIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->decision([
            'collectionMethod'     => PaymentMigrationDecision::COLLECTION_AUTOMATIC,
            'nextActionOwner'      => PaymentMigrationDecision::OWNER_REMOTE_SCHEDULE,
            'currentPaymentMethod' => 'paypal',
            'vendorSubscriptionId' => 'I-SYNTHETICFIXTURE0001',
            'activePaymentMethod'  => ['vendor_method_id' => 'VAULT-SYNTHETIC-FIXTURE-0001'],
        ]);
    }

    /**
     * `SubscriptionMapper.php:223-228` assigned a PayPal subscription ID as the
     * customer ID. The cheapest version of that mistake is now unconstructible.
     */
    public function testASubscriptionIdInTheCustomerFieldIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a customer ID');

        $this->decision([
            'collectionMethod'     => PaymentMigrationDecision::COLLECTION_AUTOMATIC,
            'nextActionOwner'      => PaymentMigrationDecision::OWNER_REMOTE_SCHEDULE,
            'currentPaymentMethod' => 'paypal',
            'vendorCustomerId'     => 'I-SYNTHETICFIXTURE0001',
            'vendorSubscriptionId' => 'I-SYNTHETICFIXTURE0001',
        ]);
    }

    public function testAVaultIdInTheCustomerFieldIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->decision([
            'collectionMethod'     => PaymentMigrationDecision::COLLECTION_SYSTEM,
            'nextActionOwner'      => PaymentMigrationDecision::OWNER_TARGET_SYSTEM,
            'currentPaymentMethod' => 'paypal',
            'vendorCustomerId'     => 'VAULT-SYNTHETIC-FIXTURE-0001',
            'activePaymentMethod'  => ['vendor_method_id' => 'VAULT-SYNTHETIC-FIXTURE-0001'],
        ]);
    }

    public function testAManualDecisionCarryingAMandateIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->decision(['vendorCustomerId' => 'cus_synthetic_fixture_0001']);
    }

    /**
     * Section 8.4 is explicit: the gateway-neutral value is the empty string,
     * not the invented slug `manual`, which is not a FluentCart gateway.
     */
    public function testTheInventedSlugManualIsNotAValidPaymentMethod(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a registered target gateway slug');

        $this->decision(['currentPaymentMethod' => 'manual']);
    }

    /**
     * Section 9.4: free-form strings do not control cutover. Commands,
     * receipts and retry logic all key off these codes, so an unrecognised one
     * is refused here rather than discovered three phases later when a retry
     * stops matching its own blocker.
     */
    public function testAnUnrecognisedReasonCodeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown reason code');

        $this->decision(['reasonCodes' => ['it_looked_a_bit_wrong']]);
    }

    public function testReasonCodesAreSortedAndDeduplicated(): void
    {
        $decision = $this->decision([
            'reasonCodes' => [
                'provider_method_missing',
                'manual_confirmation_required',
                'provider_method_missing',
            ],
        ]);

        $this->assertSame(
            ['manual_confirmation_required', 'provider_method_missing'],
            $decision->reasonCodes,
        );
    }

    public function testTheWriterFacingMetadataKeyIsTheOneFluentCartReads(): void
    {
        $this->assertSame('vendor_method_id', PaymentMigrationDecision::ACTIVE_METHOD_ID);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function decision(array $overrides = []): PaymentMigrationDecision
    {
        $fields = array_merge([
            'strategy'             => PaymentMigrationDecision::STRATEGY_MANUAL,
            'outcome'              => PaymentMigrationDecision::OUTCOME_READY,
            'collectionMethod'     => PaymentMigrationDecision::COLLECTION_MANUAL,
            'currentPaymentMethod' => '',
            'nextActionOwner'      => PaymentMigrationDecision::OWNER_TARGET_MANUAL,
            'vendorCustomerId'     => null,
            'vendorPlanId'         => null,
            'vendorSubscriptionId' => null,
            'activePaymentMethod'  => [],
            'reasonCodes'          => [],
        ], $overrides);

        return new PaymentMigrationDecision(...$fields);
    }
}

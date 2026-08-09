<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Subscription\Payment;

use CartShift\Domain\Subscription\Payment\PaymentCapabilityProbe;
use CartShift\Domain\Subscription\Payment\PaymentEnvironment;
use CartShift\Domain\Subscription\Payment\PaymentMigrationDecision;
use CartShift\Domain\Subscription\Payment\StripePaymentStrategy;
use CartShift\Domain\Subscription\SubscriptionRecord;

/**
 * The 367 Lapka Stripe subscriptions, and the one destination available to
 * them.
 *
 * Not `automatic`: none of them has a `_stripe_subscription_id`, because Woo
 * Stripe charges WCS renewals locally rather than running a remote schedule.
 * Marking them `automatic` — today's behaviour — leaves nothing responsible for
 * the next charge. The eligible destination is FluentCart `system`, and the bar
 * for it is exactly what `Stripe::chargeRenewal()` reads at fire time.
 */
final class StripePaymentStrategyTest extends PaymentStrategyTestCase
{
    // ── the eligible case ──────────────────────────────────

    public function testAVerifiedModernMethodBecomesASystemSubscription(): void
    {
        $decision = $this->assess($this->record('stripePaymentMethod'), $this->environment());

        $this->assertSame(PaymentMigrationDecision::OUTCOME_READY, $decision->outcome);
        $this->assertSame(PaymentMigrationDecision::COLLECTION_SYSTEM, $decision->collectionMethod);
        $this->assertSame('stripe', $decision->currentPaymentMethod);
        $this->assertSame(PaymentMigrationDecision::OWNER_TARGET_SYSTEM, $decision->nextActionOwner);
        $this->assertSame('cus_synthetic_fixture_0001', $decision->vendorCustomerId);
        $this->assertNull(
            $decision->vendorSubscriptionId,
            'FluentCart bills this one. A remote schedule ID would mean two things charging the same contract.',
        );
        $this->assertSame([], $decision->reasonCodes);
    }

    /**
     * `Stripe::chargeRenewal()` returns `missing_token` without both a
     * `vendor_customer_id` and `active_payment_method.vendor_method_id`
     * (Stripe.php:213-221). A system decision that lacks either is a
     * subscription that fails its first renewal.
     */
    public function testASystemDecisionCarriesTheExactIdentifiersTheChargePathReads(): void
    {
        $decision = $this->assess($this->record('stripePaymentMethod'), $this->environment());

        $this->assertNotSame('', (string) $decision->vendorCustomerId);
        $this->assertSame(
            'pm_synthetic_fixture_0001',
            $decision->activePaymentMethod[PaymentMigrationDecision::ACTIVE_METHOD_ID],
        );
    }

    public function testVerificationIsRetrievalOnly(): void
    {
        $this->assess($this->record('stripePaymentMethod'), $this->environment());

        $this->assertSame([
            'customers/cus_synthetic_fixture_0001',
            'payment_methods/pm_synthetic_fixture_0001',
        ], $this->reads);
    }

    // ── the legacy cohort ──────────────────────────────────

    /**
     * 246 of the 367. A `src_` value posted into FluentCart's `payment_method`
     * field is not "probably fine"; the charge path sends it straight to Stripe
     * as a PaymentIntent's payment method.
     */
    public function testALegacySourceIsHeldAtConfirmationRequired(): void
    {
        $decision = $this->assess($this->record('stripeLegacySource'), $this->environment());

        $this->assertSame(PaymentMigrationDecision::OUTCOME_CONFIRMATION_REQUIRED, $decision->outcome);
        $this->assertSame(PaymentMigrationDecision::COLLECTION_MANUAL, $decision->collectionMethod);
        $this->assertSame(PaymentMigrationDecision::OWNER_TARGET_MANUAL, $decision->nextActionOwner);
        $this->assertContains('provider_method_unsupported', $decision->reasonCodes);
        $this->assertSame(
            PaymentMigrationDecision::STRATEGY_STRIPE,
            $decision->strategy,
            'Still a Stripe record; the cohort has to stay reportable.',
        );
    }

    /**
     * The flag is a sandbox proof's receipt, not an optimism setting. With it,
     * the same record verifies and becomes system.
     */
    public function testALegacySourceIsEligibleOnceTheChargePathIsProven(): void
    {
        $decision = $this->assess(
            $this->record('stripeLegacySource'),
            $this->environment(['legacySourceChargePathProven' => true]),
        );

        $this->assertSame(PaymentMigrationDecision::OUTCOME_READY, $decision->outcome);
        $this->assertSame(PaymentMigrationDecision::COLLECTION_SYSTEM, $decision->collectionMethod);
        $this->assertSame(
            'src_synthetic_fixture_0002',
            $decision->activePaymentMethod[PaymentMigrationDecision::ACTIVE_METHOD_ID],
        );
    }

    // ── missing references ─────────────────────────────────

    public function testAMissingCustomerBecomesDeliberateManualNotSystemBilling(): void
    {
        $decision = $this->assess(
            $this->record('stripePaymentMethod', ['meta' => ['_stripe_customer_id' => '']]),
            $this->environment(),
        );

        $this->assertSame(PaymentMigrationDecision::COLLECTION_MANUAL, $decision->collectionMethod);
        $this->assertContains('provider_customer_missing', $decision->reasonCodes);
        $this->assertSame([], $decision->activePaymentMethod);
    }

    /** The one Lapka Stripe record with no usable source identifier. */
    public function testAMissingTokenBecomesDeliberateManual(): void
    {
        $decision = $this->assess(
            $this->record('stripePaymentMethod', ['meta' => ['_stripe_source_id' => '']]),
            $this->environment(),
        );

        $this->assertSame(PaymentMigrationDecision::COLLECTION_MANUAL, $decision->collectionMethod);
        $this->assertContains('provider_method_missing', $decision->reasonCodes);
    }

    /**
     * A payment method that exists but belongs to somebody else. Charging it
     * would be the single worst outcome this plan exists to prevent, so an
     * ownership failure is a failure and not a warning.
     */
    public function testAMethodOwnedByAnotherCustomerIsRefused(): void
    {
        $verifier = $this->stripeVerifier([
            'payment_methods/pm_synthetic_fixture_0001' => [
                'id'       => 'pm_synthetic_fixture_0001',
                'customer' => 'cus_synthetic_somebody_else',
                'livemode' => true,
            ],
        ]);

        $decision = $this->assess(
            $this->record('stripePaymentMethod'),
            $this->environment(['verifiers' => ['stripe' => $verifier]]),
        );

        $this->assertSame(PaymentMigrationDecision::COLLECTION_MANUAL, $decision->collectionMethod);
        $this->assertContains('provider_method_missing', $decision->reasonCodes);
    }

    public function testATestModeCustomerOnALiveTargetIsAModeMismatch(): void
    {
        $verifier = $this->stripeVerifier([
            'customers/cus_synthetic_fixture_0001' => ['id' => 'cus_synthetic_fixture_0001', 'livemode' => false],
        ]);

        $decision = $this->assess(
            $this->record('stripePaymentMethod'),
            $this->environment(['verifiers' => ['stripe' => $verifier]]),
        );

        $this->assertContains('provider_mode_mismatch', $decision->reasonCodes);
        $this->assertSame(PaymentMigrationDecision::COLLECTION_MANUAL, $decision->collectionMethod);
    }

    /**
     * A remote Stripe subscription still charging the same contract. None of
     * the 367 has one, but adopting a record that did as `system` would bill
     * the customer twice.
     */
    public function testARunningRemoteScheduleDisqualifiesSystemAdoption(): void
    {
        $verifier = $this->stripeVerifier([
            'subscriptions/sub_synthetic_fixture_0001' => [
                'id'     => 'sub_synthetic_fixture_0001',
                'status' => 'active',
            ],
        ]);

        $decision = $this->assess(
            $this->record('stripePaymentMethod', [
                'meta' => ['_stripe_subscription_id' => 'sub_synthetic_fixture_0001'],
            ]),
            $this->environment(['verifiers' => ['stripe' => $verifier]]),
        );

        $this->assertContains('provider_schedule_mismatch', $decision->reasonCodes);
        $this->assertSame(PaymentMigrationDecision::COLLECTION_MANUAL, $decision->collectionMethod);
    }

    public function testATargetWithNoStripeCredentialsProvesNothing(): void
    {
        $decision = $this->assess(
            $this->record('stripePaymentMethod'),
            $this->environment(['verifiers' => []]),
        );

        $this->assertSame(PaymentMigrationDecision::COLLECTION_MANUAL, $decision->collectionMethod);
        $this->assertContains('provider_method_missing', $decision->reasonCodes);
    }

    // ── store policy and approval ──────────────────────────

    public function testAStoreThatDoesNotPermitSystemCollectionCannotProduceOne(): void
    {
        $this->seedStoreSettings(mode: 'gateway_managed', systemCharge: 'no');

        $decision = $this->assess($this->record('stripePaymentMethod'), $this->environment());

        $this->assertSame(PaymentMigrationDecision::COLLECTION_MANUAL, $decision->collectionMethod);
        $this->assertContains(
            PaymentCapabilityProbe::REASON_STORE_MODE_NOT_APPROVED,
            $decision->reasonCodes,
        );
        $this->assertNotContains(
            PaymentCapabilityProbe::REASON_GATEWAY_LACKS_CAPABILITY,
            $decision->reasonCodes,
            'Stripe still advertises the capability. Blaming it sends the operator to the wrong screen.',
        );
    }

    /**
     * A store may be configured perfectly and still not be approved. Approval
     * is bound to the exact settings/census hash an operator reviewed; a store
     * that moved since has not been approved, it has been approved once, for
     * something else.
     */
    public function testAnUnapprovedTargetCannotProduceASystemDecision(): void
    {
        $decision = $this->assess(
            $this->record('stripePaymentMethod'),
            $this->environment(['approvedSettingsFingerprint' => null]),
        );

        $this->assertSame(PaymentMigrationDecision::COLLECTION_MANUAL, $decision->collectionMethod);
        $this->assertSame(
            ['manual_confirmation_required', PaymentCapabilityProbe::REASON_STORE_MODE_NOT_APPROVED],
            $decision->reasonCodes,
        );
    }

    public function testAStaleApprovalIsNotAnApproval(): void
    {
        $decision = $this->assess(
            $this->record('stripePaymentMethod'),
            $this->environment(['approvedSettingsFingerprint' => 'the-hash-of-a-store-that-has-since-changed']),
        );

        $this->assertSame(PaymentMigrationDecision::COLLECTION_MANUAL, $decision->collectionMethod);
        $this->assertContains(
            PaymentCapabilityProbe::REASON_STORE_MODE_NOT_APPROVED,
            $decision->reasonCodes,
        );
    }

    /**
     * Two empty fingerprints must not compare equal into an approval. This is
     * the failure mode that would approve every store by construction.
     */
    public function testTwoEmptyFingerprintsDoNotConstituteAnApproval(): void
    {
        $decision = $this->assess($this->record('stripePaymentMethod'), $this->environment([
            'settingsFingerprint'         => '',
            'approvedSettingsFingerprint' => '',
        ]));

        $this->assertSame(PaymentMigrationDecision::COLLECTION_MANUAL, $decision->collectionMethod);
    }

    /**
     * Nothing in the assessment path may change a FluentCart store setting.
     * CartShift does not own store-wide policy.
     */
    public function testAssessmentChangesNoFluentCartSetting(): void
    {
        $before = $GLOBALS['_cartshift_test_options']['fluent_cart_store_settings'];

        $this->assess($this->record('stripePaymentMethod'), $this->environment());
        $this->assess($this->record('stripeLegacySource'), $this->environment());

        $this->assertSame($before, $GLOBALS['_cartshift_test_options']['fluent_cart_store_settings']);
        $this->assertSame([], $GLOBALS['_cartshift_test_queries']);
    }

    // ── the schedule gate ──────────────────────────────────

    /**
     * Two of the 78 active Lapka records have a next date already in the past.
     *
     * The payment layer's job is to refuse the live mandate and say why.
     * Emitting `blocked` is section 9.3's job, and Task 8's write gate owns it.
     */
    public function testAnActiveRecordWithAPastNextDateLosesItsLiveMandate(): void
    {
        $decision = $this->assess($this->record('activePastDate'), $this->environment());

        $this->assertSame(PaymentMigrationDecision::OUTCOME_CONFIRMATION_REQUIRED, $decision->outcome);
        $this->assertSame(PaymentMigrationDecision::COLLECTION_MANUAL, $decision->collectionMethod);
        $this->assertContains('active_next_date_past', $decision->reasonCodes);
    }

    public function testAnActiveRecordWithNoNextDateLosesItsLiveMandate(): void
    {
        $decision = $this->assess($this->record('activeMissingNextDate'), $this->environment());

        $this->assertSame(PaymentMigrationDecision::OUTCOME_CONFIRMATION_REQUIRED, $decision->outcome);
        $this->assertContains('active_next_date_missing', $decision->reasonCodes);
    }

    /**
     * The finding this ordering bug would have hidden.
     *
     * In the real Lapka run there is no approval hash yet, so every Stripe
     * record already carries `system_store_mode_not_approved`. Evaluating the
     * schedule fault only when nothing else objected meant the two active
     * past-date records came back with no mention of the date at all — Task 8
     * would still have stopped them, and the operator would never have learned
     * why.
     */
    public function testTheDateFaultSurvivesAlongsideEveryOtherObjection(): void
    {
        $this->seedStoreSettings(mode: 'gateway_managed', systemCharge: 'no');

        $decision = $this->assess(
            $this->record('activePastDate', ['meta' => ['_stripe_source_id' => '']]),
            $this->environment(['approvedSettingsFingerprint' => null]),
        );

        $this->assertSame([
            'active_next_date_past',
            'manual_confirmation_required',
            'provider_method_missing',
            'system_store_mode_not_approved',
        ], $decision->reasonCodes);
    }

    /**
     * A confirmed manual cohort covers "this customer now receives an
     * invoice". It does not cover "and its next billing date is in the past",
     * so a receipt must not read `ready` with that in its reason codes.
     */
    public function testAConfirmedCohortStillCannotMakeADateFaultReady(): void
    {
        $decision = $this->assess(
            $this->record('activePastDate'),
            $this->environment(['manualFallbackConfirmed' => true]),
        );

        $this->assertSame(PaymentMigrationDecision::OUTCOME_CONFIRMATION_REQUIRED, $decision->outcome);
        $this->assertSame(['active_next_date_past'], $decision->reasonCodes);
    }

    /**
     * A cancelled remote Stripe subscription is not charging anything, so it
     * obstructs nothing — and `hasSchedule()` must not report one that is dead.
     */
    public function testACancelledRemoteScheduleDoesNotDisqualifySystemAdoption(): void
    {
        $verifier = $this->stripeVerifier([
            'subscriptions/sub_synthetic_fixture_0001' => [
                'id'     => 'sub_synthetic_fixture_0001',
                'status' => 'canceled',
            ],
        ]);

        $record = $this->record('stripePaymentMethod', [
            'meta' => ['_stripe_subscription_id' => 'sub_synthetic_fixture_0001'],
        ]);

        $verification = $verifier->verify($record, $this->environment());

        $this->assertFalse($verification->hasSchedule());
        $this->assertSame([], $verification->reasonCodes);

        $decision = $this->assess($record, $this->environment(['verifiers' => ['stripe' => $verifier]]));

        $this->assertSame(PaymentMigrationDecision::COLLECTION_SYSTEM, $decision->collectionMethod);
    }

    /**
     * 125 records are on hold and 360 have no next-payment date. An on-hold
     * subscription is not renewing, so its absent date is a fact rather than a
     * fault — and nothing here invents one.
     */
    public function testAnOnHoldRecordWithNoNextDateIsNotBlockedOverIt(): void
    {
        $decision = $this->assess($this->record('onHoldNoNextDate'), $this->environment());

        $this->assertNotSame(PaymentMigrationDecision::OUTCOME_BLOCKED, $decision->outcome);
        $this->assertNotContains('active_next_date_missing', $decision->reasonCodes);
    }

    // ── helper ─────────────────────────────────────────────

    private function assess(SubscriptionRecord $record, PaymentEnvironment $environment): PaymentMigrationDecision
    {
        return (new StripePaymentStrategy())->assess($record, $environment);
    }
}

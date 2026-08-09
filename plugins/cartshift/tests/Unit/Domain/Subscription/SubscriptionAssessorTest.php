<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Subscription;

use CartShift\Domain\Subscription\InvalidSourceRecord;
use CartShift\Domain\Subscription\Payment\PaymentCapabilityProbe;
use CartShift\Domain\Subscription\Payment\PaymentEnvironment;
use CartShift\Domain\Subscription\Payment\PaymentMigrationDecision;
use CartShift\Domain\Subscription\Payment\PaymentStrategyRegistry;
use CartShift\Domain\Subscription\SubscriptionAssessment;
use CartShift\Domain\Subscription\SubscriptionAssessor;
use CartShift\Domain\Subscription\SubscriptionLifecycleProjector;
use CartShift\Domain\Subscription\SubscriptionRecord;
use CartShift\Domain\Subscription\SubscriptionRecordFactory;
use CartShift\Storage\IdMapRepository;
use CartShift\Support\Enums\MigrationErrorCode;
use CartShift\Support\Enums\MigrationErrorSeverity;
use CartShift\Tests\Unit\PluginTestCase;

require_once dirname(__DIR__, 3) . '/stubs/EntityMigratorStubs.php';

/**
 * Plan section 9.3's required-reference gate, read as a specification rather
 * than as prose.
 *
 * Every bullet in that section is a test here, and the shared assertion behind
 * all of them is the one the whole task exists for: when a gate fails the
 * assessment is `blocked`, and a blocked assessment is the only thing standing
 * between a broken source row and a `fct_subscriptions` INSERT. The old code
 * answered a missing product by flipping the status to `paused` and writing the
 * row anyway; a status has never satisfied a NOT NULL column.
 *
 * Records are built by the real `SubscriptionRecordFactory` from the Lapka
 * fixtures, never hand-assembled. A hand-built record would let this file
 * assert against a shape the source cannot actually produce, which is how a
 * gate ends up passing tests and failing customers.
 */
final class SubscriptionAssessorTest extends PluginTestCase
{
    /** @var array<string, callable> */
    private array $shapes;

    private SubscriptionRecordFactory $factory;

    private ?object $originalWpdb = null;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->originalWpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb']    = new \CartShiftTestWpdb();

        $GLOBALS['_cartshift_test_id_map'] = [];
        $GLOBALS['_cartshift_test_get_var_callback'] = cartshift_test_id_map_reader();

        $this->shapes  = require dirname(__DIR__, 3) . '/fixtures/lapka-subscription-shapes.php';
        $this->factory = new SubscriptionRecordFactory();

        $GLOBALS['_cartshift_test_fc_gateways'] = [];
        $GLOBALS['_cartshift_test_options']['fluent_cart_store_settings'] = [
            'subscription_management_mode' => 'gateway_managed',
            'subscription_system_charge'   => 'no',
        ];
    }

    #[\Override]
    protected function tearDown(): void
    {
        if ($this->originalWpdb !== null) {
            $GLOBALS['wpdb'] = $this->originalWpdb;
        }

        parent::tearDown();
    }

    // ──────────────────────────────────────────────
    // The healthy shape, so the gate is not simply refusing everything
    // ──────────────────────────────────────────────

    public function testAFullyResolvedLiveRecordIsReady(): void
    {
        $assessment = $this->assess('monthlyPln29');

        $this->assertSame(SubscriptionAssessment::OUTCOME_READY, $assessment->outcome);
        $this->assertSame([], $assessment->errorCodes());
    }

    /**
     * A subscription WCS was charging silently becomes one FluentCart invoices.
     * Section 8.4 holds that at `confirmation_required` until the operator
     * accepts the behaviour change — and a `confirmation_required` assessment
     * cannot be staged, which is what makes the acceptance mean something.
     */
    public function testWithoutTheAcceptedManualFallbackAPreviouslyAutomaticRecordAwaitsConfirmation(): void
    {
        $assessment = $this->assessor(false)->assess($this->record('monthlyPln29'));

        $this->assertSame(SubscriptionAssessment::OUTCOME_CONFIRMATION_REQUIRED, $assessment->outcome);
        $this->assertFalse($assessment->isStageable());
        $this->assertSame([], $assessment->errorCodes(), 'Awaiting a decision is not the same as blocked.');
    }

    public function testTheResolvedReferencesAreTheDestinationIdsNotTheSourceOnes(): void
    {
        $record     = $this->record('monthlyPln29');
        $assessment = $this->assessor()->assess($record);

        $this->assertSame(
            $record->sourceCustomerId + 10_000,
            $assessment->resolvedReferences['customer_id'],
        );
        $this->assertSame($record->parentOrderId + 10_000, $assessment->resolvedReferences['parent_order_id']);
        $this->assertSame(
            CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID + 10_000,
            $assessment->resolvedReferences['product_id'],
        );
        $this->assertSame('Monthly membership (fixture)', $assessment->resolvedReferences['item_name']);
        $this->assertSame(1, $assessment->resolvedReferences['quantity']);
    }

    // ──────────────────────────────────────────────
    // Every NOT NULL reference, one at a time
    // ──────────────────────────────────────────────

    public function testAnUnresolvedCustomerBlocks(): void
    {
        $this->assertBlockedWithout('customer', SubscriptionAssessment::REASON_CUSTOMER_NOT_FOUND);
    }

    public function testAnUnresolvedParentOrderBlocks(): void
    {
        $this->assertBlockedWithout('order', SubscriptionAssessment::REASON_REQUIRED_REFERENCE_MISSING);
    }

    public function testAnUnresolvedProductBlocks(): void
    {
        $this->assertBlockedWithout('product', SubscriptionAssessment::REASON_REQUIRED_REFERENCE_MISSING);
    }

    public function testAnUnresolvedVariationBlocks(): void
    {
        $this->assertBlockedWithout('variation', SubscriptionAssessment::REASON_REQUIRED_REFERENCE_MISSING);
    }

    /**
     * `item_name` is `TEXT NOT NULL`. The source row that carries no name is a
     * row somebody has to go and repair, not one to write with an empty string
     * and hope the renewal invoice reads well.
     */
    public function testAnEmptyItemNameNeverProducesAValidRecordAtAll(): void
    {
        $this->assertInstanceOf(
            InvalidSourceRecord::class,
            $this->factory->subscriptionFromWoo('lapka', $this->shapes['itemWithNoName']()),
        );
    }

    // ──────────────────────────────────────────────
    // Exactly one source item
    // ──────────────────────────────────────────────

    public function testAMultiItemSubscriptionIsBlockedRatherThanTruncated(): void
    {
        $assessment = $this->assess('multiItem');

        $this->assertSame(SubscriptionAssessment::OUTCOME_BLOCKED, $assessment->outcome);
        $this->assertContains(
            SubscriptionAssessment::REASON_MULTI_ITEM,
            $assessment->errorCodes(),
            'A FluentCart subscription holds one contract, so keeping the first item is data loss.',
        );
    }

    public function testTheMultiItemMessageNamesEveryItemSoTheOperatorCanSplitIt(): void
    {
        $message = $this->firstMessage($this->assess('multiItem'), SubscriptionAssessment::REASON_MULTI_ITEM);

        $this->assertStringContainsString('Monthly membership (fixture)', (string) $message);
        $this->assertStringContainsString('Yearly membership (fixture)', (string) $message);
    }

    // ──────────────────────────────────────────────
    // Cadence — the carried P1
    // ──────────────────────────────────────────────

    /**
     * `month/2` is a real WooCommerce cadence FluentCart cannot express. The
     * lenient reading collapsed it to monthly, which bills a customer twice as
     * often as they agreed to.
     */
    public function testAnUnrepresentableCadenceNeverProducesAValidRecord(): void
    {
        $record = $this->factory->subscriptionFromWoo(
            'lapka',
            $this->shapes['monthlyPln29'](['billing_interval' => 2]),
        );

        $this->assertInstanceOf(InvalidSourceRecord::class, $record);
        $this->assertContains('unsupported_billing_cadence', $record->reasonCodes);
    }

    /**
     * And the assessor refuses it a second time, from the record's own
     * contract, because `cartshift/mapper/subscription` and a hand-built
     * package both reach the writer without passing the decoder's gate twice.
     */
    public function testTheAssessorAlsoRefusesAContractWithNoRepresentableInterval(): void
    {
        $record = $this->recordWithContract($this->record('monthlyPln29'), targetInterval: null);

        $assessment = $this->assessor()->assess($record);

        $this->assertSame(SubscriptionAssessment::OUTCOME_BLOCKED, $assessment->outcome);
        $this->assertContains(
            SubscriptionAssessment::REASON_UNSUPPORTED_CADENCE,
            $assessment->errorCodes(),
        );
    }

    // ──────────────────────────────────────────────
    // Lifecycle projection (section 9.3), enforced rather than characterised
    // ──────────────────────────────────────────────

    /**
     * Two of the 78 active Lapka records. Task 1 pinned this as today's
     * behaviour — mapped active, past date handed straight through. It is now
     * the contract: nothing owns a charge that was due last year.
     */
    public function testAnActiveRecordWithAPastNextDateIsBlocked(): void
    {
        $assessment = $this->assess('activePastDate');

        $this->assertSame(SubscriptionAssessment::OUTCOME_BLOCKED, $assessment->outcome);
        $this->assertContains('active_next_date_past', $assessment->errorCodes());
    }

    /**
     * Likewise `active_next_date_missing`, and likewise a Task 1
     * characterisation converted into a gate.
     */
    public function testAnActiveRecordWithNoNextDateIsBlocked(): void
    {
        $assessment = $this->assess('activeMissingNextDate');

        $this->assertSame(SubscriptionAssessment::OUTCOME_BLOCKED, $assessment->outcome);
        $this->assertContains('active_next_date_missing', $assessment->errorCodes());
    }

    /**
     * The manual cohort is gated too. `ManualPaymentStrategy` attaches no date
     * reason — it has no opinion about schedules — so a manual record with a
     * past date would otherwise walk straight past a gate that only consulted
     * the payment decision. The assessor asks `PaymentEnvironment
     * ::liveScheduleFault()`, which is the same helper the Stripe and PayPal
     * strategies ask, so there is one definition of "future and plausible" in
     * the plugin rather than two that can disagree.
     */
    public function testAManualLiveRecordWithAPastNextDateIsBlockedToo(): void
    {
        $assessment = $this->assess('activePastDate', [
            'payment_method'          => 'bacs',
            'requires_manual_renewal' => true,
            'meta'                    => [],
        ]);

        $this->assertSame(SubscriptionAssessment::OUTCOME_BLOCKED, $assessment->outcome);
        $this->assertContains('active_next_date_past', $assessment->errorCodes());
    }

    public function testThePastDateReasonIsReportedOnceEvenWhenBothLayersSeeIt(): void
    {
        $codes = $this->assess('activePastDate')->errorCodes();

        $this->assertSame(
            1,
            count(array_keys($codes, 'active_next_date_past', true)),
            'The payment decision and the write gate agree; that is one fault, not two.',
        );
    }

    /**
     * 125 Lapka records are on hold and 360 have no next-payment date. An
     * on-hold record legitimately has none, and blocking over its absence would
     * refuse a quarter of the source for a schedule nobody is waiting on.
     */
    public function testAnOnHoldRecordWithNoNextDateIsNotBlockedForIt(): void
    {
        $assessment = $this->assess('onHoldNoNextDate');

        $this->assertNotContains('active_next_date_missing', $assessment->errorCodes());
        $this->assertNull($assessment->lifecycle['next_billing_date'], 'Nothing may invent one either.');
    }

    public function testATerminalRecordWithNoNextDateKeepsItsStatusAndItsNull(): void
    {
        $assessment = $this->assess('terminalNoNextDate');

        $this->assertSame(SubscriptionAssessment::OUTCOME_READY, $assessment->outcome);
        $this->assertSame('canceled', $assessment->lifecycle['status']);
        $this->assertNull($assessment->lifecycle['next_billing_date']);
        $this->assertSame('2023-11-30 08:00:00', $assessment->lifecycle['canceled_at']);
    }

    /**
     * Section 11 Phase B: a validated live record is created paused and
     * activated later, so the status it is written with is `paused` and the one
     * it is destined for travels in config.
     */
    public function testALiveRecordWithAFutureDateStagesPausedWithItsIntendedStatusRecorded(): void
    {
        $assessment = $this->assess('activeFutureDate');

        $this->assertSame(SubscriptionAssessment::OUTCOME_READY, $assessment->outcome);
        $this->assertSame('paused', $assessment->lifecycle['status']);
        $this->assertSame('active', $assessment->lifecycle['intended_status']);
        $this->assertSame('2099-05-11 09:15:00', $assessment->lifecycle['next_billing_date']);
    }

    /**
     * A twelve-month contract with twelve payments taken, still called active.
     * Either the source status or the count is wrong, and guessing which is how
     * somebody gets billed a thirteenth time.
     */
    public function testAFinitePlanPaidToItsTermWhileStillActiveIsBlocked(): void
    {
        $assessment = $this->assess('finitePaidToTermConflict', [
            'meta' => ['_subscription_length' => '12'],
        ]);

        $this->assertSame(SubscriptionAssessment::OUTCOME_BLOCKED, $assessment->outcome);
        $this->assertContains('finite_term_state_conflict', $assessment->errorCodes());
    }

    public function testAFinitePlanPaidToItsTermWithTerminalSourceEvidencePasses(): void
    {
        $assessment = $this->assess('finitePaidToTermConflict', [
            'status' => 'cancelled',
            'meta'   => ['_subscription_length' => '12'],
            'dates'  => ['next_payment' => '', 'cancelled' => '2024-02-19 12:41:00'],
        ]);

        $this->assertNotContains('finite_term_state_conflict', $assessment->errorCodes());
    }

    // ──────────────────────────────────────────────
    // The finite term (section 9.2), and why it blocks
    // ──────────────────────────────────────────────

    /**
     * `finiteCycles === null` means two different things: WCS writes
     * `_subscription_length = 0` for a genuinely unlimited plan, and writes
     * nothing at all when the subscription's own meta is silent.
     *
     * Answering the second with `bill_times = 0` tells FluentCart to bill for
     * ever, which is a contract the source never expressed — and it also
     * disarms `finite_term_state_conflict`, whose check only examines a
     * positive term. One unanswered question would decide two section 9.3
     * gates, so it is refused.
     *
     * The current product's `_subscription_length` is still not substituted:
     * that value describes today's catalogue rather than what this subscriber
     * agreed to, and the fixture carries twelve on the product precisely so
     * this test can show it being ignored.
     */
    public function testAnUndeclaredFiniteTermIsBlockedRatherThanAnsweredAsUnlimited(): void
    {
        $assessment = $this->assess('termDeclaredNowhere');

        $this->assertSame(SubscriptionAssessment::OUTCOME_BLOCKED, $assessment->outcome);
        $this->assertContains(
            SubscriptionAssessment::REASON_FINITE_TERM_UNDECLARED,
            $assessment->errorCodes(),
        );
        $this->assertFalse(
            $assessment->isStageable(),
            'The projection still carries bill_times = 0. What stops it reaching the database is the '
            . 'block, not the number — which is exactly why the number alone was never enough.',
        );
    }

    public function testTheMessageNamesBothRemediesRatherThanJustTheCode(): void
    {
        $message = (string) $this->firstMessage(
            $this->assess('termDeclaredNowhere'),
            SubscriptionAssessment::REASON_FINITE_TERM_UNDECLARED,
        );

        $this->assertStringContainsString(
            'neither does the product',
            $message,
            'The operator has to be told both places were checked.',
        );
        $this->assertStringContainsString('Nothing was migrated', $message);
    }

    /**
     * Section 9.2's middle state, and the one every Lapka record is in:
     * `_subscription_length` occurs four times in the whole preserved source,
     * on the two products and on none of the 564 subscriptions. So the
     * product's answer is used — and warned about, because it describes today's
     * catalogue rather than what a subscriber agreed to years ago.
     */
    public function testATermTakenFromTheProductIsUsedAndWarnedAbout(): void
    {
        $assessment = $this->assess('monthlyPln29');

        $this->assertSame(SubscriptionAssessment::OUTCOME_READY, $assessment->outcome);
        $this->assertContains(
            SubscriptionAssessment::REASON_FINITE_TERM_FROM_PRODUCT,
            $assessment->warningCodes(),
        );
        $this->assertSame(
            0,
            $assessment->lifecycle['bill_times'],
            'Both Lapka products are unlimited, which WCS writes as 0 — a declared answer.',
        );
    }

    public function testAFiniteTermOnTheProductIsCarriedThroughRatherThanIgnored(): void
    {
        $assessment = $this->assess('finitePaidToTermConflict', ['status' => 'cancelled']);

        $this->assertSame(
            12,
            $assessment->lifecycle['bill_times'],
            'The product says twelve and the subscription says nothing, so twelve it is — with a warning.',
        );
        $this->assertContains(
            SubscriptionAssessment::REASON_FINITE_TERM_FROM_PRODUCT,
            $assessment->warningCodes(),
        );
    }

    /**
     * An explicitly unlimited plan — which is what WooCommerce Subscriptions
     * writes for both Lapka source products — is not the same condition and is
     * not blocked.
     */
    public function testASubscriptionThatDeclaresItsOwnUnlimitedTermNeedsNoFallback(): void
    {
        $assessment = $this->assess('monthlyPln29', ['meta' => ['_subscription_length' => '0']]);

        $this->assertNotContains(
            SubscriptionAssessment::REASON_FINITE_TERM_UNDECLARED,
            $assessment->errorCodes(),
        );
        $this->assertNotContains(
            SubscriptionAssessment::REASON_FINITE_TERM_FROM_PRODUCT,
            $assessment->warningCodes(),
            'The subscription answered for itself, so nothing was borrowed and there is nothing to warn about.',
        );
        $this->assertSame(0, $assessment->lifecycle['bill_times'], 'Unlimited, because the source said so.');
    }

    public function testTheSubscriptionsOwnTermBeatsTheProducts(): void
    {
        // The product says unlimited; the subscription says six. Section 9.2's
        // order of preference is the subscriber's own contract first.
        $assessment = $this->assess('monthlyPln29', ['meta' => ['_subscription_length' => '6']]);

        $this->assertSame(6, $assessment->lifecycle['bill_times']);
        $this->assertNotContains(
            SubscriptionAssessment::REASON_FINITE_TERM_FROM_PRODUCT,
            $assessment->warningCodes(),
        );
    }

    /**
     * Provenance missing altogether counts as nothing declared.
     *
     * Neither factory can produce such a record, but "the key was absent" must
     * not be the one path that falls through to `bill_times = 0` and bills for
     * ever — which is the original defect, one layer up.
     */
    public function testARecordWithNoProvenanceAtAllIsTreatedAsUndeclared(): void
    {
        $record   = $this->record('monthlyPln29');
        $contract = $record->contract;
        $plan     = $contract->sourcePlan;

        unset($plan[SubscriptionRecordFactory::FINITE_CYCLES_SOURCE]);

        $stripped = $this->recordWithSourcePlan($record, $plan);

        $this->assertContains(
            SubscriptionAssessment::REASON_FINITE_TERM_UNDECLARED,
            $this->assessor()->assess($stripped)->errorCodes(),
        );
    }

    // ──────────────────────────────────────────────
    // Variation ownership (section 9.3)
    // ──────────────────────────────────────────────

    /**
     * "Both references resolved" and "the variation is on that product" are
     * different claims. `fct_product_variations.id` is a global auto-increment
     * with nothing linking `fct_order_items.object_id` back to it, and the ID
     * map has two writers, so a stale mapping decision can pair a product with
     * somebody else's variant. Section 9.3 asks for the pairing to be checked
     * before the subscription is written, not merely to have been checked when
     * the mapping was promoted.
     */
    public function testAVariationOnAnotherProductIsBlocked(): void
    {
        $record = $this->record('monthlyPln29');

        cartshift_test_own_variation(
            CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID + 10_000,
            999_999,
        );

        $assessment = $this->assessor()->assess($record);

        $this->assertSame(SubscriptionAssessment::OUTCOME_BLOCKED, $assessment->outcome);
        $this->assertContains('target_variation_not_on_product', $assessment->errorCodes());
    }

    public function testTheOwnershipMessageNamesBothProducts(): void
    {
        $record = $this->record('monthlyPln29');

        cartshift_test_own_variation(CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID + 10_000, 999_999);

        $message = (string) $this->firstMessage(
            $this->assessor()->assess($record),
            SubscriptionAssessment::REASON_VARIATION_NOT_ON_PRODUCT,
        );

        $this->assertStringContainsString('999999', $message);
        $this->assertStringContainsString((string) (CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID + 10_000), $message);
    }

    /**
     * A dry run has no catalogue to ask, and must not refuse for it.
     *
     * `MigrationOrchestrator` fills the ID map with SIMULATED destination IDs —
     * numbers standing in for rows that will exist if the owner runs for real —
     * so the ownership lookup finds nothing and every subscription would refuse
     * under `target_variation_not_on_product`. The preview would then
     * contradict the run it previews, which is the fault this class was
     * rewritten to remove. Note that no ownership is stated here: the point is
     * that none is needed.
     */
    public function testADryRunDoesNotRefuseForACatalogueItCannotSee(): void
    {
        $record = $this->record('monthlyPln29');

        $GLOBALS['_cartshift_test_fc_variation_owner'] = [];

        $idMap = new IdMapRepository();
        $idMap->setSimulating(true);

        $assessment = (new SubscriptionAssessor(
            $idMap,
            PaymentStrategyRegistry::withDefaults(),
            $this->environment(),
            new SubscriptionLifecycleProjector(),
        ))->assess($record);

        $this->assertNotContains('target_variation_not_on_product', $assessment->errorCodes());
        $this->assertSame(SubscriptionAssessment::OUTCOME_READY, $assessment->outcome);
    }

    /**
     * And the real run still asks, on the same record and the same map.
     */
    public function testTheRealRunStillAsksTheCatalogue(): void
    {
        $record = $this->record('monthlyPln29');

        $GLOBALS['_cartshift_test_fc_variation_owner'] = [];

        $this->assertContains(
            'target_variation_not_on_product',
            $this->assessor()->assess($record)->errorCodes(),
        );
    }

    // ──────────────────────────────────────────────
    // The record that stops a cohort must not read like a success
    // ──────────────────────────────────────────────

    /**
     * `manual_confirmation_required` is the reason nothing was written, and on
     * a target with no provider credentials it is the reason nothing was
     * written for every live subscription in the shop. It used to be reported
     * as "…will now be invoiced by FluentCart instead. Nothing charges this
     * customer off-session" — true, and indistinguishable from a result.
     */
    public function testTheUnacceptedManualFallbackSaysNothingWasMigrated(): void
    {
        $assessment = $this->assessor(false)->assess($this->record('monthlyPln29'));

        $message = (string) $this->firstWarningMessage(
            $assessment,
            SubscriptionAssessment::REASON_MANUAL_NOT_ACCEPTED,
        );

        $this->assertStringContainsString('Nothing was migrated', $message);
        $this->assertStringContainsString('has to be accepted', $message);
        $this->assertStringNotContainsString(
            'will now be invoiced',
            $message,
            'That sentence describes a record that migrated. This one did not.',
        );
    }

    /**
     * The refusal and the note are different codes, and have to be.
     *
     * They were one string for a round: a subscription that staged
     * successfully then logged under "Manual renewal has not been accepted"
     * with a hint reading "Nothing was migrated", at blocking severity — the
     * log saying the opposite of what happened. A code a UI groups by is only
     * worth having if the group means one thing.
     */
    public function testTheRefusalAndTheAdoptionNoteAreNotTheSameCode(): void
    {
        $this->assertNotSame(
            SubscriptionAssessment::REASON_MANUAL_NOT_ACCEPTED,
            SubscriptionAssessment::REASON_MANUAL_RENEWAL_ADOPTED,
        );

        $refused = $this->assessor(false)->assess($this->record('monthlyPln29'));
        $staged  = $this->assess('monthlyPln29');

        $this->assertContains(SubscriptionAssessment::REASON_MANUAL_NOT_ACCEPTED, $refused->warningCodes());
        $this->assertNotContains(
            SubscriptionAssessment::REASON_MANUAL_RENEWAL_ADOPTED,
            $refused->warningCodes(),
        );

        $this->assertContains(SubscriptionAssessment::REASON_MANUAL_RENEWAL_ADOPTED, $staged->warningCodes());
        $this->assertNotContains(
            SubscriptionAssessment::REASON_MANUAL_NOT_ACCEPTED,
            $staged->warningCodes(),
        );

        // And a record that migrated is not filed under a blocking code whose
        // operator copy says nothing was migrated.
        $this->assertSame(
            MigrationErrorSeverity::Warning,
            MigrationErrorCode::from(SubscriptionAssessment::REASON_MANUAL_RENEWAL_ADOPTED)->severity(),
        );
        $this->assertSame(
            MigrationErrorSeverity::Error,
            MigrationErrorCode::from(SubscriptionAssessment::REASON_MANUAL_NOT_ACCEPTED)->severity(),
        );
    }

    // ──────────────────────────────────────────────
    // Term copy an operator can act on
    // ──────────────────────────────────────────────

    /**
     * `0` is WooCommerce Subscriptions' encoding of "unlimited" and is legible
     * as such only to somebody who already knows that — which is nobody the
     * message is written for. Every one of the 564 Lapka records lands here.
     */
    public function testTheProductTermWarningSaysUnlimitedRatherThanZero(): void
    {
        $message = (string) $this->firstWarningMessage(
            $this->assess('monthlyPln29'),
            SubscriptionAssessment::REASON_FINITE_TERM_FROM_PRODUCT,
        );

        $this->assertStringContainsString('unlimited', $message);
        $this->assertStringNotContainsString('(0)', $message);
    }

    public function testAFiniteProductTermIsCountedInBillingPeriods(): void
    {
        $message = (string) $this->firstWarningMessage(
            $this->assess('finitePaidToTermConflict', ['status' => 'cancelled']),
            SubscriptionAssessment::REASON_FINITE_TERM_FROM_PRODUCT,
        );

        $this->assertStringContainsString('12 billing periods', $message);
    }

    /**
     * A package exported before CartShift read the product carries no evidence
     * either way, so telling its operator "and neither does the product it
     * sells" asserts something nothing checked — and on the Lapka source it is
     * untrue, because both products declare a term. That operator needs to
     * re-export, not to go and fix a product that is fine.
     */
    public function testAnExportPredatingTheProductReadIsToldToReExport(): void
    {
        $record = $this->record('termDeclaredNowhere');
        $plan   = $record->contract->sourcePlan;

        unset($plan[SubscriptionRecordFactory::PLAN_PRODUCT_READ]);

        $message = (string) $this->firstMessage(
            $this->assessor()->assess($this->recordWithSourcePlan($record, $plan)),
            SubscriptionAssessment::REASON_FINITE_TERM_UNDECLARED,
        );

        $this->assertStringContainsString('export the source again', $message);
        $this->assertStringNotContainsString(
            'neither does the product',
            $message,
            'Nothing asked the product, so nothing may report its answer.',
        );
    }

    /**
     * And the current exporter, which did ask, says so plainly.
     */
    public function testACurrentExportWhoseProductIsAlsoSilentSaysSo(): void
    {
        $message = (string) $this->firstMessage(
            $this->assess('termDeclaredNowhere'),
            SubscriptionAssessment::REASON_FINITE_TERM_UNDECLARED,
        );

        $this->assertStringContainsString('neither does the product', $message);
        $this->assertStringContainsString('set the subscription length in WooCommerce', $message);
    }

    /**
     * And the accepted case keeps its informational note, because the customer
     * really is about to start receiving invoices.
     */
    public function testTheAcceptedManualFallbackKeepsTheInformationalNote(): void
    {
        $message = (string) $this->firstWarningMessage(
            $this->assess('monthlyPln29'),
            SubscriptionAssessment::REASON_MANUAL_RENEWAL_ADOPTED,
        );

        $this->assertStringContainsString('will now be invoiced by FluentCart', $message);
    }

    /**
     * A variation the target catalogue does not hold at all is refused under
     * the same code: either way the subscription would bill against a line
     * FluentCart cannot resolve.
     */
    public function testAVariationMissingFromTheTargetCatalogueIsBlocked(): void
    {
        $record = $this->record('monthlyPln29');

        $assessment = (new SubscriptionAssessor(
            new IdMapRepository(),
            PaymentStrategyRegistry::withDefaults(),
            $this->environment(),
            new SubscriptionLifecycleProjector(),
            static fn (int $variationId): ?int => null,
        ))->assess($record);

        $this->assertContains('target_variation_not_on_product', $assessment->errorCodes());
    }

    // ──────────────────────────────────────────────
    // Payment ownership
    // ──────────────────────────────────────────────

    /**
     * Section 8.1 step 6. A gateway nothing supports is blocked, not guessed
     * into one of the three buckets, because a guess here bills a real person.
     */
    public function testAnUnsupportedGatewayBlocks(): void
    {
        $assessment = $this->assess('monthlyPln29', ['payment_method' => 'some-bespoke-gateway']);

        $this->assertSame(SubscriptionAssessment::OUTCOME_BLOCKED, $assessment->outcome);
        $this->assertContains('unsupported_gateway', $assessment->errorCodes());
    }

    /**
     * The mapper no longer has a gateway branch at all: whatever the source
     * slug was, the collection method comes from the strategy registry. On this
     * target no gateway is registered and no settings hash is approved, so
     * every live record lands on the safe end.
     */
    public function testTheAssessorNeverInventsAnAutomaticCollectionMethod(): void
    {
        $assessment = $this->assess('monthlyPln29');

        $this->assertSame(PaymentMigrationDecision::COLLECTION_MANUAL, $assessment->payment->collectionMethod);
        $this->assertNotSame(
            PaymentMigrationDecision::COLLECTION_AUTOMATIC,
            $assessment->payment->collectionMethod,
            '`automatic` means a gateway owns a remote schedule, and none of the 367 Lapka Stripe '
            . 'records has one.',
        );
    }

    /**
     * A record WCS was charging automatically becomes one FluentCart invoices.
     * That is a behaviour change the customer will notice, so it is counted
     * rather than absorbed.
     */
    public function testAPreviouslyAutomaticRecordStagedManualCarriesTheAdoptionWarning(): void
    {
        $assessment = $this->assess('monthlyPln29');

        $this->assertContains(
            SubscriptionAssessment::REASON_MANUAL_RENEWAL_ADOPTED,
            $assessment->warningCodes(),
        );
    }

    public function testASourceThatWasAlreadyManualCarriesNoSuchWarning(): void
    {
        $assessment = $this->assess('manualRenewal');

        $this->assertNotContains(
            SubscriptionAssessment::REASON_MANUAL_RENEWAL_ADOPTED,
            $assessment->warningCodes(),
            'WCS was not charging this record either, so nothing changes for its customer.',
        );
    }

    // ──────────────────────────────────────────────
    // Read-only
    // ──────────────────────────────────────────────

    /**
     * Assessment is section 11 Phase A, and Phase A writes nothing. Every gate
     * above resolves references by reading the ID map; none of them creates a
     * customer, an order, or a subscription on the way.
     */
    public function testAssessingWritesNothingToTheDatabase(): void
    {
        $this->assess('monthlyPln29');
        $this->assess('multiItem');
        $this->assess('activePastDate');

        foreach ($GLOBALS['_cartshift_test_queries'] ?? [] as $query) {
            $this->assertNotContains(
                $query[0] ?? '',
                ['insert', 'update', 'delete', 'replace'],
                sprintf('Assessment performed a %s.', (string) ($query[0] ?? '')),
            );
        }
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    /**
     * @param array<string, mixed> $overrides
     */
    private function assess(string $shape, array $overrides = []): SubscriptionAssessment
    {
        $record = $this->record($shape, $overrides);

        return $this->assessor()->assess($record);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function record(string $shape, array $overrides = []): SubscriptionRecord
    {
        $subscription = $this->shapes[$shape]($overrides);
        $record       = $this->factory->subscriptionFromWoo('lapka', $subscription);

        $this->assertNotInstanceOf(
            InvalidSourceRecord::class,
            $record,
            sprintf('Fixture "%s" did not decode into a valid record.', $shape),
        );

        /** @var SubscriptionRecord $record */
        $this->mapEverythingFor($record);

        return $record;
    }

    /**
     * @param array<string, mixed> $sourcePlan
     */
    private function recordWithSourcePlan(SubscriptionRecord $record, array $sourcePlan): SubscriptionRecord
    {
        $contract = $record->contract;

        return $this->rebuild($record, new \CartShift\Domain\Subscription\SubscriptionContract(
            $contract->period,
            $contract->multiplier,
            $contract->targetInterval,
            $contract->recurringAmount,
            $contract->recurringTax,
            $contract->recurringTotal,
            $contract->finiteCycles,
            $contract->trialLength,
            $contract->trialPeriod,
            $contract->setupFee,
            $sourcePlan,
        ));
    }

    private function rebuild(
        SubscriptionRecord $record,
        \CartShift\Domain\Subscription\SubscriptionContract $contract,
    ): SubscriptionRecord {
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
            $contract,
            $record->gateway,
            $record->requiresManualRenewal,
            $record->paymentReferences,
            $record->dates,
            $record->relatedOrders,
            $record->sourcePaymentCount,
            $record->fingerprint,
        );
    }

    private function recordWithContract(SubscriptionRecord $record, ?string $targetInterval): SubscriptionRecord
    {
        $contract = $record->contract;

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
            new \CartShift\Domain\Subscription\SubscriptionContract(
                $contract->period,
                $contract->multiplier,
                $targetInterval,
                $contract->recurringAmount,
                $contract->recurringTax,
                $contract->recurringTotal,
                $contract->finiteCycles,
                $contract->trialLength,
                $contract->trialPeriod,
                $contract->setupFee,
                $contract->sourcePlan,
            ),
            $record->gateway,
            $record->requiresManualRenewal,
            $record->paymentReferences,
            $record->dates,
            $record->relatedOrders,
            $record->sourcePaymentCount,
            $record->fingerprint,
        );
    }

    /**
     * The default environment has the manual fallback already accepted.
     *
     * Not a convenience. Without it every live record on this target comes back
     * `confirmation_required` — no provider credentials are configured and no
     * settings hash is approved — and each gate below would then be asserting
     * against an outcome the payment layer had already decided, proving nothing
     * about the gate. The unaccepted case has its own test, immediately after
     * the healthy one.
     */
    private function assessor(bool $manualFallbackConfirmed = true): SubscriptionAssessor
    {
        return new SubscriptionAssessor(
            new IdMapRepository(),
            PaymentStrategyRegistry::withDefaults(),
            $this->environment($manualFallbackConfirmed),
            new SubscriptionLifecycleProjector(),
        );
    }

    private function environment(bool $manualFallbackConfirmed = true): PaymentEnvironment
    {
        return new PaymentEnvironment(
            capabilities: new PaymentCapabilityProbe(),
            settingsFingerprint: 'fingerprint-of-the-reviewed-target',
            manualFallbackConfirmed: $manualFallbackConfirmed,
            nowUtc: '2026-08-09 00:00:00',
        );
    }

    private function assertBlockedWithout(string $entityType, string $reasonCode): void
    {
        $record = $this->record('monthlyPln29');

        unset($GLOBALS['_cartshift_test_id_map'][$entityType]);

        $assessment = $this->assessor()->assess($record);

        $this->assertSame(SubscriptionAssessment::OUTCOME_BLOCKED, $assessment->outcome);
        $this->assertContains($reasonCode, $assessment->errorCodes());
    }

    private function firstMessage(SubscriptionAssessment $assessment, string $code): ?string
    {
        foreach ($assessment->errors as $error) {
            if (($error['code'] ?? '') === $code) {
                return (string) ($error['message'] ?? '');
            }
        }

        return null;
    }

    private function firstWarningMessage(SubscriptionAssessment $assessment, string $code): ?string
    {
        foreach ($assessment->warnings as $warning) {
            if (($warning['code'] ?? '') === $code) {
                return (string) ($warning['message'] ?? '');
            }
        }

        return null;
    }

    /**
     * Resolve every reference this subscription needs, and state the target
     * catalogue as well.
     *
     * The ownership pairing is said out loud rather than derived from the ID
     * map. A mapping row and a catalogue row are different facts, and the gate
     * that reads the second exists precisely because the first can be stale.
     */
    private function mapEverythingFor(SubscriptionRecord $record): void
    {
        $this->mapEntity('customer', (int) $record->sourceCustomerId);
        $this->mapEntity('order', $record->parentOrderId);

        foreach ($record->items as $item) {
            $sourceProductId = (int) $item['source_product_id'];

            $this->mapEntity('product', $sourceProductId);
            $this->mapEntity('variation', $sourceProductId);

            if ($sourceProductId > 0) {
                cartshift_test_own_variation($sourceProductId + 10_000, $sourceProductId + 10_000);
            }
        }
    }

    private function mapEntity(string $entityType, int $wcId): void
    {
        if ($wcId <= 0) {
            return;
        }

        $GLOBALS['_cartshift_test_id_map'][$entityType][(string) $wcId] = $wcId + 10_000;
    }
}

<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Subscription;

use CartShift\Domain\Mapping\SubscriptionMapper;
use CartShift\Domain\Subscription\DatasetClosureValidator;
use CartShift\Domain\Subscription\DatasetManifest;
use CartShift\Domain\Subscription\OrderRecord;
use CartShift\Domain\Subscription\Payment\PaymentMigrationDecision;
use CartShift\Domain\Subscription\ReconciliationResult;
use CartShift\Domain\Subscription\SubscriptionAssessment;
use CartShift\Domain\Subscription\SubscriptionHistoryIndex;
use CartShift\Domain\Subscription\SubscriptionHistoryLinker;
use CartShift\Domain\Subscription\SubscriptionLifecycleProjector;
use CartShift\Domain\Subscription\SubscriptionOrderImporter;
use CartShift\Domain\Subscription\SubscriptionReconciler;
use CartShift\Domain\Subscription\SubscriptionRecord;
use CartShift\Domain\Subscription\SubscriptionSelection;
use CartShift\Domain\Subscription\SubscriptionWriter;
use CartShift\Support\Constants;

/**
 * Three numbers, compared. Never one number, written.
 *
 * Plan section 10's algorithm asks FluentCart's own `calculateBillCount()` for
 * the canonical count, and compares it with WCS `get_payment_count()` and the
 * included paid-order evidence. Agreement sets `bill_count`. Disagreement keeps
 * the record paused and names the orders, because a number written to make a
 * mismatch go away is a number FluentCart will contradict at the next recompute
 * — and the difference will be somebody's billing history.
 *
 * `SubscriptionService::syncSubscriptionStates()` is forbidden here and the
 * reason is specific: it completes finite subscriptions, clears their dates,
 * and calls `guessNextBillingDate()` whenever the next date is empty. The
 * preserved Lapka source has 360 empty next-payment dates. Run that service
 * over them and CartShift reports 564 schedules, 360 of which it invented, each
 * one a date a real card would be charged on.
 */
final class SubscriptionReconcilerTest extends SubscriptionHistoryTestCase
{
    // ──────────────────────────────────────────────
    // Agreement
    // ──────────────────────────────────────────────

    public function testThreeAgreeingCountsSetBillCount(): void
    {
        $result = $this->reconcileFixture();

        $this->assertTrue($result->reconciled);
        $this->assertSame(2, $result->sourcePaymentCount);
        $this->assertSame(2, $result->includedPaidOrderCount);
        $this->assertSame(2, $result->fluentCartCount);
        $this->assertSame([], $result->reasonCodes);
        $this->assertSame(2, $this->stagedSubscription()->bill_count);
    }

    public function testTheCanonicalCountIsFluentCartsOwnAndNotACopiedNumber(): void
    {
        // The source says three payments; only two orders carry a charge. The
        // count FluentCart computes is the one that survives a recompute, so a
        // disagreement has to be visible rather than papered over.
        $result = $this->reconcileFixture(['source_payment_count' => 3]);

        $this->assertFalse($result->reconciled);
        $this->assertSame(3, $result->sourcePaymentCount);
        $this->assertSame(2, $result->includedPaidOrderCount);
        $this->assertSame(2, $result->fluentCartCount);
    }

    // ──────────────────────────────────────────────
    // Mismatch
    // ──────────────────────────────────────────────

    public function testAMismatchKeepsTheRecordPausedAndWritesNoBillCount(): void
    {
        $before = null;

        $result = $this->reconcileFixture(['source_payment_count' => 3], function () use (&$before): void {
            $before = $this->stagedSubscription()->bill_count;
        });

        $this->assertFalse($result->reconciled);
        $this->assertSame('paused', $this->stagedSubscription()->status);
        $this->assertSame($before, $this->stagedSubscription()->bill_count);
    }

    public function testAMismatchCarriesTheStableReasonCodeAndTheRelatedOrderIds(): void
    {
        $result = $this->reconcileFixture(['source_payment_count' => 3]);

        $this->assertSame([ReconciliationResult::REASON_HISTORY_COUNT_MISMATCH], $result->reasonCodes);
        $this->assertSame('history_count_mismatch', ReconciliationResult::REASON_HISTORY_COUNT_MISMATCH);
        $this->assertSame([880_001, 880_501], $result->relatedOrderIds);
        $this->assertSame([880_001, 880_501], $result->paidOrderIds);
        $this->assertStringContainsString('880501', $result->message);
    }

    /**
     * The arm that catches a history that came across and did not link.
     *
     * Every other mismatch here varies the source's payment count, so
     * `fluentCartCount` and `correctedPaidCycleCount` move together and only the
     * WCS comparison bites. This one leaves all three source numbers alone and
     * breaks the link: an order adopted through the `invoice_no` probe — imported
     * by FluentCart's own WooCommerce importer, or by a CartShift run whose ID
     * map was rolled back — whose transaction was never filed under
     * `{id}_charge`. The linker looks it up, finds nothing, and returns early.
     * FluentCart then counts one paid cycle where the history carries two, and
     * nothing about the destination row looks wrong.
     *
     * This is the silent undercount the reconciler exists for, and it is caught
     * by `fluentCartCount === correctedPaidCycleCount` alone.
     */
    public function testAnAdoptedOrderWhoseTransactionWasNeverMappedIsCaughtAsAnUnlinkedCharge(): void
    {
        $record = $this->subscriptionRecord();
        $index  = $this->completeIndex($record);

        // The renewal already exists in FluentCart with its charge, and the ID
        // map knows the order but not the transaction.
        $adopted = \FluentCart\App\Models\Order::query()->create([
            'type'       => 'renewal',
            'status'     => 'completed',
            'invoice_no' => 'WC-880501',
        ]);

        \FluentCart\App\Models\OrderTransaction::query()->create([
            'order_id'         => (int) $adopted->id,
            'order_type'       => 'order',
            'transaction_type' => 'charge',
            'status'           => 'succeeded',
            'total'            => 2900,
            'subscription_id'  => null,
        ]);

        $GLOBALS['_cartshift_test_id_map'][Constants::ENTITY_ORDER]['880501'] = (int) $adopted->id;

        $result = $this->runPipeline($record, $index);

        $this->assertFalse($result->reconciled);
        $this->assertSame([ReconciliationResult::REASON_HISTORY_COUNT_MISMATCH], $result->reasonCodes);

        // All three source-side numbers agree; only FluentCart's disagrees.
        $this->assertSame(2, $result->sourcePaymentCount);
        $this->assertSame(2, $result->includedPaidOrderCount);
        $this->assertSame(2, $result->correctedPaidCycleCount);
        $this->assertSame(1, $result->fluentCartCount, 'The adopted renewal charge was never linked.');

        // And the row is left alone rather than nudged to the number that would
        // make the report look finished.
        $this->assertNotSame(1, $this->stagedSubscription()->bill_count);
    }

    // ──────────────────────────────────────────────
    // The forbidden FluentCart machinery
    // ──────────────────────────────────────────────

    public function testNeitherSyncSubscriptionStatesNorGuessNextBillingDateIsEverCalled(): void
    {
        $this->reconcileFixture();
        $this->reconcileFixture(['source_payment_count' => 9]);

        $this->assertSame([], $GLOBALS['_cartshift_test_fc_sync_subscription_states'] ?? []);
        $this->assertSame([], $GLOBALS['_cartshift_test_fc_guessed_dates'] ?? []);
    }

    // ──────────────────────────────────────────────
    // Dates: the 360
    // ──────────────────────────────────────────────

    public function testATerminalRecordWithNoNextDateKeepsItsNullThroughReconciliation(): void
    {
        $result = $this->reconcileFixture([
            'status' => 'cancelled',
            'dates'  => [
                'start_utc'        => '2023-04-11 09:15:00',
                'trial_end_utc'    => null,
                'next_payment_utc' => null,
                'cancelled_utc'    => '2024-02-19 12:41:00',
                'end_utc'          => '2024-02-19 12:41:00',
            ],
        ]);

        $this->assertTrue($result->reconciled);
        $this->assertNull($result->nextBillingDate);
        $this->assertSame(0, $result->generatedDates);
        $this->assertNull($this->stagedSubscription()->next_billing_date);
        $this->assertSame('canceled', $this->stagedSubscription()->status);
    }

    public function testAnOnHoldRecordWithNoNextDateKeepsItsNullThroughReconciliation(): void
    {
        $result = $this->reconcileFixture([
            'status' => 'on-hold',
            'dates'  => [
                'start_utc'        => '2023-04-11 09:15:00',
                'trial_end_utc'    => null,
                'next_payment_utc' => null,
                'cancelled_utc'    => null,
                'end_utc'          => null,
            ],
        ]);

        $this->assertTrue($result->reconciled);
        $this->assertNull($this->stagedSubscription()->next_billing_date);
        $this->assertSame(0, $result->generatedDates);
    }

    /**
     * The headline of the whole task, at the population's own scale.
     *
     * The verified Lapka baseline holds 360 subscriptions with no next-payment
     * date. Reconciling all of them must produce 360 nulls, not 360 plausible
     * dates — and the assertion is on the destination rows, because a result
     * object reporting zero while the rows carry dates would be the failure
     * this exists to catch.
     */
    public function testTheAggregateOfThreeHundredAndSixtyEmptyDatesGeneratesNotOneDate(): void
    {
        $empty = (int) $this->shapes['aggregates']()['next_payment_dates']['missing'];

        $this->assertSame(360, $empty);

        $generated = 0;
        $written   = 0;

        for ($index = 0; $index < $empty; $index++) {
            $GLOBALS['_cartshift_test_fc_models'] = [];
            $GLOBALS['_cartshift_test_id_map']    = $this->baseIdMap();

            $result = $this->reconcileFixture([
                'source_subscription_id' => 920_000 + $index,
                'source_ref'             => 'subscription:' . (920_000 + $index),
                'status'                 => 'on-hold',
                'dates'                  => [
                    'start_utc'        => '2023-04-11 09:15:00',
                    'trial_end_utc'    => null,
                    'next_payment_utc' => null,
                    'cancelled_utc'    => null,
                    'end_utc'          => null,
                ],
            ]);

            $generated += $result->generatedDates;

            if ($this->stagedSubscription()->next_billing_date !== null) {
                $written++;
            }
        }

        $this->assertSame(0, $generated);
        $this->assertSame(0, $written, 'A migrated schedule nobody agreed to is a charge nobody agreed to.');
        $this->assertSame([], $GLOBALS['_cartshift_test_fc_guessed_dates'] ?? []);
    }

    // ──────────────────────────────────────────────
    // The two cycle-count corrections
    // ──────────────────────────────────────────────

    public function testBothCorrectionsAreZeroForALapkaShapeWithNoTrialAndNoSetupFee(): void
    {
        $result = $this->reconcileFixture();

        $this->assertSame(0, $result->billedCyclesOffset, 'The parent charged real money; nothing to translate.');
        $this->assertSame(0, $result->billedCyclesDeduction);
        $this->assertSame(
            [],
            $GLOBALS['_cartshift_test_fc_meta']['Subscription'][$this->stagedSubscription()->id] ?? [],
        );
    }

    /**
     * THE OFFSET, EARNED WITHOUT A WORD OF TRIAL METADATA.
     *
     * The metadata route — declared trial, zero setup fee, finite term — is
     * FluentCart's own and is unchanged. It also cannot see the Lapka case at
     * all: those 230 subscriptions declare no trial, carry no setup fee and run
     * open-ended, and their parent order still settled for 0.00. The subscriber
     * received a billing period and no money moved, which is a consumed free
     * cycle whatever the meta rows say, and the evidence route is what reads it.
     */
    public function testAZeroTotalPaidParentEarnsTheOffsetWithNoTrialMetadataAtAll(): void
    {
        $result = $this->reconcileConsumedFreeCycleFixture();

        $this->assertSame(1, $result->billedCyclesOffset);
        $this->assertSame(0, $result->billedCyclesDeduction);
        $this->assertSame(1, $result->includedPaidOrderCount, 'Only the renewal carried a charge.');
        $this->assertSame(2, $result->correctedPaidCycleCount, 'Two cycles were consumed.');
        $this->assertSame(2, $result->sourcePaymentCount);
        $this->assertTrue($result->reconciled);
        $this->assertSame(
            1,
            $GLOBALS['_cartshift_test_fc_meta']['Subscription'][$this->stagedSubscription()->id]['billed_cycles_offset'] ?? 0,
            'FluentCart recomputes bill_count from this meta, so it has to be written, not merely reported.',
        );
    }

    /**
     * THE BOUNDARY. Unpaid means no cycle was consumed, and an offset there
     * would invent a billing period that never happened. The mismatch is left
     * standing with its diagnostics, which is what section 10 step 7 is for.
     */
    public function testAZeroTotalParentThatWasNeverPaidEarnsNoOffset(): void
    {
        $result = $this->reconcileConsumedFreeCycleFixture(parentPaidAt: null);

        $this->assertSame(0, $result->billedCyclesOffset);
        $this->assertSame(1, $result->correctedPaidCycleCount, 'One charge, and no cycle to add to it.');
        $this->assertFalse($result->reconciled);
        $this->assertArrayNotHasKey(
            'billed_cycles_offset',
            $GLOBALS['_cartshift_test_fc_meta']['Subscription'][$this->stagedSubscription()->id] ?? [],
        );
    }

    /**
     * FluentCart's own rule, from `CheckoutProcessor::syncInitialCycleCounting()`:
     * a free first cycle consumes a cycle without producing a `total > 0`
     * transaction, so the offset makes the arithmetic right.
     */
    public function testAConsumedFreeFirstCycleOnAFiniteTermAddsTheOffset(): void
    {
        $result = $this->reconcileFreeTrialFixture();

        $this->assertSame(1, $result->billedCyclesOffset);
        $this->assertSame(0, $result->billedCyclesDeduction);
        $this->assertSame(
            1,
            $GLOBALS['_cartshift_test_fc_meta']['Subscription'][$this->stagedSubscription()->id]['billed_cycles_offset'] ?? 0,
        );
    }

    /**
     * And the corrected evidence is what the three-way comparison uses, or a
     * free trial cycle — a cycle with no charge behind it — would block every
     * trial subscriber for ever.
     */
    public function testTheOffsetIsAppliedToTheEvidenceSoATrialSubscriptionCanReconcile(): void
    {
        $result = $this->reconcileFreeTrialFixture();

        $this->assertSame(1, $result->includedPaidOrderCount, 'One order carried a charge.');
        $this->assertSame(2, $result->correctedPaidCycleCount, 'Two cycles were consumed.');
        $this->assertSame(2, $result->fluentCartCount);
        $this->assertSame(2, $result->sourcePaymentCount);
        $this->assertTrue($result->reconciled);
    }

    public function testASignupFeeOnlyChargeOnARealTrialAddsTheDeduction(): void
    {
        $result = $this->reconcileSignupFeeFixture();

        $this->assertSame(0, $result->billedCyclesOffset);
        $this->assertSame(1, $result->billedCyclesDeduction);
        $this->assertSame(2, $result->includedPaidOrderCount, 'The fee and the renewal both charged.');
        $this->assertSame(1, $result->correctedPaidCycleCount, 'Only the renewal was a cycle.');
        $this->assertSame(1, $result->fluentCartCount);
        $this->assertTrue($result->reconciled);
    }

    /**
     * A correction has to converge downward as well as upward.
     *
     * Writing only the positives is a one-way ratchet, and section 9.3
     * explicitly contemplates the case that trips it: an operator corrects the
     * source and re-exports. An offset written by an earlier run against bad
     * trial data would otherwise survive the correction for ever, and FluentCart
     * adds it to every `calculateBillCount()` from then on — a permanent extra
     * paid cycle, and on a finite plan a customer who stops being billed a month
     * early.
     */
    public function testACorrectionWrittenByAnEarlierRunIsClearedWhenItIsNoLongerEarned(): void
    {
        $record = $this->subscriptionRecord();
        $index  = $this->completeIndex($record);

        $first = $this->runPipeline($record, $index);

        $this->assertTrue($first->reconciled);

        $subscriptionId = $first->subscriptionId;
        $imported       = (new SubscriptionOrderImporter($this->idMap()))->import($record, $index);

        // An earlier run, against source data that has since been corrected.
        $GLOBALS['_cartshift_test_fc_meta']['Subscription'][$subscriptionId]['billed_cycles_offset'] = 1;

        $stale = (new SubscriptionReconciler($index, $this->idMap()))
            ->reconcile($record, $subscriptionId);

        $this->assertSame(3, $stale->fluentCartCount, 'The stale offset is still being added.');
        $this->assertFalse($stale->reconciled);

        (new SubscriptionHistoryLinker($this->idMap()))
            ->link($record, $index, $subscriptionId, $imported['orders']);

        $this->assertArrayNotHasKey(
            'billed_cycles_offset',
            $GLOBALS['_cartshift_test_fc_meta']['Subscription'][$subscriptionId] ?? [],
            'A correction the source no longer earns must be deleted, not skipped.',
        );

        $after = (new SubscriptionReconciler($index, $this->idMap()))
            ->reconcile($record, $subscriptionId);

        $this->assertSame(2, $after->fluentCartCount);
        $this->assertTrue($after->reconciled);
    }

    /**
     * The raw evidence is still reported beside the corrected number, because
     * an operator repairing a mismatch needs to know how many orders actually
     * came across — not just how many cycles CartShift thinks they represent.
     */
    public function testTheRawOrderEvidenceIsReportedAlongsideTheCorrectedCycleCount(): void
    {
        $result = $this->reconcileFixture();

        $this->assertSame(2, $result->includedPaidOrderCount);
        $this->assertSame(2, $result->correctedPaidCycleCount);
        $this->assertSame(0, $result->billedCyclesOffset);
        $this->assertSame(0, $result->billedCyclesDeduction);
    }

    /**
     * The corrections exist to make the arithmetic correct, never to make a
     * mismatch green. A record with no trial and no setup fee gets neither,
     * whatever the counts do.
     */
    public function testACorrectionIsNeverInventedToCloseAGap(): void
    {
        $result = $this->reconcileFixture(['source_payment_count' => 5]);

        $this->assertFalse($result->reconciled);
        $this->assertSame(0, $result->billedCyclesOffset);
        $this->assertSame(0, $result->billedCyclesDeduction);
    }

    // ──────────────────────────────────────────────
    // Refunded history
    // ──────────────────────────────────────────────

    /**
     * A refunded order that was paid still carries a succeeded charge — the
     * dataset factory emits one for any order with a paid date and a positive
     * total — so it contributes to FluentCart's recomputed count. Six of the
     * Lapka renewals are refunded. Whether WCS counted them is a question the
     * evidence answers out loud rather than one this code decides.
     */
    public function testARefundedButPaidRenewalContributesToTheRecomputedCount(): void
    {
        $record = $this->subscriptionRecord([
            'related_orders' => [
                ['source_order_id' => 880_001, 'relationship' => 'parent'],
                ['source_order_id' => 880_501, 'relationship' => 'renewal'],
                ['source_order_id' => 880_503, 'relationship' => 'renewal'],
            ],
            'source_payment_count' => 3,
        ]);

        $index = $this->completeIndex($record, [$this->refundedRenewal()]);

        $result = $this->runPipeline($record, $index);

        $this->assertSame(3, $result->fluentCartCount);
        $this->assertSame(3, $result->includedPaidOrderCount);
        $this->assertTrue($result->reconciled);
    }

    public function testWhenTheSourceDidNotCountTheRefundTheDisagreementIsSurfacedNotHidden(): void
    {
        $record = $this->subscriptionRecord([
            'related_orders' => [
                ['source_order_id' => 880_001, 'relationship' => 'parent'],
                ['source_order_id' => 880_501, 'relationship' => 'renewal'],
                ['source_order_id' => 880_503, 'relationship' => 'renewal'],
            ],
            // WCS did not count the refunded renewal as a payment.
            'source_payment_count' => 2,
        ]);

        $index = $this->completeIndex($record, [$this->refundedRenewal()]);

        $result = $this->runPipeline($record, $index);

        $this->assertFalse($result->reconciled);
        $this->assertSame([ReconciliationResult::REASON_HISTORY_COUNT_MISMATCH], $result->reasonCodes);
        $this->assertSame(2, $result->sourcePaymentCount);
        $this->assertSame(3, $result->fluentCartCount);

        // And the closure validator says the same thing about the same dataset,
        // rather than the two layers disagreeing about which number is real.
        $records = [
            $record,
            $this->orderRecord('parentOrderPayload'),
            $this->orderRecord('renewalOrderPayload'),
            $this->refundedRenewal(),
        ];

        $report = (new DatasetClosureValidator())->validate($this->manifestFor($records), $records);

        $this->assertContains('history_count_mismatch', $report->reasonCodes());
    }

    // ──────────────────────────────────────────────
    // Reconciliation is rerunnable
    // ──────────────────────────────────────────────

    public function testASecondReconciliationChangesNothingAndDuplicatesNothing(): void
    {
        $record = $this->subscriptionRecord();
        $index  = $this->completeIndex($record);

        $first = $this->runPipeline($record, $index);

        $transactions = count(\CartShiftFcModelStore::all('OrderTransaction'));
        $orders       = count(\CartShiftFcModelStore::all('Order'));
        $subscription = $this->stagedSubscription();

        $second = $this->runPipeline($record, $index);

        $this->assertSame($first->subscriptionId, $second->subscriptionId);
        $this->assertSame($first->fluentCartCount, $second->fluentCartCount);
        $this->assertTrue($second->reconciled);
        $this->assertCount($transactions, \CartShiftFcModelStore::all('OrderTransaction'));
        $this->assertCount($orders, \CartShiftFcModelStore::all('Order'));
        $this->assertCount(1, \CartShiftFcModelStore::all('Subscription'));
        $this->assertSame($subscription->id, $this->stagedSubscription()->id);
    }

    /**
     * Reconciling against a subscription that was never staged reports the
     * missing reference rather than counting against nothing — and, crucially,
     * writes no row of its own to reconcile against.
     */
    public function testReconcilingAnUnstagedSubscriptionReportsTheMissingReference(): void
    {
        $record = $this->subscriptionRecord();
        $index  = $this->completeIndex($record);

        $result = (new SubscriptionReconciler($index, $this->idMap()))->reconcile($record, 987_654);

        $this->assertFalse($result->reconciled);
        $this->assertSame([ReconciliationResult::REASON_SUBSCRIPTION_MISSING], $result->reasonCodes);
        $this->assertSame(0, $result->fluentCartCount);
        $this->assertSame([], \CartShiftFcModelStore::all('Subscription'));
    }

    /**
     * The receipt shape, because a reconciliation nobody can read is a
     * reconciliation nobody can act on.
     */
    public function testTheResultSerialisesEveryNumberTheOperatorNeeds(): void
    {
        $payload = $this->reconcileFixture(['source_payment_count' => 3])->toArray();

        $this->assertSame(3, $payload['source_payment_count']);
        $this->assertSame(2, $payload['included_paid_order_count']);
        $this->assertSame(2, $payload['corrected_paid_cycle_count']);
        $this->assertSame(2, $payload['fluent_cart_count']);
        $this->assertSame(0, $payload['generated_dates']);
        $this->assertSame(['history_count_mismatch'], $payload['reason_codes']);
        $this->assertSame([880_001, 880_501], $payload['related_order_ids']);
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    /**
     * @param array<string, mixed> $overrides
     */
    private function reconcileFixture(array $overrides = [], ?callable $afterStage = null): ReconciliationResult
    {
        $record = $this->subscriptionRecord($overrides);
        $index  = $this->completeIndex($record);

        return $this->runPipeline($record, $index, $afterStage);
    }

    private function reconcileFreeTrialFixture(): ReconciliationResult
    {
        $record = $this->subscriptionRecord([
            'contract' => [
                'period'           => 'month',
                'multiplier'       => 1,
                'recurring_amount' => 2900,
                'recurring_tax'    => 0,
                'recurring_total'  => 2900,
                'finite_cycles'    => 12,
                'trial_length'     => 14,
                'trial_period'     => 'day',
                'setup_fee'        => 0,
                'source_plan'      => ['finite_cycles_source' => 'declared'],
            ],
            'dates' => [
                'start_utc'        => '2023-04-11 09:15:00',
                'trial_end_utc'    => '2023-04-25 09:15:00',
                'next_payment_utc' => '2099-05-11 09:15:00',
                'cancelled_utc'    => null,
                'end_utc'          => null,
            ],
            // The free first cycle produced no charge; the one renewal did.
            'source_payment_count' => 2,
        ]);

        $index = SubscriptionHistoryIndex::fromRecords(self::SOURCE_KEY, [
            $record,
            $this->orderRecord('parentOrderPayload', [
                'status'       => 'completed',
                'transactions' => [],
                'totals'       => ['subtotal' => 0, 'tax' => 0, 'total' => 0, 'refunded' => 0],
                'dates'        => ['created_utc' => '2023-04-11 09:15:00', 'paid_utc' => '2023-04-11 09:15:00'],
            ]),
            $this->orderRecord('renewalOrderPayload'),
        ]);

        return $this->runPipeline($record, $index);
    }

    /**
     * The Lapka shape exactly — no trial, no setup fee, no finite term — whose
     * parent order settled for nothing. `$parentPaidAt` is the one variable:
     * null makes it a zero-total order that never settled, which earns no
     * correction.
     */
    private function reconcileConsumedFreeCycleFixture(
        ?string $parentPaidAt = '2023-04-11 09:15:00',
    ): ReconciliationResult {
        $record = $this->subscriptionRecord(['source_payment_count' => 2]);

        $index = SubscriptionHistoryIndex::fromRecords(self::SOURCE_KEY, [
            $record,
            $this->orderRecord('parentOrderPayload', [
                'status'       => $parentPaidAt === null ? 'pending' : 'completed',
                'transactions' => [],
                'totals'       => ['subtotal' => 0, 'tax' => 0, 'total' => 0, 'refunded' => 0],
                'dates'        => ['created_utc' => '2023-04-11 09:15:00', 'paid_utc' => $parentPaidAt],
            ]),
            $this->orderRecord('renewalOrderPayload'),
        ]);

        return $this->runPipeline($record, $index);
    }

    private function reconcileSignupFeeFixture(): ReconciliationResult
    {
        $record = $this->subscriptionRecord([
            'contract' => [
                'period'           => 'month',
                'multiplier'       => 1,
                'recurring_amount' => 2900,
                'recurring_tax'    => 0,
                'recurring_total'  => 2900,
                'finite_cycles'    => 12,
                'trial_length'     => 14,
                'trial_period'     => 'day',
                'setup_fee'        => 1000,
                'source_plan'      => ['finite_cycles_source' => 'declared'],
            ],
            'dates' => [
                'start_utc'        => '2023-04-11 09:15:00',
                'trial_end_utc'    => '2023-04-25 09:15:00',
                'next_payment_utc' => '2099-05-11 09:15:00',
                'cancelled_utc'    => null,
                'end_utc'          => null,
            ],
            'source_payment_count' => 1,
        ]);

        $index = SubscriptionHistoryIndex::fromRecords(self::SOURCE_KEY, [
            $record,
            // The parent order charged the signup fee and nothing else.
            $this->orderRecord('parentOrderPayload', [
                'transactions' => [[
                    'source_transaction_id' => 'txn-fixture-signup',
                    'type'                  => 'charge',
                    'status'                => 'succeeded',
                    'total'                 => 1000,
                    'currency'              => 'PLN',
                    'gateway'               => 'stripe',
                    'paid_at_utc'           => '2023-04-11 09:15:00',
                ]],
                'totals' => ['subtotal' => 1000, 'tax' => 0, 'total' => 1000, 'refunded' => 0],
            ]),
            $this->orderRecord('renewalOrderPayload'),
        ]);

        return $this->runPipeline($record, $index);
    }

    /**
     * @param list<object> $records
     */
    private function manifestFor(array $records): DatasetManifest
    {
        $counts = ['customer' => 0, 'product' => 0, 'order' => 0, 'subscription' => 0];

        foreach ($records as $record) {
            $counts[$record->kind()]++;
        }

        return DatasetManifest::fromArray([
            'schema_version'        => 1,
            'source_key'            => self::SOURCE_KEY,
            'storage_authority'     => 'cpt',
            'currencies'            => ['PLN'],
            'exported_at_utc'       => '2026-08-09 10:00:00',
            'versions'              => ['cartshift' => '1.4.1', 'woocommerce' => '11.0.0', 'wcs' => '8.7.1'],
            'selection_fingerprint' => (new SubscriptionSelection(self::SOURCE_KEY))->fingerprint(),
            'counts'                => $counts,
            'invalid_count'         => 0,
            'total_records'         => array_sum($counts),
            'records_checksum'      => str_repeat('a', 64),
        ]);
    }

    private function refundedRenewal(): OrderRecord
    {
        return $this->orderRecord('renewalOrderPayload', [
            'source_ref'      => 'order:880503',
            'source_order_id' => 880_503,
            'status'          => 'refunded',
            'transactions'    => [[
                'source_transaction_id' => 'txn-fixture-880503',
                'type'                  => 'charge',
                'status'                => 'succeeded',
                'total'                 => 2900,
                'currency'              => 'PLN',
                'gateway'               => 'stripe',
                'paid_at_utc'           => '2023-07-11 09:15:00',
            ]],
            'totals' => ['subtotal' => 2900, 'tax' => 0, 'total' => 2900, 'refunded' => 2900],
            'dates'  => ['created_utc' => '2023-07-11 09:15:00', 'paid_utc' => '2023-07-11 09:15:00'],
        ]);
    }

    /**
     * Section 6.2's import order, in the order it names: orders, then the
     * paused subscription, then the links, then reconciliation.
     */
    private function runPipeline(
        SubscriptionRecord $record,
        SubscriptionHistoryIndex $index,
        ?callable $afterStage = null,
    ): ReconciliationResult {
        $imported = (new SubscriptionOrderImporter($this->idMap()))->import($record, $index);

        $subscriptionId = (new SubscriptionWriter($this->idMap(), new SubscriptionMapper()))
            ->stage($record, $this->assessmentFor($record));

        if ($afterStage !== null) {
            $afterStage();
        }

        (new SubscriptionHistoryLinker($this->idMap()))
            ->link($record, $index, $subscriptionId, $imported['orders']);

        return (new SubscriptionReconciler($index, $this->idMap()))->reconcile($record, $subscriptionId);
    }

    private function stagedSubscription(): object
    {
        $rows = \CartShiftFcModelStore::all('Subscription');

        $this->assertNotSame([], $rows, 'Nothing was staged.');

        return $rows[count($rows) - 1];
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function baseIdMap(): array
    {
        return [
            Constants::ENTITY_CUSTOMER  => ['660001' => 501],
            Constants::ENTITY_PRODUCT   => [(string) CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID => 701],
            Constants::ENTITY_VARIATION => [(string) CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID => 801],
        ];
    }

    private function assessmentFor(SubscriptionRecord $record): SubscriptionAssessment
    {
        $item = $record->items[0];

        return new SubscriptionAssessment(
            SubscriptionAssessment::OUTCOME_READY,
            [],
            [],
            [
                'customer_id'     => 501,
                'parent_order_id' => 601,
                'product_id'      => 701,
                'variation_id'    => 801,
                'item_name'       => (string) $item['name'],
                'quantity'        => (int) $item['quantity'],
            ],
            new PaymentMigrationDecision(
                strategy: PaymentMigrationDecision::STRATEGY_MANUAL,
                outcome: PaymentMigrationDecision::OUTCOME_READY,
                collectionMethod: PaymentMigrationDecision::COLLECTION_MANUAL,
                currentPaymentMethod: '',
                nextActionOwner: PaymentMigrationDecision::OWNER_TARGET_MANUAL,
                vendorCustomerId: null,
                vendorPlanId: null,
                vendorSubscriptionId: null,
                activePaymentMethod: [],
                reasonCodes: [],
            ),
            (new SubscriptionLifecycleProjector())->project($record, null),
        );
    }
}

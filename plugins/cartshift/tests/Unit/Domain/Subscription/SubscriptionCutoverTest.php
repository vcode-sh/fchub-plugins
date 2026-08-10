<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Subscription;

use CartShift\Domain\Mapping\MappingSetValidator;
use CartShift\Domain\Mapping\ProductMapDecision;
use CartShift\Domain\Subscription\CutoverReceipt;
use CartShift\Domain\Subscription\DatasetManifest;
use CartShift\Domain\Subscription\SourceRenewalGuard;
use CartShift\Domain\Subscription\SubscriptionCutover;
use CartShift\Domain\Subscription\SubscriptionDatasetSource;
use CartShift\Domain\Subscription\SubscriptionRecordFactory;
use CartShift\Domain\Subscription\SubscriptionSelection;
use CartShift\Storage\ProductMapRepository;
use CartShift\Support\Constants;

require_once dirname(__DIR__, 3) . '/stubs/WcSourceReleaseDoubles.php';
require_once dirname(__DIR__, 3) . '/stubs/PostStatusStubs.php';

/**
 * The five staged commands, and the boundary between each pair of them.
 *
 * Every test here answers one question: if the operator's terminal died
 * immediately after this call, what is true? The acceptable answers are "the
 * source still bills and the destination is paused" and "the destination bills
 * and the source cannot". There is no third one, and the tests that look like
 * they are about reason codes are really about that.
 */
final class SubscriptionCutoverTest extends SubscriptionHistoryTestCase
{
    private string $workspace;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = realpath(sys_get_temp_dir()) . '/cartshift-cutover-' . bin2hex(random_bytes(6));
        mkdir($this->workspace, 0700, true);

        // Section 8.4's acceptance, expressed the way the plan requires: an
        // explicit configuration decision, not a default. Without it the Stripe
        // cohort is `confirmation_required` and nothing stages at all — which is
        // itself asserted below.
        $GLOBALS['_cartshift_test_filters']['cartshift/subscription/manual_fallback_confirmed'] = [
            static fn (): bool => true,
        ];

        $GLOBALS['_cartshift_test_fc_gateways'] = [
            'stripe' => \CartShiftFakeGateway::stripe(),
            'paypal' => \CartShiftFakeGateway::paypal(),
        ];

        // The destination variant sits on the destination product. Section 9.3
        // asks that question of the catalogue, and an unanswered one blocks.
        cartshift_test_own_variation(801, 701);

        // THE PRE-SEEDED PRODUCT AND VARIATION ROWS ARE TAKEN BACK OUT, AND
        // THAT REMOVAL IS THE POINT OF THIS WHOLE FILE.
        //
        // `SubscriptionHistoryTestCase` puts them in, which is right for the
        // units it was built for — `SubscriptionWriter`, `SubscriptionReconciler`
        // and `SubscriptionHistoryLinker` all take a resolved ID map as their
        // input and have no business creating one. It is wrong here. `stage()`
        // is the command that OWNS section 6.2's import order, and a test that
        // hands it the output of step 2 can never notice that step 2 does not
        // run: twelve reviews and a green suite of 1,962 tests missed a `stage`
        // that promoted nothing, and a rehearsal on 564 live subscriptions
        // blocked every single one.
        //
        // So from here the references arrive the way they arrive in production
        // — a `link` decision in the staging table, promoted by `stage` itself —
        // and the three catalogue facts promotion consults are stated below
        // rather than assumed.
        unset(
            $GLOBALS['_cartshift_test_id_map'][Constants::ENTITY_PRODUCT][(string) CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID],
            $GLOBALS['_cartshift_test_id_map'][Constants::ENTITY_VARIATION][(string) CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID],
        );

        // The link target is a live FluentCart product, so `fcProductStillExists()`
        // says yes and the decision is not reported `dead`.
        $GLOBALS['_cartshift_test_posts'][701] = [
            'status' => 'publish',
            'type'   => Constants::FC_PRODUCT_POST_TYPE,
        ];

        // And 801 is one of its variants, so promotion's membership check does
        // not report the mapped variant `foreign`. Answered from the same
        // registry `cartshift_test_own_variation()` writes, so a test cannot
        // state the two facts inconsistently.
        $GLOBALS['_cartshift_test_get_col_callback'] = static function (string $query): array {
            if (preg_match('/fct_product_variations WHERE post_id = (\d+)/', $query, $matches) !== 1) {
                return [];
            }

            $owners = (array) ($GLOBALS['_cartshift_test_fc_variation_owner'] ?? []);

            return array_values(array_keys($owners, (int) $matches[1], true));
        };
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ((array) glob($this->workspace . '/*') as $file) {
            @unlink((string) $file);
        }

        @rmdir($this->workspace);

        parent::tearDown();
    }

    // ──────────────────────────────────────────────
    // Stage
    // ──────────────────────────────────────────────

    public function testStagingWritesTheReceiptAndPausesTheDestination(): void
    {
        $result = $this->stage();

        $this->assertTrue($result['ok'], json_encode($result['failures']));
        $this->assertSame(CutoverReceipt::STATE_STAGED, $result['state']);

        $entry = $result['receipt']->entryFor('subscription:910001');

        $this->assertNotNull($entry);
        $this->assertSame(CutoverReceipt::STATE_STAGED, $entry['state']);
        $this->assertSame('paused', $entry['staged_status']);
        $this->assertSame('active', $entry['intended_status']);
        $this->assertIsInt($entry['target_subscription_id']);
        $this->assertGreaterThan(0, $entry['target_subscription_id']);

        $this->assertSame('paused', $this->stagedSubscription()->status);
    }

    /**
     * The cutover path owns writer + history + reconciliation as one record.
     * A linker failure after the writer's inner commit must therefore roll the
     * subscription back instead of leaving a row with half-attached history.
     */
    public function testAHistoryLinkFailureRollsBackTheWholeStagedSubscription(): void
    {
        $GLOBALS['_cartshift_test_fc_before_save'] = static function (string $class, object $row): void {
            if ((string) ($row->transaction_type ?? '') === 'charge'
                && (int) ($row->subscription_id ?? 0) > 0
            ) {
                throw new \RuntimeException('The transaction link failed.');
            }
        };

        $result = $this->stage();

        $this->assertTrue($result['ok'], json_encode($result['failures']));
        $entry = $result['receipt']->entryFor('subscription:910001');

        $this->assertSame(CutoverReceipt::OUTCOME_BLOCKED, $entry['outcome']);
        $this->assertSame(CutoverReceipt::STATE_ASSESSED, $entry['state']);
        $this->assertSame([SubscriptionCutover::REASON_DATABASE_WRITE_FAILED], $entry['reason_codes']);
        $this->assertSame(
            ['START TRANSACTION', 'ROLLBACK'],
            $this->transactionStatements(),
            'The writer commit must stay nested inside the cutover record boundary.',
        );
        $this->assertSame(0, \CartShift\Support\DatabaseTransaction::depth());
    }

    /**
     * The receipt exists before the first destination row does. An interruption
     * between the two leaves an `assessed` receipt and a source that still
     * bills, which the next run picks up and completes.
     *
     * Observed from inside `SubscriptionWriter`'s own mapper filter, which runs
     * after the assessment and before `Subscription::save()` — the last moment
     * at which no destination subscription exists.
     */
    public function testTheReceiptIsWrittenAssessedBeforeAnyDestinationRowIsCreated(): void
    {
        $seen = null;

        $GLOBALS['_cartshift_test_filters']['cartshift/mapper/subscription'] = [
            function (array $attributes) use (&$seen): array {
                $seen ??= CutoverReceipt::read($this->receiptPath())['receipt']?->state;

                return $attributes;
            },
        ];

        $this->stage();

        $this->assertSame(CutoverReceipt::STATE_ASSESSED, $seen);
    }

    public function testStagingIsIdempotent(): void
    {
        $first = $this->stage();
        $second = $this->stage();

        $this->assertTrue($second['ok'], json_encode($second['failures']));
        $this->assertSame(
            $first['receipt']->entryFor('subscription:910001')['target_subscription_id'],
            $second['receipt']->entryFor('subscription:910001')['target_subscription_id'],
        );
        $this->assertCount(1, \CartShiftFcModelStore::all('Subscription'));
    }

    // ──────────────────────────────────────────────
    // Stage promotes the operator's mapping decisions
    // ──────────────────────────────────────────────

    /**
     * THE DEFECT, STATED DIRECTLY: a decision saved, nothing promoted, now stage.
     *
     * The cross-runtime operator flow is export → prepare-package → map →
     * stage, and only the same-site route ever promoted — `MigrationOrchestrator
     * Factory::forRun()` at run start, and `ProductMigrator` for everything it
     * creates. Neither runs here. So the mapping decision sat correct and
     * complete in `cartshift_product_map`, `SubscriptionAssessor::resolve()`
     * asked the ID map for the product it names, the ID map had never been told,
     * and all 564 Lapka subscriptions blocked on `required_reference_missing`.
     *
     * The assertions are about the ID map rather than about the receipt on
     * purpose. A test that only checked "it staged" would pass again the moment
     * anybody made the assessor improvise a reference, which is the one repair
     * this defect must never receive.
     */
    public function testStagingPromotesTheOperatorsMappingDecisionsIntoTheIdMap(): void
    {
        $this->assertNull(
            $this->idMapped(Constants::ENTITY_PRODUCT, (string) CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID),
            'The product reference must not exist before stage runs, or this test proves nothing.',
        );

        $result = $this->stage();

        $this->assertTrue($result['ok'], json_encode($result['failures']));
        $this->assertSame(
            701,
            $this->idMapped(Constants::ENTITY_PRODUCT, (string) CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID),
        );
        $this->assertSame(
            801,
            $this->idMapped(Constants::ENTITY_VARIATION, (string) CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID),
        );

        $this->assertSame(1, $result['summary']['mapped_products']);
        $this->assertSame(1, $result['summary']['mapped_variants']);
    }

    /**
     * And the reference has to be there BEFORE the order importer reads it.
     *
     * `SubscriptionOrderImporter::createItems()` resolves `post_id` and
     * `object_id` through the same ID map and writes 0 when there is no answer,
     * so promotion running one step later than section 6.2 says would produce a
     * cohort that stages perfectly and leaves every order line pointing at
     * nothing — which is exactly what the live rehearsal did to 5,277 of them.
     */
    public function testPromotedReferencesReachTheOrderLineItems(): void
    {
        $this->stage();

        $items = \CartShiftFcModelStore::all('OrderItem');

        $this->assertNotSame([], $items);

        foreach ($items as $item) {
            $this->assertSame(701, (int) $item->post_id);
            $this->assertSame(801, (int) $item->object_id);
        }
    }

    /**
     * An order imported before the mapping was promoted is repaired, not left.
     *
     * The order is in the ID map, so the next run ADOPTS it and never reaches
     * `createItems()` again: without a repair the dangling lines survive every
     * re-run and only a full reset clears them.
     *
     * THE DECISION SET IS IDENTICAL ACROSS BOTH RUNS, and it has to be. The
     * receipt fingerprints the staging table, so a test that added the decision
     * between the two runs would be refused `receipt_transition_invalid` and
     * would prove nothing about the repair. What differs is whether promotion
     * could act on the decision — the link target is absent on the first run and
     * present on the second, which is a stale mapping the operator then fixed,
     * and reaches the importer in precisely the state the live rehearsal did.
     */
    public function testAdoptedOrdersHaveTheirDanglingItemReferencesRepaired(): void
    {
        $posts = $GLOBALS['_cartshift_test_posts'][701];
        unset($GLOBALS['_cartshift_test_posts'][701]);

        $this->stage();

        $before = \CartShiftFcModelStore::all('OrderItem');

        $this->assertNotSame([], $before);

        foreach ($before as $item) {
            $this->assertSame(0, (int) $item->post_id, 'The unpromoted run must leave the reference unresolved.');
        }

        $orders = count(\CartShiftFcModelStore::all('Order'));

        $GLOBALS['_cartshift_test_posts'][701] = $posts;

        $this->stage();

        $this->assertCount($orders, \CartShiftFcModelStore::all('Order'), 'The repair must not duplicate orders.');
        $this->assertCount(count($before), \CartShiftFcModelStore::all('OrderItem'));

        foreach (\CartShiftFcModelStore::all('OrderItem') as $item) {
            $this->assertSame(701, (int) $item->post_id);
            $this->assertSame(801, (int) $item->object_id);
        }
    }

    /**
     * A line that already carries a reference is not overwritten.
     *
     * The repair's warrant is "the map now has an answer where nothing had one",
     * not "the map is more right than you" — an owner who corrected a line by
     * hand, or FluentCart's own importer, keeps what they wrote.
     */
    public function testTheRepairLeavesLineItemsThatAlreadyCarryAReference(): void
    {
        $posts = $GLOBALS['_cartshift_test_posts'][701];
        unset($GLOBALS['_cartshift_test_posts'][701]);

        $this->stage();

        foreach (\CartShiftFcModelStore::all('OrderItem') as $item) {
            $item->post_id = 4242;
            $item->save();
        }

        $GLOBALS['_cartshift_test_posts'][701] = $posts;

        $this->stage();

        foreach (\CartShiftFcModelStore::all('OrderItem') as $item) {
            $this->assertSame(4242, (int) $item->post_id, 'A reference somebody else wrote must survive.');
            $this->assertSame(801, (int) $item->object_id, 'The genuinely empty one is still filled.');
        }
    }

    /**
     * Promotion does not make the gate softer, and this is the test that says so.
     *
     * A decision whose FluentCart target was deleted between mapping and running
     * promotes NOTHING for that product — `MappingPromoter` reports it `dead` —
     * and the subscription blocks under `required_reference_missing`, named. The
     * whole point of task 8 is that a missing reference stops a subscription
     * rather than being improvised around, and a fix for the promotion gap that
     * quietly relaxed that would be worse than the gap.
     */
    public function testADecisionPointingAtADeletedTargetStillBlocksTheSubscription(): void
    {
        unset($GLOBALS['_cartshift_test_posts'][701]);

        $result = $this->stage();

        $entry = $result['receipt']->entryFor('subscription:910001');

        $this->assertSame(CutoverReceipt::OUTCOME_BLOCKED, $entry['outcome']);
        $this->assertContains('required_reference_missing', $entry['reason_codes']);

        // Reported by name, so the operator is not left reading 564 identical
        // reason codes with nothing saying which decision caused them.
        $this->assertSame([701], $result['summary']['mapping_dead_targets']);
        $this->assertSame(0, $result['summary']['mapped_products']);
        $this->assertSame([], \CartShiftFcModelStore::all('Subscription'));
    }

    /**
     * A mapped variant that belongs to some other product is refused, and the
     * subscription blocks rather than billing against somebody else's line.
     */
    public function testAVariantOnAnotherProductIsNotPromoted(): void
    {
        cartshift_test_own_variation(801, 999);

        $result = $this->stage();

        $this->assertNull(
            $this->idMapped(Constants::ENTITY_VARIATION, (string) CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID),
        );
        $this->assertSame(
            [CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID],
            $result['summary']['mapping_foreign_variants'],
        );
        $this->assertSame(
            CutoverReceipt::OUTCOME_BLOCKED,
            $result['receipt']->entryFor('subscription:910001')['outcome'],
        );
    }

    /**
     * Promotion is idempotent across runs, and so is everything it feeds.
     *
     * A second `stage` on a target that already holds this cohort must add no
     * customer, no order and no subscription — the live rehearsal reaches this
     * with 5,277 orders and 214 customers already written, and a second run
     * that duplicated any of them would be a worse outcome than the block it
     * was run to clear.
     */
    public function testASecondStageRePromotesNothingAndDuplicatesNothing(): void
    {
        $first = $this->stage();

        $orders    = count(\CartShiftFcModelStore::all('Order'));
        $items     = count(\CartShiftFcModelStore::all('OrderItem'));
        $customers = count(\CartShiftFcModelStore::all('Customer'));

        $this->assertSame(1, $first['summary']['mapped_products']);

        $second = $this->stage();

        $this->assertTrue($second['ok'], json_encode($second['failures']));

        // Zero, not one: the product row is promotion's own completion marker,
        // so a decision already promoted is skipped rather than rewritten.
        $this->assertSame(0, $second['summary']['mapped_products']);
        $this->assertSame(0, $second['summary']['mapped_variants']);

        $this->assertCount($orders, \CartShiftFcModelStore::all('Order'));
        $this->assertCount($items, \CartShiftFcModelStore::all('OrderItem'));
        $this->assertCount($customers, \CartShiftFcModelStore::all('Customer'));
        $this->assertCount(1, \CartShiftFcModelStore::all('Subscription'));
    }

    /**
     * Obligation: the audit screen publishes `mapping.shared_target_variations`
     * and nothing consumed it. A global collision is refused, and refused before
     * anything is written.
     */
    public function testAGlobalTargetVariationCollisionRefusesTheWholeStage(): void
    {
        $result = $this->stage(['product_map' => $this->collidingDecisions()]);

        $this->assertFalse($result['ok']);
        $this->assertContains(MappingSetValidator::ERROR_COLLISION, $this->codes($result['failures']));
        $this->assertSame([], \CartShiftFcModelStore::all('Subscription'));
        $this->assertFalse(file_exists($this->receiptPath()));
    }

    /**
     * Obligation: `target.approval_fingerprint` is published with `approved:
     * false` and nothing bound it. A cohort with a system-capable candidate
     * needs the exact hash.
     */
    public function testASystemCapableCohortNeedsTheExactApprovedSettingsFingerprint(): void
    {
        $this->assertFalse(
            $this->stage(['approve_system_settings' => str_repeat('f', 64), 'system' => true])['ok'],
        );

        $this->assertSame([], \CartShiftFcModelStore::all('Subscription'));
    }

    public function testAMalformedApprovalHashIsRefusedBeforeAnythingIsRead(): void
    {
        $result = $this->stage(['approve_system_settings' => 'yes-please']);

        $this->assertFalse($result['ok']);
        $this->assertContains(
            CutoverReceipt::REASON_SETTINGS_NOT_APPROVED,
            $this->codes($result['failures']),
        );
        $this->assertSame([], \CartShiftFcModelStore::all('Subscription'));
    }

    public function testTheApprovalIsStoredInTheReceiptExactly(): void
    {
        $fingerprint = $this->targetFingerprint();

        $result = $this->stage(['approve_system_settings' => $fingerprint]);

        $this->assertTrue($result['ok'], json_encode($result['failures']));
        $this->assertSame($fingerprint, $result['receipt']->approvedSettingsFingerprint);
    }

    /**
     * Obligation: `CustomerResolver` had no caller. Staging is where creating a
     * customer is legitimate, and the ID map row it writes is what the assessor
     * then resolves.
     */
    public function testStagingResolvesTheCustomerThroughSectionNinePointOne(): void
    {
        // No pre-seeded mapping: the resolver has to find or make one.
        unset($GLOBALS['_cartshift_test_id_map'][Constants::ENTITY_CUSTOMER]['660001']);

        $this->installIdentityLookup(['subscriber-660001@example.invalid' => [9001]], []);

        $result = $this->stage();

        $this->assertTrue($result['ok'], json_encode($result['failures']));
        $this->assertSame(9001, $result['receipt']->entryFor('subscription:910001')['target_customer_id']);
        $this->assertSame(
            9001,
            $this->idMap()->getFcId(Constants::ENTITY_CUSTOMER, '660001'),
        );
    }

    public function testABlankCustomerEmailBlocksTheRecordRatherThanTheCohort(): void
    {
        unset($GLOBALS['_cartshift_test_id_map'][Constants::ENTITY_CUSTOMER]['660001']);

        $this->installIdentityLookup([], []);

        $result = $this->stage(['subscription' => ['billing_email' => '']]);

        $entry = $result['receipt']?->entryFor('subscription:910001');

        $this->assertNotNull($entry);
        $this->assertSame(CutoverReceipt::OUTCOME_BLOCKED, $entry['outcome']);
        $this->assertSame([], \CartShiftFcModelStore::all('Subscription'));
    }

    /**
     * Obligation: nothing injected a `SubscriptionHistoryIndex` for the
     * subscription path, so history linking and reconciliation never ran
     * outside unit tests. Staging owns section 6.2's import order.
     */
    public function testStagingImportsLinksAndReconcilesTheHistory(): void
    {
        $result = $this->stage();

        $entry = $result['receipt']->entryFor('subscription:910001');

        $this->assertSame(2, $entry['history']['related_orders']);
        $this->assertSame(2, $entry['history']['paid_orders']);
        $this->assertGreaterThan(0, $entry['history']['linked_transactions']);
        $this->assertTrue($entry['history']['reconciled']);
        $this->assertSame(
            $entry['target_parent_order_id'],
            $this->idMap()->getFcId(Constants::ENTITY_ORDER, '880001'),
        );
    }

    public function testWithoutTheManualFallbackAcceptanceNothingStages(): void
    {
        unset($GLOBALS['_cartshift_test_filters']['cartshift/subscription/manual_fallback_confirmed']);

        $result = $this->stage();

        $entry = $result['receipt']?->entryFor('subscription:910001');

        $this->assertNotNull($entry);
        $this->assertNotSame(CutoverReceipt::STATE_STAGED, $entry['state']);
        $this->assertSame([], \CartShiftFcModelStore::all('Subscription'));
    }

    public function testTheStageOptionExplicitlyAcceptsManualFallback(): void
    {
        unset($GLOBALS['_cartshift_test_filters']['cartshift/subscription/manual_fallback_confirmed']);

        $result = $this->stage(['accept_manual_fallback' => true]);
        $entry  = $result['receipt']?->entryFor('subscription:910001');

        $this->assertTrue($result['ok'], json_encode($result['failures']));
        $this->assertSame(CutoverReceipt::STATE_STAGED, $entry['state']);
        $this->assertSame('manual', $entry['collection_method']);
    }

    // ──────────────────────────────────────────────
    // cutover-source
    // ──────────────────────────────────────────────

    public function testCutoverSourceRefusesWithoutTheRenewalMaintenanceAcknowledgement(): void
    {
        $this->stage();

        $subscription = $this->sourceDouble();

        $result = $this->cutover($subscription, renewalsPaused: false);

        $this->assertFalse($result['ok']);
        $this->assertSame(
            [SourceRenewalGuard::REASON_MAINTENANCE_UNCONFIRMED],
            $this->codes($result['failures']),
        );

        // Before any mutation. Not one call reached the source.
        $this->assertSame([], $subscription->calls);
    }

    public function testCutoverSourceRecordsTheAcknowledgementAndItsTimestamp(): void
    {
        $this->stage();

        $result = $this->cutover($this->sourceDouble());

        $this->assertTrue($result['ok'], json_encode($result['failures']));
        $this->assertTrue($result['receipt']->renewalMaintenance['acknowledged']);
        $this->assertNotNull($result['receipt']->renewalMaintenance['acknowledged_at_utc']);
    }

    public function testCutoverSourceReleasesAndRecordsThePreviousFlagAndBothFingerprints(): void
    {
        $this->stage();

        $result = $this->cutover($this->sourceDouble());

        $entry = $result['receipt']->entryFor('subscription:910001');

        $this->assertSame(CutoverReceipt::STATE_SOURCE_RELEASED, $result['state']);
        $this->assertSame(CutoverReceipt::RELEASE_RELEASED, $entry['source_release']['state']);
        $this->assertFalse($entry['source_release']['previous_requires_manual_renewal']);
        $this->assertNotSame('', $entry['source_release']['pre_fingerprint']);
        $this->assertSame(
            $entry['source_release']['pre_fingerprint'],
            $entry['source_release']['post_fingerprint'],
        );
    }

    public function testCutoverSourceStopsOnAnOpenRenewalOrderWithoutTouchingTheFlag(): void
    {
        $this->stage();

        $subscription = $this->sourceDouble([
            'renewal' => [\CartShiftSourceOrderDouble::openWithGateway(880_502)],
        ]);

        $result = $this->cutover($subscription);

        $this->assertFalse($result['ok']);
        $this->assertContains(
            SourceRenewalGuard::REASON_OPEN_RENEWAL_GATEWAY,
            $this->codes($result['failures']),
        );
        $this->assertFalse($subscription->is_manual());

        // The receipt does not advance, so activation stays impossible.
        $this->assertSame(CutoverReceipt::STATE_STAGED, $result['state']);
    }

    /**
     * A stopped release is persisted, not merely returned.
     *
     * The receipt on disk is the only thing that survives the process, and a
     * cohort that stopped halfway with a receipt that still describes the state
     * before it started is the one situation an operator cannot act on: it
     * describes neither what is true nor what to do next.
     */
    public function testAStoppedReleaseIsRecordedOnDiskRatherThanOnlyReturned(): void
    {
        $this->stage();

        $this->cutover($this->sourceDouble([
            'renewal' => [\CartShiftSourceOrderDouble::openWithGateway(880_502)],
        ]));

        $receipt = CutoverReceipt::read($this->receiptPath())['receipt'];

        $this->assertNotNull($receipt);
        $this->assertTrue($receipt->renewalMaintenance['acknowledged']);
        $this->assertContains(
            SourceRenewalGuard::REASON_OPEN_RENEWAL_GATEWAY,
            $receipt->entryFor('subscription:910001')['source_release']['reason_codes'],
        );

        // And the receipt has NOT advanced, so activation is still impossible.
        $this->assertSame(CutoverReceipt::STATE_STAGED, $receipt->state);
    }

    /**
     * The released state and the previous flag reach the file as soon as the
     * source is actually changed, not at the end of the cohort.
     */
    public function testTheReleasedFlagIsDurableBeforeTheCommandFinishes(): void
    {
        $this->stage();
        $this->cutover($this->sourceDouble());

        $release = CutoverReceipt::read($this->receiptPath())['receipt']
            ?->entryFor('subscription:910001')['source_release'];

        $this->assertSame(CutoverReceipt::RELEASE_RELEASED, $release['state']);
        $this->assertFalse($release['previous_requires_manual_renewal']);
        $this->assertNotNull($release['released_at_utc']);
    }

    public function testCutoverSourceStopsWhenTheSourceRecordItselfMoved(): void
    {
        $this->stage();

        $result = $this->cutover($this->sourceDouble(), fingerprint: 'a-different-source-record');

        $this->assertFalse($result['ok']);
        $this->assertContains(
            CutoverReceipt::REASON_SOURCE_FINGERPRINT_CHANGED,
            $this->codes($result['failures']),
        );
        $this->assertSame(CutoverReceipt::STATE_STAGED, $result['state']);
    }

    public function testCutoverSourceIsRefusedOnAReceiptThatWasNeverStaged(): void
    {
        $receipt = CutoverReceipt::begin('lapka', 'checksum', 'selection', 'mapping', 'settings');
        $receipt->write($this->receiptPath());

        $result = $this->cutover($this->sourceDouble());

        $this->assertFalse($result['ok']);
        $this->assertContains(CutoverReceipt::REASON_TRANSITION_INVALID, $this->codes($result['failures']));
    }

    public function testCutoverSourceIsIdempotent(): void
    {
        $this->stage();

        $subscription = $this->sourceDouble();

        $this->cutover($subscription);
        $second = $this->cutover($subscription);

        $this->assertTrue($second['ok'], json_encode($second['failures']));
        $this->assertSame(CutoverReceipt::STATE_SOURCE_RELEASED, $second['state']);
    }

    public function testANarrowedSelectionSurvivesTheEntireCutover(): void
    {
        $selection = new SubscriptionSelection(self::SOURCE_KEY, [], [], [910_116]);

        $staged = $this->stage(['selection' => $selection]);
        $this->assertTrue($staged['ok'], json_encode($staged['failures']));
        $this->assertSame($selection->fingerprint(), $staged['receipt']->selection()->fingerprint());

        $this->assertTrue($this->cutover($this->sourceDouble())['ok']);
        $this->assertTrue($this->activate()['ok']);
        $this->assertTrue($this->reconcile()['ok']);

        $receipt = CutoverReceipt::read($this->receiptPath())['receipt'];
        $this->assertSame($selection->fingerprint(), $receipt?->selection()->fingerprint());
    }

    // ──────────────────────────────────────────────
    // activate
    // ──────────────────────────────────────────────

    public function testActivationIsRefusedWhileTheSourceStillOwnsBilling(): void
    {
        $this->stage();

        $result = $this->activate();

        $this->assertFalse($result['ok']);
        $this->assertContains(CutoverReceipt::REASON_TRANSITION_INVALID, $this->codes($result['failures']));

        $this->assertSame('paused', $this->stagedSubscription()->status);
    }

    public function testActivationSetsTheIntendedStatusOnceTheSourceIsReleased(): void
    {
        $this->stage();
        $this->cutover($this->sourceDouble());

        $result = $this->activate();

        $this->assertTrue($result['ok'], json_encode($result['failures']));
        $this->assertSame(CutoverReceipt::STATE_ACTIVATED, $result['state']);
        $this->assertSame('active', $this->stagedSubscription()->status);
    }

    public function testActivationIsRefusedWhenTheTargetSettingsMoved(): void
    {
        $this->stage();
        $this->cutover($this->sourceDouble());

        // The owner changed a store-wide subscription setting between the
        // release and the activation. CartShift never changes one back.
        $GLOBALS['_cartshift_test_options']['fluent_cart_store_settings'] = [
            'subscription_management_mode' => 'store_managed',
            'subscription_system_charge'   => 'yes',
        ];

        $result = $this->activate();

        $this->assertFalse($result['ok']);
        $this->assertContains(
            CutoverReceipt::REASON_SETTINGS_NOT_APPROVED,
            $this->codes($result['failures']),
        );
        $this->assertSame('paused', $this->stagedSubscription()->status);
    }

    // ──────────────────────────────────────────────
    // reconcile
    // ──────────────────────────────────────────────

    public function testReconcileComparesTheCountsAndClosesTheReceipt(): void
    {
        $this->stage();
        $this->cutover($this->sourceDouble());
        $this->activate();

        $result = $this->reconcile();

        $this->assertTrue($result['ok'], json_encode($result['failures']));
        $this->assertSame(CutoverReceipt::STATE_RECONCILED, $result['state']);
        $this->assertSame(1, $result['summary']['activated']);
        $this->assertSame(0, $result['summary']['blocked']);
    }

    public function testReconcileIsRefusedBeforeActivation(): void
    {
        $this->stage();

        $result = $this->reconcile();

        $this->assertFalse($result['ok']);
        $this->assertContains(CutoverReceipt::REASON_TRANSITION_INVALID, $this->codes($result['failures']));
    }

    // ──────────────────────────────────────────────
    // restore-source
    // ──────────────────────────────────────────────

    public function testRestorationPutsTheSourceFlagBackWhenNothingMoved(): void
    {
        $this->stage();

        $subscription = $this->sourceDouble();

        $this->cutover($subscription);

        $result = $this->restore($subscription);

        $this->assertTrue($result['ok'], json_encode($result['failures']));
        $this->assertSame(CutoverReceipt::STATE_SOURCE_RESTORED, $result['state']);
        $this->assertFalse($subscription->is_manual());
    }

    public function testRestorationIsRefusedOnceATargetRecordIsActivated(): void
    {
        $this->stage();

        $subscription = $this->sourceDouble();

        $this->cutover($subscription);
        $this->activate();

        $result = $this->restore($subscription);

        $this->assertFalse($result['ok']);
        $this->assertContains(CutoverReceipt::REASON_TRANSITION_INVALID, $this->codes($result['failures']));
        $this->assertTrue($subscription->is_manual(), 'The source must stay manual while the target bills.');
    }

    public function testRestorationIsRefusedWhenAPendingInvoiceAppearedAfterRelease(): void
    {
        $this->stage();

        $subscription = $this->sourceDouble();

        $this->cutover($subscription);

        $subscription->addRenewalOrder(\CartShiftSourceOrderDouble::openWithoutGateway(880_777));

        $result = $this->restore($subscription);

        $this->assertFalse($result['ok']);
        $this->assertContains(
            CutoverReceipt::REASON_SOURCE_FINGERPRINT_CHANGED,
            $this->codes($result['failures']),
        );
        $this->assertTrue($subscription->is_manual());
    }

    // ──────────────────────────────────────────────
    // The closure gate, which was not on the write path
    // ──────────────────────────────────────────────

    /**
     * Two subscriptions on one parent order. Both assess `ready` — the importer
     * adopts the already-mapped FluentCart order for the second — so no
     * per-record gate can see it, and two `fct_subscriptions` rows used to land
     * against one `parent_order_id`. FluentCart's renewal service assumes that
     * never happens.
     */
    public function testStagingRefusesTwoSubscriptionsThatShareAParentOrder(): void
    {
        $result = $this->stage(['cohort' => [[], [
            'parent_order_id' => 880_001,
            'related_orders'  => [
                ['source_order_id' => 880_001, 'relationship' => 'parent'],
                ['source_order_id' => 880_502, 'relationship' => 'renewal'],
            ],
        ]]]);

        $this->assertFalse($result['ok']);
        $this->assertContains(
            \CartShift\Domain\Subscription\ClosureReport::CODE_SHARED_PARENT_ORDER,
            $this->codes($result['failures']),
        );
        $this->assertStringContainsString('one subscription per parent order', $result['failures'][0]['message']);
        $this->assertSame([], \CartShiftFcModelStore::all('Subscription'), 'Nothing may be written.');
    }

    /**
     * One reference, two different payloads. Last write wins and the target
     * silently gets whichever line was read first.
     */
    public function testStagingRefusesADatasetThatCarriesOneReferenceTwice(): void
    {
        $result = $this->stage(['extra_records' => [
            $this->orderRecord('parentOrderPayload', [
                'source_ref'      => 'order:880001',
                'source_order_id' => 880_001,
                'status'          => 'refunded',
            ]),
        ]]);

        $this->assertFalse($result['ok']);
        $this->assertContains(
            \CartShift\Domain\Subscription\ClosureReport::CODE_DUPLICATE_REFERENCE,
            $this->codes($result['failures']),
        );
        $this->assertSame([], \CartShiftFcModelStore::all('Subscription'));
    }

    /**
     * And the half the gate must NOT take: a malformed subscription blocks its
     * own entry and the rest of the cohort migrates. Section 6.2 forces the
     * affected ENTITY to blocked, not the package — the reference dataset is 564
     * records with one bad one and is expected to move 563.
     */
    public function testAMalformedRecordBlocksItsOwnEntryAndNotTheCohort(): void
    {
        $result = $this->stage(['extra_records' => [
            new \CartShift\Domain\Subscription\InvalidSourceRecord(
                self::SOURCE_KEY,
                \CartShift\Domain\Subscription\SubscriptionRecord::KIND,
                'subscription:919999',
                [SubscriptionRecordFactory::REASON_REQUIRED_REFERENCE_MISSING],
                ['source_subscription_id' => 919_999],
                str_repeat('a', 64),
            ),
        ]]);

        $this->assertTrue($result['ok'], json_encode($result['failures']));
        $this->assertSame(
            CutoverReceipt::OUTCOME_BLOCKED,
            $result['receipt']->entryFor('subscription:919999')['outcome'],
        );
        $this->assertSame(CutoverReceipt::STATE_STAGED, $result['receipt']->entryFor('subscription:910001')['state']);
    }

    // ──────────────────────────────────────────────
    // Resuming a release that mutated the source and then blocked
    // ──────────────────────────────────────────────

    /**
     * The highest-stakes branch in the design: `SourceRenewalGuard::release()`
     * reached `save()` and then found the source had moved. The source is manual,
     * the receipt holds the only copy of what it was before, and the operator has
     * to be able to finish.
     *
     * The record fingerprint cannot be the comparator on that path.
     * `SubscriptionRecord::fingerprintPayload()` includes
     * `requires_manual_renewal`, so re-deriving it from the mutated source
     * answered `source_fingerprint_changed` on every later run — and the message
     * said "re-export and start again", which is the one route that loses
     * `previous_requires_manual_renewal` for good.
     */
    public function testAReleaseThatMutatedTheSourceAndThenDriftedCanBeResumed(): void
    {
        $this->stage();

        $subscription = $this->sourceDouble();

        // A renewal that completed while the flag was being saved. The guard's
        // fingerprint moves; no open order appears, so the block is drift and
        // nothing else.
        $subscription->onNextSave(static function (\CartShiftSourceSubscriptionDouble $source): void {
            $source->addRenewalOrder(\CartShiftSourceOrderDouble::paid(880_999));
        });

        $first = $this->cutover($subscription, fingerprintFollowsManualFlag: true);

        $this->assertFalse($first['ok']);
        $this->assertTrue($subscription->is_manual(), 'The guard leaves the source manual on a post-save block.');

        $release = CutoverReceipt::read($this->receiptPath())['receipt']
            ->entryFor('subscription:910001')['source_release'];

        $this->assertTrue($release['source_mutated']);
        $this->assertFalse(
            $release['previous_requires_manual_renewal'],
            'The only surviving record that this subscriber was on automatic billing.',
        );

        // Nothing further moved. The resume finishes the release.
        $second = $this->cutover($subscription, fingerprintFollowsManualFlag: true);

        $this->assertTrue($second['ok'], json_encode($second['failures']));
        $this->assertSame(CutoverReceipt::STATE_SOURCE_RELEASED, $second['state']);

        $entry = CutoverReceipt::read($this->receiptPath())['receipt']->entryFor('subscription:910001');

        $this->assertSame(CutoverReceipt::STATE_SOURCE_RELEASED, $entry['state']);
        $this->assertSame(CutoverReceipt::RELEASE_ALREADY_MANUAL, $entry['source_release']['state']);
        $this->assertTrue($entry['source_release']['source_mutated'], 'The mutation stays latched.');

        // And the recorded value wins over the guard's fresh reading, which is
        // `true` only because CartShift made it so.
        $this->assertFalse($entry['source_release']['previous_requires_manual_renewal']);
    }

    /**
     * The other half: a resume that the guard still refuses — an open invoice
     * appeared, so the source can still be charged — names `restore-source` and
     * says in as many words not to re-export. Re-exporting is the destructive
     * route here and it was the only one the messages used to mention.
     */
    public function testABlockedResumeNamesRestoreSourceAndRefusesToRecommendAReExport(): void
    {
        $this->stage();

        $subscription = $this->sourceDouble();

        $subscription->onNextSave(static function (\CartShiftSourceSubscriptionDouble $source): void {
            $source->addRenewalOrder(\CartShiftSourceOrderDouble::openWithGateway(880_999));
        });

        $this->cutover($subscription, fingerprintFollowsManualFlag: true);

        $result = $this->cutover($subscription, fingerprintFollowsManualFlag: true);

        $this->assertFalse($result['ok']);

        $messages = implode("\n", array_column($result['failures'], 'message'));

        $this->assertStringContainsString('restore-source', $messages);
        $this->assertStringContainsString('do NOT re-export', $messages);
        $this->assertStringContainsString('automatic renewal', $messages);
    }

    /**
     * A NON-normalised entry must not silently invert the one fact that cannot
     * be re-derived. A receipt round-tripped through a decoder that renders
     * booleans as `1`/`0` — or edited by hand — used to map to `null`, and null
     * means "use the guard's fresh reading", which on a resume reads the flag
     * CartShift itself wrote.
     */
    public function testANonBooleanPreviousFlagIsCoercedRatherThanDiscarded(): void
    {
        $state = \CartShift\Domain\Subscription\CutoverEntryState::of([
            'source_ref'     => 'subscription:910001',
            'source_release' => [
                'required'                         => true,
                'source_mutated'                   => true,
                'previous_requires_manual_renewal' => 0,
            ],
        ]);

        $this->assertFalse($state->previousRequiresManualRenewal);

        $truthy = \CartShift\Domain\Subscription\CutoverEntryState::of([
            'source_ref'     => 'subscription:910001',
            'source_release' => ['previous_requires_manual_renewal' => '1'],
        ]);

        $this->assertTrue($truthy->previousRequiresManualRenewal);

        $absent = \CartShift\Domain\Subscription\CutoverEntryState::of(['source_ref' => 'subscription:910001']);

        $this->assertNull($absent->previousRequiresManualRenewal);
    }

    // ──────────────────────────────────────────────
    // Mixed cohorts: where the interesting failures live
    // ──────────────────────────────────────────────

    /**
     * Two subscriptions, one of which stops the release. The other one was
     * genuinely released, and the receipt on disk has to say so — otherwise a
     * later restore has no previous flag to put back.
     */
    public function testAPartiallyReleasedCohortRecordsWhatItActuallyDidToEachSource(): void
    {
        $this->stage(['cohort' => [[], []]]);

        $ok      = $this->sourceDouble();
        $blocked = $this->sourceDouble(
            ['renewal' => [\CartShiftSourceOrderDouble::openWithGateway(880_777)]],
            910_002,
        );

        $result = $this->cutover([910_001 => $ok, 910_002 => $blocked]);

        $this->assertFalse($result['ok']);
        $this->assertTrue($ok->is_manual());
        $this->assertFalse($blocked->is_manual());

        $receipt = CutoverReceipt::read($this->receiptPath())['receipt'];

        $this->assertSame(
            CutoverReceipt::RELEASE_RELEASED,
            $receipt->entryFor('subscription:910001')['source_release']['state'],
        );
        $this->assertFalse(
            $receipt->entryFor('subscription:910001')['source_release']['previous_requires_manual_renewal'],
        );
        $this->assertSame(
            CutoverReceipt::RELEASE_BLOCKED,
            $receipt->entryFor('subscription:910002')['source_release']['state'],
        );

        // The header never advanced, so activation is still impossible.
        $this->assertSame(CutoverReceipt::STATE_STAGED, $receipt->state);
    }

    /**
     * And staging again over that cohort is refused.
     *
     * `stage` rewrites every entry from the dataset. On a receipt whose header
     * is still `staged`, the same-state no-op used to let it through — and
     * entry 910001's `previous_requires_manual_renewal` went with it, leaving a
     * subscriber whose automatic renewal is off permanently and no record it was
     * ever on.
     */
    public function testAPartiallyReleasedCohortRefusesToBeStagedAgain(): void
    {
        $this->stage(['cohort' => [[], []]]);

        $this->cutover([
            910_001 => $this->sourceDouble(),
            910_002 => $this->sourceDouble(
                ['renewal' => [\CartShiftSourceOrderDouble::openWithGateway(880_777)]],
                910_002,
            ),
        ]);

        $before = (string) file_get_contents($this->receiptPath());
        $result = $this->stage(['cohort' => [[], []]]);

        $this->assertFalse($result['ok']);
        $this->assertContains(CutoverReceipt::REASON_TRANSITION_INVALID, $this->codes($result['failures']));
        $this->assertSame($before, file_get_contents($this->receiptPath()), 'The receipt was rewritten anyway.');
    }

    /**
     * The other half: the rollback route has to be available in exactly that
     * case, because some sources really are manual and the header never moved.
     */
    public function testAPartiallyReleasedCohortCanStillBeRestored(): void
    {
        $this->stage(['cohort' => [[], []]]);

        $ok      = $this->sourceDouble();
        $blocked = $this->sourceDouble(
            ['renewal' => [\CartShiftSourceOrderDouble::openWithGateway(880_777)]],
            910_002,
        );

        $this->cutover([910_001 => $ok, 910_002 => $blocked]);

        $result = $this->restore([910_001 => $ok, 910_002 => $blocked]);

        $this->assertTrue($result['ok'], json_encode($result['failures']));
        $this->assertFalse($ok->is_manual(), 'The released source must be handed back.');
    }

    /**
     * The activation ordering, which every safety claim about `restore-source`
     * rests on: the receipt says `activated` before the status is written, so a
     * crash between the two over-states rather than under-states.
     *
     * Forced by removing the second destination row, which makes `activateOne`
     * fail after the mark has already been written.
     */
    public function testActivationMarksTheReceiptBeforeItTouchesTheDestination(): void
    {
        $this->stage(['cohort' => [[], []]]);
        $this->cutover([910_001 => $this->sourceDouble(), 910_002 => $this->sourceDouble([], 910_002)]);

        $receipt = CutoverReceipt::read($this->receiptPath())['receipt'];
        $second  = (int) $receipt->entryFor('subscription:910002')['target_subscription_id'];

        $GLOBALS['_cartshift_test_fc_models']['Subscription'] = array_values(array_filter(
            $GLOBALS['_cartshift_test_fc_models']['Subscription'],
            static fn (object $row): bool => (int) $row->id !== $second,
        ));

        $result = $this->activate();

        $this->assertFalse($result['ok']);

        $onDisk = CutoverReceipt::read($this->receiptPath())['receipt'];

        $this->assertSame(
            CutoverReceipt::STATE_ACTIVATED,
            $onDisk->entryFor('subscription:910002')['state'],
            'The mark must survive an activation that then failed.',
        );
    }

    /**
     * A receipt write that cannot land stops the command before it changes
     * anything else. Without this, `activate` would set a status while the
     * on-disk receipt still read `source_released` — and `restore-source` would
     * then hand the source back while FluentCart was already billing.
     */
    public function testAFailedReceiptWriteStopsActivationBeforeTheStatusIsSaved(): void
    {
        $this->stage();
        $this->cutover($this->sourceDouble());

        $this->lockWorkspaceOrSkip();

        $result = $this->activate();

        chmod($this->workspace, 0700);

        $this->assertFalse($result['ok']);
        $this->assertContains(CutoverReceipt::REASON_WRITE_FAILED, $this->codes($result['failures']));
        $this->assertSame('paused', $this->stagedSubscription()->status);
    }

    public function testAFailedReceiptWriteStopsTheReleaseBeforeTheSourceIsTouched(): void
    {
        $this->stage();

        $subscription = $this->sourceDouble();

        $this->lockWorkspaceOrSkip();

        $result = $this->cutover($subscription);

        chmod($this->workspace, 0700);

        $this->assertFalse($result['ok']);
        $this->assertContains(CutoverReceipt::REASON_WRITE_FAILED, $this->codes($result['failures']));
    }

    // ──────────────────────────────────────────────
    // The blocked restoration, which used to reopen C1
    // ──────────────────────────────────────────────

    /**
     * `SourceRenewalGuard::restore()` refuses BEFORE it mutates, so a refused
     * restoration leaves the source exactly as released as it was. The entry
     * has to keep saying so.
     *
     * It used to be overwritten with `blocked`, which was false twice over: a
     * second `restore-source` then skipped the entry — un-retryable at the one
     * moment it is needed — and the staging guard, which keyed off that same
     * field, went quiet.
     */
    public function testABlockedRestorationLeavesTheEntryHonestAndRetryable(): void
    {
        $this->stage(['cohort' => [[], []]]);

        $released = $this->sourceDouble();
        $blocked  = $this->sourceDouble(
            ['renewal' => [\CartShiftSourceOrderDouble::openWithGateway(880_777)]],
            910_002,
        );

        $this->cutover([910_001 => $released, 910_002 => $blocked]);

        // A renewal invoice appears on the released one before the rollback.
        $released->addRenewalOrder(\CartShiftSourceOrderDouble::openWithoutGateway(880_888));

        $first = $this->restore([910_001 => $released, 910_002 => $blocked]);

        $this->assertFalse($first['ok']);
        $this->assertTrue($released->is_manual(), 'A refused restoration must not have touched the source.');

        $entry = CutoverReceipt::read($this->receiptPath())['receipt']->entryFor('subscription:910001');

        $this->assertSame(CutoverReceipt::STATE_SOURCE_RELEASED, $entry['state']);
        $this->assertSame(CutoverReceipt::RELEASE_RELEASED, $entry['source_release']['state']);
        $this->assertContains(
            CutoverReceipt::REASON_SOURCE_FINGERPRINT_CHANGED,
            $entry['source_release']['reason_codes'],
        );

        // Retryable: the second run reaches the entry and refuses for the same
        // honest reason, rather than reporting that nothing was ever released.
        $second = $this->restore([910_001 => $released, 910_002 => $blocked]);

        $this->assertFalse($second['ok']);
        $this->assertContains(
            CutoverReceipt::REASON_SOURCE_FINGERPRINT_CHANGED,
            $this->codes($second['failures']),
        );
    }

    /**
     * The regression itself, end to end: release, blocked rollback, re-stage.
     *
     * With the guard keyed on the release vocabulary, step three walked through
     * and rebuilt a still-manual WooCommerce subscription as `pending` with no
     * previous flag — C1's exact harm, by a route I1 had just opened. The guard
     * now reads the entry's own state, which no failure branch can clear.
     */
    public function testABlockedRestorationStillBlocksRestaging(): void
    {
        $this->stage(['cohort' => [[], []]]);

        $released = $this->sourceDouble();
        $blocked  = $this->sourceDouble(
            ['renewal' => [\CartShiftSourceOrderDouble::openWithGateway(880_777)]],
            910_002,
        );

        $this->cutover([910_001 => $released, 910_002 => $blocked]);

        $released->addRenewalOrder(\CartShiftSourceOrderDouble::openWithoutGateway(880_888));

        $this->restore([910_001 => $released, 910_002 => $blocked]);

        $before = (string) file_get_contents($this->receiptPath());
        $result = $this->stage(['cohort' => [[], []]]);

        $this->assertFalse($result['ok'], 'Staging must not run over a source that is still manual.');
        $this->assertContains(CutoverReceipt::REASON_TRANSITION_INVALID, $this->codes($result['failures']));
        $this->assertSame($before, file_get_contents($this->receiptPath()));
        $this->assertTrue($released->is_manual());
    }

    /**
     * And the door the remedy needs: once everything really came back, the
     * cohort may be staged again. That is what makes "repair the history and
     * re-run `stage`" true after a cutover rather than a dead end.
     */
    public function testAFullyRestoredCohortMayBeStagedAgain(): void
    {
        $this->stage();

        $subscription = $this->sourceDouble();

        $this->cutover($subscription);

        $restored = $this->restore($subscription);

        $this->assertTrue($restored['ok'], json_encode($restored['failures']));
        $this->assertFalse($subscription->is_manual());

        $result = $this->stage();

        $this->assertTrue($result['ok'], json_encode($result['failures']));
        $this->assertSame(CutoverReceipt::STATE_STAGED, $result['state']);
    }

    // ──────────────────────────────────────────────
    // History that did not reconcile
    // ──────────────────────────────────────────────

    /**
     * A destination row whose bill count nobody could verify is neither
     * released nor activated. Section 10 step 7, and this task's governing
     * rule: the destination row AND ITS HISTORY have to be ready.
     */
    public function testAnUnreconciledRecordIsNeitherReleasedNorActivated(): void
    {
        // Nine payments claimed by the source, two paid orders in the package.
        $staged = $this->stage(['subscription' => ['source_payment_count' => 9]]);

        $entry = $staged['receipt']->entryFor('subscription:910001');

        $this->assertFalse($entry['history']['reconciled']);
        $this->assertSame(1, $staged['summary']['history_mismatch']);

        $subscription = $this->sourceDouble();
        $released     = $this->cutover($subscription);

        $this->assertTrue($released['ok'], json_encode($released['failures']));
        $this->assertFalse($subscription->is_manual(), 'The source must keep billing until the history agrees.');

        $activated = $this->activate();

        $this->assertTrue($activated['ok'], json_encode($activated['failures']));
        $this->assertSame('paused', $this->stagedSubscription()->status);
        $this->assertSame(1, $activated['summary']['history_mismatch']);
    }

    /**
     * And the cohort does not get to close over it.
     *
     * A held record is a subscriber paused in FluentCart and still auto-billing
     * in WooCommerce. Reconcile used to stamp it `reconciled` — outcome is
     * `ready`, so the blocked exemption missed it — and report `ok: true` with
     * `history_mismatch: 1` as the only signal. Section 11 Phase E's "zero
     * unexplained records" is now the verdict rather than a number in the
     * summary.
     */
    public function testReconcileRefusesToCloseACohortWithAHeldRecord(): void
    {
        $this->stage(['subscription' => ['source_payment_count' => 9]]);
        $this->cutover($this->sourceDouble());
        $this->activate();

        $result = $this->reconcile();

        $this->assertFalse($result['ok']);
        $this->assertSame(
            [\CartShift\Domain\Subscription\ReconciliationResult::REASON_HISTORY_COUNT_MISMATCH],
            $this->codes($result['failures']),
        );
        $this->assertStringContainsString('subscription:910001', $result['failures'][0]['message']);

        // The entry's own state is left telling the truth.
        $entry = CutoverReceipt::read($this->receiptPath())['receipt']->entryFor('subscription:910001');

        $this->assertSame(CutoverReceipt::STATE_STAGED, $entry['state']);
        $this->assertNotSame(CutoverReceipt::STATE_RECONCILED, $entry['state']);
    }

    /**
     * N1: the refusal names a step the machine will accept, or admits there
     * is none.
     *
     * `reconcile` only ever runs from `activated`, and from there `stage` is a
     * reversal and `restore-source` is refused because something is live — so
     * the honest answer is "nothing", plus what to do outside the tool. The
     * previous message recommended both, and the machine refused both, every
     * time.
     */
    public function testTheReconcileRefusalNamesOnlyTransitionsTheMachineAccepts(): void
    {
        $this->stage(['cohort' => [[], ['source_payment_count' => 9]]]);
        $this->cutover([910_001 => $this->sourceDouble(), 910_002 => $this->sourceDouble([], 910_002)]);
        $this->activate();

        $message = $this->reconcile()['failures'][0]['message'];

        $this->assertStringContainsString('No CartShift command can move this receipt any further', $message);

        // And the specific unsafe workaround is pre-empted rather than left to
        // be discovered.
        $this->assertStringContainsString('disable renewal at the source FIRST', $message);

        // The two commands the machine would refuse are not recommended.
        $this->assertStringNotContainsString('then re-run it', $message);
        $this->assertStringNotContainsString('hands every source back', $message);
    }

    /**
     * And from a state where something IS available, it says so.
     */
    public function testTheReconcileRemedyNamesTheRealRouteWhenOneExists(): void
    {
        $receipt = CutoverReceipt::begin(
            self::SOURCE_KEY,
            '',
            SubscriptionSelection::all(self::SOURCE_KEY)->fingerprint(),
            'map',
            'set',
        )
            ->withState(CutoverReceipt::STATE_ACTIVATED)
            ->withEntry(CutoverReceipt::entry([
                'source_ref'             => 'subscription:910001',
                'target_subscription_id' => 41,
                'history'                => ['reconciled' => false],
            ]));

        $remedy = (new \ReflectionMethod(SubscriptionCutover::class, 'remedy'))
            ->invoke(null, $receipt->withState(CutoverReceipt::STATE_STAGED), self::SOURCE_KEY);

        $this->assertStringContainsString('`stage`', $remedy);
    }

    // ──────────────────────────────────────────────
    // The mutation fact, end to end
    // ──────────────────────────────────────────────

    /**
     * N3: a release that mutated the source and then stopped on drift.
     *
     * `SourceRenewalGuard` sets the flag, saves, re-scans, finds a new invoice,
     * and leaves the source MANUAL on purpose — its own message says so. The
     * entry stays at `staged`, because the release never completed, so nothing
     * about its state or its release vocabulary distinguishes it from a refusal
     * that never touched WooCommerce. Only the mutation fact does, and staging
     * over it would rebuild a subscription CartShift had already disabled.
     */
    public function testASourceMutatedByADriftedReleaseBlocksRestagingAndIsRestorable(): void
    {
        $this->stage();

        $subscription = $this->sourceDouble([], onSave: static function (
            \CartShiftSourceSubscriptionDouble $subscription,
        ): void {
            $subscription->addRenewalOrder(\CartShiftSourceOrderDouble::openWithoutGateway(880_888));
        });

        $released = $this->cutover($subscription);

        $this->assertFalse($released['ok']);
        $this->assertTrue($subscription->is_manual(), 'The guard leaves a drifted source manual on purpose.');

        $entry = CutoverReceipt::read($this->receiptPath())['receipt']->entryFor('subscription:910001');

        // The two fields a previous guard keyed on both say "not released".
        $this->assertSame(CutoverReceipt::STATE_STAGED, $entry['state']);
        $this->assertSame(CutoverReceipt::RELEASE_BLOCKED, $entry['source_release']['state']);

        // The one that matters says otherwise.
        $this->assertTrue($entry['source_release']['source_mutated']);
        $this->assertFalse($entry['source_release']['previous_requires_manual_renewal']);

        // So staging is refused …
        $result = $this->stage();

        $this->assertFalse($result['ok'], 'Staging must not rewrite a source CartShift has already disabled.');
        $this->assertContains(CutoverReceipt::REASON_TRANSITION_INVALID, $this->codes($result['failures']));

        // … and the rollback route is available, which it was not before.
        $restored = $this->restore($subscription);

        $this->assertTrue($restored['ok'], json_encode($restored['failures']));
        $this->assertFalse($subscription->is_manual());
    }

    /**
     * And resuming that release never overwrites the previous flag.
     *
     * The source is manual because CartShift made it manual, so a second run
     * reads `is_manual() === true` and the guard reports `already_manual` with
     * `previous = true`. Recording that would say "this subscriber was always on
     * manual renewal" over the truth, with no second copy anywhere to correct it
     * from.
     */
    public function testResumingAMutatedReleaseKeepsTheOriginalPreviousFlag(): void
    {
        $this->stage();

        $subscription = $this->sourceDouble([], onSave: static function (
            \CartShiftSourceSubscriptionDouble $subscription,
        ): void {
            $subscription->addRenewalOrder(\CartShiftSourceOrderDouble::openWithoutGateway(880_888));
        });

        $this->cutover($subscription);

        // The operator settles the invoice; the drift is gone next time round.
        $subscription->removeRenewalOrder(880_888);

        $resumed = $this->cutover($subscription);

        $this->assertTrue($resumed['ok'], json_encode($resumed['failures']));

        $release = CutoverReceipt::read($this->receiptPath())['receipt']
            ->entryFor('subscription:910001')['source_release'];

        $this->assertFalse(
            $release['previous_requires_manual_renewal'],
            'The subscriber was on automatic renewal, and only this receipt remembers it.',
        );
        $this->assertTrue($release['source_mutated']);
    }

    /**
     * N2: a cohort containing a terminal record can still be re-staged after a
     * fully successful restore — which is the whole point of that door.
     *
     * The terminal record reaches `source_released` through a short-circuit that
     * writes nothing to WooCommerce, so a guard reading the entry's rank locked
     * the cohort out for ever while asserting in a comment that it did not.
     */
    public function testACohortWithATerminalRecordIsStillRestageableAfterARestore(): void
    {
        $this->stage(['cohort' => [[], ['status' => 'cancelled', 'source_payment_count' => 2]]]);

        $terminal = CutoverReceipt::read($this->receiptPath())['receipt']->entryFor('subscription:910002');

        $this->assertTrue($terminal['terminal'], 'The fixture must actually be terminal.');
        $this->assertSame(CutoverReceipt::RELEASE_NOT_REQUIRED, $terminal['source_release']['state']);

        $subscription = $this->sourceDouble();

        $this->cutover([910_001 => $subscription]);

        // It travelled with the cohort, and it was never touched.
        $travelled = CutoverReceipt::read($this->receiptPath())['receipt']->entryFor('subscription:910002');

        $this->assertSame(CutoverReceipt::STATE_SOURCE_RELEASED, $travelled['state']);
        $this->assertFalse($travelled['source_release']['source_mutated']);

        $this->assertTrue($this->restore([910_001 => $subscription])['ok']);

        $result = $this->stage(['cohort' => [[], ['status' => 'cancelled', 'source_payment_count' => 2]]]);

        $this->assertTrue($result['ok'], json_encode($result['failures']));
    }

    /**
     * The intent reaches the file BEFORE the source is handed back.
     *
     * Observed from inside the guard, at the only moment that matters: the
     * receipt is read from disk as `set_requires_manual_renewal()` is called on
     * the way back to automatic. If the intent were not already there, a write
     * that failed immediately afterwards would leave a receipt reading
     * `released` over a source that is automatic again — and `activate` from
     * that receipt is structurally legal.
     */
    public function testRestorationRecordsItsIntentBeforeItTouchesTheSource(): void
    {
        $this->stage();

        $seen = null;

        $subscription = $this->sourceDouble();

        $this->cutover($subscription);

        $subscription->onNextSave(function () use (&$seen): void {
            $seen ??= CutoverReceipt::read($this->receiptPath())['receipt']
                ?->entryFor('subscription:910001')['source_release']['restore_intent_at_utc'];
        });

        $this->restore($subscription);

        $this->assertNotNull($seen, 'The intent must be on disk before the flag goes back.');

        // And it is cleared once the outcome is known, so the entry stops
        // reading as unknown.
        $release = CutoverReceipt::read($this->receiptPath())['receipt']
            ->entryFor('subscription:910001')['source_release'];

        $this->assertNull($release['restore_intent_at_utc']);
        $this->assertFalse($release['source_mutated']);
    }

    /**
     * A restoration that was refused before touching anything clears its intent
     * too: the source is exactly as it was, which is a known state.
     */
    public function testARefusedRestorationDoesNotLeaveTheSourceUnverified(): void
    {
        $this->stage();

        $subscription = $this->sourceDouble();

        $this->cutover($subscription);

        $subscription->addRenewalOrder(\CartShiftSourceOrderDouble::openWithoutGateway(880_888));

        $this->restore($subscription);

        $release = CutoverReceipt::read($this->receiptPath())['receipt']
            ->entryFor('subscription:910001')['source_release'];

        $this->assertNull($release['restore_intent_at_utc']);
        $this->assertTrue($release['source_mutated'], 'A refused restoration leaves the source released.');
    }

    // ──────────────────────────────────────────────
    // Source key, on stage too
    // ──────────────────────────────────────────────

    /**
     * `stage` was the one command that did not compare, so a re-run against an
     * existing receipt with the wrong key surfaced as `source_fingerprint_changed`
     * — which reads as "the source moved" and sends the operator to re-export.
     */
    public function testStagingAgainstAReceiptForADifferentSourceKeyIsNamedRatherThanBlamedOnTheSource(): void
    {
        $this->stage();

        $result = $this->stage(['source_key' => 'somebody-elses-shop']);

        $this->assertFalse($result['ok']);
        $this->assertSame(
            [CutoverReceipt::REASON_TRANSITION_INVALID],
            $this->codes($result['failures']),
        );
        $this->assertStringContainsString('do NOT re-export', $result['failures'][0]['message']);
    }

    // ──────────────────────────────────────────────
    // Source key
    // ──────────────────────────────────────────────

    /**
     * The receipt knows which source it is about. An operator who omits or
     * mistypes `--source-key` must not be told to re-export — that is the wrong
     * action, and doing it destroys the maintenance-window freeze.
     */
    public function testASourceKeyThatDisagreesWithTheReceiptIsNamedRatherThanBlamedOnTheSource(): void
    {
        $this->stage();

        $subscription = $this->sourceDouble();

        $result = $this->cutover($subscription, sourceKey: 'somebody-elses-shop');

        $this->assertFalse($result['ok']);
        $this->assertContains(CutoverReceipt::REASON_TRANSITION_INVALID, $this->codes($result['failures']));
        $this->assertStringContainsString('--source-key', $result['failures'][0]['message']);
        $this->assertStringContainsString('do NOT re-export', $result['failures'][0]['message']);
        $this->assertSame([], $subscription->calls);
    }

    public function testOmittingTheSourceKeyEntirelyIsFine(): void
    {
        $this->stage();

        $this->assertTrue($this->cutover($this->sourceDouble())['ok']);
    }

    // ──────────────────────────────────────────────
    // Harness
    // ──────────────────────────────────────────────

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function stage(array $options = []): array
    {
        $records = [
            $this->factory->customerFromPayload(self::SOURCE_KEY, $this->shapes['customerPayload']()),
            $this->factory->productFromPayload(self::SOURCE_KEY, $this->shapes['monthlyProductPayload']()),
        ];

        foreach ($this->cohort($options) as $index => $overrides) {
            $records = array_merge($records, $this->subscriptionRecords($index, $overrides));
        }

        // Whatever a closure test needs to add to an otherwise sound dataset.
        $records = array_merge($records, (array) ($options['extra_records'] ?? []));

        return $this->cutoverService($options)->stage($this->dataset($records), [
            'source_key'              => (string) ($options['source_key'] ?? self::SOURCE_KEY),
            'receipt_path'            => $this->receiptPath(),
            'package_checksum'        => (string) ($options['package_checksum'] ?? ''),
            'approve_system_settings' => $options['approve_system_settings'] ?? null,
            'accept_manual_fallback'   => $options['accept_manual_fallback'] ?? false,
            'migration_id'            => 'cutover-test',
            'selection'               => $options['selection'] ?? null,
        ]);
    }

    /**
     * The per-subscription overrides this run is about, in order.
     *
     * One by default. `['cohort' => [[], ['source_payment_count' => 9]]]` gives
     * two, the second with a payment count its history cannot account for.
     *
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    private function cohort(array $options): array
    {
        if (isset($options['cohort'])) {
            return (array) $options['cohort'];
        }

        return [(array) ($options['subscription'] ?? [])];
    }

    /**
     * One subscription and the two orders that belong to it, exclusively.
     *
     * §6.2 blocks two subscriptions sharing a parent order
     * (`shared_parent_order_requires_projection`), so each gets its own pair.
     *
     * @param array<string, mixed> $overrides
     * @return list<object>
     */
    private function subscriptionRecords(int $index, array $overrides): array
    {
        $subscriptionId = 910_001 + $index;
        $parentId       = 880_001 + $index;
        $renewalId      = 880_501 + $index;

        return [
            $this->orderRecord('parentOrderPayload', [
                'source_ref'      => 'order:' . $parentId,
                'source_order_id' => $parentId,
            ]),
            $this->orderRecord('renewalOrderPayload', [
                'source_ref'      => 'order:' . $renewalId,
                'source_order_id' => $renewalId,
            ]),
            $this->factory->subscriptionFromPayload(
                self::SOURCE_KEY,
                $this->shapes['subscriptionPayload'](array_merge([
                    'source_ref'             => 'subscription:' . $subscriptionId,
                    'source_subscription_id' => $subscriptionId,
                    'parent_order_id'        => $parentId,
                    'related_orders'         => [
                        ['source_order_id' => $parentId, 'relationship' => 'parent'],
                        ['source_order_id' => $renewalId, 'relationship' => 'renewal'],
                    ],
                    // Both Lapka source products declare `_subscription_length
                    // = 0`, an explicit "unlimited" rather than a silence — and
                    // a silence is what `finite_term_undeclared` refuses.
                    'contract'               => [
                        'finite_cycles' => 0,
                        'source_plan'   => [SubscriptionRecordFactory::PLAN_PRODUCT_LENGTH => 0],
                    ] + $this->shapes['subscriptionPayload']()['contract'],
                ], $overrides)),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cutover(
        \CartShiftSourceSubscriptionDouble|array $subscriptions,
        bool $renewalsPaused = true,
        ?string $fingerprint = null,
        ?string $sourceKey = null,
        bool $fingerprintFollowsManualFlag = false,
    ): array {
        $byId = is_array($subscriptions)
            ? $subscriptions
            : [910_001 => $subscriptions];

        // Unless a test is deliberately moving the source, the re-derived
        // fingerprint is the one the receipt recorded for that subscription —
        // which is what an unchanged, frozen source produces.
        $receipt = CutoverReceipt::read($this->receiptPath())['receipt'];

        $recorded = [];

        foreach (array_keys($byId) as $id) {
            $recorded[$id] = (string) (
                $receipt?->entryFor('subscription:' . $id)['source_fingerprint'] ?? ''
            );
        }

        return $this->cutoverService([
            'source_loader'      => static fn (int $id): ?object => $byId[$id] ?? null,
            // `$fingerprintFollowsManualFlag` models the production derivation
            // rather than the frozen one: `SubscriptionRecord::fingerprintPayload()`
            // includes `requires_manual_renewal`, so the record fingerprint of a
            // source CartShift has already set to manual can never match the one
            // the export recorded. Tests that are not about the resume keep the
            // frozen answer, which is what an untouched source produces.
            'source_fingerprint' => static function (object $subscription, string $key) use (
                $fingerprint,
                $recorded,
                $fingerprintFollowsManualFlag,
            ): string {
                $base = $fingerprint ?? ($recorded[$subscription->get_id()] ?? '');

                return $fingerprintFollowsManualFlag && $subscription->is_manual()
                    ? hash('sha256', $base . ':manual')
                    : $base;
            },
        ])->cutoverSource([
            'source_key'      => $sourceKey,
            'receipt_path'    => $this->receiptPath(),
            'renewals_paused' => $renewalsPaused,
        ]);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function activate(array $options = []): array
    {
        return $this->cutoverService($options)->activate([
            'source_key'   => $options['source_key'] ?? null,
            'receipt_path' => $this->receiptPath(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function reconcile(): array
    {
        return $this->cutoverService()->reconcile([
            'receipt_path' => $this->receiptPath(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function restore(\CartShiftSourceSubscriptionDouble|array $subscriptions): array
    {
        $byId = is_array($subscriptions) ? $subscriptions : [910_001 => $subscriptions];

        return $this->cutoverService([
            'source_loader' => static fn (int $id): ?object => $byId[$id] ?? null,
        ])->restoreSource(['receipt_path' => $this->receiptPath()]);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function cutoverService(array $options = []): SubscriptionCutover
    {
        $loader      = $options['source_loader'] ?? null;
        $fingerprint = $options['source_fingerprint'] ?? null;

        // The decision set is the DEFAULT rather than something `stage()` opts
        // into, because `activate` and `reconcile` fingerprint the same table
        // and refuse when it has moved. A staging run that promoted a decision
        // and a later command that saw an empty table is not a scenario the
        // operator can reach — the staging table outlives the run — and a
        // harness that produced it would test the transition guard instead of
        // what these tests are about.
        return new SubscriptionCutover(
            productMap: $this->productMap($options['product_map'] ?? $this->promotableDecisions()),
            sourceLoader: is_callable($loader) ? $loader(...) : null,
            sourceFingerprint: is_callable($fingerprint) ? $fingerprint(...) : null,
        );
    }

    /**
     * @param list<ProductMapDecision> $decisions
     */
    private function productMap(array $decisions): ProductMapRepository
    {
        $rows = array_map(
            static fn (ProductMapDecision $decision): object => (object) [
                'wc_id'       => $decision->wcId(),
                'wc_type'     => $decision->wcType(),
                'decision'    => $decision->decision(),
                'fc_post_id'  => $decision->fcPostId(),
                'band'        => $decision->band(),
                'variant_map' => json_encode($decision->variantEnvelope()),
            ],
            $decisions,
        );

        $GLOBALS['_cartshift_test_get_results_callback'] = static fn (string $query): array
            => str_contains($query, 'cartshift_product_map') ? $rows : [];

        return new ProductMapRepository(self::SOURCE_KEY);
    }

    /**
     * The operator's decision for the cohort's one product, exactly as the
     * mapping screen saves it: the source product linked to a FluentCart
     * product they built by hand, and its one pseudo-variation pointed at that
     * product's own variant.
     *
     * Nothing else seeds a product or variation reference in this file, so
     * every stage test that passes is a test that promotion ran.
     *
     * @return list<ProductMapDecision>
     */
    private function promotableDecisions(): array
    {
        return [
            ProductMapDecision::link(
                CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID,
                'subscription',
                701,
                'none',
                [(string) CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID => 801],
            ),
        ];
    }

    /**
     * Two source products claiming one target variation.
     *
     * @return list<ProductMapDecision>
     */
    private function collidingDecisions(): array
    {
        return [
            ProductMapDecision::link(
                CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID,
                'subscription',
                5001,
                'none',
                [(string) CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID => 8001],
            ),
            ProductMapDecision::link(
                CARTSHIFT_LAPKA_YEARLY_PRODUCT_ID,
                'subscription',
                5001,
                'none',
                [(string) CARTSHIFT_LAPKA_YEARLY_PRODUCT_ID => 8001],
            ),
        ];
    }

    /**
     * @param list<object> $records
     */
    private function dataset(array $records): SubscriptionDatasetSource
    {
        $sourceKey = self::SOURCE_KEY;

        return new class ($sourceKey, $records) implements SubscriptionDatasetSource {
            /** @param list<object> $records */
            public function __construct(
                private readonly string $sourceKey,
                private readonly array $records,
            ) {
            }

            public function manifest(): DatasetManifest
            {
                $counts = [];

                foreach ($this->records as $record) {
                    $kind = $record->kind();

                    $counts[$kind] = ($counts[$kind] ?? 0) + 1;
                }

                return new DatasetManifest(
                    DatasetManifest::SCHEMA_VERSION,
                    $this->sourceKey,
                    'posts',
                    ['PLN'],
                    gmdate('Y-m-d H:i:s'),
                    [],
                    SubscriptionSelection::all($this->sourceKey)->fingerprint(),
                    $counts,
                    0,
                    count($this->records),
                    'test-records-checksum',
                );
            }

            public function records(SubscriptionSelection $selection): iterable
            {
                yield from $this->records;
            }
        };
    }

    private function sourceDouble(
        array $related = [],
        int $id = 910_001,
        ?callable $onSave = null,
    ): \CartShiftSourceSubscriptionDouble {
        return new \CartShiftSourceSubscriptionDouble(
            $id,
            false,
            '',
            $related === [] ? ['renewal' => [\CartShiftSourceOrderDouble::paid(880_500 + $id - 910_000)]] : $related,
            $onSave,
        );
    }

    private function stagedSubscription(): object
    {
        $rows = \CartShiftFcModelStore::all('Subscription');

        $this->assertNotSame([], $rows, 'No destination subscription was written.');

        return $rows[0];
    }

    /**
     * Make the workspace unwritable, or say why the test cannot run.
     *
     * `is_writable()` answers true for uid 0 whatever the mode bits say, so
     * under root — a container CI image, most obviously — the chmod would be a
     * silent no-op and the test would pass for the wrong reason. Checked rather
     * than assumed.
     */
    private function lockWorkspaceOrSkip(): void
    {
        chmod($this->workspace, 0500);

        if (is_writable($this->workspace)) {
            chmod($this->workspace, 0700);

            self::markTestSkipped(
                'This process can write through a 0500 directory (running as root?), so a failed receipt '
                . 'write cannot be provoked here.',
            );
        }
    }

    private function receiptPath(): string
    {
        return $this->workspace . '/receipt.ndjson';
    }

    /** @return list<string> */
    private function transactionStatements(): array
    {
        $wanted = ['START TRANSACTION', 'COMMIT', 'ROLLBACK'];
        $seen   = [];

        foreach ((array) ($GLOBALS['_cartshift_test_queries'] ?? []) as $entry) {
            if (($entry[0] ?? '') === 'query' && in_array($entry[1] ?? '', $wanted, true)) {
                $seen[] = (string) $entry[1];
            }
        }

        return $seen;
    }

    private function targetFingerprint(): string
    {
        return (new \CartShift\Domain\Subscription\RuntimeCompatibilityProbe())
            ->inspect(\CartShift\Domain\Subscription\RuntimeCompatibilityProbe::ROLE_TARGET)
            ->fingerprint();
    }

    /**
     * @param array<string, list<int>> $customersByEmail
     * @param array<string, list<int>> $usersByEmail
     */
    private function installIdentityLookup(array $customersByEmail, array $usersByEmail): void
    {
        $GLOBALS['_cartshift_test_get_col_callback'] = static function (string $query) use (
            $customersByEmail,
            $usersByEmail,
        ): array {
            if (preg_match("/'([^']*)'/", $query, $matches) !== 1) {
                return [];
            }

            $value = $matches[1];

            if (str_contains($query, 'fct_customers') && str_contains($query, 'email')) {
                return $customersByEmail[$value] ?? [];
            }

            if (str_contains($query, 'users')) {
                return $usersByEmail[$value] ?? [];
            }

            return [];
        };
    }

    /**
     * @param list<array{code: string, message: string}> $failures
     * @return list<string>
     */
    /**
     * What the ID map holds, read the way production reads it.
     *
     * Straight from the global the reader answers from, so a test cannot
     * accidentally assert against a memoised repository instance that has never
     * been near the table.
     */
    private function idMapped(string $entityType, string $wcId): ?int
    {
        $value = $GLOBALS['_cartshift_test_id_map'][$entityType][$wcId] ?? null;

        return $value === null ? null : (int) $value;
    }

    private function codes(array $failures): array
    {
        return array_values(array_unique(array_column($failures, 'code')));
    }
}

<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Subscription;

use CartShift\Domain\Subscription\ClosureReport;
use CartShift\Domain\Subscription\CustomerRecord;
use CartShift\Domain\Subscription\DatasetClosureValidator;
use CartShift\Domain\Subscription\DatasetManifest;
use CartShift\Domain\Subscription\InvalidSourceRecord;
use CartShift\Domain\Subscription\ProductRecord;
use CartShift\Domain\Subscription\SubscriptionRecord;
use CartShift\Domain\Subscription\SubscriptionRecordFactory;
use CartShift\Domain\Subscription\SubscriptionSelection;
use CartShift\Tests\Unit\PluginTestCase;

/**
 * Dependency closure, which is the whole answer to the plan's fourth P0.
 *
 * The original package carried subscription lines with numeric
 * `parentOrderId` / `relatedOrders` references and no payloads behind them, and
 * called that a dataset. It is not: a reference is not an order, and a phase
 * that promises to discover the missing rows later is promising clairvoyance.
 * Every test here drives a real dataset through the validator and asserts on
 * what it decides — none of them hand-builds a ClosureReport and admires it.
 */
final class DatasetClosureValidatorTest extends PluginTestCase
{
    /** @var array<string, callable> */
    private array $shapes;

    private SubscriptionRecordFactory $factory;

    private DatasetClosureValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shapes    = require dirname(__DIR__, 3) . '/fixtures/lapka-subscription-shapes.php';
        $this->factory   = new SubscriptionRecordFactory();
        $this->validator = new DatasetClosureValidator();
    }

    // ──────────────────────────────────────────────
    // The happy path, so every failure below means something
    // ──────────────────────────────────────────────

    public function testACompleteDatasetIsComplete(): void
    {
        $records = $this->completeDataset();

        $report = $this->validator->validate($this->manifestFor($records), $records);

        $this->assertTrue($report->isComplete(), 'Unexpected failures: ' . implode(', ', $report->reasonCodes()));
        $this->assertSame([], $report->failures);
    }

    public function testTheReportCountsWhatItSaw(): void
    {
        $records = $this->completeDataset();

        $report = $this->validator->validate($this->manifestFor($records), $records);

        $this->assertSame(1, $report->countFor('customer'));
        $this->assertSame(1, $report->countFor('product'));
        $this->assertSame(2, $report->countFor('order'));
        $this->assertSame(1, $report->countFor('subscription'));
    }

    // ──────────────────────────────────────────────
    // Missing dependencies
    // ──────────────────────────────────────────────

    public function testAMissingCustomerBlocks(): void
    {
        $records = $this->completeDataset(without: [CustomerRecord::class]);

        $report = $this->validator->validate($this->manifestFor($records), $records);

        $this->assertFalse($report->isComplete());
        $this->assertContains(ClosureReport::CODE_MISSING_CUSTOMER, $report->reasonCodes());
    }

    public function testAMissingProductBlocks(): void
    {
        $records = $this->completeDataset(without: [ProductRecord::class]);

        $report = $this->validator->validate($this->manifestFor($records), $records);

        $this->assertFalse($report->isComplete());
        $this->assertContains(ClosureReport::CODE_MISSING_PRODUCT, $report->reasonCodes());
    }

    /**
     * The product is present but does not declare the variation the
     * subscription item claims. A pseudo-variation key nobody recognises is a
     * mapping decision with nowhere to land.
     */
    public function testAProductThatDoesNotDeclareTheClaimedVariationBlocks(): void
    {
        $records = $this->completeDataset(productOverrides: [
            'variations' => [[
                'source_variation_id'  => 0,
                'pseudo_variation_key' => 'some-other-key',
                'name'                 => 'Not the one',
                'sku'                  => '',
                'catalogue_price'      => 2900,
                'period'               => 'month',
                'multiplier'           => 1,
            ]],
        ]);

        $report = $this->validator->validate($this->manifestFor($records), $records);

        $this->assertFalse($report->isComplete());
        $this->assertContains(ClosureReport::CODE_MISSING_PRODUCT, $report->reasonCodes());
    }

    /**
     * The fourth P0, stated as a test: the destination cannot create a
     * subscription without a real parent order behind the number.
     */
    public function testAMissingParentOrderPayloadBlocks(): void
    {
        $records = $this->completeDataset(withoutOrderIds: [880_001]);

        $report = $this->validator->validate($this->manifestFor($records), $records);

        $this->assertFalse($report->isComplete());
        $this->assertContains(ClosureReport::CODE_MISSING_PARENT_ORDER, $report->reasonCodes());
    }

    public function testAMissingRenewalOrderPayloadBlocks(): void
    {
        $records = $this->completeDataset(withoutOrderIds: [880_501]);

        $report = $this->validator->validate($this->manifestFor($records), $records);

        $this->assertFalse($report->isComplete());
        $this->assertContains(ClosureReport::CODE_MISSING_RELATED_ORDER, $report->reasonCodes());
    }

    /**
     * The exact shape the plan's Verify section names: a package that carries
     * subscription lines and expects the destination to find the orders.
     */
    public function testASubscriptionOnlyPackageWithForeignOrderReferencesFailsClosure(): void
    {
        $records = [$this->subscription()];

        $report = $this->validator->validate($this->manifestFor($records), $records);

        $this->assertFalse($report->isComplete());
        $this->assertContains(ClosureReport::CODE_MISSING_CUSTOMER, $report->reasonCodes());
        $this->assertContains(ClosureReport::CODE_MISSING_PRODUCT, $report->reasonCodes());
        $this->assertContains(ClosureReport::CODE_MISSING_PARENT_ORDER, $report->reasonCodes());
        $this->assertContains(ClosureReport::CODE_MISSING_RELATED_ORDER, $report->reasonCodes());
    }

    /**
     * A paid order with a positive total and no succeeded charge behind it
     * cannot contribute to FluentCart's bill count, which recomputes from
     * transactions and would silently disagree with the source for ever.
     */
    public function testAPaidOrderWithNoSucceededChargeBlocks(): void
    {
        $records = $this->completeDataset(renewalOrderOverrides: ['transactions' => []]);

        $report = $this->validator->validate($this->manifestFor($records), $records);

        $this->assertFalse($report->isComplete());
        $this->assertContains(ClosureReport::CODE_MISSING_TRANSACTION, $report->reasonCodes());
    }

    // ──────────────────────────────────────────────
    // Ambiguity and duplication
    // ──────────────────────────────────────────────

    /**
     * `get_related_orders()` flattens its grouped result and throws the type
     * away, which is why the plan insists on four separate typed calls. If one
     * order comes back under two types, that is a source question, not a
     * tie-break for whichever loop iterated first.
     */
    public function testTheSameOrderUnderTwoRelationshipTypesBlocks(): void
    {
        $records = $this->completeDataset(subscriptionOverrides: [
            'related_orders' => [
                ['source_order_id' => 880_001, 'relationship' => 'parent'],
                ['source_order_id' => 880_501, 'relationship' => 'renewal'],
                ['source_order_id' => 880_501, 'relationship' => 'switch'],
            ],
        ]);

        $report = $this->validator->validate($this->manifestFor($records), $records);

        $this->assertFalse($report->isComplete());
        $this->assertContains(ClosureReport::CODE_AMBIGUOUS_ORDER_RELATIONSHIP, $report->reasonCodes());
    }

    public function testADuplicateReferenceWithADifferentFingerprintBlocks(): void
    {
        $records   = $this->completeDataset();
        $records[] = $this->factory->orderFromPayload('local', $this->shapes['parentOrderPayload']([
            'status' => 'refunded',
        ]));

        $report = $this->validator->validate($this->manifestFor($records), $records);

        $this->assertFalse($report->isComplete());
        $this->assertContains(ClosureReport::CODE_DUPLICATE_REFERENCE, $report->reasonCodes());
    }

    /**
     * The same record twice is a streaming artefact, not a contradiction — only
     * a disagreement about what the record *says* is a duplicate-reference
     * failure.
     *
     * The old name claimed more than the old assertion proved — it checked only
     * that the duplicate code was absent, which would still have passed had the
     * dataset been blocked for some other reason entirely. So it now asserts
     * `isComplete()`: a manifest that counts the line it was given, and a
     * stream that repeats a byte-identical record, agree, and the dataset is
     * genuinely ready. If a future change makes a repeated line block, this
     * fails loudly instead of shrugging.
     */
    public function testADuplicateReferenceWithTheSameFingerprintIsNotAContradiction(): void
    {
        $records   = $this->completeDataset();
        $records[] = $this->factory->orderFromPayload('local', $this->shapes['parentOrderPayload']());

        $report = $this->validator->validate($this->manifestFor($records), $records);

        $this->assertNotContains(ClosureReport::CODE_DUPLICATE_REFERENCE, $report->reasonCodes());
        $this->assertTrue(
            $report->isComplete(),
            'Unexpected failures: ' . implode(', ', $report->reasonCodes()),
        );
    }

    // ──────────────────────────────────────────────
    // Source namespaces
    // ──────────────────────────────────────────────

    /**
     * The collision the `source_key` column was added to the schema to prevent,
     * reintroduced one layer up. Two WooCommerce installs hand out the same
     * small integers; a `lapka-klub` subscription whose parent order is 880001
     * must not resolve against `local`'s order 880001 and pass.
     *
     * Section 6.1: references are `(sourceKey, kind, sourceRef)`, never bare
     * integers.
     */
    public function testASubscriptionDoesNotResolveAgainstAnotherSourcesRecords(): void
    {
        // A complete `local` dataset, plus one club subscription whose customer,
        // product and orders exist only under `local`.
        $records   = $this->completeDataset();
        $records[] = $this->factory->subscriptionFromPayload('lapka-klub', $this->shapes['subscriptionPayload']([
            'source_subscription_id' => 910_777,
            'related_orders'         => [['source_order_id' => 880_001, 'relationship' => 'parent']],
            'source_payment_count'   => 1,
        ]));

        $report = $this->validator->validate($this->manifestFor($records), $records);

        $failures = static fn (string $code): array => array_values(array_filter(
            $report->failuresFor($code),
            static fn (array $failure): bool => $failure['source_key'] === 'lapka-klub',
        ));

        $this->assertNotSame([], $failures(ClosureReport::CODE_MISSING_CUSTOMER));
        $this->assertNotSame([], $failures(ClosureReport::CODE_MISSING_PRODUCT));
        $this->assertNotSame([], $failures(ClosureReport::CODE_MISSING_PARENT_ORDER));
        $this->assertNotSame([], $failures(ClosureReport::CODE_MISSING_RELATED_ORDER));
    }

    /**
     * And the mirror bug: order number 880001 on two different sites is two
     * different orders, so nothing is being shared and nothing may be blocked
     * for sharing it.
     */
    public function testTwoSourcesUsingTheSameParentOrderNumberDoNotFalselyShareIt(): void
    {
        $records   = $this->completeDataset();
        $records[] = $this->factory->subscriptionFromPayload('lapka-klub', $this->shapes['subscriptionPayload']([
            'source_subscription_id' => 910_777,
        ]));

        $report = $this->validator->validate($this->manifestFor($records), $records);

        $this->assertNotContains(
            ClosureReport::CODE_SHARED_PARENT_ORDER,
            $report->reasonCodes(),
            'Order 880001 on the club site is not order 880001 on the shop site.',
        );
    }

    /**
     * A dataset carrying records from a source its manifest does not name is
     * not the dataset it claims to be. One failure per foreign record — flagged
     * and still indexed, so it does not cascade into a pile of consequential
     * missing-dependency failures.
     */
    public function testARecordWhoseSourceKeyDisagreesWithTheManifestBlocks(): void
    {
        $records = $this->completeDataset();

        $foreign = $this->factory->customerFromPayload('lapka-klub', $this->shapes['customerPayload']([
            'source_user_id' => 660_777,
            'email'          => 'club-660777@example.invalid',
        ]));

        $records[] = $foreign;

        $report = $this->validator->validate($this->manifestFor($records), $records);

        $this->assertFalse($report->isComplete());

        $failures = $report->failuresFor(ClosureReport::CODE_FOREIGN_SOURCE_KEY);

        $this->assertCount(1, $failures);
        $this->assertSame('lapka-klub', $failures[0]['source_key']);
        $this->assertSame('local', $failures[0]['context']['manifest_source_key']);
    }

    /**
     * A record class this validator does not know cannot be checked, and must
     * not be filed as whatever the last match arm happened to be — which is
     * what `default => $subscriptions[] = $record` did.
     */
    public function testAnUnknownRecordTypeIsReportedRatherThanFiledAsASubscription(): void
    {
        $records   = $this->completeDataset();
        $records[] = new \stdClass();

        $report = $this->validator->validate($this->manifestFor($records), $records);

        $this->assertFalse($report->isComplete());

        $failures = $report->failuresFor(ClosureReport::CODE_INVALID_SOURCE_RECORD);

        $this->assertCount(1, $failures);
        $this->assertSame('stdClass', $failures[0]['context']['record_type']);
    }

    /**
     * A mangled record blocks the dataset it arrives in, and its failure carries
     * the encoding code rather than being mistaken for a missing reference.
     */
    public function testAMangledRecordBlocksTheDatasetWithTheEncodingCode(): void
    {
        $records   = $this->completeDataset();
        $records[] = $this->factory->subscriptionFromPayload('local', $this->shapes['subscriptionPayload']([
            'source_subscription_id' => 910_888,
            'billing_identity'       => ['city' => "Krak\xC3\x28w"],
        ]));

        $report = $this->validator->validate($this->manifestFor($records), $records);

        $this->assertFalse($report->isComplete());

        $failures = $report->failuresFor(ClosureReport::CODE_INVALID_SOURCE_RECORD);

        $this->assertCount(1, $failures);
        $this->assertSame(
            [ClosureReport::CODE_SOURCE_ENCODING_INVALID],
            $failures[0]['context']['reason_codes'],
        );
        $this->assertNotSame('', $report->fingerprint());
    }

    /**
     * Two source subscriptions pointing at one parent order. FluentCart's
     * renewal service assumes one subscription per parent order, so allocating
     * a shared one is out of scope for this implementation — and picking a
     * winner silently is worse than stopping.
     */
    public function testTwoSubscriptionsSharingOneParentOrderBlock(): void
    {
        $records   = $this->completeDataset();
        $records[] = $this->subscription([
            'source_ref'             => 'subscription:910099',
            'source_subscription_id' => 910_099,
            'related_orders'         => [['source_order_id' => 880_001, 'relationship' => 'parent']],
            'source_payment_count'   => 1,
        ]);

        $report = $this->validator->validate($this->manifestFor($records), $records);

        $this->assertFalse($report->isComplete());
        $this->assertContains(ClosureReport::CODE_SHARED_PARENT_ORDER, $report->reasonCodes());

        // And it is a SET-level fault, which is the distinction `stage` refuses
        // on: no per-record gate can see it, because both subscriptions assess
        // perfectly ready on their own.
        $this->assertTrue($report->hasSetLevelFault());
        $this->assertNotSame([], $report->setLevelFailures());
    }

    /**
     * The other side of that distinction, and the reason it had to exist.
     *
     * `isComplete()` is `failures === []`, and the reference dataset contains
     * exactly one malformed subscription — so a gate keyed on `isComplete()`
     * would report a permanent red for a cohort the plan expects to migrate 563
     * of 564. Section 6.2 forces the affected ENTITY to blocked, not the
     * package.
     */
    public function testAMalformedRecordIsNotASetLevelFault(): void
    {
        $records   = $this->completeDataset();
        $records[] = $this->factory->subscriptionFromWoo('local', $this->shapes['malformedNoItemNoParent']());

        $report = $this->validator->validate($this->manifestFor($records), $records);

        $this->assertFalse($report->isComplete());
        $this->assertContains(ClosureReport::CODE_INVALID_SOURCE_RECORD, $report->reasonCodes());
        $this->assertFalse($report->hasSetLevelFault());
        $this->assertSame([], $report->setLevelFailures());
    }

    // ──────────────────────────────────────────────
    // Counts
    // ──────────────────────────────────────────────

    public function testAManifestCountMismatchBlocks(): void
    {
        $records = $this->completeDataset();

        $manifest = $this->manifestFor($records, ['counts' => [
            'customer' => 1, 'product' => 1, 'order' => 9, 'subscription' => 1,
        ]]);

        $report = $this->validator->validate($manifest, $records);

        $this->assertFalse($report->isComplete());
        $this->assertContains(ClosureReport::CODE_COUNT_MISMATCH, $report->reasonCodes());
    }

    /**
     * WCS says how many payments it took. The included succeeded, positive
     * charge evidence says how many CartShift can prove. When those disagree
     * the answer is to say so, not to write whichever number is handier into
     * `bill_count`.
     */
    public function testAPaymentCountThatDisagreesWithTheIncludedHistoryIsExplicit(): void
    {
        $records = $this->completeDataset(subscriptionOverrides: ['source_payment_count' => 9]);

        $report = $this->validator->validate($this->manifestFor($records), $records);

        $this->assertFalse($report->isComplete());
        $this->assertContains(ClosureReport::CODE_HISTORY_COUNT_MISMATCH, $report->reasonCodes());
    }

    // ──────────────────────────────────────────────
    // The consumed free cycle
    // ──────────────────────────────────────────────

    /**
     * THE 230, AND THE VOCABULARY THAT ALREADY EXISTED FOR THEM.
     *
     * WCS's `get_payment_count()` counts PAID orders. FluentCart's
     * `calculateBillCount()` counts succeeded charges with `total > 0`. A parent
     * order that settled for 0.00 is therefore one payment to WCS and no charge
     * at all to FluentCart, and both are right about their own question. The
     * subscriber received a billing period and no money moved: that is a
     * consumed free cycle, and section 10 calls the correction for it
     * `billed_cycles_offset`.
     *
     * On the Lapka source this is not a corner case. 230 subscriptions have a
     * zero-total paid parent, and those are exactly the 230
     * `history_count_mismatch` failures — verified 1:1 across the whole
     * population, against 245 with a non-succeeded renewal and 110 with both.
     * The correlation names the cause.
     */
    public function testAZeroTotalPaidParentIsAConsumedCycleAndReconciles(): void
    {
        $records = $this->completeDataset(
            subscriptionOverrides: ['source_payment_count' => 2],
            parentOrderOverrides: [
                'transactions' => [],
                'totals'       => ['subtotal' => 0, 'tax' => 0, 'total' => 0, 'refunded' => 0],
                'dates'        => ['created_utc' => '2023-04-11 09:15:00', 'paid_utc' => '2023-04-11 09:15:00'],
            ],
        );

        $report = $this->validator->validate($this->manifestFor($records), $records);

        $this->assertNotContains(
            ClosureReport::CODE_HISTORY_COUNT_MISMATCH,
            $report->reasonCodes(),
            'One provable charge plus one consumed free cycle is two cycles, which is what WCS counted.',
        );
    }

    /**
     * THE BOUNDARY, AND IT IS THE WHOLE POINT.
     *
     * Unpaid means no cycle was consumed. An offset here would not translate
     * between two counting systems, it would invent a billing period that never
     * happened — the exact forgery section 10 spends a page forbidding. So the
     * mismatch stands, and the reported offset is zero.
     */
    public function testAZeroTotalParentThatWasNeverPaidEarnsNoOffset(): void
    {
        $records = $this->completeDataset(
            subscriptionOverrides: ['source_payment_count' => 2],
            parentOrderOverrides: [
                'transactions' => [],
                'totals'       => ['subtotal' => 0, 'tax' => 0, 'total' => 0, 'refunded' => 0],
                'dates'        => ['created_utc' => '2023-04-11 09:15:00', 'paid_utc' => null],
            ],
        );

        $report = $this->validator->validate($this->manifestFor($records), $records);

        $this->assertContains(ClosureReport::CODE_HISTORY_COUNT_MISMATCH, $report->reasonCodes());
        $this->assertSame(
            ['billed_cycles_offset' => 0, 'included_paid_orders' => 1, 'source_payment_count' => 2],
            $this->historyMismatchContext($report),
            'No settlement, no consumed cycle, no correction.',
        );
    }

    /**
     * A parent order that charged real money needs no translating: FluentCart
     * can see that charge perfectly well, and adding an offset on top would
     * count the same cycle twice.
     */
    public function testAPositiveTotalPaidParentEarnsNoOffset(): void
    {
        $records = $this->completeDataset(subscriptionOverrides: ['source_payment_count' => 3]);

        $report = $this->validator->validate($this->manifestFor($records), $records);

        $this->assertContains(ClosureReport::CODE_HISTORY_COUNT_MISMATCH, $report->reasonCodes());
        $this->assertSame(
            ['billed_cycles_offset' => 0, 'included_paid_orders' => 2, 'source_payment_count' => 3],
            $this->historyMismatchContext($report),
            'Both orders carried a charge, so both are already counted.',
        );
    }

    /**
     * The remaining live Lapka mismatch: three completed zero-total cycles.
     *
     * Every correction is still earned order by order. The wider offset is not
     * inferred from the gap: each parent/renewal exists, has a paid date, and
     * settled for zero. An unpaid zero-total renewal remains worth nothing.
     */
    public function testEveryPaidZeroTotalCycleContributesToTheOffset(): void
    {
        $records = $this->completeDataset(
            subscriptionOverrides: [
                'source_payment_count' => 3,
                'related_orders' => [
                    ['source_order_id' => 880_001, 'relationship' => 'parent'],
                    ['source_order_id' => 880_501, 'relationship' => 'renewal'],
                    ['source_order_id' => 880_502, 'relationship' => 'renewal'],
                ],
            ],
            renewalOrderOverrides: [
                'transactions' => [],
                'totals'       => ['subtotal' => 0, 'tax' => 0, 'total' => 0, 'refunded' => 0],
                'dates'        => ['created_utc' => '2024-04-11 09:15:00', 'paid_utc' => '2024-04-11 09:15:00'],
            ],
            parentOrderOverrides: [
                'transactions' => [],
                'totals'       => ['subtotal' => 0, 'tax' => 0, 'total' => 0, 'refunded' => 0],
                'dates'        => ['created_utc' => '2023-04-11 09:15:00', 'paid_utc' => '2023-04-11 09:15:00'],
            ],
        );
        $records[] = $this->factory->orderFromPayload('local', $this->shapes['renewalOrderPayload']([
            'source_ref'      => 'order:880502',
            'source_order_id' => 880_502,
            'transactions'    => [],
            'totals'          => ['subtotal' => 0, 'tax' => 0, 'total' => 0, 'refunded' => 0],
            'dates'           => ['created_utc' => '2025-04-11 09:15:00', 'paid_utc' => '2025-04-11 09:15:00'],
        ]));

        $report = $this->validator->validate($this->manifestFor($records), $records);

        $this->assertNotContains(
            ClosureReport::CODE_HISTORY_COUNT_MISMATCH,
            $report->reasonCodes(),
            'Three independently evidenced free cycles are three consumed cycles, not one guessed correction.',
        );
    }

    // ──────────────────────────────────────────────
    // Invalid records stay in the totals
    // ──────────────────────────────────────────────

    public function testAnInvalidRecordBlocksWithoutDisappearingFromTheCounts(): void
    {
        $records   = $this->completeDataset();
        $records[] = $this->factory->subscriptionFromWoo('local', $this->shapes['malformedNoItemNoParent']());

        $report = $this->validator->validate($this->manifestFor($records), $records);

        $this->assertFalse($report->isComplete());
        $this->assertContains(ClosureReport::CODE_INVALID_SOURCE_RECORD, $report->reasonCodes());
        $this->assertSame(
            2,
            $report->countFor('subscription'),
            'An invalid subscription is still a selected subscription; dropping it from the total is how 564 becomes 563.',
        );
    }

    /**
     * The headline number, held end to end: 564 selected, 563 decodable, one
     * malformed, and the total still says 564 on both sides of the package.
     */
    public function test564SelectedSubscriptionsStayAt564WithExactlyOneBlockedRecord(): void
    {
        $records  = $this->lapkaSizedDataset();
        $manifest = $this->manifestFor($records);

        $report = $this->validator->validate($manifest, $records);

        $this->assertSame(564, $manifest->countFor('subscription'));
        $this->assertSame(1, $manifest->invalidCount);
        $this->assertSame(564, $report->countFor('subscription'));
        $this->assertFalse($report->isComplete());
        $this->assertSame(
            [ClosureReport::CODE_INVALID_SOURCE_RECORD],
            $report->reasonCodes(),
            'One malformed record is one blocker, not a cascade of consequential ones.',
        );
        $this->assertCount(1, $report->failuresFor(ClosureReport::CODE_INVALID_SOURCE_RECORD));
    }

    /**
     * And the same, decided identically after a package round trip. A dataset
     * that passes in the source runtime and fails in the target runtime is
     * worse than one that fails in both.
     */
    public function testThePackageRoundTripReachesTheSameVerdict(): void
    {
        $records = $this->lapkaSizedDataset();

        $decoded = array_map(
            fn (object $record): object => $this->factory->fromEnvelope(SubscriptionRecordFactory::envelope($record)),
            $records,
        );

        $live    = $this->validator->validate($this->manifestFor($records), $records);
        $package = $this->validator->validate($this->manifestFor($decoded), $decoded);

        $this->assertSame($live->toArray(), $package->toArray());
    }

    // ──────────────────────────────────────────────
    // Report shape
    // ──────────────────────────────────────────────

    public function testEveryFailureNamesTheRecordItIsAbout(): void
    {
        $records = $this->completeDataset(withoutOrderIds: [880_001]);

        $failure = $this->validator
            ->validate($this->manifestFor($records), $records)
            ->failuresFor(ClosureReport::CODE_MISSING_PARENT_ORDER)[0];

        $this->assertSame('local', $failure['source_key']);
        $this->assertSame('subscription', $failure['kind']);
        $this->assertSame('subscription:910001', $failure['source_ref']);
        $this->assertSame(880_001, $failure['context']['source_order_id']);
    }

    public function testIsCompleteIsTheOnlyReadyResult(): void
    {
        $records = $this->completeDataset(without: [CustomerRecord::class]);
        $report  = $this->validator->validate($this->manifestFor($records), $records);

        $this->assertFalse($report->isComplete());
        $this->assertNotSame([], $report->failures);
    }

    public function testTwoRunsOverTheSameDatasetProduceTheSameReport(): void
    {
        $records = $this->completeDataset(withoutOrderIds: [880_001, 880_501]);

        $first  = $this->validator->validate($this->manifestFor($records), $records);
        $second = $this->validator->validate($this->manifestFor(array_reverse($records)), array_reverse($records));

        $this->assertSame($first->toArray(), $second->toArray());
        $this->assertSame($first->fingerprint(), $second->fingerprint());
    }

    // ──────────────────────────────────────────────
    // Builders
    // ──────────────────────────────────────────────

    /**
     * The context of the one `history_count_mismatch` a report carries.
     *
     * @return array<string, mixed>
     */
    private function historyMismatchContext(ClosureReport $report): array
    {
        foreach ($report->failures as $failure) {
            if ($failure['code'] === ClosureReport::CODE_HISTORY_COUNT_MISMATCH) {
                return $failure['context'];
            }
        }

        $this->fail('The report carries no history_count_mismatch to read a context from.');
    }

    /**
     * @param list<class-string>   $without
     * @param list<int>            $withoutOrderIds
     * @param array<string, mixed> $subscriptionOverrides
     * @param array<string, mixed> $productOverrides
     * @param array<string, mixed> $renewalOrderOverrides
     * @param array<string, mixed> $parentOrderOverrides
     * @return list<object>
     */
    private function completeDataset(
        array $without = [],
        array $withoutOrderIds = [],
        array $subscriptionOverrides = [],
        array $productOverrides = [],
        array $renewalOrderOverrides = [],
        array $parentOrderOverrides = [],
    ): array {
        $records = [];

        if (!in_array(CustomerRecord::class, $without, true)) {
            $records[] = $this->factory->customerFromPayload('local', $this->shapes['customerPayload']());
        }

        if (!in_array(ProductRecord::class, $without, true)) {
            $records[] = $this->factory->productFromPayload(
                'local',
                $this->shapes['monthlyProductPayload']($productOverrides),
            );
        }

        if (!in_array(880_001, $withoutOrderIds, true)) {
            $records[] = $this->factory->orderFromPayload(
                'local',
                $this->shapes['parentOrderPayload']($parentOrderOverrides),
            );
        }

        if (!in_array(880_501, $withoutOrderIds, true)) {
            $records[] = $this->factory->orderFromPayload(
                'local',
                $this->shapes['renewalOrderPayload']($renewalOrderOverrides),
            );
        }

        $records[] = $this->subscription($subscriptionOverrides);

        return $records;
    }

    /** @param array<string, mixed> $overrides */
    private function subscription(array $overrides = []): SubscriptionRecord|InvalidSourceRecord
    {
        return $this->factory->subscriptionFromPayload('local', $this->shapes['subscriptionPayload']($overrides));
    }

    /**
     * 564 subscriptions, 563 of them decodable, each with its own customer and
     * its own parent order, all on one source product. Generated rather than
     * enumerated — the point is the arithmetic, not 564 hand-written fixtures.
     *
     * @return list<object>
     */
    private function lapkaSizedDataset(): array
    {
        $records = [$this->factory->productFromPayload('local', $this->shapes['monthlyProductPayload']())];

        for ($index = 1; $index <= 564; $index++) {
            $userId  = 660_000 + $index;
            $orderId = 880_000 + $index;
            $email   = "subscriber-{$userId}@example.invalid";

            $records[] = $this->factory->customerFromPayload('local', $this->shapes['customerPayload']([
                'source_ref'     => "customer:{$userId}",
                'source_user_id' => $userId,
                'email'          => $email,
            ]));

            // The 564th is the malformed active record: no parent order, no
            // line item, blank gateway. It therefore has no parent order to
            // include either — inventing one is precisely what is forbidden.
            $malformed = $index === 564;

            if (!$malformed) {
                $records[] = $this->factory->orderFromPayload('local', $this->shapes['parentOrderPayload']([
                    'source_ref'          => "order:{$orderId}",
                    'source_order_id'     => $orderId,
                    'source_customer_ref' => "customer:{$userId}",
                    'billing_email'       => $email,
                    'transactions'        => [[
                        'source_transaction_id' => "txn-fixture-{$orderId}",
                        'type'                  => 'charge',
                        'status'                => 'succeeded',
                        'total'                 => 2900,
                        'currency'              => 'PLN',
                        'gateway'               => 'stripe',
                        'paid_at_utc'           => '2023-04-11 09:15:00',
                    ]],
                ]));
            }

            $records[] = $this->subscription([
                'source_ref'             => 'subscription:' . (910_000 + $index),
                'source_subscription_id' => 910_000 + $index,
                'source_customer_ref'    => "customer:{$userId}",
                'source_customer_id'     => $userId,
                'billing_email'          => $email,
                'parent_order_id'        => $malformed ? 0 : $orderId,
                'items'                  => $malformed ? [] : $this->shapes['subscriptionPayload']()['items'],
                'gateway'                => $malformed ? '' : 'stripe',
                'related_orders'         => $malformed
                    ? []
                    : [['source_order_id' => $orderId, 'relationship' => 'parent']],
                'source_payment_count'   => $malformed ? 0 : 1,
            ]);
        }

        return $records;
    }

    /**
     * A manifest that agrees with the records unless a test says otherwise.
     *
     * @param list<object>         $records
     * @param array<string, mixed> $overrides
     */
    private function manifestFor(array $records, array $overrides = []): DatasetManifest
    {
        $counts  = ['customer' => 0, 'product' => 0, 'order' => 0, 'subscription' => 0];
        $invalid = 0;

        foreach ($records as $record) {
            // A record of a type the manifest has no bucket for is not counted,
            // exactly as a real exporter could not have written it. The
            // unknown-record test relies on this: it is the validator's job to
            // notice, not the manifest builder's to pre-empt.
            if (!method_exists($record, 'kind')) {
                continue;
            }

            $kind = $record instanceof InvalidSourceRecord ? $record->entityKind : $record->kind();
            $counts[$kind] = ($counts[$kind] ?? 0) + 1;

            if ($record instanceof InvalidSourceRecord) {
                $invalid++;
            }
        }

        $defaults = [
            'schema_version'        => 1,
            'source_key'            => 'local',
            'storage_authority'     => 'cpt',
            'currencies'            => ['PLN'],
            'exported_at_utc'       => '2026-08-09 10:00:00',
            'versions'              => ['cartshift' => '1.4.1', 'woocommerce' => '11.0.0', 'wcs' => '8.7.1'],
            'selection_fingerprint' => (new SubscriptionSelection('local'))->fingerprint(),
            'counts'                => $counts,
            'invalid_count'         => $invalid,
            'total_records'         => array_sum($counts),
            'records_checksum'      => str_repeat('a', 64),
        ];

        $merged = array_merge($defaults, $overrides);

        if (isset($overrides['counts'])) {
            $merged['total_records'] = array_sum($overrides['counts']);
        }

        return DatasetManifest::fromArray($merged);
    }
}

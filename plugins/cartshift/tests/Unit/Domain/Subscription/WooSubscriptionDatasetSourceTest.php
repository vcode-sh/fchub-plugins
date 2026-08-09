<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Subscription;

use CartShift\Domain\Subscription\CustomerRecord;
use CartShift\Domain\Subscription\DatasetClosureValidator;
use CartShift\Domain\Subscription\InvalidSourceRecord;
use CartShift\Domain\Subscription\OrderRecord;
use CartShift\Domain\Subscription\ProductRecord;
use CartShift\Domain\Subscription\Source\WooSubscriptionDatasetSource;
use CartShift\Domain\Subscription\SubscriptionOrderReference;
use CartShift\Domain\Subscription\SubscriptionRecord;
use CartShift\Domain\Subscription\SubscriptionSelection;
use CartShift\Tests\Unit\PluginTestCase;

require_once dirname(__DIR__, 3) . '/stubs/PreflightStubs.php';
require_once dirname(__DIR__, 3) . '/stubs/EntityMigratorStubs.php';

/**
 * The live source, read through WooCommerce's own public APIs.
 *
 * The point of every test here is that WooCommerce chooses its storage backend
 * and CartShift does not. Lapka's authoritative store is legacy CPT, the plan
 * forbids forcing HPOS, and the previous reader hard-coded the HPOS table — so
 * the first thing proved is that flipping the backend changes the reported
 * authority and nothing else. The records, and their fingerprints, are
 * identical.
 */
final class WooSubscriptionDatasetSourceTest extends PluginTestCase
{
    private const string SOURCE_KEY = 'lapka-club';

    /** @var array<string, callable> */
    private array $shapes;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->shapes = require dirname(__DIR__, 3) . '/fixtures/lapka-subscription-shapes.php';

        $GLOBALS['_cartshift_test_hpos_enabled'] = false;
        $GLOBALS['_cartshift_test_get_results_callback'] = static fn (): array => [];
        $GLOBALS['_cartshift_test_get_var_callback'] = static fn (): string => '';
    }

    // ──────────────────────────────────────────────
    // Storage neutrality
    // ──────────────────────────────────────────────

    /**
     * WHAT THIS PROVES, AND WHAT IT DOES NOT.
     *
     * It flips the HPOS flag over one stub dataset and requires the records to
     * come out identical, which proves that record building never branches on
     * the storage flag — the specific defect being removed, where the reader
     * hard-coded the HPOS table and a legacy-CPT store therefore read nothing.
     *
     * It is NOT backend parity evidence. There is no second backend here: the
     * same doubles answer both runs, because WooCommerce Subscriptions is not
     * installed on this machine and cannot be. Real parity — the same 564 rows
     * out of a real CPT store and a real HPOS store — can only come from the
     * Lapka rehearsal, and plan section 4.9 already says those two backends
     * disagree about two records. Do not read this test as saying otherwise.
     */
    public function testLegacyCptAndHposProduceIdenticalRecords(): void
    {
        $GLOBALS['_cartshift_test_hpos_enabled'] = false;
        $this->seedSource([$this->shapes['typedRelatedOrders']()]);
        $cpt = $this->source();

        $this->assertSame('posts', $cpt->storageAuthority());
        $cptRecords = $this->fingerprints($cpt);

        $GLOBALS['_cartshift_test_hpos_enabled'] = true;
        $this->seedSource([$this->shapes['typedRelatedOrders']()]);
        $hpos = $this->source();

        $this->assertSame('hpos', $hpos->storageAuthority());

        $this->assertSame(
            $cptRecords,
            $this->fingerprints($hpos),
            'The public data-store API is the whole point: the backend must not change the records.',
        );
    }

    public function testTheManifestCarriesTheStorageAuthorityAndTheSourceKey(): void
    {
        $this->seedSource([$this->shapes['typedRelatedOrders']()]);

        $manifest = $this->source()->manifest();

        $this->assertSame(self::SOURCE_KEY, $manifest->sourceKey);
        $this->assertSame('posts', $manifest->storageAuthority);
        $this->assertSame(1, $manifest->countFor(SubscriptionRecord::KIND));
    }

    public function testEveryEmittedRecordCarriesTheManifestSourceKey(): void
    {
        $this->seedSource([$this->shapes['typedRelatedOrders']()]);

        $source = $this->source();
        $manifest = $source->manifest();

        foreach ($this->records($source) as $record) {
            $this->assertSame(
                $manifest->sourceKey,
                $record->sourceKey,
                'A record whose source key disagrees with the manifest resolves against the wrong source.',
            );
        }
    }

    // ──────────────────────────────────────────────
    // Relationship types
    // ──────────────────────────────────────────────

    public function testAllFourRelationshipTypesSurviveWithTheirLabels(): void
    {
        $this->seedSource([$this->shapes['typedRelatedOrders']()]);

        $subscription = $this->onlySubscription($this->source());

        $this->assertSame([880_030], $subscription->relatedOrderIds(SubscriptionOrderReference::PARENT));
        $this->assertSame([880_531, 880_532], $subscription->relatedOrderIds(SubscriptionOrderReference::RENEWAL));
        $this->assertSame([880_631], $subscription->relatedOrderIds(SubscriptionOrderReference::SWITCH));
        $this->assertSame([880_731], $subscription->relatedOrderIds(SubscriptionOrderReference::RESUBSCRIBE));
    }

    public function testEveryRelatedOrderArrivesAsAFullOrderRecord(): void
    {
        $this->seedSource([$this->shapes['typedRelatedOrders']()]);

        $orders = [];

        foreach ($this->records($this->source()) as $record) {
            if ($record instanceof OrderRecord) {
                $orders[$record->sourceOrderId] = $record;
            }
        }

        foreach ([880_030, 880_531, 880_532, 880_631, 880_731] as $orderId) {
            $this->assertArrayHasKey(
                $orderId,
                $orders,
                'A subscription reference is not an order — the payload has to be there.',
            );
        }
    }

    public function testAnOrderClaimedByTwoRelationshipTypesIsAmbiguousRatherThanFirstWins(): void
    {
        $this->seedSource([$this->shapes['ambiguousRelatedOrder']()]);

        $report = (new DatasetClosureValidator())->validate(
            $this->source()->manifest(),
            $this->records($this->source()),
        );

        $this->assertContains(
            'dataset_ambiguous_order_relationship',
            $report->reasonCodes(),
            'Whichever type was iterated first must not silently decide the relationship.',
        );
    }

    public function testAUniqueRelatedOrderIsHydratedOncePerRunNotOncePerType(): void
    {
        $this->seedSource([$this->shapes['typedRelatedOrders']()]);

        $GLOBALS['_cartshift_test_wc_order_lookups'] = 0;

        $this->records($this->source());

        $this->assertSame(
            5,
            $GLOBALS['_cartshift_test_wc_order_lookups'],
            'Five unique related orders, five hydrations.',
        );
    }

    // ──────────────────────────────────────────────
    // Dependency closure
    // ──────────────────────────────────────────────

    public function testAWellFormedSubscriptionProducesACompleteClosure(): void
    {
        $this->seedSource([$this->shapes['typedRelatedOrders']()]);

        $source = $this->source();
        $report = (new DatasetClosureValidator())->validate($source->manifest(), $this->records($source));

        $this->assertSame(
            [],
            $report->failures,
            'The closure must hold on the live source, not only after a package round trip.',
        );
    }

    public function testTheClosureCarriesCustomerProductAndOrderPayloads(): void
    {
        $this->seedSource([$this->shapes['typedRelatedOrders']()]);

        $kinds = [];

        foreach ($this->records($this->source()) as $record) {
            $kinds[$record->kind()] = true;
        }

        foreach (
            [
                CustomerRecord::KIND,
                ProductRecord::KIND,
                OrderRecord::KIND,
                SubscriptionRecord::KIND,
            ] as $kind
        ) {
            $this->assertArrayHasKey($kind, $kinds, sprintf('The dataset must emit %s payloads.', $kind));
        }
    }

    public function testAGuestKeepsItsOwnEmailDerivedIdentity(): void
    {
        $this->seedSource([$this->shapes['guestCustomer']()]);

        $customers = [];

        foreach ($this->records($this->source()) as $record) {
            if ($record instanceof CustomerRecord) {
                $customers[] = $record;
            }
        }

        $this->assertCount(1, $customers);
        $this->assertNull($customers[0]->sourceUserId);
        $this->assertStringStartsWith('guest:', $customers[0]->sourceRef);
    }

    // ──────────────────────────────────────────────
    // The malformed record
    // ──────────────────────────────────────────────

    public function testTheMalformedRecordIsOneBlockedInvalidRecordAndStaysInTheCount(): void
    {
        $this->seedSource([
            $this->shapes['typedRelatedOrders'](),
            $this->shapes['malformedNoItemNoParent'](),
        ]);

        $source = $this->source();
        $manifest = $source->manifest();

        $this->assertSame(
            2,
            $manifest->countFor(SubscriptionRecord::KIND),
            'The malformed record is still one of the selected subscriptions.',
        );
        $this->assertSame(1, $manifest->invalidCount);

        $invalid = [];

        foreach ($this->records($source) as $record) {
            if ($record instanceof InvalidSourceRecord) {
                $invalid[] = $record;
            }
        }

        $this->assertCount(1, $invalid);
        $this->assertSame(SubscriptionRecord::KIND, $invalid[0]->entityKind);
        $this->assertContains('required_reference_missing', $invalid[0]->reasonCodes);
    }

    // ──────────────────────────────────────────────
    // Selection: one logic for count and fetch
    // ──────────────────────────────────────────────

    public function testCountAndFetchConsumeTheSameSelection(): void
    {
        $this->seedSource([
            $this->shapes['typedRelatedOrders'](),
            $this->shapes['cancelled'](),
            $this->shapes['guestCustomer'](),
        ]);

        $source = $this->source();
        $selection = new SubscriptionSelection(self::SOURCE_KEY, [], ['active']);

        $this->assertSame(2, $source->countSelected($selection));
        $this->assertCount(2, $source->page($selection, 0, 50)['records']);
    }

    // ──────────────────────────────────────────────
    // Rows the source lists and cannot hand back
    // ──────────────────────────────────────────────

    public function testAPageReportsRowsConsumedRatherThanObjectsReturned(): void
    {
        $this->seedSource([
            $this->shapes['typedRelatedOrders'](),
            $this->shapes['guestCustomer'](),
            $this->shapes['cancelled'](),
        ]);

        // The middle row is listed by the selection and cannot be hydrated —
        // the case `build()` already handles by emitting an InvalidSourceRecord,
        // so it demonstrably occurs.
        $this->makeUnhydratable([910_006]);

        $page = $this->source()->page(SubscriptionSelection::all(self::SOURCE_KEY), 0, 3);

        $this->assertCount(2, $page['records']);
        $this->assertSame(
            3,
            $page['consumed'],
            'A cursor advanced by survivors starts the next page early and re-processes a subscription.',
        );
        $this->assertSame([910_006], $page['unhydratable']);
    }

    public function testAWholePageThatCannotHydrateKeepsLookingRatherThanEndingTheEntity(): void
    {
        $this->seedSource([
            $this->shapes['typedRelatedOrders'](),
            $this->shapes['guestCustomer'](),
            $this->shapes['cancelled'](),
        ]);

        // The first two rows of a two-row page. An empty batch is the
        // orchestrator's only end-of-entity signal, so returning one here would
        // end the migration and leave every later subscriber untouched — with a
        // green run to show for it.
        $this->makeUnhydratable([910_006, 910_013]);

        $page = $this->source()->page(new SubscriptionSelection(self::SOURCE_KEY, [910_006, 910_013, 910_030]), 0, 2);

        $this->assertCount(1, $page['records']);
        $this->assertSame(910_030, $page['records'][0]->get_id());
        $this->assertSame(3, $page['consumed']);
        $this->assertSame([910_006, 910_013], $page['unhydratable']);
    }

    public function testAnExhaustedSelectionIsTheOnlyWayToAnEmptyPage(): void
    {
        $this->seedSource([$this->shapes['typedRelatedOrders']()]);
        $this->makeUnhydratable([910_030]);

        $page = $this->source()->page(SubscriptionSelection::all(self::SOURCE_KEY), 0, 10);

        $this->assertSame([], $page['records']);
        $this->assertSame(1, $page['consumed']);
        $this->assertSame([910_030], $page['unhydratable']);
    }

    public function testCountingIssuesNoOrderTableQuery(): void
    {
        $this->seedSource([$this->shapes['typedRelatedOrders']()]);

        $GLOBALS['_cartshift_test_queries'] = [];

        $this->assertSame(1, $this->source()->countSelected(SubscriptionSelection::all(self::SOURCE_KEY)));

        $this->assertSame(
            [],
            $GLOBALS['_cartshift_test_queries'],
            'Counting goes through the public API, so it issues no SQL at all — least of all against wc_orders.',
        );
    }

    public function testPagingIsStableAcrossTheSelection(): void
    {
        $this->seedSource([
            $this->shapes['typedRelatedOrders'](),
            $this->shapes['guestCustomer'](),
            $this->shapes['cancelled'](),
        ]);

        $source = $this->source();
        $selection = SubscriptionSelection::all(self::SOURCE_KEY);

        $first = $source->page($selection, 0, 2)['records'];
        $second = $source->page($selection, 2, 2)['records'];

        $this->assertCount(2, $first);
        $this->assertCount(1, $second);

        $ids = array_map(static fn (object $s): int => $s->get_id(), [...$first, ...$second]);

        $this->assertSame(array_unique($ids), $ids, 'A page must not repeat a subscription.');
    }

    // ──────────────────────────────────────────────
    // Storage mirror: reported, never adopted
    // ──────────────────────────────────────────────

    public function testADivergentMirrorIsReportedAndNotAdopted(): void
    {
        $this->seedSource([$this->shapes['typedRelatedOrders']()]);

        // The plan's audit finding, reproduced: HPOS holds a next-payment date
        // exactly 365 days from the authoritative one, and a retry schedule the
        // legacy store has never heard of.
        $this->seedMirror([
            910_030 => [
                '_schedule_next_payment'  => '2100-05-11 09:15:00',
                '_schedule_payment_retry' => '2099-05-18 09:15:00',
            ],
        ]);

        $report = $this->source()->storageMirrorReport(SubscriptionSelection::all(self::SOURCE_KEY));

        $this->assertSame('posts', $report['authority']);
        $this->assertSame('hpos', $report['mirror']);
        $this->assertSame(
            [
                [
                    'authority'  => '2099-05-11 09:15:00',
                    'field'      => 'next_payment',
                    'mirror'     => '2100-05-11 09:15:00',
                    'source_ref' => 'subscription:910030',
                ],
                [
                    'authority'  => null,
                    'field'      => 'payment_retry',
                    'mirror'     => '2099-05-18 09:15:00',
                    'source_ref' => 'subscription:910030',
                ],
            ],
            $report['discrepancies'],
        );

        $this->assertSame(
            '2099-05-11 09:15:00',
            $this->onlySubscription($this->source())->dates->nextPaymentUtc,
            'The mirror is evidence for a report, never a value to adopt.',
        );
    }

    public function testAnAgreeingMirrorProducesNoDiscrepancies(): void
    {
        $this->seedSource([$this->shapes['typedRelatedOrders']()]);
        $this->seedMirror([910_030 => ['_schedule_next_payment' => '2099-05-11 09:15:00']]);

        $report = $this->source()->storageMirrorReport(SubscriptionSelection::all(self::SOURCE_KEY));

        $this->assertSame([], $report['discrepancies']);

        // And the field nobody could say anything about is named, rather than
        // being folded into that empty list as though it had agreed.
        $this->assertSame(['payment_retry'], $report['unverified_fields']);
        $this->assertSame(['next_payment' => 1, 'payment_retry' => 0], $report['mirror_values_found']);
    }

    /**
     * A field absent from both sides is a question nobody answered.
     *
     * This is the `payment_retry` case exactly. Plan section 4.9 records two
     * Stripe retry values existing ONLY in the HPOS mirror, and WooCommerce
     * Subscriptions is not on this machine, so `_schedule_payment_retry` is
     * convention rather than verified contract. If that literal is wrong the
     * mirror read returns nothing, the authority returns nothing, and a plain
     * equality check reports a clean bill of health for the very finding the
     * comparison exists to surface.
     */
    public function testAFieldMissingFromBothSidesIsUnverifiedRatherThanAgreed(): void
    {
        $this->seedSource([$this->shapes['typedRelatedOrders']()]);

        // A mirror that holds neither key: whatever else is true, nothing was
        // learned about either field from it.
        $this->seedMirror([910_030 => ['_schedule_something_else' => '2099-01-01 00:00:00']]);

        $report = $this->source()->storageMirrorReport(SubscriptionSelection::all(self::SOURCE_KEY));

        $this->assertTrue($report['mirror_present']);
        $this->assertSame(
            ['payment_retry'],
            $report['unverified_fields'],
            'next_payment has an authority value, so its absence from the mirror IS a discrepancy; '
            . 'payment_retry has neither side and is therefore unverified.',
        );
        $this->assertSame(['next_payment' => 1, 'payment_retry' => 0], $report['authority_values_found']);
        $this->assertSame(['next_payment' => 0, 'payment_retry' => 0], $report['mirror_values_found']);
    }

    public function testAMirrorThatWasNeverReadVerifiesNothing(): void
    {
        $this->seedSource([$this->shapes['typedRelatedOrders']()]);

        $report = $this->source()->storageMirrorReport(SubscriptionSelection::all(self::SOURCE_KEY));

        $this->assertFalse($report['mirror_present']);
        $this->assertSame(
            ['next_payment', 'payment_retry'],
            $report['unverified_fields'],
            'No mirror means no field was checked. An empty discrepancy list must not read as agreement.',
        );
    }

    public function testNoMirrorIsReportedAsAbsenceRatherThanAgreement(): void
    {
        $this->seedSource([$this->shapes['typedRelatedOrders']()]);

        $GLOBALS['_cartshift_test_get_var_callback'] = static fn (): string => '';
        $GLOBALS['_cartshift_test_get_results_callback'] = static fn (): array => [];

        $report = $this->source()->storageMirrorReport(SubscriptionSelection::all(self::SOURCE_KEY));

        $this->assertFalse($report['mirror_present']);
        $this->assertSame([], $report['discrepancies']);
    }

    // ──────────────────────────────────────────────
    // The WCS not-set sentinel
    // ──────────────────────────────────────────────

    /**
     * THE ZERO-CUSTOMERS SYMPTOM, PINNED AT THE LAYER THAT CAUSED IT.
     *
     * The source emits a customer only for a subscription that decoded — see
     * `dataset()`, where `$customers[...] ??=` sits inside the valid-record arm.
     * So when `WC_Subscription::get_date()`'s integer-`0` sentinel made every
     * subscription `required_reference_missing`, the package came out with 564
     * invalid records and NOT ONE customer, which is what the first live export
     * against Lapka actually produced.
     *
     * The two counts are therefore one fact, and this test states it that way:
     * an ordinary subscription with no trial and no end date decodes, and its
     * customer comes with it.
     */
    public function testASubscriptionWithUnsetOptionalDatesStillBringsItsCustomer(): void
    {
        $subscription = $this->shapes['typedRelatedOrders']();

        $this->assertSame(0, $subscription->get_date('trial_end'), 'Precondition: WCS says "not set" with a 0.');
        $this->assertSame(0, $subscription->get_date('cancelled'));

        $this->seedSource([$subscription]);

        $subscriptions = [];
        $customers     = [];

        foreach ($this->records($this->source()) as $record) {
            if ($record instanceof SubscriptionRecord) {
                $subscriptions[] = $record;
            }

            if ($record instanceof CustomerRecord) {
                $customers[] = $record;
            }

            $this->assertNotInstanceOf(
                InvalidSourceRecord::class,
                $record,
                'An unset trial date is not a malformed source row.',
            );
        }

        $this->assertCount(1, $subscriptions);
        $this->assertCount(1, $customers, 'No customer is emitted for a subscription that did not decode.');
        $this->assertSame($subscriptions[0]->sourceCustomerRef, $customers[0]->sourceRef);
    }

    /**
     * The same sentinel, in the mirror comparison.
     *
     * The authority side used to be read with a bare `(string)` cast, so an
     * unset date arrived as `'0'` while the absent mirror value arrived as null
     * — and every subscription without a retry schedule was reported as storage
     * drift. Both sides go through the one normaliser now, so absence on both
     * sides is agreement, not a discrepancy.
     */
    public function testAnUnsetAuthorityDateIsAbsenceRatherThanStorageDrift(): void
    {
        $subscription = $this->shapes['typedRelatedOrders']();

        $this->assertSame(0, $subscription->get_date('payment_retry'), 'Precondition: the authority holds no retry.');

        $this->seedSource([$subscription]);
        $this->seedMirror([910_030 => ['_schedule_next_payment' => '2099-05-11 09:15:00']]);

        $report = $this->source()->storageMirrorReport(SubscriptionSelection::all(self::SOURCE_KEY));

        $this->assertSame([], $report['discrepancies'], 'A `0` on one side and nothing on the other is not drift.');
        $this->assertSame(['next_payment' => 1, 'payment_retry' => 0], $report['authority_values_found']);
    }

    /**
     * And a mirror row that stores the sentinel as a literal `'0'` is read the
     * same way, or the comparison would report drift in the other direction.
     */
    public function testAMirrorRowHoldingTheSentinelIsReadAsAbsenceToo(): void
    {
        $this->seedSource([$this->shapes['typedRelatedOrders']()]);
        $this->seedMirror([
            910_030 => [
                '_schedule_next_payment'  => '2099-05-11 09:15:00',
                '_schedule_payment_retry' => '0',
            ],
        ]);

        $report = $this->source()->storageMirrorReport(SubscriptionSelection::all(self::SOURCE_KEY));

        $this->assertSame([], $report['discrepancies']);
        $this->assertSame(['next_payment' => 1, 'payment_retry' => 0], $report['mirror_values_found']);
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    private function source(): WooSubscriptionDatasetSource
    {
        return new WooSubscriptionDatasetSource(self::SOURCE_KEY);
    }

    /**
     * @param list<object> $subscriptions
     */
    private function seedSource(array $subscriptions): void
    {
        $GLOBALS['_cartshift_test_wcs_pages'] = $subscriptions;
        $GLOBALS['_cartshift_test_wc_orders'] = [];
        $GLOBALS['_cartshift_test_wc_products'] = [];

        foreach ($subscriptions as $subscription) {
            foreach (SubscriptionOrderReference::RELATIONSHIPS as $relationship) {
                foreach ($subscription->get_related_orders('all', $relationship) as $order) {
                    $GLOBALS['_cartshift_test_wc_orders'][$order->get_id()] = $order;
                }
            }

            foreach ($subscription->get_items() as $item) {
                $productId = $item->get_product_id();

                if ($productId > 0) {
                    $GLOBALS['_cartshift_test_wc_products'][$productId] =
                        new \CartShiftLapkaProduct($productId, $item->get_name());
                }
            }
        }
    }

    /**
     * IDs `wcs_get_subscriptions()` still lists and `wcs_get_subscription()` refuses.
     *
     * @param list<int> $ids
     */
    private function makeUnhydratable(array $ids): void
    {
        $GLOBALS['_cartshift_test_wcs_unhydratable'] = $ids;
    }

    /**
     * @param array<int, array<string, string>> $meta
     */
    private function seedMirror(array $meta): void
    {
        $GLOBALS['_cartshift_test_get_var_callback'] = static fn (): string => 'wp_wc_orders_meta';

        $GLOBALS['_cartshift_test_get_results_callback'] = static function () use ($meta): array {
            $rows = [];

            foreach ($meta as $orderId => $pairs) {
                foreach ($pairs as $key => $value) {
                    $rows[] = (object) [
                        'object_id'  => $orderId,
                        'meta_key'   => $key,
                        'meta_value' => $value,
                    ];
                }
            }

            return $rows;
        };
    }

    /**
     * @return list<CustomerRecord|ProductRecord|OrderRecord|SubscriptionRecord|InvalidSourceRecord>
     */
    private function records(WooSubscriptionDatasetSource $source): array
    {
        return iterator_to_array(
            $source->records(SubscriptionSelection::all(self::SOURCE_KEY)),
            false,
        );
    }

    /**
     * @return list<string>
     */
    private function fingerprints(WooSubscriptionDatasetSource $source): array
    {
        $fingerprints = array_map(
            static fn (object $record): string => $record->fingerprint,
            $this->records($source),
        );

        sort($fingerprints);

        return $fingerprints;
    }

    private function onlySubscription(WooSubscriptionDatasetSource $source): SubscriptionRecord
    {
        foreach ($this->records($source) as $record) {
            if ($record instanceof SubscriptionRecord) {
                return $record;
            }
        }

        $this->fail('The source produced no subscription record.');
    }
}

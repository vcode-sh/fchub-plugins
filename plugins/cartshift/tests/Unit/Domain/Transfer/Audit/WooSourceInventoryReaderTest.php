<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Audit;

use CartShift\Domain\Transfer\Audit\CptStorageIntegrityReader;
use CartShift\Domain\Transfer\Audit\HposStorageIntegrityReader;
use CartShift\Domain\Transfer\Audit\WooSourceApi;
use CartShift\Domain\Transfer\Audit\WooSourceInventoryReader;
use CartShift\Domain\Transfer\Audit\WooStorageIntegrityReader;
use CartShift\Domain\Transfer\SelectionClause;
use CartShift\Domain\Transfer\TransferSelection;
use CartShift\Tests\Unit\PluginTestCase;

require_once dirname(__DIR__, 4) . '/fixtures/full-transfer-shapes.php';

final class WooSourceInventoryReaderTest extends PluginTestCase
{
    public function testInventoryCountsUnsupportedProductsAndDependentOrders(): void
    {
        $report = $this->readerForFixture('custom-course-with-history')->inspect($this->fullSelection());

        self::assertSame(2, $report->capabilities['product_types']['course']);
        self::assertSame(41, $report->capabilities['unsupported_type_orders']['course']);
        self::assertFalse($report->isReady());
        self::assertContains('unsupported_product_type', $this->codes($report->blockers));
        self::assertSame(
            41,
            count(array_filter(
                $report->blockers,
                static fn (array $blocker): bool => $blocker['code'] === 'unsupported_product_dependency',
            )),
            'Each blocked order needs its own reviewable allowed-loss decision.',
        );
    }

    public function testLookupTableCannotRemoveAProductFromTheCensus(): void
    {
        $report = $this->readerForFixture('published-product-without-lookup-row')->inspect($this->fullSelection());

        self::assertSame(1, $report->counts['products_considered']);
        self::assertSame(0, $report->counts['products_unaccounted']);
        self::assertSame(1, $report->capabilities['lookup_integrity']['missing']);
        self::assertSame(1, $report->capabilities['lookup_integrity']['stale']);
    }

    public function testFailedHydrationIsABlockingOutcomeNotCursorProgress(): void
    {
        $report = $this->readerForFixture('selected-product-fails-hydration')->inspect($this->fullSelection());

        self::assertSame(1, $report->counts['products_considered']);
        self::assertSame(1, $report->counts['products_blocked_hydration']);
        self::assertSame(1, $report->counts['products_blocked']);
        self::assertContains('product_hydration_failed', $this->codes($report->blockers));
    }

    public function testDeletedRawProductReferencesBecomePerLinePlaceholderBlockers(): void
    {
        $fixture = cartshift_full_transfer_shapes()['published-product-without-lookup-row'];
        $fixture['order_pages'] = [[9468], []];
        $fixture['orders'] = [9468 => [
            'id' => 9468,
            'status' => 'completed',
            'modified_gmt' => '2026-08-10T10:00:00Z',
            'product_ids' => [],
            'missing_product_refs' => [
                ['line_id' => 73, 'product_id' => 9467, 'line_shape' => ['name' => 'Deleted A', 'sku' => '', 'unit_total' => 2500, 'currency' => 'PLN']],
                ['line_id' => 74, 'product_id' => 9466, 'line_shape' => ['name' => 'Deleted B', 'sku' => '', 'unit_total' => 3000, 'currency' => 'PLN']],
            ],
            'has_fee' => false,
            'has_coupon' => false,
            'has_shipping' => false,
            'tax_rate_count' => 0,
            'refund_state' => 'none',
        ]];
        $report = $this->readerForData($fixture)->inspect($this->fullSelection());
        $findings = array_values(array_filter(
            $report->blockers,
            static fn (array $blocker): bool => $blocker['code'] === 'historical_product_missing',
        ));

        self::assertSame([
            'lapka-web:order:9468:item:73',
            'lapka-web:order:9468:item:74',
        ], array_column($findings, 'identity'));
        self::assertSame('lapka-web:product:9467', $findings[0]['context']['placeholder_identity']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $findings[0]['context']['placeholder_fingerprint']);
        self::assertSame(1, $report->counts['orders_blocked']);
        self::assertSame(0, $report->counts['orders_exported']);
    }

    public function testFullShapeMatrixReportsEveryRecognisedCapabilityFamily(): void
    {
        $report = $this->readerForFixture('full-shape-matrix')->inspect($this->fullSelection());

        self::assertSame(
            [
                'product_types',
                'product_statuses',
                'attribute_contracts',
                'price_contracts',
                'tax_classes',
                'stock_contracts',
                'download_contracts',
                'media_contracts',
                'catalogue_contracts',
                'relationship_contracts',
                'order_statuses',
                'order_shapes',
                'subscription_relationships',
                'subscription_statuses',
                'subscription_schedules',
                'lookup_integrity',
                'semantic_enumeration',
                'storage_integrity',
                'unsupported_type_orders',
                'target_schema',
            ],
            array_keys($report->capabilities),
        );
        self::assertSame(1, $report->capabilities['price_contracts']['explicit_zero_regular']);
        self::assertSame(1, $report->capabilities['price_contracts']['explicit_zero_sale']);
        self::assertSame(1, $report->capabilities['price_contracts']['scheduled_sale']);
        self::assertSame(1, $report->capabilities['attribute_contracts']['wildcard']);
        self::assertSame(1, $report->capabilities['stock_contracts']['parent_owned']);
        self::assertSame(1, $report->capabilities['download_contracts']['missing']);
        self::assertSame(1, $report->capabilities['order_shapes']['partial_refund']);
        self::assertSame(1, $report->capabilities['order_shapes']['full_refund']);
        self::assertSame(1, $report->capabilities['order_shapes']['multi_tax']);
        self::assertSame(1, $report->capabilities['subscription_relationships']['parent']);
        self::assertSame(1, $report->capabilities['subscription_relationships']['renewal']);
        self::assertSame(1, $report->capabilities['order_shapes']['refund_records']);
        self::assertSame(1, $report->capabilities['product_statuses']['pending']);
        self::assertSame(1, $report->capabilities['product_statuses']['trash']);
        self::assertSame(1, $report->capabilities['product_statuses']['training-review']);
        self::assertSame(1, $report->capabilities['order_statuses']['pending']);
        self::assertSame(1, $report->capabilities['order_statuses']['failed']);
    }

    public function testSkuOverflowAndActiveSubscriptionDateAbsenceAreExplicitFindings(): void
    {
        $fixture = cartshift_full_transfer_shapes()['full-shape-matrix'];
        $fixture['products'][1]['sku_length'] = 31;
        $fixture['subscriptions'][301] += [
            'status' => 'active',
            'has_next_payment' => false,
            'has_end' => false,
            'source_gateway' => 'stripe',
            'requires_manual_renewal' => false,
        ];
        $report = $this->readerForData($fixture)->inspect($this->fullSelection());

        self::assertSame(1, $report->capabilities['target_schema']['sku_over_limit']);
        self::assertContains('target_schema_unrepresentable', $this->codes($report->blockers));
        self::assertContains('unrepresentable_product_dependency', $this->codes($report->blockers));
        self::assertSame(1, $report->capabilities['subscription_schedules']['active_missing_next_payment']);
        self::assertSame(1, $report->capabilities['subscription_schedules']['active_missing_end']);
        self::assertContains('subscription_schedule_absence', $this->codes($report->blockers));
        self::assertContains('subscription_payment_ownership_unassessed', $this->codes($report->blockers));
    }

    public function testDownloadableProductOrderCountIsRecordedOncePerOrder(): void
    {
        $fixture = cartshift_full_transfer_shapes()['full-shape-matrix'];
        foreach ($fixture['products'] as &$product) {
            $product['downloads'] = [];
        }
        unset($product);
        $fixture['orders'][201]['product_ids'] = [1, 2];
        $fixture['products'][1]['downloads'] = ['local'];
        $fixture['products'][2]['downloads'] = ['remote'];
        $report = $this->readerForData($fixture)->inspect($this->fullSelection());

        self::assertSame(1, $report->capabilities['order_shapes']['downloadable_product_orders']);
    }

    public function testOrderNotesAreAVisibilityDecisionRatherThanSilentlyFlattened(): void
    {
        $fixture = cartshift_full_transfer_shapes()['full-shape-matrix'];
        $fixture['orders'][201]['note_count'] = 3;
        $fixture['orders'][201]['customer_visible_note_count'] = 1;

        $report = $this->readerForData($fixture)->inspect($this->fullSelection());
        $finding = current(array_filter(
            $report->blockers,
            static fn (array $row): bool => $row['code'] === 'order_note_visibility_decision_required',
        ));

        self::assertIsArray($finding);
        self::assertSame(3, $finding['context']['note_count']);
        self::assertSame(1, $finding['context']['customer_visible_note_count']);
        self::assertSame(1, $report->capabilities['order_shapes']['orders_with_notes']);
    }

    public function testSelectionExclusionIsExplicitAndCardinalityReconciles(): void
    {
        $selection = new TransferSelection(
            'lapka-web',
            SelectionClause::ids([1, 3]),
            SelectionClause::none(),
            SelectionClause::none(),
            SelectionClause::none(),
        );
        $report = $this->readerForFixture('full-shape-matrix')->inspect($selection);

        self::assertSame(8, $report->counts['product_census_ids']);
        self::assertSame(2, $report->counts['products_considered']);
        self::assertSame(1, $report->counts['products_exported']);
        self::assertSame(1, $report->counts['products_blocked']);
        self::assertSame(6, $report->counts['products_excluded']);
        self::assertContains('product_relation_loss_decision_required', $this->codes($report->blockers));
        self::assertSame(
            $report->counts['product_census_ids'],
            $report->counts['products_exported']
                + $report->counts['products_excluded']
                + $report->counts['products_blocked'],
        );
    }

    public function testDuplicateCensusIdentityBlocksInsteadOfBeingSilentlyDeduplicated(): void
    {
        $fixture = cartshift_full_transfer_shapes()['published-product-without-lookup-row'];
        $fixture['product_pages'] = [[1], [1], []];
        $report = $this->readerForData($fixture)->inspect($this->fullSelection());

        self::assertSame(1, $report->counts['product_duplicates']);
        self::assertContains('product_census_duplicate', $this->codes($report->blockers));
        self::assertFalse($report->isReady());
    }

    public function testHposAndCptIntegrityReadersReturnTheSameSemanticOrphanFinding(): void
    {
        $rows = [
            ['item_id' => 501, 'parent_id' => 999, 'item_type' => 'line_item', 'parent_type' => null],
        ];
        $hpos = new HposStorageIntegrityReader(static fn (): array => $rows);
        $cpt = new CptStorageIntegrityReader(static fn (): array => $rows);

        self::assertSame($hpos->inspect('lapka-web'), $cpt->inspect('lapka-web'));
        self::assertSame('order_item_parent_missing', $hpos->inspect('lapka-web')[0]['code']);
        self::assertSame('lapka-web:order:999:item:501', $hpos->inspect('lapka-web')[0]['identity']);
    }

    public function testIntegrityQueriesTreatRefundItemsAsOwnedRowsRatherThanOrphans(): void
    {
        $GLOBALS['_cartshift_test_get_results_callback'] = static fn (): array => [];

        (new HposStorageIntegrityReader())->inspect('lapka-web');
        (new CptStorageIntegrityReader())->inspect('lapka-web');

        $queries = array_values(array_map(
            static fn (array $entry): string => (string) ($entry[1] ?? ''),
            array_filter(
                $GLOBALS['_cartshift_test_queries'],
                static fn (array $entry): bool => ($entry[0] ?? null) === 'get_results',
            ),
        ));
        self::assertCount(2, $queries);

        foreach ($queries as $query) {
            self::assertStringContainsString("'shop_order_refund'", $query);
        }
    }

    public function testReportFingerprintIsDeterministicButBindsRuntimeAndSelection(): void
    {
        $left = $this->readerForFixture('full-shape-matrix', 'runtime-a')->inspect($this->fullSelection());
        $same = $this->readerForFixture('full-shape-matrix', 'runtime-a')->inspect($this->fullSelection());
        $drifted = $this->readerForFixture('full-shape-matrix', 'runtime-b')->inspect($this->fullSelection());

        self::assertSame($left->fingerprint, $same->fingerprint);
        self::assertNotSame($left->fingerprint, $drifted->fingerprint);
        self::assertSame($this->fullSelection()->fingerprint(), $left->selectionFingerprint);
    }

    private function fullSelection(): TransferSelection
    {
        return new TransferSelection(
            'lapka-web',
            SelectionClause::all(),
            SelectionClause::all(),
            SelectionClause::all(),
            SelectionClause::all(),
        );
    }

    private function readerForFixture(string $name, string $runtime = 'runtime-fixture'): WooSourceInventoryReader
    {
        return $this->readerForData(cartshift_full_transfer_shapes()[$name], $runtime);
    }

    /** @param array<string, mixed> $fixture */
    private function readerForData(array $fixture, string $runtime = 'runtime-fixture'): WooSourceInventoryReader
    {
        return new WooSourceInventoryReader(
            new FixtureWooSourceApi($fixture),
            new FixtureStorageIntegrityReader(),
            $runtime,
            pageSize: 2,
        );
    }

    /**
     * @param list<array{code: string, identity: string, context: array<string, scalar|null>}> $blockers
     * @return list<string>
     */
    private function codes(array $blockers): array
    {
        return array_column($blockers, 'code');
    }
}

final class FixtureWooSourceApi implements WooSourceApi
{
    /** @var array<string, int> */
    private array $pageCalls = [];

    /** @param array<string, mixed> $fixture */
    public function __construct(private readonly array $fixture) {}

    public function productCensusPage(int $page, int $limit): array
    {
        return $this->page('product_pages', $page);
    }

    public function semanticProductIds(): array
    {
        return $this->fixture['semantic_product_ids'];
    }

    public function lookupProductIds(): array
    {
        return $this->fixture['lookup_product_ids'];
    }

    public function product(int $id): ?array
    {
        return $this->fixture['products'][$id] ?? null;
    }

    public function orderCensusPage(int $page, int $limit): array
    {
        return $this->page('order_pages', $page);
    }

    public function order(int $id): ?array
    {
        return $this->fixture['orders'][$id] ?? null;
    }

    public function subscriptionCensusPage(int $page, int $limit): array
    {
        return $this->page('subscription_pages', $page);
    }

    public function subscription(int $id): ?array
    {
        return $this->fixture['subscriptions'][$id] ?? null;
    }

    /** @return list<int> */
    private function page(string $key, int $page): array
    {
        $this->pageCalls[$key] = ($this->pageCalls[$key] ?? 0) + 1;

        if ($this->pageCalls[$key] !== $page) {
            throw new \RuntimeException('Reader did not request pages monotonically.');
        }

        return $this->fixture[$key][$page - 1] ?? [];
    }
}

final class FixtureStorageIntegrityReader implements WooStorageIntegrityReader
{
    public function inspect(string $sourceKey): array
    {
        return [];
    }
}

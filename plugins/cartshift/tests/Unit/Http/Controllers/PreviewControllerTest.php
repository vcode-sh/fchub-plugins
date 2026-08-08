<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Http\Controllers;

use CartShift\Core\Container;
use CartShift\Domain\Scope\ScopeResolver;
use CartShift\Http\Controllers\PreviewController;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\ProductTypes;
use CartShift\Tests\Unit\PluginTestCase;
use WP_REST_Request;

require_once dirname(__DIR__, 3) . '/stubs/HttpCliStubs.php';

/**
 * Covers the two hard requirements on POST /preview: it never starts a
 * migration and it never returns a record, only counts and consequences.
 */
final class PreviewControllerTest extends PluginTestCase
{
    private ?\wpdb $originalWpdb = null;

    #[\Override]
    protected function tearDown(): void
    {
        if ($this->originalWpdb !== null) {
            $GLOBALS['wpdb'] = $this->originalWpdb;
            $this->originalWpdb = null;
        }

        unset(
            $GLOBALS['_cartshift_test_get_var_callback'],
            $GLOBALS['_cartshift_test_get_results_callback'],
        );

        parent::tearDown();
    }

    public function testPreviewReturnsCountsWithoutStartingAnything(): void
    {
        $response = $this->controller()->preview($this->request(['scope' => ['mode' => 'everything']]));
        $data = $response->get_data()['data'];

        $this->assertArrayHasKey('counts', $data);
        $this->assertArrayHasKey('consequences', $data);
        $this->assertSame('everything', $data['scope']['mode']);
        $this->assertFalse($data['too_large']);

        // The one thing preview must never do.
        $this->assertSame('idle', (new MigrationState())->getProgress()['status']);
    }

    public function testAnInvalidScopeIsNormalisedRatherThanRejected(): void
    {
        $response = $this->controller()->preview($this->request(['scope' => ['mode' => 'nonsense']]));

        $this->assertSame(200, $response->get_status());
        $this->assertSame('everything', $response->get_data()['data']['scope']['mode']);
    }

    public function testAnOversizedClosureIsReportedNotTruncated(): void
    {
        // too_large is a flag on a 200, not an error: the owner is still
        // choosing, and a 500 in the middle of a selection screen tells them
        // nothing about what to change.
        $this->stubClosureBuyers(range(1, ScopeResolver::MAX_CLOSURE_IDS + 1));

        $data = $this->controller()->preview($this->request([
            'scope' => [
                'mode'                        => 'explicit',
                'product_ids'                 => [12],
                'include_orders_for_products' => true,
            ],
        ]))->get_data()['data'];

        $this->assertTrue($data['too_large']);
        $this->assertSame('idle', (new MigrationState())->getProgress()['status']);
    }

    public function testPreviewCountsOnlyTheRequestedEntityTypes(): void
    {
        $response = $this->controller()->preview($this->request([
            'scope'        => ['mode' => 'everything'],
            'entity_types' => ['product', 'coupon'],
        ]));
        $counts = $response->get_data()['data']['counts'];

        $this->assertArrayHasKey('product', $counts);
        $this->assertArrayHasKey('coupon', $counts);
        $this->assertArrayNotHasKey('customer', $counts);
        $this->assertArrayNotHasKey('order', $counts);
        $this->assertArrayNotHasKey('subscription', $counts);
    }

    public function testProductSearchMatchesTitleAndSku(): void
    {
        $this->stubSearchResults(
            productRows: [['id' => 12, 'title' => 'Blue Hoodie', 'sku' => 'HOOD-1']],
        );

        $results = $this->controller()->search($this->request(['type' => 'product', 'q' => 'hood']))
            ->get_data()['data']['results'];

        $this->assertSame('product', $results[0]['kind']);
        $this->assertArrayHasKey('sublabel', $results[0]);
        $this->assertSame('12', $results[0]['id']);
        $this->assertSame('SKU HOOD-1', $results[0]['sublabel']);
    }

    public function testCustomerSearchDistinguishesRegisteredFromGuest(): void
    {
        $this->stubSearchResults(
            registeredRows: [['id' => 7, 'display_name' => 'Bob Smith', 'email' => 'bob@example.com']],
            guestRows: [['email' => 'b@x', 'order_count' => 3]],
        );

        $results = $this->controller()->search($this->request(['type' => 'customer', 'q' => 'bob']))
            ->get_data()['data']['results'];

        $kinds = array_column($results, 'kind');

        $this->assertContains('registered', $kinds);
        $this->assertContains('guest', $kinds);

        // Registered first, matching the merge order the search is built on.
        $this->assertSame('registered', $results[0]['kind']);
        $this->assertSame('7', $results[0]['id']);
        $this->assertSame('bob@example.com', $results[0]['sublabel']);

        $this->assertSame('guest', $results[1]['kind']);
        $this->assertSame('b@x', $results[1]['id']);
        $this->assertSame('guest, 3 orders', $results[1]['sublabel']);
    }

    public function testAnEmptyQueryReturnsNothingRatherThanTheWholeShop(): void
    {
        $data = $this->controller()->search($this->request(['type' => 'product', 'q' => '']))
            ->get_data()['data'];

        $this->assertSame([], $data['results']);
        $this->assertFalse($data['truncated']);
    }

    public function testAnUnknownTypeIsRejected(): void
    {
        $this->assertSame(
            422,
            $this->controller()->search($this->request(['type' => 'orders', 'q' => 'x']))->get_status(),
        );
    }

    public function testSearchResultsAreClampedAndReportTruncation(): void
    {
        $this->stubSearchResults(
            productRows: [
                ['id' => 1, 'title' => 'Hoodie One', 'sku' => ''],
                ['id' => 2, 'title' => 'Hoodie Two', 'sku' => ''],
                ['id' => 3, 'title' => 'Hoodie Three', 'sku' => ''],
            ],
        );

        $data = $this->controller()->search($this->request(['type' => 'product', 'q' => 'hoodie', 'limit' => 2]))
            ->get_data()['data'];

        $this->assertCount(2, $data['results']);
        $this->assertTrue($data['truncated']);
    }

    /**
     * A product of a type ProductMigrator cannot source — a LearnDash
     * `course`, in the real store this is a regression test for — must not
     * appear in the picker at all. Showing it lets the owner pick it, and
     * ScopeResolver's closure does not know about product types either, so
     * the pick travels silently into MigrationScope::productIds() and only
     * evaporates later as counts['product'] === 0 with no explanation.
     *
     * Simulates the type predicate for real: the fake product_type_counts
     * query reports one `course`-type product, and the fake results handler
     * for the main product query evaluates the predicate the code actually
     * sent against the fixture rows — so this fails if the predicate is ever
     * missing, not just if the fixture happens to agree with it.
     */
    public function testUnsupportedProductTypeIsExcludedFromSearch(): void
    {
        $this->stubProductTypeFixture(
            rows: [
                ['id' => 1, 'title' => 'Fleece Hoodie', 'sku' => 'HOOD-A', 'type' => 'simple'],
                ['id' => 2, 'title' => 'Hoodie Course', 'sku' => '', 'type' => 'course'],
            ],
            typeCounts: [['slug' => 'course', 'count' => 1]],
        );

        $results = $this->controller()->search($this->request(['type' => 'product', 'q' => 'hoodie']))
            ->get_data()['data']['results'];

        $this->assertNotContains('Hoodie Course', array_column($results, 'label'));
    }

    /**
     * The other half of the same test: the exclusion must not be so broad
     * that it drops a supported product whose title happens to look similar
     * to an excluded one.
     */
    public function testASupportedProductWithASimilarTitleStillMatches(): void
    {
        $this->stubProductTypeFixture(
            rows: [
                ['id' => 1, 'title' => 'Fleece Hoodie', 'sku' => 'HOOD-A', 'type' => 'simple'],
                ['id' => 2, 'title' => 'Hoodie Course', 'sku' => '', 'type' => 'course'],
            ],
            typeCounts: [['slug' => 'course', 'count' => 1]],
        );

        $results = $this->controller()->search($this->request(['type' => 'product', 'q' => 'hoodie']))
            ->get_data()['data']['results'];

        $this->assertContains('Fleece Hoodie', array_column($results, 'label'));
    }

    /**
     * A product with no `product_type` term is an ordinary simple product —
     * that is WooCommerce's own reading of a missing term — so the picker has
     * to offer it, and the migrator has to migrate it. Both halves matter and
     * they used to be answered differently: the picker's old `NOT IN
     * (unsupported slugs)` let such a product through, ProductMigrator's
     * positive `IN (supported slugs)` did not, and the owner picked something
     * that then evaporated as counts['product'] === 0.
     *
     * `'type' => null` is the fixture's spelling of "no term row at all"; see
     * stubProductTypeFixture(), which drops such a row unless the query really
     * carries the no-type branch.
     */
    public function testAProductWithNoTypeTermIsStillOfferedByThePicker(): void
    {
        $this->stubProductTypeFixture(
            rows: [
                ['id' => 1, 'title' => 'Hoodie Untyped', 'sku' => '', 'type' => null],
                ['id' => 2, 'title' => 'Hoodie Course', 'sku' => '', 'type' => 'course'],
            ],
            typeCounts: [['slug' => 'course', 'count' => 1]],
        );

        $labels = array_column(
            $this->controller()->search($this->request(['type' => 'product', 'q' => 'hoodie']))
                ->get_data()['data']['results'],
            'label',
        );

        $this->assertContains('Hoodie Untyped', $labels);
        $this->assertNotContains('Hoodie Course', $labels);
    }

    /**
     * The predicate is unconditional now, and that is the fix rather than an
     * oversight. It used to be added only when the catalogue happened to
     * contain an unsupported type, which meant the picker asked a different
     * question from the migrator on every store that had none — and the
     * migrator's question, the one that decides what actually travels, is
     * asked every time.
     */
    public function testTheTypePredicateIsAppliedEvenWithNothingUnsupportedInTheCatalogue(): void
    {
        $db = $this->stubSearchResults(productRows: [['id' => 1, 'title' => 'Hoodie', 'sku' => '']]);

        $this->controller()->search($this->request(['type' => 'product', 'q' => 'hoodie']));

        [$expected, $values] = ProductTypes::migratableClause('p.ID');

        $this->assertStringContainsString(
            $GLOBALS['wpdb']->prepare($expected, ...$values),
            $db->lastQuery,
            'The picker must ask the migrator\'s question, not a lookalike.',
        );
    }

    public function testSearchLimitIsClampedToFifty(): void
    {
        $db = $this->stubSearchResults(productRows: []);

        $this->controller()->search($this->request(['type' => 'product', 'q' => 'x', 'limit' => 9999]));

        // Asked for limit + 1 (51), to detect truncation without a second
        // COUNT query — never the raw 9999 the caller asked for.
        $this->assertStringContainsString('LIMIT 51', $db->lastQuery);
        $this->assertStringNotContainsString('10000', $db->lastQuery);
    }

    public function testProductSearchTermIsLikeEscaped(): void
    {
        $db = $this->stubSearchResults(productRows: []);

        $this->controller()->search($this->request(['type' => 'product', 'q' => '50%_off']));

        // esc_like() neutralises the wildcards in the typed term before it is
        // wrapped in the LIKE %...% pattern — a literal % or _ in the search
        // box must not act as a SQL wildcard.
        $this->assertStringContainsString("'%50\\%\\_off%'", $db->lastQuery);
    }

    public function testPreviewReportsTheAddedClosureNotTheRawClosureSize(): void
    {
        // A picked product with no closure hit at all: closedProductIds()
        // returns exactly what was picked, so the added count is zero rather
        // than the size of the pick itself.
        $data = $this->controller()->preview($this->request([
            'scope' => [
                'mode'        => 'explicit',
                'product_ids' => [12, 13],
            ],
        ]))->get_data()['data'];

        $this->assertSame(0, $data['closure']['products']);
        $this->assertSame(0, $data['closure']['customers']);
    }

    /**
     * Swap $GLOBALS['wpdb'] for one whose DISTINCT customer_id closure query
     * returns this many buyers, and restore the original in tearDown().
     *
     * @param list<int> $buyers
     */
    private function stubClosureBuyers(array $buyers): void
    {
        $this->originalWpdb ??= $GLOBALS['wpdb'];

        $GLOBALS['wpdb'] = new class ($buyers) extends \wpdb {
            /** @param list<int> $buyers */
            public function __construct(private readonly array $buyers)
            {
            }

            #[\Override]
            public function get_col(string $query): array
            {
                return str_contains($query, 'DISTINCT customer_id')
                    ? array_map(strval(...), $this->buyers)
                    : [];
            }
        };
    }

    /**
     * Swap $GLOBALS['wpdb'] for one whose get_results() is keyed on which
     * search query ran — product (the SKU join), registered customer (the
     * wp_users join, distinguished by selecting display_name) or guest (the
     * billing_email-only query, everything else) — and restore the original
     * in tearDown().
     *
     * @param list<array{id: int, title: string, sku: string}>            $productRows
     * @param list<array{id: int, display_name: string, email: string}>   $registeredRows
     * @param list<array{email: string, order_count: int}>                $guestRows
     */
    private function stubSearchResults(array $productRows = [], array $registeredRows = [], array $guestRows = []): object
    {
        $this->originalWpdb ??= $GLOBALS['wpdb'];

        $db = new class ($productRows, $registeredRows, $guestRows) extends \wpdb {
            public string $lastQuery = '';

            /**
             * @param list<array<string, int|string>> $productRows
             * @param list<array<string, int|string>> $registeredRows
             * @param list<array<string, int|string>> $guestRows
             */
            public function __construct(
                private readonly array $productRows,
                private readonly array $registeredRows,
                private readonly array $guestRows,
            ) {
            }

            #[\Override]
            public function get_results(string $query, string $output = OBJECT): array
            {
                $GLOBALS['_cartshift_test_queries'][] = ['get_results', $query, $output];
                $this->lastQuery = $query;

                if (str_contains($query, 'wc_product_meta_lookup')) {
                    return $this->productRows;
                }

                if (str_contains($query, 'display_name')) {
                    return $this->registeredRows;
                }

                return $this->guestRows;
            }
        };

        $GLOBALS['wpdb'] = $db;

        return $db;
    }

    /**
     * Drives search() through $GLOBALS['_cartshift_test_get_results_callback']
     * (the mechanism the rest of the suite uses for product_type_counts —
     * see PreflightCheckTest) rather than a custom wpdb subclass, because this
     * one has to behave like a real database: the product_type_counts branch
     * reports the configured type histogram, and the main product query
     * evaluates the type predicate PreviewController actually put in the query
     * against `$rows` before returning them — so a missing or wrong predicate
     * fails the test on its own, not on a fixture that was curated to already
     * look filtered.
     *
     * The predicate is positive now (ProductTypes::migratableClause: the slug
     * list in `t.slug IN (...)` is what CartShift CAN migrate, not what it
     * cannot) with a second branch for products carrying no `product_type`
     * term at all. A fixture row spells that state as `'type' => null`, and
     * this stub honours it exactly as the SQL does: kept, because WooCommerce
     * reads a missing term as `simple`.
     *
     * Cleared automatically: tearDown() already unsets
     * _cartshift_test_get_results_callback after every test.
     *
     * @param list<array{id: int, title: string, sku: string, type: string|null}> $rows
     * @param list<array{slug: string, count: int}>                               $typeCounts
     */
    private function stubProductTypeFixture(array $rows, array $typeCounts): void
    {
        $GLOBALS['_cartshift_test_get_results_callback'] = static function (string $query) use ($rows, $typeCounts): array {
            if (str_contains($query, 'GROUP BY t.slug')) {
                return array_map(static fn (array $row): object => (object) $row, $typeCounts);
            }

            if (!str_contains($query, 'wc_product_meta_lookup')) {
                return [];
            }

            $supportedTypes = [];

            if (preg_match('/t\.slug IN \(([^)]*)\)/', $query, $matches)) {
                $supportedTypes = array_map(
                    static fn (string $slug): string => trim($slug, " '"),
                    explode(',', $matches[1]),
                );
            }

            // The no-type branch of the real predicate. Without it in the
            // query, an untyped fixture row must disappear here too.
            $admitsUntyped = str_contains($query, "NOT IN (\n                        SELECT tr.object_id");

            return array_values(array_filter(
                $rows,
                static fn (array $row): bool => $row['type'] === null
                    ? $admitsUntyped
                    : in_array($row['type'], $supportedTypes, true),
            ));
        };
    }

    private function controller(): PreviewController
    {
        $container = new Container();
        $container->singleton(IdMapRepository::class, static fn (): IdMapRepository => new IdMapRepository());
        $container->singleton(
            MigrationLogRepository::class,
            static fn (): MigrationLogRepository => new MigrationLogRepository(),
        );
        $container->singleton(MigrationState::class, static fn (): MigrationState => new MigrationState());

        return new PreviewController($container);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function request(array $params): WP_REST_Request
    {
        $request = new WP_REST_Request();

        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        return $request;
    }
}

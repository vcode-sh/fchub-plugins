<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Http\Controllers;

use CartShift\Core\Container;
use CartShift\Domain\Scope\ScopeResolver;
use CartShift\Http\Controllers\PreviewController;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
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

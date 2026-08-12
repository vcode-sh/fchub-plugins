<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Http\Controllers;

use CartShift\Core\Container;
use CartShift\Domain\Mapping\ProductMapDecision;
use CartShift\Http\Controllers\MappingController;
use CartShift\Storage\ProductMapRepository;
use CartShift\Tests\Unit\PluginTestCase;
use WP_REST_Request;

/**
 * The controller is a thin seam: it sanitises, delegates, and wraps in
 * {data: …} because useApi() unwraps exactly that. The tests worth having are
 * about refusing rubbish, not about the happy path.
 *
 * ProductMapRepository is `final`, so it cannot be subclassed into a fake —
 * confirmed against the real class, and the same constraint
 * MappingPromoterTest.php and ProductMapRepositoryTest.php both work around.
 * The double here drives the real repository through the $wpdb stub's global
 * callbacks instead: an insert callback captures what save()/saveMany() write,
 * keyed by wc_id the way REPLACE INTO behaves, and $this->saved is rebuilt
 * from that table through ProductMapDecision::fromRow() after every write —
 * the same read path ProductMapRepository::all() itself uses.
 *
 * clear() needed one more thing the shared stub does not provide. save()
 * writes through $wpdb->insert()/replace(), which the stub already routes to
 * a configurable `_cartshift_test_insert_callback`; but ProductMapRepository::
 * clear() issues `TRUNCATE TABLE …` through $wpdb->query(), and the stub's
 * query() has no callback hook at all — it just logs and returns. So this
 * file swaps $GLOBALS['wpdb'] for a local subclass that adds one, the same
 * technique half a dozen other test files already use for gaps the shared
 * stub doesn't cover (see MigrationControllerTest::stubClosureBuyers() for
 * the closest sibling), and restores the original in tearDown().
 */
final class MappingControllerTest extends PluginTestCase
{
    private array $saved = [];

    private ?\wpdb $originalWpdb = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->saved = [];
    }

    /**
     * Restore the real $wpdb singleton.
     *
     * PluginTestCase clears every `_cartshift_test_*` global between tests,
     * which covers the insert/query callbacks and the backing rows this file
     * installs — but $GLOBALS['wpdb'] itself is not `_cartshift_test_*`
     * prefixed, so the swap in controller() would otherwise leak into
     * whichever test class runs next.
     */
    protected function tearDown(): void
    {
        if ($this->originalWpdb !== null) {
            $GLOBALS['wpdb'] = $this->originalWpdb;
            $this->originalWpdb = null;
        }

        parent::tearDown();
    }

    private function controller(): MappingController
    {
        $this->originalWpdb ??= $GLOBALS['wpdb'];

        $GLOBALS['_cartshift_test_product_map_rows'] = [];

        // save()/saveMany() -> $wpdb->replace() -> $wpdb->insert(), which the
        // shared stub already dispatches here. REPLACE semantics: keyed by
        // wc_id, a later write for the same product overwrites the earlier
        // one, mirroring the table's UNIQUE(wc_id).
        $GLOBALS['_cartshift_test_insert_callback'] = function (string $table, array $data): int {
            if (str_contains($table, 'cartshift_product_map')) {
                $GLOBALS['_cartshift_test_product_map_rows'][(int) $data['wc_id']] = $data;
                $this->resyncSaved();
            }

            return 1;
        };

        // clear() -> $wpdb->query(), which the shared stub does not route
        // anywhere — so a local wpdb subclass below adds a callback hook for
        // it, mirroring the one insert() already has. Both verbs are matched:
        // the repository issued a TRUNCATE until the source-key namespace made
        // it a scoped DELETE, and a harness that recognised only one would
        // report an empty table as full without saying why.
        //
        // The DELETE's WHERE is honoured rather than ignored, and an
        // unscoped DELETE deletes everything — which is what MySQL would do,
        // and is exactly why the fake alone proves nothing. A `clear()` that
        // lost its WHERE would still empty the fake and
        // testClearEmptiesTheTable would still pass, because that test's only
        // row is the one being deleted either way.
        //
        // So the fake is honest and the *test* carries the proof:
        // testClearIsScopedToOneSource seeds a row under a second source key
        // and asserts it survives. That fails the moment the scoping is
        // removed, whichever way the fake is written.
        $GLOBALS['_cartshift_test_query_callback'] = function (string $query): void {
            if (!str_contains($query, 'cartshift_product_map')) {
                return;
            }

            if (str_contains($query, 'TRUNCATE')) {
                $GLOBALS['_cartshift_test_product_map_rows'] = [];
                $this->resyncSaved();

                return;
            }

            if (!str_contains($query, 'DELETE FROM')) {
                return;
            }

            $sourceKey = preg_match("/source_key = '([^']*)'/", $query, $matches) === 1
                ? $matches[1]
                : null;

            $GLOBALS['_cartshift_test_product_map_rows'] = array_filter(
                $GLOBALS['_cartshift_test_product_map_rows'],
                // An unscoped DELETE keeps nothing. A scoped one keeps every
                // row belonging to another source.
                static fn (array $row): bool
                    => $sourceKey !== null && ($row['source_key'] ?? null) !== $sourceKey,
            );

            $this->resyncSaved();
        };

        // ProductMapRepository::all() reads the table back, and the whole-set
        // validation added for subscription mapping depends on it seeing what
        // earlier saves wrote. The shared stub has no read path for the fake
        // table, so this wraps whatever get_results() callback a fixture
        // already installed rather than replacing it — seedCatalogue() runs
        // before controller() in the rows tests and not at all in these.
        $existing = $GLOBALS['_cartshift_test_get_results_callback'] ?? null;

        $GLOBALS['_cartshift_test_get_results_callback'] = static function (string $query) use ($existing): array {
            if (str_contains($query, 'cartshift_product_map')) {
                $rows = $GLOBALS['_cartshift_test_product_map_rows'];
                ksort($rows);

                return array_values(array_map(static fn (array $row): object => (object) $row, $rows));
            }

            return $existing !== null ? $existing($query) : [];
        };

        $GLOBALS['wpdb'] = new class () extends \wpdb {
            #[\Override]
            public function query(string $query): int|false
            {
                $GLOBALS['_cartshift_test_queries'][] = ['query', $query];

                if (isset($GLOBALS['_cartshift_test_query_callback'])) {
                    ($GLOBALS['_cartshift_test_query_callback'])($query);
                }

                return 0;
            }
        };

        $container = new Container();
        $container->instance(ProductMapRepository::class, new ProductMapRepository());

        return new MappingController($container);
    }

    private function legacyMutation(string $method, WP_REST_Request $request): \WP_REST_Response
    {
        return $this->legacyMutationOn($this->controller(), $method, $request);
    }

    private function legacyMutationOn(
        MappingController $controller,
        string $method,
        WP_REST_Request $request,
    ): \WP_REST_Response {
        $reflection = new \ReflectionMethod($controller, $method);

        return $reflection->invoke($controller, $request);
    }

    /**
     * Rebuild $this->saved from the fake table, in the same shape
     * ProductMapRepository::all() itself would produce: ORDER BY wc_id ASC,
     * each row through ProductMapDecision::fromRow(). Called after every
     * write the fake table sees — insert or truncate alike — so the tests
     * can read $this->saved as a plain property and always see the
     * repository's current state.
     */
    private function resyncSaved(): void
    {
        $rows = $GLOBALS['_cartshift_test_product_map_rows'];
        ksort($rows);

        $this->saved = array_values(array_map(
            static fn (array $row): ProductMapDecision => ProductMapDecision::fromRow((object) $row),
            $rows,
        ));
    }

    private function request(array $params): WP_REST_Request
    {
        $request = new WP_REST_Request();

        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        return $request;
    }

    /**
     * Register a plain one-time WooCommerce product.
     *
     * The save gate re-reads the source product to verify its contracts, so a
     * decision about a `wc_id` no WooCommerce product answers to is now refused
     * with `required_reference_missing` — see
     * testALinkWhoseWooProductCannotBeReadIsRefused. Tests that are about
     * something else therefore have to give their product an existence.
     */
    private function registerWooProduct(int $id, string $name = 'Test Product'): void
    {
        $GLOBALS['_cartshift_test_wc_products'][$id] = $this->createWooProduct(['id' => $id, 'name' => $name]);
    }

    /**
     * The variant map is keyed by *source variation*, and for a simple product
     * that key is the product's own ID — the pseudo-variation shape
     * ProductMigrator, VariantResolver and the mapping screen all share. This
     * fixture used to say `11 => 501` against product 42, which is not a shape
     * WooCommerce can produce; it now says what the screen would actually post,
     * because the save gate checks that a named source variation belongs to the
     * product (see testAVariantMapKeyThatIsNotAVariationOfTheProductIsRefused).
     */
    public function testDecideSavesALink(): void
    {
        $this->registerWooProduct(42);

        $response = $this->legacyMutation('legacyDecide', $this->request([
            'wc_id'       => 42,
            'wc_type'     => 'simple',
            'decision'    => 'link',
            'fc_post_id'  => 900,
            'band'        => 'strong',
            'variant_map' => ['42' => '501'],
        ]));

        $this->assertSame(200, $response->get_status());
        $this->assertCount(1, $this->saved);
        $this->assertSame('link', $this->saved[0]->decision());
        $this->assertSame(900, $this->saved[0]->fcPostId());
        $this->assertSame([42 => 501], $this->saved[0]->variantMap());
    }

    public function testALinkWithoutATargetIsRefused(): void
    {
        $response = $this->legacyMutation('legacyDecide', $this->request([
            'wc_id'    => 42,
            'decision' => 'link',
        ]));

        $this->assertSame(422, $response->get_status());
        $this->assertSame([], $this->saved, 'A link with nowhere to point must never reach the table.');
    }

    public function testAnUnknownDecisionIsRefused(): void
    {
        $response = $this->legacyMutation('legacyDecide', $this->request([
            'wc_id'    => 42,
            'decision' => 'obliterate',
        ]));

        $this->assertSame(422, $response->get_status());
        $this->assertSame([], $this->saved);
    }

    public function testAMissingProductIsRefused(): void
    {
        $response = $this->legacyMutation('legacyDecide', $this->request([
            'wc_id'    => 0,
            'decision' => 'skip',
        ]));

        $this->assertSame(422, $response->get_status());
        $this->assertSame([], $this->saved);
    }

    public function testBulkSavesEveryRow(): void
    {
        $response = $this->legacyMutation('legacyBulk', $this->request([
            'decision' => 'create',
            'band'     => 'none',
            'rows'     => [
                ['wc_id' => 1, 'wc_type' => 'simple'],
                ['wc_id' => 2, 'wc_type' => 'simple'],
                ['wc_id' => 3, 'wc_type' => 'simple'],
            ],
        ]));

        $this->assertSame(200, $response->get_status());
        $this->assertSame(3, $response->get_data()['data']['saved']);
        $this->assertCount(3, $this->saved);
    }

    public function testBulkSkipsRowsItCannotUse(): void
    {
        $this->registerWooProduct(1);
        $this->registerWooProduct(2);

        $response = $this->legacyMutation('legacyBulk', $this->request([
            'decision' => 'link',
            'band'     => 'strong',
            'rows'     => [
                ['wc_id' => 1, 'fc_post_id' => 900],
                ['wc_id' => 2],
            ],
        ]));

        $this->assertSame(1, $response->get_data()['data']['saved']);
        $this->assertCount(1, $this->saved);
    }

    public function testClearEmptiesTheTable(): void
    {
        $controller = $this->controller();

        $this->legacyMutationOn($controller, 'legacyDecide', $this->request(['wc_id' => 1, 'decision' => 'skip']));
        $this->legacyMutationOn($controller, 'legacyClear', $this->request([]));

        $this->assertSame([], $this->saved);
    }

    /**
     * "Clear all mappings" clears *this* source's mappings.
     *
     * The test above cannot tell the difference: its only row is the one being
     * deleted either way, so it passes whether `clear()` scopes its DELETE or
     * truncates the table. This one seeds a decision belonging to a second
     * source — the cross-runtime package route, where both Lapka sites number
     * their products from one — and asserts it is still there afterwards.
     *
     * Read off the fake table rather than off $this->saved, because
     * ProductMapDecision carries no source key: the thing being asserted is
     * which *rows* survived, which is a storage fact, not a decision one.
     */
    public function testClearIsScopedToOneSource(): void
    {
        $controller = $this->controller();

        $this->legacyMutationOn($controller, 'legacyDecide', $this->request(['wc_id' => 1, 'decision' => 'skip']));

        $GLOBALS['_cartshift_test_product_map_rows'][777] = [
            'source_key'  => 'lapka-klub',
            'wc_id'       => 777,
            'wc_type'     => 'simple',
            'decision'    => 'skip',
            'fc_post_id'  => null,
            'band'        => 'none',
            'variant_map' => null,
        ];

        $this->legacyMutationOn($controller, 'legacyClear', $this->request([]));

        $this->assertSame(
            [777],
            array_keys($GLOBALS['_cartshift_test_product_map_rows']),
            "Another source's decisions are not this source's to throw away.",
        );
    }

    // ── rows() ───────────────────────────────────────────────
    //
    // None of the seven tests above exercise rows() at all — it is the
    // largest, most logic-dense method in the controller (band carry-through,
    // variant summary, orphan rehydration, money conversion, both N+1s), and
    // a regression in any of it went undetected. Fixture: two FluentCart
    // products (900 "Blue Widget" / one variant, 901 "Red Widget" / one
    // variant) and three WooCommerce products (42 "Blue Widget" simple,
    // matches 900 strongly by SKU+title; 43 "Red Widget" variable with an
    // extra size FC has no counterpart for, matches 901 strongly by
    // title+price; 44 unrelated, matches nothing). Band and variant outcomes
    // were verified independently against the real ProductMatcher/
    // VariantResolver before being pinned here as assertions, not guessed.

    /**
     * @param list<array{id: int, title: string, variants: list<array{id: int, sku: string, name: string, item_price: int}>}> $fcProducts
     * @param list<int>                                                                                                       $wooProductIds
     * @param array<int, int>                                                                                                 $orderCounts wc product id => order count, default 0
     * @param list<int>                                                                                                       $fcProductsWithDownloads FluentCart post ids carrying at least one file
     */
    private function seedCatalogue(
        array $fcProducts,
        array $wooProductIds,
        array $orderCounts = [],
        int $wooTotalCount = 0,
        array $fcProductsWithDownloads = [],
    ): void {
        $variantsById = [];

        foreach ($fcProducts as $fc) {
            $variantsById[$fc['id']] = $fc['variants'];
        }

        // The page query is recorded, not just answered: the scope and product
        // type predicates it now carries are only real if they reach the SQL,
        // and a callback that ignores its argument cannot tell.
        $GLOBALS['_cartshift_test_get_col_callback'] = static function (string $query) use (
            $wooProductIds,
            $fcProductsWithDownloads,
        ): array {
            // MappingController::fcProductsWithDownloads(). Branched rather
            // than left to fall through to the Woo page ids, which would answer
            // "every FluentCart product already has files" and make the warning
            // untestable in the direction that matters.
            if (str_contains($query, 'fct_product_downloads')) {
                return $fcProductsWithDownloads;
            }

            $GLOBALS['_cartshift_test_last_page_query'] = $query;

            return $wooProductIds;
        };

        $GLOBALS['_cartshift_test_get_results_callback'] = static function (string $query) use ($fcProducts, $variantsById): array {
            // MappingController::fcCandidates()
            if (str_contains($query, "post_type = 'fluent-products'")) {
                $out = [];

                foreach ($fcProducts as $fc) {
                    $out[] = (object) ['ID' => $fc['id'], 'post_title' => $fc['title']];
                }

                return $out;
            }

            // MappingController::fcVariants(), per FC post id.
            if (str_contains($query, 'fct_product_variations')) {
                foreach ($variantsById as $postId => $variants) {
                    if (str_contains($query, "post_id = {$postId}")) {
                        return array_map(
                            static fn (array $v): object => (object) [
                                'id'              => $v['id'],
                                'variation_title' => $v['name'],
                                'item_price'      => $v['item_price'],
                                'sku'             => $v['sku'],
                                // Nullable in FluentCart's own schema, so the
                                // fixture leaves them absent unless a test says
                                // otherwise — a hand-built one-time product is
                                // the common shape and must keep working.
                                'payment_type'    => $v['payment_type'] ?? null,
                                'other_info'      => isset($v['other_info'])
                                    ? (string) json_encode($v['other_info'])
                                    : null,
                            ],
                            $variants,
                        );
                    }
                }

                return [];
            }

            return [];
        };

        $GLOBALS['_cartshift_test_get_var_callback'] = static function (string $query) use ($orderCounts, $wooTotalCount): int {
            // MappingController::orderCount(), per Woo product id.
            if (str_contains($query, 'woocommerce_order_itemmeta')) {
                foreach ($orderCounts as $productId => $count) {
                    if (str_contains($query, "meta_value = {$productId}")) {
                        return $count;
                    }
                }

                return 0;
            }

            // MappingController::wooProductCount() — the only other get_var() call rows() makes.
            return $wooTotalCount;
        };
    }

    private function createWooProduct(array $overrides = []): \WC_Product
    {
        $product = new \WC_Product();

        $defaults = [
            'id'       => 1,
            'name'     => 'Test Product',
            'type'     => 'simple',
            'sku'      => '',
            'price'    => '0',
            'children' => [],
        ];

        $this->hydrate($product, array_merge($defaults, $overrides));

        return $product;
    }

    /**
     * A WooCommerce variation the way WooCommerce actually shapes one.
     *
     * `name` is the *generated post title* — "Parent - Value, Value", or bare
     * "Parent" once a product has three or more attributes — and `attributes`
     * is where the values the FluentCart side speaks live. Fixtures that set a
     * bare attribute-shaped `name` are the reason VariantResolver's name pass
     * looked covered while never firing in production.
     */
    private function createWooVariation(array $overrides = []): \WC_Product_Variation
    {
        $variation = new \WC_Product_Variation();

        $defaults = [
            'id'         => 1,
            'name'       => 'Variation',
            'sku'        => '',
            'attributes' => [],
        ];

        $this->hydrate($variation, array_merge($defaults, $overrides));

        return $variation;
    }

    /**
     * Set protected WC_Product(_Variation) properties directly, the same
     * reflection technique ProductMapperTest::createProduct() already
     * established for this exact stub — it declares protected properties
     * with no constructor and no public setters.
     */
    private function hydrate(object $product, array $data): void
    {
        $ref = new \ReflectionClass($product);

        foreach ($data as $key => $value) {
            if ($ref->hasProperty($key)) {
                $ref->getProperty($key)->setValue($product, $value);
            }
        }
    }

    /**
     * Build the shared fixture, call rows(), and hand back the decoded
     * `data` payload. Every rows() test reads from this one arrangement and
     * asserts on whichever row or field it cares about — the fixture itself
     * was verified against the real matcher independently (see the class
     * docblock section above), not reverse-engineered from what rows()
     * happened to return.
     */
    private function rowsResponse(mixed $scope = null): array
    {
        // The attribute vocabulary both sides have to share: FluentCart stores
        // "Small" / "XL" as variation_title, and the Woo side only reaches
        // those words through the attribute terms.
        $GLOBALS['_cartshift_test_terms']['pa_size']['small'] = (object) ['name' => 'Small'];
        $GLOBALS['_cartshift_test_terms']['pa_size']['xl']    = (object) ['name' => 'XL'];

        $fcProducts = [
            [
                'id'       => 900,
                'title'    => 'Blue Widget',
                'variants' => [
                    ['id' => 501, 'sku' => 'BW-1', 'name' => 'Default', 'item_price' => 1999],
                ],
            ],
            [
                'id'       => 901,
                'title'    => 'Red Widget',
                'variants' => [
                    ['id' => 601, 'sku' => 'RW-S', 'name' => 'Small', 'item_price' => 999],
                ],
            ],
        ];

        // Woo 42: simple product, strong match on 900 by SKU + title.
        $GLOBALS['_cartshift_test_wc_products'][42] = $this->createWooProduct([
            'id' => 42, 'name' => 'Blue Widget', 'type' => 'simple', 'sku' => 'BW-1', 'price' => '19.99',
        ]);

        // Woo 43: variable product, blank parent SKU, strong match on 901 by
        // title + price. Two variations; FC 901 has only one ("Small"), so
        // "XL" (432) is left over as an orphan the resolver cannot place.
        $GLOBALS['_cartshift_test_wc_products'][43] = $this->createWooProduct([
            'id' => 43, 'name' => 'Red Widget', 'type' => 'variable', 'sku' => '', 'price' => '9.99',
            'children' => [431, 432],
        ]);
        $GLOBALS['_cartshift_test_wc_products'][431] = $this->createWooVariation([
            'id' => 431, 'name' => 'Red Widget - Small', 'sku' => 'RW-S',
            'attributes' => ['attribute_pa_size' => 'small'],
        ]);
        // Priced and downloadable on purpose: 432 is the orphan, and what
        // promotion creates from its descriptor is a row in the owner's live
        // catalogue. A descriptor that drops the price adds a free item; one
        // that drops the fulfilment type makes FluentCart demand a shipping
        // address for a file.
        $GLOBALS['_cartshift_test_wc_products'][432] = $this->createWooVariation([
            'id' => 432, 'name' => 'Red Widget - XL', 'sku' => 'RW-XL',
            'attributes' => ['attribute_pa_size' => 'xl'],
            'price' => '24.99', 'regular_price' => '24.99',
            'downloadable' => true,
        ]);

        // Woo 44: matches neither FC product — band 'none'.
        $GLOBALS['_cartshift_test_wc_products'][44] = $this->createWooProduct([
            'id' => 44, 'name' => 'Zephyr Kayak Paddle', 'type' => 'simple', 'sku' => 'ZZZ-999', 'price' => '500.00',
        ]);

        $this->seedCatalogue(
            $fcProducts,
            [42, 43, 44],
            orderCounts: [42 => 3, 43 => 0, 44 => 0],
            wooTotalCount: 3,
        );

        $params = ['page' => 1, 'per_page' => 50];

        if ($scope !== null) {
            $params['scope'] = $scope;
        }

        $response = $this->controller()->rows($this->request($params));

        return $response->get_data()['data'];
    }

    /**
     * @param list<array{id: int, ...}> $rows
     */
    private function findRow(array $rows, int $wcId): array
    {
        foreach ($rows as $row) {
            if ($row['wc_id'] === $wcId) {
                return $row;
            }
        }

        $this->fail("No row for wc_id {$wcId}.");
    }

    // ──────────────────────────────────────────────
    // Downloads the linked product does not have
    // ──────────────────────────────────────────────

    /**
     * A mapped product is skipped by ProductMigrator before it reaches
     * migrateDownloadFiles(), so its files never travel; and CartShift will not
     * write them into a product the owner built by hand, because which of their
     * variants each file belongs to has no safe automatic answer. That leaves
     * the customer with an order page, a receipt and an email showing no files
     * at all — so it is said here, while the owner is still choosing and the
     * alternatives are one dropdown away.
     *
     * @param list<int> $fcProductsWithDownloads
     */
    private function downloadableRow(array $fcProductsWithDownloads): array
    {
        $GLOBALS['_cartshift_test_wc_products'][99] = $this->createWooProduct([
            'id'           => 99,
            'name'         => 'Kurs WordPress',
            'type'         => 'simple',
            'sku'          => 'DIG-WP-001',
            'price'        => '99.00',
            'downloadable' => true,
            'downloads'    => [(object) ['id' => 'a', 'file' => 'https://example.com/course.pdf']],
        ]);

        $this->seedCatalogue(
            [[
                'id'       => 900,
                'title'    => 'Kurs WordPress',
                'variants' => [['id' => 501, 'sku' => 'DIG-WP-001', 'name' => 'Default', 'item_price' => 9900]],
            ]],
            [99],
            wooTotalCount: 1,
            fcProductsWithDownloads: $fcProductsWithDownloads,
        );

        return $this->findRow(
            $this->controller()->rows($this->request(['page' => 1, 'per_page' => 50]))->get_data()['data']['rows'],
            99,
        );
    }

    public function testARowWarnsWhenTheLinkedProductCarriesNoFiles(): void
    {
        $row = $this->downloadableRow([]);

        $this->assertSame(900, $row['suggested']);
        $this->assertTrue($row['downloads_lost']);
    }

    public function testNothingIsFlaggedWhenTheLinkedProductAlreadyHasFiles(): void
    {
        $row = $this->downloadableRow([900]);

        $this->assertFalse($row['downloads_lost']);
    }

    /**
     * Per candidate, not per row: choosing a different FluentCart product is a
     * different answer, and a warning that goes on describing the previous one
     * is worse than no warning at all. useMapping::chooseCandidate() moves it
     * with the selection for exactly this reason.
     */
    public function testTheFlagTravelsWithEachCandidate(): void
    {
        $row = $this->downloadableRow([]);

        $this->assertNotSame([], $row['candidates']);

        foreach ($row['candidates'] as $candidate) {
            $this->assertArrayHasKey('downloads_lost', $candidate);
            $this->assertTrue($candidate['downloads_lost']);
        }
    }

    /**
     * A Woo product with no files loses nothing, however empty the FluentCart
     * product it is linked to. Warning here would put a false positive on the
     * majority of every catalogue.
     */
    public function testAWooProductWithNoFilesIsNeverFlagged(): void
    {
        $row = $this->findRow($this->rowsResponse()['rows'], 42);

        $this->assertFalse($row['downloads_lost']);
    }

    public function testAMatchedRowCarriesBandAndVariantSummary(): void
    {
        $row = $this->findRow($this->rowsResponse()['rows'], 42);

        $this->assertSame('strong', $row['band']);
        $this->assertSame(900, $row['suggested']);

        // band travels with the candidate, not only the row-level winner —
        // this is finding 1 of the review's own prior round, re-checked here
        // through rows() rather than only through labelCandidates() in
        // isolation.
        $top = $row['candidates'][0];
        $this->assertSame(900, $top['id']);
        $this->assertSame('strong', $top['band']);

        $this->assertNotNull($row['variant']);
        $this->assertSame(1, $row['variant']['matched']);
        $this->assertSame(1, $row['variant']['total']);
        $this->assertSame(0, $row['variant']['adds']);
        $this->assertSame([], $row['variant']['orphans']);
    }

    public function testAnOrphanVariationIsRehydratedToAFullDescriptor(): void
    {
        $row = $this->findRow($this->rowsResponse()['rows'], 43);

        $this->assertSame('strong', $row['band']);
        $this->assertSame(901, $row['suggested']);
        $this->assertNotNull($row['variant']);
        $this->assertSame(1, $row['variant']['matched']);
        $this->assertSame(2, $row['variant']['total']);
        $this->assertSame(1, $row['variant']['adds']);

        // The whole point: promotion needs the name and SKU to create the
        // variant, so the orphan must be the full descriptor, not the bare
        // Woo variation id VariantResolver itself returns — and "full" now
        // means everything the created row is priced and fulfilled by, read
        // straight off VariationMapper so the orphan and the variant
        // ProductMigrator would have created cannot disagree.
        $this->assertSame(
            [[
                'id'               => 432,
                'sku'              => 'RW-XL',
                'name'             => 'XL',
                'price'            => 2499,
                'fulfillment_type' => 'digital',
                'downloadable'     => 'true',
            ]],
            $row['variant']['orphans'],
        );
    }

    public function testARowWithNoCandidateHasNoBandNoSuggestionAndNoVariantSummary(): void
    {
        $row = $this->findRow($this->rowsResponse()['rows'], 44);

        $this->assertSame('none', $row['band']);
        $this->assertNull($row['suggested']);
        $this->assertNull($row['variant']);
    }

    public function testFcProductCountReflectsTheCatalogueSize(): void
    {
        $data = $this->rowsResponse();

        $this->assertSame(2, $data['fc_product_count']);
        $this->assertSame(3, $data['total']);
    }

    /**
     * A product using Advanced Variations must not be offered as a link target.
     *
     * FluentCart regenerates such a product's variants from the attribute
     * cartesian on every combination save and deletes everything not in it
     * (AdvancedVariationService -> ProductAdminHelper::deleteOrphanVariant), so
     * a variant CartShift adds for an orphan Woo variation is destroyed the
     * next time the owner touches the attribute options — taking every
     * historical order line pointing at it into a dangling state, which is the
     * exact failure the orphan feature exists to prevent.
     *
     * Read off the query rather than off the returned rows, for the same
     * reason MigrationOrchestratorFactoryTest reads the skip exclusion off
     * ProductMigrator's source query: the exclusion is only real if it reaches
     * the SQL, and a fixture that answers whatever it is asked cannot tell.
     */
    public function testAdvancedVariationProductsAreNotOfferedAsLinkTargets(): void
    {
        $this->rowsResponse();

        $candidateQueries = array_filter(
            $GLOBALS['_cartshift_test_queries'],
            static fn (array $entry): bool
                => $entry[0] === 'prepare' && str_contains((string) $entry[1], "post_type = 'fluent-products'"),
        );

        $this->assertNotEmpty($candidateQueries, 'The candidate query must be prepared, not concatenated.');

        foreach ($candidateQueries as $entry) {
            $this->assertStringContainsString('fct_product_details', (string) $entry[1]);
            $this->assertStringContainsString('d.variation_type IS NULL OR d.variation_type !=', (string) $entry[1]);
            $this->assertContains('advanced_variations', $entry[2]);
        }
    }

    /**
     * The join is a LEFT JOIN on purpose. A FluentCart product whose detail row
     * is missing is broken in some other way, and silently dropping it from the
     * mapping screen turns "I cannot find my product" into a support ticket
     * with no evidence — the fixture here has no `fct_product_details` rows at
     * all, and both products still appear.
     */
    public function testAProductWithNoDetailRowIsStillOffered(): void
    {
        $this->assertSame(2, $this->rowsResponse()['fc_product_count']);
    }

    /**
     * fcVariants() used to run once per FC product inside fcCandidates()
     * building the candidate list, then again per matched row when rows()
     * resolved variants for whichever candidate the matcher picked — on
     * this fixture, 2 candidate-build queries plus 2 more for the two
     * matched rows (42 and 43) would be 4. Caching fcCandidates()'s reads in
     * cachedFcVariants() collapses that back to exactly the catalogue size.
     */
    public function testVariantsAreQueriedOnceThroughTheWholeCatalogueNotOncePerRow(): void
    {
        $this->rowsResponse();

        $variantQueries = array_filter(
            $GLOBALS['_cartshift_test_queries'],
            static fn (array $entry): bool => $entry[0] === 'get_results' && str_contains($entry[1], 'fct_product_variations'),
        );

        $this->assertCount(
            2,
            $variantQueries,
            'fct_product_variations must be queried once per FC catalogue product (2), not once more per matched row.',
        );
    }

    // ── Candidates carry their own variant block ─────────────
    //
    // The row's FC side is a <select>. FluentCart variation IDs are global in
    // fct_product_variations, so a row that ships one variant map and lets the
    // owner change the product underneath it writes ENTITY_VARIATION rows
    // pointing at a *different* product's variants — and every historical line
    // item for that product then attaches to the wrong one. The label goes on
    // saying "4/4 variants matched" while it happens.

    public function testEveryCandidateCarriesItsOwnVariantBlock(): void
    {
        $row = $this->findRow($this->rowsResponse()['rows'], 43);

        // Woo 43 has two variations; FC 901 has one ("Small"), FC 900 has one
        // ("Default", SKU BW-1). Two candidates, two different outcomes.
        $this->assertGreaterThan(1, count($row['candidates']), 'This row needs a real choice to be worth testing.');

        foreach ($row['candidates'] as $candidate) {
            $this->assertArrayHasKey('variant', $candidate);
            $this->assertNotNull(
                $candidate['variant'],
                'A candidate offered in the dropdown without its own variant map is a link waiting to go wrong.',
            );
            $this->assertSame(2, $candidate['variant']['total']);
        }

        $byId = [];

        foreach ($row['candidates'] as $candidate) {
            $byId[$candidate['id']] = $candidate['variant'];
        }

        $this->assertSame(
            [431 => 601],
            $byId[901]['map'],
            'Linking to 901 pairs the Small variation with 901\'s own variant.',
        );
        $this->assertSame(
            [431 => 501],
            $byId[900]['map'],
            'Linking to 900 must pair against 900\'s variant instead — not carry 901\'s.',
        );
        $this->assertNotSame($byId[900]['map'], $byId[901]['map']);
    }

    public function testTheRowLevelVariantBlockIsTheSuggestedCandidates(): void
    {
        $row = $this->findRow($this->rowsResponse()['rows'], 43);

        $suggested = null;

        foreach ($row['candidates'] as $candidate) {
            if ($candidate['id'] === $row['suggested']) {
                $suggested = $candidate['variant'];
            }
        }

        $this->assertSame($suggested, $row['variant']);
    }

    /**
     * A row with nothing plausible must offer no dropdown at all. The ranked
     * list is every candidate scored, and slicing eight off the top of it
     * handed a "No candidate" row eight irrelevant products to pick from.
     */
    public function testANoCandidateRowOffersNothingToPick(): void
    {
        $row = $this->findRow($this->rowsResponse()['rows'], 44);

        $this->assertSame('none', $row['band']);
        $this->assertSame([], $row['candidates']);
    }

    // ── Variant pairing speaks FluentCart's vocabulary ───────

    /**
     * The name pass, on the catalogue that killed SKU-first matching.
     *
     * Both Woo variations and both FC variants are SKU-less, so SKU cannot
     * decide it and position would pair them in `id ASC` order — which here is
     * exactly backwards. Only a name pass fed attribute labels gets it right,
     * and the controller only produces those by going through
     * VariationMapper::variationTitle() rather than WC_Product_Variation
     * ::get_name().
     */
    public function testABlankSkuVariableProductPairsByAttributeNameNotPosition(): void
    {
        $GLOBALS['_cartshift_test_terms']['pa_size']['small'] = (object) ['name' => 'Small'];
        $GLOBALS['_cartshift_test_terms']['pa_size']['xl']    = (object) ['name' => 'XL'];

        $GLOBALS['_cartshift_test_wc_products'][50] = $this->createWooProduct([
            'id' => 50, 'name' => 'Green Kite', 'type' => 'variable', 'sku' => '', 'price' => '30.00',
            'children' => [501, 502],
        ]);
        // Display order Small, XL — the reverse of the FC product's id order.
        $GLOBALS['_cartshift_test_wc_products'][501] = $this->createWooVariation([
            'id' => 501, 'name' => 'Green Kite - Small', 'sku' => '',
            'attributes' => ['attribute_pa_size' => 'small'],
        ]);
        $GLOBALS['_cartshift_test_wc_products'][502] = $this->createWooVariation([
            'id' => 502, 'name' => 'Green Kite - XL', 'sku' => '',
            'attributes' => ['attribute_pa_size' => 'xl'],
        ]);

        $this->seedCatalogue(
            [[
                'id'       => 910,
                'title'    => 'Green Kite',
                'variants' => [
                    ['id' => 700, 'sku' => '', 'name' => 'XL', 'item_price' => 3000],
                    ['id' => 701, 'sku' => '', 'name' => 'Small', 'item_price' => 3000],
                ],
            ]],
            [50],
            wooTotalCount: 1,
        );

        $row = $this->findRow(
            $this->controller()->rows($this->request(['page' => 1, 'per_page' => 50]))->get_data()['data']['rows'],
            50,
        );

        $this->assertSame(910, $row['suggested']);
        $this->assertSame(
            [501 => 701, 502 => 700],
            $row['variant']['map'],
            'Position would have paired Small with XL. Names are the only signal left, and they have to be the same names FluentCart holds.',
        );
        $this->assertSame(0, $row['variant']['adds']);
    }

    // ── variable-subscription ────────────────────────────────
    //
    // wooProductPage() and anyVariationHasDownloads() both used to decide
    // "does this product have children" by comparing the bare literal
    // 'variable', which a variable-subscription product never matches. The
    // first collapsed every variation into a single "Default" row keyed off
    // the parent; the second read only the parent's own downloads and missed
    // whatever lived on the children, understating a warning the owner needs
    // before they link the product.

    public function testAVariableSubscriptionListsAllItsVariations(): void
    {
        $GLOBALS['_cartshift_test_wc_products'][60] = $this->createWooProduct([
            'id' => 60, 'name' => 'Yoga Pass', 'type' => 'variable-subscription', 'sku' => '', 'price' => '9.99',
            'children' => [601, 602],
        ]);
        $GLOBALS['_cartshift_test_wc_products'][601] = $this->createWooVariation([
            'id' => 601, 'name' => 'Yoga Pass - Monthly', 'sku' => 'YOGA-M',
        ]);
        $GLOBALS['_cartshift_test_wc_products'][602] = $this->createWooVariation([
            'id' => 602, 'name' => 'Yoga Pass - Yearly', 'sku' => 'YOGA-Y',
        ]);

        $this->seedCatalogue([], [60], wooTotalCount: 1);

        $row = $this->findRow(
            $this->controller()->rows($this->request(['page' => 1, 'per_page' => 50]))->get_data()['data']['rows'],
            60,
        );

        $this->assertSame(
            2,
            $row['variations'],
            'A variable-subscription product must not collapse to a single pseudo-variation.',
        );
    }

    public function testAFileOnAVariableSubscriptionVariationCountsAsAFileOnTheProduct(): void
    {
        $GLOBALS['_cartshift_test_wc_products'][61] = $this->createWooProduct([
            'id' => 61, 'name' => 'Course Pass', 'type' => 'variable-subscription', 'sku' => '', 'price' => '49.00',
            'children' => [611],
        ]);
        $GLOBALS['_cartshift_test_wc_products'][611] = $this->createWooVariation([
            'id' => 611, 'name' => 'Course Pass - Monthly', 'sku' => 'CP-M',
            'downloadable' => true,
            'downloads'    => [(object) ['id' => 'a', 'file' => 'https://example.com/lesson1.pdf']],
        ]);

        $this->seedCatalogue(
            [[
                'id'       => 902,
                'title'    => 'Course Pass',
                'variants' => [['id' => 611, 'sku' => 'CP-M', 'name' => 'Default', 'item_price' => 4900]],
            ]],
            [61],
            wooTotalCount: 1,
        );

        $row = $this->findRow(
            $this->controller()->rows($this->request(['page' => 1, 'per_page' => 50]))->get_data()['data']['rows'],
            61,
        );

        $this->assertSame(902, $row['suggested']);
        $this->assertTrue(
            $row['downloads_lost'],
            'The variation carries the only file this product has — reading only the parent misses it.',
        );
    }

    // ── Scope and product type ───────────────────────────────

    /**
     * The screen presents what the run will migrate, and nothing else. Without
     * the scope predicate a "let me choose these twelve" run offered the whole
     * catalogue for mapping; without the type predicate it offered rows for
     * products ProductMigrator does not source at all.
     */
    public function testThePageQueryCarriesTheScopeAndTheProductTypeTest(): void
    {
        $this->rowsResponse((string) wp_json_encode([
            'mode'        => 'explicit',
            'product_ids' => [42, 43],
        ]));

        $query = (string) ($GLOBALS['_cartshift_test_last_page_query'] ?? '');

        $this->assertStringContainsString('wc_product_meta_lookup', $query, 'Source shape must match ProductMigrator::countTotal().');
        $this->assertStringContainsString("tt.taxonomy = 'product_type'", $query);
        $this->assertStringContainsString('p.ID IN (42, 43)', $query);
    }

    public function testAnAbsentScopeStillMeansEverything(): void
    {
        $this->rowsResponse();

        $query = (string) ($GLOBALS['_cartshift_test_last_page_query'] ?? '');

        $this->assertStringNotContainsString('p.ID IN (', $query);
        $this->assertStringContainsString("tt.taxonomy = 'product_type'", $query);
    }

    // ── Bulk answers in one shape ────────────────────────────

    /**
     * A bulk press has to leave a row in the state a per-row press would. The
     * only way to guarantee that is for both to adopt the server's own
     * ProductMapDecision::toArray(), which means bulk has to return it.
     */
    public function testBulkReturnsTheDecisionsItSavedNotJustACount(): void
    {
        $this->registerWooProduct(1);

        $response = $this->legacyMutation('legacyBulk', $this->request([
            'decision' => 'link',
            'band'     => 'strong',
            'rows'     => [
                ['wc_id' => 1, 'wc_type' => 'simple', 'fc_post_id' => 900, 'variant_map' => ['1' => '501']],
            ],
        ]));

        $data = $response->get_data()['data'];

        $this->assertSame(1, $data['saved']);
        $this->assertSame(
            [[
                'wc_id'               => 1,
                'wc_type'             => 'simple',
                'decision'            => 'link',
                'fc_post_id'          => 900,
                'band'                => 'strong',
                'variant_map'         => [1 => 501],
                'orphans'             => [],
                // Added by the subscription mapping work: the operator's
                // explicit "yes, two source products may land on this one
                // variation". Off unless they said so.
                'allow_shared_target' => false,
            ]],
            $data['decisions'],
        );
    }

    // ──────────────────────────────────────────────
    // Manual catalogue search — the band=none rescue
    // ──────────────────────────────────────────────
    //
    // ProductMatcher scored both Lapka source products `band=none`, and rows()
    // drops every `none` candidate before the slice. That is right for a
    // dropdown of suggestions and wrong as the only way to pick a product: "no
    // automatic suggestion" is not "cannot be selected". So the whole target
    // catalogue is searchable, and what comes back carries the billing contract
    // of every variation, because that is what the operator is choosing between.

    /**
     * @param list<array{id: int, title: string, variants: list<array<string, mixed>>}> $fcProducts
     */
    private function catalogueResponse(array $fcProducts, array $params = []): array
    {
        // The shared get_var() stub answers every non-order-count query with
        // this number, which for catalogue() is its COUNT(*) of target products.
        $this->seedCatalogue($fcProducts, [], wooTotalCount: count($fcProducts));

        return $this->controller()
            ->catalogue($this->request(array_merge(['page' => 1, 'per_page' => 50], $params)))
            ->get_data()['data'];
    }

    /**
     * @return list<array{id: int, title: string, variants: list<array<string, mixed>>}>
     */
    private function membershipCatalogue(): array
    {
        return [[
            'id'       => 88,
            'title'    => 'Klubu Przyjaciol Psow',
            'variants' => [
                [
                    'id' => 4101, 'sku' => '', 'name' => 'Miesiecznie', 'item_price' => 2900,
                    'payment_type' => 'subscription',
                    'other_info'   => ['repeat_interval' => 'monthly', 'trial_days' => 0, 'times' => 0],
                ],
                [
                    'id' => 4102, 'sku' => '', 'name' => 'Rocznie', 'item_price' => 29000,
                    'payment_type' => 'subscription',
                    'other_info'   => ['repeat_interval' => 'yearly', 'trial_days' => 7, 'times' => 3],
                ],
            ],
        ]];
    }

    public function testTheCatalogueEndpointOffersEveryTargetProductRegardlessOfBand(): void
    {
        $data = $this->catalogueResponse($this->membershipCatalogue());

        $this->assertSame(1, $data['total']);
        $this->assertSame(88, $data['products'][0]['id']);
        $this->assertSame('Klubu Przyjaciol Psow', $data['products'][0]['name']);
    }

    public function testTheCatalogueCarriesEveryVariationsBillingContract(): void
    {
        $variations = $this->catalogueResponse($this->membershipCatalogue())['products'][0]['variations'];

        $this->assertSame([
            [
                'id'              => 4101,
                'sku'             => '',
                'name'            => 'Miesiecznie',
                'price'           => 29.0,
                'payment_type'    => 'subscription',
                'repeat_interval' => 'monthly',
                'trial_days'      => 0,
                'times'           => 0,
            ],
            [
                'id'              => 4102,
                'sku'             => '',
                'name'            => 'Rocznie',
                'price'           => 290.0,
                'payment_type'    => 'subscription',
                'repeat_interval' => 'yearly',
                'trial_days'      => 7,
                'times'           => 3,
            ],
        ], $variations);
    }

    public function testTheCatalogueSearchFiltersByTitle(): void
    {
        $this->catalogueResponse($this->membershipCatalogue(), ['q' => 'Klub']);

        $queries = array_filter(
            $GLOBALS['_cartshift_test_queries'],
            static fn (array $entry): bool
                => $entry[0] === 'prepare' && str_contains((string) $entry[1], 'post_title LIKE'),
        );

        $this->assertNotEmpty($queries, 'A search term has to reach the SQL, or the endpoint is paging blindly.');
    }

    /**
     * The same exclusion rows() applies: a product using Advanced Variations
     * regenerates its variants from the attribute cartesian and deletes
     * everything not in it, so it must not be offered here either.
     */
    public function testTheCatalogueExcludesAdvancedVariationProducts(): void
    {
        $this->catalogueResponse($this->membershipCatalogue());

        $queries = array_values(array_filter(
            $GLOBALS['_cartshift_test_queries'],
            static fn (array $entry): bool
                => $entry[0] === 'prepare' && str_contains((string) $entry[1], "post_type = 'fluent-products'"),
        ));

        $this->assertNotEmpty($queries);

        foreach ($queries as $entry) {
            $this->assertStringContainsString('d.variation_type IS NULL OR d.variation_type !=', (string) $entry[1]);
        }
    }

    // ──────────────────────────────────────────────
    // Saving a decision validates the whole set
    // ──────────────────────────────────────────────
    //
    // VariantResolver's `$claimed` protects one product decision. Two Woo
    // products decided one after another each get their own, so both can claim
    // the same FluentCart variation and neither call notices. On Lapka that is
    // the yearly product landing on the monthly variation.

    private function subscriptionProduct(int $id, string $name, string $period, string $price): \WC_Product
    {
        return $this->createWooProduct([
            'id'    => $id,
            'name'  => $name,
            'type'  => 'subscription',
            'sku'   => '',
            'price' => $price,
            'meta'  => [
                '_subscription_period'          => $period,
                '_subscription_period_interval' => '1',
            ],
        ]);
    }

    public function testDecideStoresTheSharedTargetFlag(): void
    {
        $this->registerWooProduct(42);

        $this->legacyMutation('legacyDecide', $this->request([
            'wc_id'               => 42,
            'wc_type'             => 'subscription',
            'decision'            => 'link',
            'fc_post_id'          => 88,
            'band'                => 'none',
            'variant_map'         => ['42' => '4101'],
            'allow_shared_target' => true,
        ]));

        $this->assertTrue($this->saved[0]->allowSharedTarget());
    }

    public function testDecideDefaultsTheSharedTargetFlagToOff(): void
    {
        $this->registerWooProduct(42);

        $this->legacyMutation('legacyDecide', $this->request([
            'wc_id'       => 42,
            'wc_type'     => 'subscription',
            'decision'    => 'link',
            'fc_post_id'  => 88,
            'variant_map' => ['42' => '4101'],
        ]));

        $this->assertFalse($this->saved[0]->allowSharedTarget());
    }

    /**
     * Two source products of the *same* cadence, neither opting in.
     *
     * The per-row gate cannot catch this one — both are genuinely monthly and
     * the variation genuinely is monthly, so every per-row check passes twice.
     * It is only visible across the set, which is the whole reason
     * MappingSetValidator exists. (A monthly and a yearly product colliding is
     * caught one step earlier, by the contract gate: see
     * testASubscriptionLinkOntoTheWrongCadenceIsRefusedByTheServer.)
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testASecondSourceClaimingAnAlreadyClaimedVariationIsRefused(): void
    {
        require_once dirname(__DIR__, 3) . '/stubs/WcSubscriptionsProductStub.php';

        $GLOBALS['_cartshift_test_wc_products'][901] = $this->subscriptionProduct(901, 'Legacy A', 'month', '29.00');
        $GLOBALS['_cartshift_test_wc_products'][902] = $this->subscriptionProduct(902, 'Legacy B', 'month', '24.00');

        $this->seedCatalogue($this->membershipCatalogue(), [], wooTotalCount: 0);

        $controller = $this->controller();

        $first = $this->legacyMutationOn($controller, 'legacyDecide', $this->request([
            'wc_id' => 901, 'wc_type' => 'subscription', 'decision' => 'link',
            'fc_post_id' => 88, 'variant_map' => ['901' => '4101'],
        ]));

        $this->assertSame(200, $first->get_status());

        $second = $this->legacyMutationOn($controller, 'legacyDecide', $this->request([
            'wc_id' => 902, 'wc_type' => 'subscription', 'decision' => 'link',
            'fc_post_id' => 88, 'variant_map' => ['902' => '4101'],
        ]));

        $this->assertSame(422, $second->get_status());
        $this->assertSame(
            ['target_variation_contract_collision'],
            array_column($second->get_data()['data']['errors'], 'code'),
        );
        $this->assertCount(1, $this->saved, 'The colliding decision must never reach the table.');
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testTheTwoLapkaProductsSaveWhenTheyTakeDifferentVariations(): void
    {
        require_once dirname(__DIR__, 3) . '/stubs/WcSubscriptionsProductStub.php';

        $GLOBALS['_cartshift_test_wc_products'][770_001] = $this->subscriptionProduct(770_001, 'Monthly', 'month', '29.00');
        $GLOBALS['_cartshift_test_wc_products'][770_002] = $this->subscriptionProduct(770_002, 'Yearly', 'year', '290.00');

        $this->seedCatalogue($this->membershipCatalogue(), [], wooTotalCount: 0);

        $controller = $this->controller();

        $this->legacyMutationOn($controller, 'legacyDecide', $this->request([
            'wc_id'       => 770_001,
            'wc_type'     => 'subscription',
            'decision'    => 'link',
            'fc_post_id'  => 88,
            'variant_map' => ['770001' => '4101'],
        ]));

        $second = $this->legacyMutationOn($controller, 'legacyDecide', $this->request([
            'wc_id'       => 770_002,
            'wc_type'     => 'subscription',
            'decision'    => 'link',
            'fc_post_id'  => 88,
            'variant_map' => ['770002' => '4102'],
        ]));

        $this->assertSame(200, $second->get_status());
        $this->assertCount(2, $this->saved);
        $this->assertSame([770_001 => 4101], $this->saved[0]->variantMap());
        $this->assertSame([770_002 => 4102], $this->saved[1]->variantMap());
    }

    /**
     * Two one-time products landing on one variation is what CartShift has
     * always done. A subscription fix that broke it would be a regression in
     * the ninety-nine per cent of catalogues that have no subscriptions at all.
     */
    public function testTwoOneTimeProductsMayStillShareOneTargetVariation(): void
    {
        $controller = $this->controller();

        $this->registerWooProduct(11, 'A');
        $this->registerWooProduct(12, 'B');

        $this->legacyMutationOn($controller, 'legacyDecide', $this->request([
            'wc_id' => 11, 'wc_type' => 'simple', 'decision' => 'link',
            'fc_post_id' => 88, 'variant_map' => ['11' => '501'],
        ]));

        $second = $this->legacyMutationOn($controller, 'legacyDecide', $this->request([
            'wc_id' => 12, 'wc_type' => 'simple', 'decision' => 'link',
            'fc_post_id' => 88, 'variant_map' => ['12' => '501'],
        ]));

        $this->assertSame(200, $second->get_status());
        $this->assertCount(2, $this->saved);
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testTwoEquivalentSubscriptionsMayShareATargetWhenBothOptIn(): void
    {
        require_once dirname(__DIR__, 3) . '/stubs/WcSubscriptionsProductStub.php';

        $GLOBALS['_cartshift_test_wc_products'][901] = $this->subscriptionProduct(901, 'Legacy A', 'month', '29.00');
        $GLOBALS['_cartshift_test_wc_products'][902] = $this->subscriptionProduct(902, 'Legacy B', 'month', '24.00');

        $this->seedCatalogue($this->membershipCatalogue(), [], wooTotalCount: 0);

        $controller = $this->controller();

        $this->legacyMutationOn($controller, 'legacyDecide', $this->request([
            'wc_id' => 901, 'wc_type' => 'subscription', 'decision' => 'link',
            'fc_post_id' => 88, 'variant_map' => ['901' => '4101'], 'allow_shared_target' => true,
        ]));

        $second = $this->legacyMutationOn($controller, 'legacyDecide', $this->request([
            'wc_id' => 902, 'wc_type' => 'subscription', 'decision' => 'link',
            'fc_post_id' => 88, 'variant_map' => ['902' => '4101'], 'allow_shared_target' => true,
        ]));

        $this->assertSame(200, $second->get_status());
        $this->assertCount(2, $this->saved);
    }

    /**
     * The response carries the mapping-set fingerprint Tasks 10 and 11 persist
     * into stage and cutover receipts, so the operator's approved mapping and
     * the one the run executes can be compared rather than assumed equal.
     */
    public function testASavedDecisionReturnsTheMappingSetFingerprint(): void
    {
        $this->registerWooProduct(42);

        $response = $this->legacyMutation('legacyDecide', $this->request([
            'wc_id' => 42, 'wc_type' => 'simple', 'decision' => 'link',
            'fc_post_id' => 88, 'variant_map' => ['42' => '501'],
        ]));

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/',
            $response->get_data()['data']['mapping_fingerprint'],
        );
    }

    // ──────────────────────────────────────────────
    // Saving requires an explicit, compatible variation
    // ──────────────────────────────────────────────
    //
    // The mapping screen hides the Link button while a subscription source has
    // no compatible target, and a screen is not a gate: the endpoint takes a
    // variant map from the browser and the browser is the one thing on this
    // path CartShift does not control. So the contracts are re-derived from
    // WooCommerce and FluentCart here, and the client's choices are checked
    // against them rather than trusted.

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testASubscriptionLinkWithNoVariationChoiceIsRefused(): void
    {
        require_once dirname(__DIR__, 3) . '/stubs/WcSubscriptionsProductStub.php';

        $GLOBALS['_cartshift_test_wc_products'][770_002]
            = $this->subscriptionProduct(770_002, 'Klubu Przyjaciol Psow rocznie', 'year', '290.00');

        $this->seedCatalogue($this->membershipCatalogue(), [770_002], wooTotalCount: 1);

        $response = $this->legacyMutation('legacyDecide', $this->request([
            'wc_id' => 770_002, 'wc_type' => 'subscription', 'decision' => 'link', 'fc_post_id' => 88,
        ]));

        $this->assertSame(422, $response->get_status());
        $this->assertSame(
            ['target_variation_missing'],
            array_column($response->get_data()['data']['errors'], 'code'),
        );
        $this->assertSame([], $this->saved);
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testASubscriptionLinkOntoTheWrongCadenceIsRefusedByTheServer(): void
    {
        require_once dirname(__DIR__, 3) . '/stubs/WcSubscriptionsProductStub.php';

        $GLOBALS['_cartshift_test_wc_products'][770_002]
            = $this->subscriptionProduct(770_002, 'Klubu Przyjaciol Psow rocznie', 'year', '290.00');

        $this->seedCatalogue($this->membershipCatalogue(), [770_002], wooTotalCount: 1);

        $response = $this->legacyMutation('legacyDecide', $this->request([
            'wc_id' => 770_002, 'wc_type' => 'subscription', 'decision' => 'link', 'fc_post_id' => 88,
            // The monthly variation, posted for a yearly product.
            'variant_map' => ['770002' => '4101'],
        ]));

        $this->assertSame(422, $response->get_status());
        $this->assertSame(
            ['target_variation_contract_mismatch'],
            array_column($response->get_data()['data']['errors'], 'code'),
        );
        $this->assertSame([], $this->saved);
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testACompatibleSubscriptionLinkSaves(): void
    {
        require_once dirname(__DIR__, 3) . '/stubs/WcSubscriptionsProductStub.php';

        $GLOBALS['_cartshift_test_wc_products'][770_002]
            = $this->subscriptionProduct(770_002, 'Klubu Przyjaciol Psow rocznie', 'year', '290.00');

        $this->seedCatalogue($this->membershipCatalogue(), [770_002], wooTotalCount: 1);

        $response = $this->legacyMutation('legacyDecide', $this->request([
            'wc_id' => 770_002, 'wc_type' => 'subscription', 'decision' => 'link', 'fc_post_id' => 88,
            'variant_map' => ['770002' => '4102'],
        ]));

        $this->assertSame(200, $response->get_status());
        $this->assertSame([770_002 => 4102], $this->saved[0]->variantMap());
    }

    /**
     * A one-time product is not asked for anything it was not asked for
     * before: no variant map is still a legal link, because the resolver's
     * name and position passes are a reasonable answer for a size and the
     * whole of CartShift 1.4.x depends on it.
     */
    public function testAOneTimeLinkWithNoVariantMapIsStillAccepted(): void
    {
        $GLOBALS['_cartshift_test_wc_products'][42] = $this->createWooProduct(['id' => 42, 'name' => 'Blue Widget']);

        $response = $this->legacyMutation('legacyDecide', $this->request([
            'wc_id' => 42, 'wc_type' => 'simple', 'decision' => 'link', 'fc_post_id' => 900,
        ]));

        $this->assertSame(200, $response->get_status());
        $this->assertCount(1, $this->saved);
    }

    /**
     * The gate's other direction. A one-time source naming a subscription
     * variation is the same defect as a subscription source naming a one-time
     * one, and it used to sail through: contractErrors() looked only at
     * subscription source variations and returned early when there were none.
     */
    public function testAOneTimeSourceClaimingASubscriptionVariationIsRefused(): void
    {
        $this->registerWooProduct(42, 'Blue Widget');

        $this->seedCatalogue($this->membershipCatalogue(), [], wooTotalCount: 0);

        $response = $this->legacyMutation('legacyDecide', $this->request([
            'wc_id' => 42, 'wc_type' => 'simple', 'decision' => 'link',
            'fc_post_id' => 88, 'variant_map' => ['42' => '4101'],
        ]));

        $this->assertSame(422, $response->get_status());
        $this->assertSame(
            ['target_variation_contract_mismatch'],
            array_column($response->get_data()['data']['errors'], 'code'),
        );
        $this->assertSame([], $this->saved);
    }

    /**
     * An unreadable WooCommerce source used to make both gates default to
     * "fine": contractErrors() returned no errors and sourceContract() returned
     * null, which the set validator read as one-time. The plan's inference
     * policy says an unresolved load-bearing fact gets a named refusal, and
     * this is the named refusal.
     */
    public function testALinkWhoseWooProductCannotBeReadIsRefused(): void
    {
        $response = $this->legacyMutation('legacyDecide', $this->request([
            'wc_id' => 999_999, 'wc_type' => 'simple', 'decision' => 'link',
            'fc_post_id' => 88, 'variant_map' => ['999999' => '4101'],
        ]));

        $this->assertSame(422, $response->get_status());
        $this->assertSame(
            ['required_reference_missing'],
            array_column($response->get_data()['data']['errors'], 'code'),
        );
        $this->assertSame([], $this->saved);
    }

    /**
     * A variant map key naming a source variation the product does not have.
     *
     * The third member of the family: the gate refused the shape it was
     * written for — a subscription source with no choice, a choice with the
     * wrong cadence — and waved through a key that named nothing at all, which
     * the set validator then saw as one uncontested claim. Reachable by
     * re-saving a decision after the owner deleted or regenerated a variation.
     */
    public function testAVariantMapKeyThatIsNotAVariationOfTheProductIsRefused(): void
    {
        $this->registerWooProduct(42, 'Blue Widget');

        $response = $this->legacyMutation('legacyDecide', $this->request([
            'wc_id' => 42, 'wc_type' => 'simple', 'decision' => 'link',
            // 42 is a simple product: its only source variation is 42 itself.
            'fc_post_id' => 900, 'variant_map' => ['11' => '501'],
        ]));

        $this->assertSame(422, $response->get_status());
        $this->assertSame(
            ['required_reference_missing'],
            array_column($response->get_data()['data']['errors'], 'code'),
        );
        $this->assertSame([], $this->saved);
    }

    /**
     * The same refusal, read by somebody who has to act on it.
     *
     * "Variation 11 is not a variation of WooCommerce product 42. Reload the
     * mapping screen and choose again." is correct and nearly useless: it does
     * not say why the variation vanished, which product to look for on a screen
     * of two thousand rows, or which target choice is being discarded. The
     * ordinary route to this refusal is catalogue maintenance — somebody deleted
     * or regenerated a variation of a mapped variable product — so the message
     * has to name the row and the choice, not just the two integers that
     * disagree.
     */
    public function testTheStaleVariationRefusalNamesTheRowTheTargetAndWhyItVanished(): void
    {
        $this->registerWooProduct(42, 'Blue Widget');

        $response = $this->legacyMutation('legacyDecide', $this->request([
            'wc_id' => 42, 'wc_type' => 'simple', 'decision' => 'link',
            'fc_post_id' => 900, 'variant_map' => ['11' => '501'],
        ]));

        $message = $response->get_data()['data']['errors'][0]['message'];

        // Which row to go back to, by name and not only by ID.
        $this->assertStringContainsString('Blue Widget', $message);
        $this->assertStringContainsString('42', $message);
        // Which choice is being discarded.
        $this->assertStringContainsString('501', $message);
        // Why it vanished, so the owner knows where to look.
        $this->assertStringContainsString('deleted', $message);
    }

    /**
     * And a variable product's real children are still fine, so the check is a
     * membership test rather than "the map must be keyed by the product ID".
     */
    public function testAVariableProductsOwnVariationKeysAreAccepted(): void
    {
        $GLOBALS['_cartshift_test_wc_products'][43] = $this->createWooProduct([
            'id' => 43, 'name' => 'Red Widget', 'type' => 'variable', 'children' => [431, 432],
        ]);
        $GLOBALS['_cartshift_test_wc_products'][431] = $this->createWooVariation(['id' => 431]);
        $GLOBALS['_cartshift_test_wc_products'][432] = $this->createWooVariation(['id' => 432]);

        $response = $this->legacyMutation('legacyDecide', $this->request([
            'wc_id' => 43, 'wc_type' => 'variable', 'decision' => 'link',
            'fc_post_id' => 901, 'variant_map' => ['431' => '601', '432' => '602'],
        ]));

        $this->assertSame(200, $response->get_status());
        $this->assertSame([431 => 601, 432 => 602], $this->saved[0]->variantMap());
    }

    /**
     * The set-level half of the same hole, which the per-row gate cannot reach.
     *
     * The gate refuses an *incoming* decision whose product is unreadable. It
     * says nothing about decisions already in the table whose products have
     * been deleted since — and those used to key as `onetime`, hit the
     * all-one-time pass, and let a monthly/yearly collision validate clean. The
     * fixture seeds them directly, because that is exactly the state an
     * operator reaches by mapping two products and then deleting them.
     */
    public function testAlreadySavedDecisionsWithUnreadableSourcesCollideRatherThanPassing(): void
    {
        $controller = $this->controller();

        foreach ([770_001, 770_002] as $wcId) {
            $GLOBALS['_cartshift_test_product_map_rows'][$wcId] = [
                'source_key'  => 'local',
                'wc_id'       => $wcId,
                'wc_type'     => 'subscription',
                'decision'    => 'link',
                'fc_post_id'  => 88,
                'band'        => 'none',
                'variant_map' => (string) json_encode(['map' => [$wcId => 4101], 'orphans' => []]),
            ];
        }

        $this->registerWooProduct(42, 'Something else');

        $response = $this->legacyMutationOn($controller, 'legacyDecide', $this->request([
            'wc_id' => 42, 'wc_type' => 'simple', 'decision' => 'link',
            'fc_post_id' => 900, 'variant_map' => ['42' => '501'],
        ]));

        $this->assertSame(422, $response->get_status());
        $this->assertSame(
            ['target_variation_contract_collision'],
            array_column($response->get_data()['data']['errors'], 'code'),
        );
    }

    /**
     * Create and skip are unaffected: they claim no variation, so there is no
     * contract to read and nothing to refuse.
     */
    public function testAnUnreadableWooProductStillAcceptsCreateAndSkip(): void
    {
        $controller = $this->controller();

        $this->assertSame(200, $this->legacyMutationOn($controller, 'legacyDecide', $this->request([
            'wc_id' => 999_999, 'wc_type' => 'simple', 'decision' => 'create',
        ]))->get_status());

        $this->assertSame(200, $this->legacyMutationOn($controller, 'legacyDecide', $this->request([
            'wc_id' => 999_998, 'wc_type' => 'simple', 'decision' => 'skip',
        ]))->get_status());

        $this->assertCount(2, $this->saved);
    }

    /**
     * bulk() drops rows it cannot *build* and refuses the whole batch on a
     * contract error, which is a deliberate departure from the surrounding
     * "drop, do not fail" semantics — pinned here so the next reader knows it
     * was chosen. Silently skipping the yearly product out of a "link all"
     * would leave 188 subscribers unmapped behind a screen reporting success.
     */
    public function testOneContractErrorRefusesTheWholeBatchIncludingTheGoodRows(): void
    {
        $this->registerWooProduct(11, 'A');
        $this->registerWooProduct(12, 'B');

        $this->seedCatalogue($this->membershipCatalogue(), [], wooTotalCount: 0);

        $response = $this->legacyMutation('legacyBulk', $this->request([
            'decision' => 'link',
            'band'     => 'strong',
            'rows'     => [
                ['wc_id' => 11, 'wc_type' => 'simple', 'fc_post_id' => 88, 'variant_map' => ['11' => '4101']],
                ['wc_id' => 12, 'wc_type' => 'simple', 'fc_post_id' => 88, 'variant_map' => ['12' => '501']],
            ],
        ]));

        $this->assertSame(422, $response->get_status());
        $this->assertSame([], $this->saved, 'The good row is not saved either — the batch is one decision.');
    }

    /**
     * The gate does not pay for the order count. describeWooProduct() runs a
     * COUNT(DISTINCT) over woocommerce_order_itemmeta for the mapping screen,
     * which the contract gate never reads — and bulk() would run one per row.
     */
    public function testTheSaveGateDoesNotCountOrders(): void
    {
        $this->registerWooProduct(42, 'Blue Widget');

        $this->legacyMutation('legacyDecide', $this->request([
            'wc_id' => 42, 'wc_type' => 'simple', 'decision' => 'link',
            'fc_post_id' => 900, 'variant_map' => ['42' => '501'],
        ]));

        foreach ($GLOBALS['_cartshift_test_queries'] as $entry) {
            $this->assertStringNotContainsString(
                'woocommerce_order_itemmeta',
                (string) ($entry[1] ?? ''),
                'Saving a decision must not re-count the orders the mapping screen already counted.',
            );
        }
    }

    // ──────────────────────────────────────────────
    // Subscription rows carry contracts, not positions
    // ──────────────────────────────────────────────

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testAYearlySourceRowSuggestsTheYearlyVariationNotTheFirstOne(): void
    {
        require_once dirname(__DIR__, 3) . '/stubs/WcSubscriptionsProductStub.php';

        $GLOBALS['_cartshift_test_wc_products'][770_002]
            = $this->subscriptionProduct(770_002, 'Klubu Przyjaciol Psow rocznie', 'year', '290.00');

        $this->seedCatalogue($this->membershipCatalogue(), [770_002], wooTotalCount: 1);

        $row = $this->findRow(
            $this->controller()->rows($this->request(['page' => 1, 'per_page' => 50]))->get_data()['data']['rows'],
            770_002,
        );

        $this->assertNotNull($row['variant'], 'The row has to offer the membership product to be worth testing.');
        $this->assertSame(
            [770_002 => 4102],
            $row['variant']['map'],
            'Positional fallback answered 4101 — the monthly variation, because FluentCart lists it first.',
        );
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testASubscriptionRowDescribesEveryTargetVariationForTheOperator(): void
    {
        require_once dirname(__DIR__, 3) . '/stubs/WcSubscriptionsProductStub.php';

        $GLOBALS['_cartshift_test_wc_products'][770_002]
            = $this->subscriptionProduct(770_002, 'Klubu Przyjaciol Psow rocznie', 'year', '290.00');

        $this->seedCatalogue($this->membershipCatalogue(), [770_002], wooTotalCount: 1);

        $row = $this->findRow(
            $this->controller()->rows($this->request(['page' => 1, 'per_page' => 50]))->get_data()['data']['rows'],
            770_002,
        );

        $sources = $row['variant']['sources'];

        $this->assertCount(1, $sources);
        $this->assertTrue($sources[0]['subscription']);
        $this->assertSame('yearly', $sources[0]['interval']);
        $this->assertCount(2, $sources[0]['options'], 'Both target variations are listed, compatible or not.');

        $byId = [];

        foreach ($sources[0]['options'] as $option) {
            $byId[$option['id']] = $option;
        }

        $this->assertFalse($byId[4101]['compatible']);
        $this->assertTrue($byId[4102]['compatible']);
        $this->assertSame(7, $byId[4102]['trial_days']);
        $this->assertSame(3, $byId[4102]['times']);
    }
}

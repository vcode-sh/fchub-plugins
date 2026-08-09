<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Support;

use CartShift\Core\Container;
use CartShift\Domain\Scope\MigrationScope;
use CartShift\Domain\Scope\ScopeConsequences;
use CartShift\Domain\Scope\ScopeResolver;
use CartShift\Http\Controllers\PreviewController;
use CartShift\Migrator\ProductMigrator;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\ProductTypes;
use CartShift\Tests\Unit\PluginTestCase;
use CartShift\Validator\PreflightCheck;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use WP_REST_Request;

require_once dirname(__DIR__, 2) . '/stubs/EntityMigratorStubs.php';
require_once dirname(__DIR__, 2) . '/stubs/ProductMigratorStubs.php';
require_once dirname(__DIR__, 2) . '/stubs/HttpCliStubs.php';
require_once dirname(__DIR__, 2) . '/stubs/PreflightStubs.php';

/**
 * Four places decide whether CartShift can migrate a product, and for a long
 * time they decided differently:
 *
 *   PreflightCheck            a private constant listing four types
 *   PreviewController         NOT IN (the unsupported slugs this shop uses)
 *   ScopeConsequences         the same negative list, admittedly divergent
 *   ProductMigrator           a second copy of the constant, gated on the
 *                             WooCommerce Subscriptions class being loaded
 *
 * Two defects fell out of that. A product carrying no `product_type` term was
 * offered by the picker, ignored by the consequences panel and then dropped by
 * the migrator's positive `IN (...)` join — silently, because a product absent
 * from the denominator is not a skip, it is an absence. And on a store that had
 * once sold subscriptions and disabled the add-on, the terms stayed in the
 * database, so preflight and the picker called those products migratable while
 * the migrator dropped them.
 *
 * Three docblocks already said a second copy must never exist. That is the
 * point of this file: intent in prose is not a test. Each case below drives the
 * real callers and compares the predicate they actually emitted against
 * ProductTypes' — reintroduce a private list anywhere and it fails there.
 */
final class ProductTypePredicateAgreementTest extends PluginTestCase
{
    private ?\wpdb $originalWpdb = null;

    #[\Override]
    protected function tearDown(): void
    {
        if ($this->originalWpdb !== null) {
            $GLOBALS['wpdb'] = $this->originalWpdb;
            $this->originalWpdb = null;
        }

        parent::tearDown();
    }

    // ──────────────────────────────────────────────
    // One predicate, four callers
    // ──────────────────────────────────────────────

    public function testTheMigratorsTotalUsesThePredicateAndNoPrivateList(): void
    {
        $this->productMigrator()->count();

        $this->assertQueriesContain(ProductTypes::migratableClause('pml.product_id'));
    }

    public function testTheMigratorsIdPageUsesThePredicateAndNoPrivateList(): void
    {
        $this->productMigrator()->fetchBatch(null, 50);

        $this->assertQueriesContain(ProductTypes::migratableClause('pml.product_id'));
    }

    public function testThePickerUsesThePredicateAndNoPrivateList(): void
    {
        $this->previewController()->search($this->request(['type' => 'product', 'q' => 'hoodie']));

        $this->assertQueriesContain(ProductTypes::migratableClause('p.ID'));
    }

    /**
     * The consequences panel counts what will be left behind, so it needs the
     * complement — and takes it as `NOT (the migrator's predicate)` rather than
     * writing its own. Anything else and the two are only accidentally
     * opposite, which is how a product with no type term came to be dropped by
     * one and reported as fine by the other.
     */
    public function testTheConsequencesPanelUsesTheExactComplementOfThePredicate(): void
    {
        $this->withCatalogueTypes(['simple' => 23, 'course' => 2]);

        (new ScopeConsequences(new ScopeResolver(MigrationScope::everything())))->all();

        $this->assertQueriesContain(ProductTypes::unmigratableClause('p.ID'));
        $this->assertQueriesContain(ProductTypes::unmigratableClause('CAST(im.meta_value AS UNSIGNED)'));
    }

    /**
     * The same catalogue, asked by all four in one go: whatever the supported
     * list happens to be, every caller has to be quoting the same one. This is
     * the assertion a fifth caller with its own list would fail.
     */
    public function testAllFourCallersQuoteTheSameSupportedList(): void
    {
        $this->withCatalogueTypes(['simple' => 23, 'course' => 2]);

        $this->productMigrator()->count();
        $this->productMigrator()->fetchBatch(null, 50);
        $this->previewController()->search($this->request(['type' => 'product', 'q' => 'hoodie']));
        (new ScopeConsequences(new ScopeResolver(MigrationScope::everything())))->all();

        $queries = $this->predicateQueries();

        $this->assertGreaterThanOrEqual(4, count($queries), 'All four callers must have asked.');

        $lists = [];

        foreach ($queries as $query) {
            preg_match_all("/t\.slug IN \(([^)]*)\)/", $query, $matches);

            foreach ($matches[1] as $list) {
                $lists[trim($list)] = true;
            }
        }

        $expected = "'" . implode("', '", ProductTypes::supported()) . "'";

        $this->assertSame(
            [$expected],
            array_keys($lists),
            'Every product-type slug list any of the four callers emitted must be ProductTypes::supported().',
        );
    }

    // ──────────────────────────────────────────────
    // D1 — a product with no product_type term
    // ──────────────────────────────────────────────

    /**
     * WooCommerce resolves a product with no `product_type` term to
     * ProductType::SIMPLE — so it is an ordinary simple product, and every
     * screen that has an opinion about it has to say so.
     *
     * @see woocommerce/includes/data-stores/class-wc-product-data-store-cpt.php::get_product_type() (v11.0.0, line 2149)
     */
    public function testTheMigratorSourcesAProductWithNoTypeTerm(): void
    {
        $this->productMigrator()->count();
        $this->productMigrator()->fetchBatch(null, 50);

        $branch = $this->noTypeBranch('pml.product_id');

        // Both, separately. The total and the batch disagreeing is worse than
        // either being wrong: it is a progress bar that never reaches the end.
        $this->assertStringContainsString($branch, $this->lastQueryMatching('SELECT COUNT(*)', 'pml'));
        $this->assertStringContainsString($branch, $this->lastQueryMatching('SELECT p.ID', 'pml'));
    }

    /**
     * Fixing the SQL alone would have moved the defect rather than removed it.
     * wc_get_products() cannot return a product with no `product_type` term
     * under any arguments — WC_Product_Query always emits a tax_query, and
     * omitting `type` merely defaults it to every registered type — so an ID
     * page that now includes such a product would have lost it again at
     * hydration: counted in the total, never handed to the orchestrator.
     *
     * Hydration therefore filters nothing. One product per ID, because the ID
     * page already decided.
     *
     * @see woocommerce/includes/data-stores/class-wc-product-data-store-cpt.php::get_wp_query_args() (v11.0.0, line 2242)
     */
    public function testHydrationYieldsOneProductPerIdOnThePage(): void
    {
        $this->stubIdPage([11, 12]);

        $GLOBALS['_cartshift_test_wc_products'] = [
            11 => (object) ['id' => 11],
            // No product_type term, so unreachable through wc_get_products().
            12 => (object) ['id' => 12],
        ];

        $batch = $this->productMigrator()->fetchBatch(null, 50);

        $this->assertSame([11, 12], array_map(static fn (object $p): int => $p->id, $batch));
    }

    public function testThePickerOffersAProductWithNoTypeTerm(): void
    {
        $this->previewController()->search($this->request(['type' => 'product', 'q' => 'hoodie']));

        $this->assertStringContainsString(
            $this->noTypeBranch('p.ID'),
            $this->lastQueryMatching('wc_product_meta_lookup', 'post_title LIKE'),
        );
    }

    /**
     * And the panel must not call it a loss. The complement of "has a supported
     * term OR has no term" excludes it by construction; the old hand-written
     * `IN (unsupported slugs)` excluded it by accident, and the accident was the
     * only thing keeping those two answers in step.
     */
    public function testTheConsequencesPanelDoesNotCallANoTypeProductALoss(): void
    {
        $this->withCatalogueTypes(['simple' => 23, 'course' => 2]);

        (new ScopeConsequences(new ScopeResolver(MigrationScope::everything())))->all();

        [$migratable, $values] = ProductTypes::migratableClause('p.ID');

        $this->assertStringContainsString(
            'NOT ' . $GLOBALS['wpdb']->prepare($migratable, ...$values),
            $this->lastQueryMatching('SELECT COUNT(*)', 'FROM wp_posts p', 't.slug IN ('),
            'The count of unmigratable products is the negation of the migrator\'s own predicate, so a '
            . 'product with no type term — which the predicate admits — cannot appear in it.',
        );
    }

    /**
     * The preflight histogram is a count the owner reads immediately before the
     * progress bar quotes another. Leaving untyped products out of it reports
     * "Simple: 25" over a run the migrator takes to 26 — the same disagreement
     * as the rest of this file, one screen earlier.
     */
    public function testPreflightCountsAnUntypedProductAsSimple(): void
    {
        $this->withCatalogueTypes(['simple' => 25, 'course' => 2], untyped: 1);

        $check = (new PreflightCheck())->run()['checks']['product_types'];

        $this->assertSame(26, $check['types']['simple'], 'The untyped product is a simple product.');
        $this->assertSame(28, array_sum($check['types']));
        $this->assertSame(['course' => 2], $check['unsupported'], 'It is not unsupported, merely untyped.');
    }

    // ──────────────────────────────────────────────
    // D2 — the WooCommerce Subscriptions gate
    // ──────────────────────────────────────────────

    /**
     * The gate used to live in ProductMigrator alone. Disabling the add-on
     * leaves the `subscription` terms in the database, so preflight and the
     * picker went on calling those products migratable while the migrator
     * dropped them.
     *
     * The gate is protective and stays — see ProductTypes::supported() for what
     * migrating a subscription product without the add-on actually produces —
     * so the honest outcome is that all four now report those products as
     * unmigratable on such a store. Which is what this asserts: the same
     * catalogue, one verdict.
     */
    public function testSubscriptionProductsAreUnmigratableEverywhereWithoutTheAddon(): void
    {
        $this->assertFalse(class_exists('WC_Subscriptions'), 'Precondition: the shared process has no add-on.');

        $this->withCatalogueTypes(['simple' => 9, 'subscription' => 4, 'variable-subscription' => 2]);

        $this->assertSame(
            ['subscription' => 4, 'variable-subscription' => 2],
            PreflightCheck::unsupportedProductTypeCounts(),
            'Preflight must name them, rather than leaving the migrator to drop them quietly.',
        );

        $this->productMigrator()->count();
        $this->previewController()->search($this->request(['type' => 'product', 'q' => 'sub']));
        (new ScopeConsequences(new ScopeResolver(MigrationScope::everything())))->all();

        $queries = $this->predicateQueries();

        $this->assertGreaterThanOrEqual(3, count($queries));

        foreach ($queries as $query) {
            $this->assertStringNotContainsString(
                "'subscription'",
                $query,
                'No caller may offer, count or source a subscription product without the add-on.',
            );
        }

        // And the panel says so out loud rather than leaving it to the
        // migrator to drop them off-screen: the negated predicate is what the
        // unmigratable-product count is built from.
        $this->assertQueriesContain(ProductTypes::unmigratableClause('p.ID'));
    }

    /**
     * The other side of the gate. With the add-on loaded the same catalogue is
     * fully migratable, and — the part that used to be wrong — preflight stops
     * calling the subscription types unsupported at the same moment the
     * migrator starts sourcing them, because both read one method.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSubscriptionProductsAreMigratableEverywhereWithTheAddon(): void
    {
        require_once dirname(__DIR__, 2) . '/stubs/WcSubscriptionsStub.php';

        $this->withCatalogueTypes(['simple' => 9, 'subscription' => 4, 'variable-subscription' => 2]);

        $this->assertSame([], PreflightCheck::unsupportedProductTypeCounts());

        $this->productMigrator()->count();
        $this->previewController()->search($this->request(['type' => 'product', 'q' => 'sub']));

        $queries = $this->predicateQueries();

        $this->assertGreaterThanOrEqual(2, count($queries));

        foreach ($queries as $query) {
            $this->assertStringContainsString("'subscription'", $query);
            $this->assertStringContainsString("'variable-subscription'", $query);
        }
    }

    // ──────────────────────────────────────────────
    // isVariable() — one predicate for the call sites that used to compare
    // $type === 'variable' by hand
    // ──────────────────────────────────────────────
    //
    // A second, structurally different disagreement lived alongside the D1/D2
    // one above: ProductMapper (twice), ProductMigrator (twice) and
    // MappingController (twice) each decided "does this product have children"
    // with a bare `$type === 'variable'` or `get_type() !== 'variable'`.
    // ProductTypes::supported() has advertised `variable-subscription` as
    // migratable since D2, but none of those six comparisons recognised it, so
    // a variable subscription product was silently collapsed to a single
    // pseudo-variation keyed off the parent — every variation, and every
    // distinct cadence sold through them, lost. isVariable() is the one
    // predicate all six now share.

    public function testIsVariableIsTrueForBothVariableTypes(): void
    {
        $this->assertTrue(ProductTypes::isVariable('variable'));
        $this->assertTrue(
            ProductTypes::isVariable('variable-subscription'),
            'variable-subscription carries its recurring configuration on the children, not the parent '
            . "type — structurally it has to agree with 'variable'.",
        );
    }

    public function testIsVariableIsFalseForEverythingThatIsNotStructurallyVariable(): void
    {
        $this->assertFalse(ProductTypes::isVariable('simple'));
        $this->assertFalse(ProductTypes::isVariable('subscription'), 'A plain subscription has no children.');
        $this->assertFalse(ProductTypes::isVariable('grouped'));
        $this->assertFalse(ProductTypes::isVariable('external'));
        $this->assertFalse(ProductTypes::isVariable(''), 'No product_type term at all is WooCommerce\'s own simple.');
    }

    /**
     * The regression this section exists to prevent. A static list of "the six
     * known sites" would go stale the moment a seventh is added and nobody
     * remembers to update this file to match — exactly the failure this whole
     * class exists to catch for the four-caller supported()/migratableClause()
     * story above. So this runs the same search the brief hands every
     * implementer, over the live `app/` tree, on every run: any file outside
     * ProductTypes.php itself that still spells the comparison out by hand
     * fails this test, no matter how it got there or how many more call sites
     * exist by then.
     */
    public function testNoCallSiteOutsideProductTypesComparesTheBareVariableLiteral(): void
    {
        $hits = self::scanForBareVariableComparisons();

        $this->assertSame(
            [],
            $hits,
            "Found a direct 'variable' string comparison outside ProductTypes.php. Use "
            . 'ProductTypes::isVariable() instead, so variable-subscription is never silently excluded '
            . 'again:' . "\n" . implode("\n", $hits),
        );
    }

    /**
     * Proof the scan above is not vacuously passing. ProductTypes.php's own
     * isVariable() docblock spells the historical bug out in prose —
     * `$type === 'variable'` — on purpose, so re-running the identical scan
     * without the ProductTypes.php exclusion has to find at least that one
     * line. If this assertion ever fails, the regex stopped matching anything
     * at all and the test above would be passing for the wrong reason.
     */
    public function testTheScanItselfCanFindAViolationWhenNothingIsExcluded(): void
    {
        $hits = self::scanForBareVariableComparisons(excludeProductTypes: false);

        $this->assertNotSame(
            [],
            $hits,
            'The scan matched nothing at all, even including ProductTypes.php — the pattern is broken, '
            . 'not the codebase.',
        );
    }

    /**
     * The brief's own `rg -n "get_type\(\).*['"]variable['"]|type[^\n]*=== ['"]variable['"]|
     * type[^\n]*!== ['"]variable['"]" app` command, reimplemented in PHP so this test does not
     * depend on ripgrep being installed wherever the suite runs — CI's `ubuntu-latest` runners
     * are not guaranteed to carry it, and nothing else in this harness depends on an external
     * binary. Same three alternatives, same target directory, applied line by line rather than
     * as one multiline regex: rg matches per line by default, and PHP's PCRE and Rust's regex
     * do not agree on `.` closely enough to trust a byte-for-byte multiline port.
     *
     * @return list<string> "relative/path:line: content" for every match, sorted for a stable diff.
     */
    private static function scanForBareVariableComparisons(bool $excludeProductTypes = true): array
    {
        $root = rtrim(CARTSHIFT_PLUGIN_PATH, '/') . '/app';

        $pattern = '/get_type\(\)[^\n]*[\'"]variable[\'"]|type[^\n]*=== [\'"]variable[\'"]|type[^\n]*!== [\'"]variable[\'"]/';

        $files = new \RegexIterator(
            new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            ),
            '/\.php$/',
        );

        $hits = [];

        foreach ($files as $file) {
            $path = (string) $file->getPathname();

            if ($excludeProductTypes && basename($path) === 'ProductTypes.php') {
                continue;
            }

            $lines = file($path, FILE_IGNORE_NEW_LINES);

            if ($lines === false) {
                continue;
            }

            foreach ($lines as $number => $line) {
                if (preg_match($pattern, $line) === 1) {
                    $hits[] = sprintf('app%s:%d: %s', substr($path, strlen($root)), $number + 1, trim($line));
                }
            }
        }

        sort($hits);

        return $hits;
    }

    // ──────────────────────────────────────────────
    // Harness
    // ──────────────────────────────────────────────

    /**
     * Describe the catalogue: a `product_type` histogram, plus how many
     * products carry no type term at all.
     *
     * @param array<string, int> $types
     */
    private function withCatalogueTypes(array $types, int $untyped = 0): void
    {
        $rows = [];

        foreach ($types as $slug => $count) {
            $rows[] = (object) ['slug' => $slug, 'count' => $count];
        }

        $GLOBALS['_cartshift_test_get_results_callback'] = static function (string $query) use ($rows): array {
            if (str_contains($query, 'GROUP BY t.slug')) {
                return $rows;
            }

            return [];
        };

        $GLOBALS['_cartshift_test_get_var_callback'] = static function (string $query) use ($untyped): string {
            if (str_contains($query, 'SHOW TABLES LIKE')) {
                return 'exists';
            }

            // The untyped count is the only COUNT(*) that reaches wp_posts
            // through the "carries no product_type term" anti-join.
            if (str_contains($query, 'FROM wp_posts p') && str_contains($query, 'NOT IN')) {
                return (string) $untyped;
            }

            return '0';
        };
    }

    /**
     * Make fetchProductIdPage() answer with exactly these IDs, once.
     *
     * @param list<int> $ids
     */
    private function stubIdPage(array $ids): void
    {
        $remaining = $ids;

        $GLOBALS['_cartshift_test_get_col_callback'] = static function (string $query) use (&$remaining): array {
            if (!str_contains($query, "tt.taxonomy = 'product_type'")) {
                return [];
            }

            $page = $remaining;
            $remaining = [];

            return array_map(strval(...), $page);
        };
    }

    /** @return list<string> Every query string the stubs were handed. */
    private function recordedQueries(): array
    {
        $queries = [];

        foreach ($GLOBALS['_cartshift_test_queries'] ?? [] as $entry) {
            if (($entry[0] ?? '') === 'prepare') {
                continue;
            }

            $queries[] = (string) ($entry[1] ?? '');
        }

        return $queries;
    }

    /**
     * The queries that carry ProductTypes' predicate — not merely any query
     * mentioning a slug list, because PreflightCheck::countOrdersAffectedByTypes()
     * legitimately quotes the UNsupported slugs while counting the orders that
     * carry them.
     *
     * Identified by the no-type branch, taken from the real clause rather than
     * matched against a pattern retyped here.
     *
     * @return list<string>
     */
    private function predicateQueries(): array
    {
        $markers = array_map(
            $this->noTypeBranch(...),
            ['p.ID', 'pml.product_id', 'CAST(im.meta_value AS UNSIGNED)'],
        );

        return array_values(array_filter(
            $this->recordedQueries(),
            static function (string $query) use ($markers): bool {
                foreach ($markers as $marker) {
                    if (str_contains($query, $marker)) {
                        return true;
                    }
                }

                return false;
            },
        ));
    }

    /**
     * `<column> NOT IN (every product carrying any product_type term)` — the
     * branch that makes an untyped product migratable, lifted straight out of
     * ProductTypes so no test here re-types its SQL.
     */
    private function noTypeBranch(string $column): string
    {
        [$sql] = ProductTypes::migratableClause($column);

        $parts = explode('OR ', $sql, 2);

        $this->assertCount(2, $parts, 'migratableClause() must still have a no-type branch to find.');

        return rtrim(rtrim(trim($parts[1]), ')'));
    }

    /**
     * The last recorded query containing every one of these needles.
     */
    private function lastQueryMatching(string ...$needles): string
    {
        $matches = array_values(array_filter(
            $this->recordedQueries(),
            static function (string $query) use ($needles): bool {
                foreach ($needles as $needle) {
                    if (!str_contains($query, $needle)) {
                        return false;
                    }
                }

                return true;
            },
        ));

        $this->assertNotSame([], $matches, sprintf('No query matching "%s" was run.', implode('" + "', $needles)));

        return (string) end($matches);
    }

    /**
     * @param array{0: string, 1: list<string>} $clause
     */
    private function assertQueriesContain(array $clause): void
    {
        [$sql, $values] = $clause;

        $expected = $GLOBALS['wpdb']->prepare($sql, ...$values);

        foreach ($this->recordedQueries() as $query) {
            if (str_contains($query, $expected)) {
                $this->addToAssertionCount(1);

                return;
            }
        }

        $this->fail('No query carried ProductTypes\' predicate: ' . $expected);
    }

    private function productMigrator(): ProductMigrator
    {
        return new ProductMigrator(
            new IdMapRepository(),
            new MigrationLogRepository(),
            new MigrationState(),
        );
    }

    private function previewController(): PreviewController
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

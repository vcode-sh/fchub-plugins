<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Migrator;

use CartShift\Migrator\ProductMigrator;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\Constants;
use CartShift\Tests\Unit\PluginTestCase;

require_once dirname(__DIR__, 2) . '/stubs/ProductMigratorStubs.php';
require_once dirname(__DIR__, 2) . '/stubs/FluentCartModelStubs.php';

/**
 * The orchestrator used to skip initialize() during a dry run and put nothing in
 * its place, because initialize() creates WordPress terms and FluentCart rows.
 * The consequence was that ENTITY_CATEGORY stayed empty for the whole run, so
 * CouponMapper resolved every `included_categories` and `excluded_categories`
 * restriction to nothing — and both of those keys are in WIDENING_ON_TOTAL_LOSS,
 * which means every coupon carrying a category restriction was reported as
 * would-be-disabled whether it would be or not.
 *
 * initializeSimulated() is the read-only stand-in: same maps, resolved from what
 * FluentCart already has, with synthetic IDs for what a real run would create.
 */
final class ProductMigratorSimulatedTaxonomyTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['_cartshift_test_id_map_rows'] = [];
        $GLOBALS['_cartshift_test_taxonomy_terms'] = [];
        $GLOBALS['_cartshift_test_wc_attribute_taxonomies'] = [];
        $GLOBALS['_cartshift_test_terms'] = [];
        $GLOBALS['_cartshift_test_inserted_terms'] = [];

        // A working ORM fake, so a FluentCart lookup answers "no such row" rather
        // than throwing. Reads only — nothing here calls create().
        \CartShiftFcModelStore::install();

        $GLOBALS['_cartshift_test_get_results_callback'] = static function (string $query): array {
            if (!str_contains($query, 'cartshift_id_map')) {
                return [];
            }

            preg_match("/entity_type = '([^']*)'/", $query, $matches);

            $rows = [];
            foreach ($GLOBALS['_cartshift_test_id_map_rows'] as $row) {
                if ($row['entity_type'] === $matches[1]) {
                    $rows[] = (object) ['wc_id' => (string) $row['wc_id'], 'fc_id' => $row['fc_id']];
                }
            }

            return $rows;
        };
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['_cartshift_test_get_results_callback'],
            $GLOBALS['_cartshift_test_id_map_rows'],
            $GLOBALS['_cartshift_test_taxonomy_terms'],
            $GLOBALS['_cartshift_test_wc_attribute_taxonomies'],
        );

        parent::tearDown();
    }

    /**
     * The residual "8 coupons would be disabled" figure this release exists to
     * produce is only meaningful if a category restriction can resolve at all.
     */
    public function testCategoriesAreRegisteredSoCouponRestrictionsCanResolve(): void
    {
        $GLOBALS['_cartshift_test_taxonomy_terms']['product_cat'] = [
            new \WP_Term(['term_id' => 10, 'name' => 'Shirts', 'slug' => 'shirts']),
        ];

        $migrator = $this->makeSimulatingMigrator();
        $migrator->initializeSimulated();

        $this->assertSame(
            [10 => 800_000_010],
            $this->readPrivate($migrator, 'categoryMap'),
            'A category FluentCart does not have yet still needs an answer, or the '
            . 'coupon that restricts to it is judged against silence.',
        );
    }

    /**
     * Where FluentCart already has the term, the rehearsal uses the real ID — the
     * dry run should predict what a real run would do, not what it would like.
     */
    public function testAnExistingFluentCartTermIsAdoptedRatherThanSimulated(): void
    {
        $GLOBALS['_cartshift_test_taxonomy_terms']['product_cat'] = [
            new \WP_Term(['term_id' => 10, 'name' => 'Shirts', 'slug' => 'shirts']),
        ];
        $GLOBALS['_cartshift_test_terms']['product-categories']['shirts'] = (object) ['term_id' => 44];

        $migrator = $this->makeSimulatingMigrator();
        $migrator->initializeSimulated();

        $this->assertSame([10 => 44], $this->readPrivate($migrator, 'categoryMap'));
    }

    /**
     * migrateCategories() skips 'uncategorized' outright, so a real run never maps
     * it. The rehearsal has to agree, or it predicts a restriction surviving that
     * would not.
     */
    public function testUncategorizedIsSkippedExactlyAsARealRunSkipsIt(): void
    {
        $GLOBALS['_cartshift_test_taxonomy_terms']['product_cat'] = [
            new \WP_Term(['term_id' => 1, 'name' => 'Uncategorized', 'slug' => 'uncategorized']),
            new \WP_Term(['term_id' => 10, 'name' => 'Shirts', 'slug' => 'shirts']),
        ];

        $migrator = $this->makeSimulatingMigrator();
        $migrator->initializeSimulated();

        $this->assertSame([10 => 800_000_010], $this->readPrivate($migrator, 'categoryMap'));
    }

    /**
     * The whole reason initialize() could not simply be called: it inserts.
     */
    public function testNothingIsCreatedOutsideTheIdMap(): void
    {
        $GLOBALS['_cartshift_test_taxonomy_terms']['product_cat'] = [
            new \WP_Term(['term_id' => 10, 'name' => 'Shirts', 'slug' => 'shirts']),
        ];
        $GLOBALS['_cartshift_test_taxonomy_terms']['product_brand'] = [
            new \WP_Term(['term_id' => 20, 'name' => 'Acme', 'slug' => 'acme']),
        ];
        $GLOBALS['_cartshift_test_taxonomy_terms']['product_shipping_class'] = [
            new \WP_Term(['term_id' => 30, 'name' => 'Bulky', 'slug' => 'bulky']),
        ];

        $migrator = $this->makeSimulatingMigrator();
        $migrator->initializeSimulated();

        $this->assertSame([], $GLOBALS['_cartshift_test_inserted_terms'], 'No WordPress terms.');

        foreach ($GLOBALS['_cartshift_test_queries'] as $query) {
            if ($query[0] !== 'insert') {
                continue;
            }

            $this->assertMatchesRegularExpression(
                '/cartshift_(id_map|migration_log)$/',
                (string) $query[1],
                sprintf('A dry run wrote to %s, which it has no business touching.', $query[1]),
            );
        }
    }

    public function testEveryRegisteredRowBelongsToTheSimulatedRealm(): void
    {
        $GLOBALS['_cartshift_test_taxonomy_terms']['product_cat'] = [
            new \WP_Term(['term_id' => 10, 'name' => 'Shirts', 'slug' => 'shirts']),
        ];

        $migrator = $this->makeSimulatingMigrator();
        $migrator->initializeSimulated();

        $inserts = array_values(array_filter(
            $GLOBALS['_cartshift_test_queries'],
            static fn (array $q): bool => $q[0] === 'insert' && str_contains((string) $q[1], 'id_map'),
        ));

        $this->assertNotSame([], $inserts);

        foreach ($inserts as $insert) {
            $this->assertSame(1, $insert[2]['is_simulated']);
        }
    }

    /**
     * A mapping already in the ID map from an earlier real run wins, and is not
     * written a second time — the same idempotency initialize() has, which also
     * makes a re-entered first batch harmless.
     */
    public function testStoredMappingsAreAdoptedWithoutASecondRow(): void
    {
        $GLOBALS['_cartshift_test_id_map_rows'][] = [
            'entity_type' => Constants::ENTITY_CATEGORY,
            'wc_id'       => '10',
            'fc_id'       => 100,
        ];
        $GLOBALS['_cartshift_test_taxonomy_terms']['product_cat'] = [
            new \WP_Term(['term_id' => 10, 'name' => 'Shirts', 'slug' => 'shirts']),
        ];

        $migrator = $this->makeSimulatingMigrator();
        $migrator->initializeSimulated();

        $this->assertSame([10 => 100], $this->readPrivate($migrator, 'categoryMap'));
        $this->assertSame(
            0,
            count(array_filter(
                $GLOBALS['_cartshift_test_queries'],
                static fn (array $q): bool => $q[0] === 'insert' && str_contains((string) $q[1], 'id_map'),
            )),
        );
    }

    /**
     * Categories and shipping classes are both wp_terms, whose IDs are unique
     * across taxonomies, so one synthetic base serves both without collision. The
     * base also has to stay clear of the orchestrator's counter (900,000,001 up)
     * and of ProductMigrator's variation base.
     */
    public function testSyntheticIdsAreObviouslyFakeAndDoNotCollideAcrossEntityTypes(): void
    {
        $GLOBALS['_cartshift_test_taxonomy_terms']['product_cat'] = [
            new \WP_Term(['term_id' => 10, 'name' => 'Shirts', 'slug' => 'shirts']),
        ];
        $GLOBALS['_cartshift_test_taxonomy_terms']['product_shipping_class'] = [
            new \WP_Term(['term_id' => 30, 'name' => 'Bulky', 'slug' => 'bulky']),
        ];

        $migrator = $this->makeSimulatingMigrator();
        $migrator->initializeSimulated();

        $category      = $this->readPrivate($migrator, 'categoryMap')[10];
        $shippingClass = $this->readPrivate($migrator, 'shippingClassMap')[30];

        $this->assertNotSame($category, $shippingClass);

        foreach ([$category, $shippingClass] as $id) {
            $this->assertGreaterThan(700_000_000, $id, 'No real FluentCart ID looks like this.');
            $this->assertLessThan(900_000_000, $id, 'And it stays clear of the orchestrator\'s counter.');
        }
    }

    /**
     * Populating the shipping class map is only useful if the mappers are rebuilt
     * around it — they hold a copy.
     */
    public function testTheMappersAreRebuiltAroundTheSimulatedShippingClasses(): void
    {
        $GLOBALS['_cartshift_test_taxonomy_terms']['product_shipping_class'] = [
            new \WP_Term(['term_id' => 30, 'name' => 'Bulky', 'slug' => 'bulky']),
        ];

        $migrator = $this->makeSimulatingMigrator();
        $migrator->initializeSimulated();

        $this->assertSame(
            [30 => 800_000_030],
            $this->readPrivate($this->readPrivate($migrator, 'productMapper'), 'shippingClassMap'),
        );
    }

    private function makeSimulatingMigrator(): ProductMigrator
    {
        $idMap = new IdMapRepository();
        $idMap->setSimulating(true);

        return new ProductMigrator($idMap, new MigrationLogRepository(), new MigrationState());
    }

    private function readPrivate(object $target, string $property): mixed
    {
        return (new \ReflectionClass($target))->getProperty($property)->getValue($target);
    }
}

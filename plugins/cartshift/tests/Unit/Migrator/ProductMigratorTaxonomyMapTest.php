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

/**
 * BUG 1: the taxonomy maps were instance state populated only by initialize(),
 * which the orchestrator calls on the first batch only — while a brand new
 * ProductMigrator is constructed for every REST batch and every Action Scheduler
 * invocation. Products past the first batch silently lost their categories,
 * brands, attributes and shipping classes.
 */
final class ProductMigratorTaxonomyMapTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['_cartshift_test_id_map_rows'] = [];
        $GLOBALS['_cartshift_test_taxonomy_terms'] = [];
        $GLOBALS['_cartshift_test_wc_attribute_taxonomies'] = [];
        $GLOBALS['_cartshift_test_terms'] = [];
        $GLOBALS['_cartshift_test_inserted_terms'] = [];

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

        $GLOBALS['_cartshift_test_get_var_callback'] = static function (string $query): string|null {
            if (!str_contains($query, 'cartshift_id_map')) {
                return null;
            }

            preg_match("/entity_type = '([^']*)' AND wc_id = '([^']*)'/", $query, $matches);

            foreach ($GLOBALS['_cartshift_test_id_map_rows'] as $row) {
                if ($row['entity_type'] === $matches[1] && $row['wc_id'] === $matches[2]) {
                    return (string) $row['fc_id'];
                }
            }

            return null;
        };
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['_cartshift_test_get_results_callback'],
            $GLOBALS['_cartshift_test_get_var_callback'],
            $GLOBALS['_cartshift_test_id_map_rows'],
            $GLOBALS['_cartshift_test_taxonomy_terms'],
            $GLOBALS['_cartshift_test_wc_attribute_taxonomies'],
        );

        parent::tearDown();
    }

    // ────────────────────────────────────────────
    // Rehydration without initialize()
    // ────────────────────────────────────────────

    public function testRehydratesTermMapsFromTheIdMap(): void
    {
        $this->seedRow(Constants::ENTITY_CATEGORY, '10', 100);
        $this->seedRow(Constants::ENTITY_CATEGORY, '11', 101);
        $this->seedRow(Constants::ENTITY_BRAND, '20', 200);
        $this->seedRow(Constants::ENTITY_SHIPPING_CLASS, '30', 300);

        $migrator = $this->makeMigrator();
        $this->invokePrivate($migrator, 'ensureTaxonomyMaps');

        $this->assertSame([10 => 100, 11 => 101], $this->readPrivate($migrator, 'categoryMap'));
        $this->assertSame([20 => 200], $this->readPrivate($migrator, 'brandMap'));
        $this->assertSame([30 => 300], $this->readPrivate($migrator, 'shippingClassMap'));
    }

    public function testRehydrationRebuildsBothMappersWithTheShippingClassMap(): void
    {
        $this->seedRow(Constants::ENTITY_SHIPPING_CLASS, '30', 300);

        $migrator = $this->makeMigrator();

        $this->assertSame(
            [],
            $this->readPrivate($this->readPrivate($migrator, 'productMapper'), 'shippingClassMap'),
            'Sanity check: the constructor builds mappers without shipping classes',
        );

        $this->invokePrivate($migrator, 'ensureTaxonomyMaps');

        $this->assertSame(
            [30 => 300],
            $this->readPrivate($this->readPrivate($migrator, 'productMapper'), 'shippingClassMap'),
        );
        $this->assertSame(
            [30 => 300],
            $this->readPrivate($this->readPrivate($migrator, 'variationMapper'), 'shippingClassMap'),
        );
    }

    public function testRehydratesAttributeMapsUsingCompositeKeys(): void
    {
        // The ID map stores WC attribute_id and WC term_id, but the maps are keyed
        // by taxonomy slug and "groupSlug:termSlug" — so the keys must be re-derived.
        $this->seedRow(Constants::ENTITY_ATTRIBUTE_GROUP, '5', 70);
        $this->seedRow(Constants::ENTITY_ATTRIBUTE_TERM, '31', 81);
        $this->seedRow(Constants::ENTITY_ATTRIBUTE_TERM, '32', 82);

        $GLOBALS['_cartshift_test_wc_attribute_taxonomies'] = [
            (object) ['attribute_id' => 5, 'attribute_name' => 'color', 'attribute_label' => 'Colour'],
        ];
        $GLOBALS['_cartshift_test_taxonomy_terms']['pa_color'] = [
            new \WP_Term(['term_id' => 31, 'name' => 'Red', 'slug' => 'red']),
            new \WP_Term(['term_id' => 32, 'name' => 'Blue', 'slug' => 'blue']),
        ];

        $migrator = $this->makeMigrator();
        $this->invokePrivate($migrator, 'ensureTaxonomyMaps');

        $this->assertSame(['pa_color' => 70], $this->readPrivate($migrator, 'attributeGroupMap'));
        $this->assertSame(
            ['color:red' => 81, 'color:blue' => 82],
            $this->readPrivate($migrator, 'attributeTermMap'),
        );
    }

    public function testAttributeRehydrationSkipsUnmappedAttributes(): void
    {
        $this->seedRow(Constants::ENTITY_ATTRIBUTE_GROUP, '5', 70);

        $GLOBALS['_cartshift_test_wc_attribute_taxonomies'] = [
            (object) ['attribute_id' => 5, 'attribute_name' => 'color', 'attribute_label' => 'Colour'],
            (object) ['attribute_id' => 6, 'attribute_name' => 'size', 'attribute_label' => 'Size'],
        ];

        $migrator = $this->makeMigrator();
        $this->invokePrivate($migrator, 'ensureTaxonomyMaps');

        $this->assertSame(['pa_color' => 70], $this->readPrivate($migrator, 'attributeGroupMap'));
    }

    public function testRehydrationHappensOnlyOnce(): void
    {
        $this->seedRow(Constants::ENTITY_CATEGORY, '10', 100);

        $migrator = $this->makeMigrator();

        $this->invokePrivate($migrator, 'ensureTaxonomyMaps');
        $afterFirst = $this->countIdMapQueries();

        $this->invokePrivate($migrator, 'ensureTaxonomyMaps');

        $this->assertGreaterThan(0, $afterFirst, 'The first call must read the ID map');
        $this->assertSame($afterFirst, $this->countIdMapQueries(), 'The second call must be free');
    }

    public function testProcessRecordRehydratesWithoutInitialize(): void
    {
        $this->seedRow(Constants::ENTITY_CATEGORY, '10', 100);
        $this->seedRow(Constants::ENTITY_SHIPPING_CLASS, '30', 300);
        // Product 5 is already migrated, so processRecord() short-circuits before
        // touching the ORM — but the maps must be live by then all the same.
        $this->seedRow(Constants::ENTITY_PRODUCT, '5', 500);

        $migrator = $this->makeMigrator();

        $this->assertFalse($migrator->processRecord($this->makeProduct(5)));

        $this->assertSame([10 => 100], $this->readPrivate($migrator, 'categoryMap'));
        $this->assertSame([30 => 300], $this->readPrivate($migrator, 'shippingClassMap'));
    }

    // ────────────────────────────────────────────
    // initialize() is idempotent
    // ────────────────────────────────────────────

    public function testMigrateCategoriesAdoptsStoredMappingsSilently(): void
    {
        $this->seedRow(Constants::ENTITY_CATEGORY, '10', 100);

        $GLOBALS['_cartshift_test_taxonomy_terms']['product_cat'] = [
            new \WP_Term(['term_id' => 10, 'name' => 'Shirts', 'slug' => 'shirts']),
        ];

        $migrator = $this->makeMigrator();
        $migrator->migrateCategories();

        $this->assertSame([10 => 100], $this->readPrivate($migrator, 'categoryMap'));
        $this->assertSame(0, $this->countInserts('cartshift_id_map'), 'No duplicate ID map row');
        $this->assertSame(0, $this->countInserts('cartshift_migration_log'), 'No repeated log line');
    }

    public function testMigrateCategoriesFirstRunStillRecordsAndLogs(): void
    {
        // Nothing in the ID map yet, but the FC term already exists.
        $GLOBALS['_cartshift_test_taxonomy_terms']['product_cat'] = [
            new \WP_Term(['term_id' => 10, 'name' => 'Shirts', 'slug' => 'shirts']),
        ];
        $GLOBALS['_cartshift_test_terms']['product-categories']['shirts'] = (object) ['term_id' => 100];

        $migrator = $this->makeMigrator();
        $migrator->migrateCategories();

        $this->assertSame([10 => 100], $this->readPrivate($migrator, 'categoryMap'));
        $this->assertSame(1, $this->countInserts('cartshift_id_map'), 'First run records the mapping');
        $this->assertSame(1, $this->countInserts('cartshift_migration_log'), 'First run logs the skip');
    }

    public function testMigrateCategoriesCreatesMissingTermsOnTheFirstRun(): void
    {
        $GLOBALS['_cartshift_test_taxonomy_terms']['product_cat'] = [
            new \WP_Term(['term_id' => 10, 'name' => 'Shirts', 'slug' => 'shirts']),
        ];

        $migrator = $this->makeMigrator();
        $migrator->migrateCategories();

        $this->assertCount(1, $GLOBALS['_cartshift_test_inserted_terms']);
        $this->assertSame(1, $this->countInserts('cartshift_id_map'));
    }

    public function testMigrateShippingClassesAdoptsStoredMappingsSilently(): void
    {
        $this->seedRow(Constants::ENTITY_SHIPPING_CLASS, '30', 300);

        $GLOBALS['_cartshift_test_taxonomy_terms']['product_shipping_class'] = [
            new \WP_Term(['term_id' => 30, 'name' => 'Bulky', 'slug' => 'bulky']),
        ];

        $migrator = $this->makeMigrator();

        // A stored mapping must short-circuit before any FluentCart model call.
        $migrator->migrateShippingClasses();

        $this->assertSame([30 => 300], $this->readPrivate($migrator, 'shippingClassMap'));
        $this->assertSame(0, $this->countInserts('cartshift_id_map'));
        $this->assertSame(0, $this->countInserts('cartshift_migration_log'));
    }

    public function testMigrateBrandsAdoptsStoredMappingsSilently(): void
    {
        $this->seedRow(Constants::ENTITY_BRAND, '20', 200);

        $GLOBALS['_cartshift_test_taxonomy_terms']['product_brand'] = [
            new \WP_Term(['term_id' => 20, 'name' => 'Acme', 'slug' => 'acme']),
        ];

        $migrator = $this->makeMigrator();
        $migrator->migrateBrands();

        $this->assertSame([20 => 200], $this->readPrivate($migrator, 'brandMap'));
        $this->assertSame(0, $this->countInserts('cartshift_id_map'));
        $this->assertSame(0, $this->countInserts('cartshift_migration_log'));
    }

    // ────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────

    private function makeMigrator(): ProductMigrator
    {
        return new ProductMigrator(
            new IdMapRepository(),
            new MigrationLogRepository(),
            new MigrationState(),
        );
    }

    private function makeProduct(int $id): \WC_Product
    {
        $product = new \WC_Product();

        $ref = new \ReflectionClass($product);
        $ref->getProperty('id')->setValue($product, $id);

        return $product;
    }

    private function seedRow(string $entityType, string $wcId, int $fcId): void
    {
        $GLOBALS['_cartshift_test_id_map_rows'][] = [
            'entity_type' => $entityType,
            'wc_id'       => $wcId,
            'fc_id'       => $fcId,
        ];
    }

    private function invokePrivate(object $target, string $method, array $args = []): mixed
    {
        return (new \ReflectionClass($target))->getMethod($method)->invokeArgs($target, $args);
    }

    private function readPrivate(object $target, string $property): mixed
    {
        return (new \ReflectionClass($target))->getProperty($property)->getValue($target);
    }

    private function countIdMapQueries(): int
    {
        return count(array_filter(
            $GLOBALS['_cartshift_test_queries'] ?? [],
            static fn (array $query): bool => in_array($query[0], ['get_var', 'get_results'], true)
                && str_contains((string) $query[1], 'cartshift_id_map'),
        ));
    }

    private function countInserts(string $table): int
    {
        return count(array_filter(
            $GLOBALS['_cartshift_test_queries'] ?? [],
            static fn (array $query): bool => $query[0] === 'insert'
                && str_contains((string) $query[1], $table),
        ));
    }
}

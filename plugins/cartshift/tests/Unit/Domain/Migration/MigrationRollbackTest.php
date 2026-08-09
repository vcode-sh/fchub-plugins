<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Migration;

use CartShift\Domain\Migration\MigrationRollback;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\Constants;
use CartShift\Tests\Unit\PluginTestCase;
use FluentCart\App\Models\ProductVariation;

require_once dirname(__DIR__, 3) . '/stubs/FluentCartModelStubs.php';

final class MigrationRollbackTest extends PluginTestCase
{
    private IdMapRepository $idMap;
    private MigrationLogRepository $log;
    private MigrationRollback $rollback;

    protected function setUp(): void
    {
        parent::setUp();

        $this->idMap    = new IdMapRepository();
        $this->log      = new MigrationLogRepository();
        $this->rollback = new MigrationRollback($this->idMap, $this->log);
    }

    /**
     * PluginTestCase::setUp() does not clear the query callbacks, so a test that
     * installs one and walks away leaves it running for every class that follows.
     * The callbacks here return stdClass rows — correct for this class, since the
     * id-map reads them as objects — which then land in a later class that asked
     * for ARRAY_A and indexes them as arrays. That surfaces as a fatal several
     * files away with nothing pointing back here, so the cleanup belongs with the
     * code that does the setting.
     */
    protected function tearDown(): void
    {
        unset(
            $GLOBALS['_cartshift_test_get_results_callback'],
            $GLOBALS['_cartshift_test_get_var_callback'],
        );

        parent::tearDown();
    }

    /**
     * Rollback should only request records flagged created_by_migration.
     * The query must include "created_by_migration = 1".
     */
    public function testRollbackOnlyDeletesCreatedByMigration(): void
    {
        $migrationId = 'test-migration-123';

        // Configure wpdb to return one product mapping for the ENTITY_PRODUCT entity type.
        $GLOBALS['_cartshift_test_get_results_callback'] = function (string $query) use ($migrationId): array {
            if (str_contains($query, 'created_by_migration') && str_contains($query, Constants::ENTITY_PRODUCT)) {
                return [(object) ['wc_id' => '42', 'fc_id' => 100]];
            }
            return [];
        };

        $stats = $this->rollback->rollback($migrationId);

        $this->assertArrayHasKey(Constants::ENTITY_PRODUCT, $stats);
        $this->assertSame(1, $stats[Constants::ENTITY_PRODUCT]);

        // Verify wp_delete_post was called for the product (fc_id = 100).
        $deletedPosts = $GLOBALS['_cartshift_test_deleted_posts'] ?? [];
        $this->assertNotEmpty($deletedPosts);
        $this->assertSame(100, $deletedPosts[0][0]);
        $this->assertTrue($deletedPosts[0][1]); // force_delete = true
    }

    /**
     * Rollback order includes guest_customer — verify it is processed.
     */
    public function testRollbackIncludesGuestCustomer(): void
    {
        $migrationId = 'test-migration-456';

        $GLOBALS['_cartshift_test_get_results_callback'] = function (string $query): array {
            if (str_contains($query, Constants::ENTITY_GUEST_CUSTOMER)) {
                return [(object) ['wc_id' => 'guest_99', 'fc_id' => 501]];
            }
            return [];
        };

        $stats = $this->rollback->rollback($migrationId);

        // Guest customer maps to fct_customers table via deleteFromTable.
        $this->assertArrayHasKey(Constants::ENTITY_GUEST_CUSTOMER, $stats);
        $this->assertSame(1, $stats[Constants::ENTITY_GUEST_CUSTOMER]);

        // Verify a delete query was issued for the customers table.
        $queries = $GLOBALS['_cartshift_test_queries'] ?? [];
        $deleteQueries = array_filter($queries, fn (array $q) => $q[0] === 'delete');
        $this->assertNotEmpty($deleteQueries);

        $lastDelete = end($deleteQueries);
        $this->assertStringContainsString('fct_customers', $lastDelete[1]);
        $this->assertSame(['id' => 501], $lastDelete[2]);
    }

    /**
     * The rollback iteration order must exactly match Constants::ROLLBACK_ORDER.
     * Verify entity types are processed in dependency-safe order.
     */
    public function testRollbackOrderMatchesConstants(): void
    {
        $migrationId = 'test-migration-789';
        $processedTypes = [];

        $GLOBALS['_cartshift_test_get_results_callback'] = function (string $query) use (&$processedTypes): array {
            // Extract entity type from the query. The query contains entity_type = '{type}'.
            foreach (Constants::ROLLBACK_ORDER as $type) {
                if (str_contains($query, "'{$type}'")) {
                    $processedTypes[] = $type;
                    return [(object) ['wc_id' => '1', 'fc_id' => 1]];
                }
            }
            return [];
        };

        $this->rollback->rollback($migrationId);

        // Every entity type in ROLLBACK_ORDER was queried, in that exact order.
        $this->assertSame(Constants::ROLLBACK_ORDER, $processedTypes);
    }

    /**
     * Rollback returns a stats array keyed by entity type with deletion counts.
     */
    public function testRollbackReturnsStats(): void
    {
        $migrationId = 'test-migration-stats';

        $GLOBALS['_cartshift_test_get_results_callback'] = function (string $query): array {
            if (str_contains($query, Constants::ENTITY_ORDER)) {
                return [
                    (object) ['wc_id' => '10', 'fc_id' => 200],
                    (object) ['wc_id' => '11', 'fc_id' => 201],
                    (object) ['wc_id' => '12', 'fc_id' => 202],
                ];
            }
            if (str_contains($query, Constants::ENTITY_CATEGORY)) {
                return [
                    (object) ['wc_id' => '5', 'fc_id' => 300],
                ];
            }
            return [];
        };

        $stats = $this->rollback->rollback($migrationId);

        // order appears in multiple ROLLBACK_ORDER slots (order, order_item, etc.)
        // but the callback only returns data when the query contains 'order' literal.
        // Categories use wp_delete_term.
        $this->assertArrayHasKey(Constants::ENTITY_CATEGORY, $stats);
        $this->assertSame(1, $stats[Constants::ENTITY_CATEGORY]);

        // Verify wp_delete_term was called for the category.
        $deletedTerms = $GLOBALS['_cartshift_test_deleted_terms'] ?? [];
        $this->assertNotEmpty($deletedTerms);
        $this->assertSame(300, $deletedTerms[0][0]);
        $this->assertSame('product-categories', $deletedTerms[0][1]);

        // Overall stats should only include entity types that had records.
        foreach ($stats as $count) {
            $this->assertGreaterThan(0, $count);
        }
    }

    // ──────────────────────────────────────────────
    // Orphan child-row cleanup
    // ──────────────────────────────────────────────

    /**
     * fct_order_meta and fct_applied_coupons rows are written per order but never
     * mapped, so rollback used to leave them behind. They must be deleted by order_id.
     */
    public function testOrderChildRowsAreDeletedByOrderId(): void
    {
        $this->mapOnly(Constants::ENTITY_ORDER, [200, 201]);

        $this->rollback->rollback('test-orphans-orders');

        $couponSql = $this->findDeleteFor('fct_applied_coupons');
        $this->assertStringContainsString('order_id IN (200,201)', $couponSql);

        $metaSql = $this->findDeleteFor('fct_order_meta');
        $this->assertStringContainsString('order_id IN (200,201)', $metaSql);
    }

    /**
     * fct_product_downloads hangs off the product post via post_id — verified against
     * FluentCart's ProductDownloadsMigrator schema, which has no `product_id` column.
     */
    public function testProductDownloadsAreDeletedByPostId(): void
    {
        $this->mapOnly(Constants::ENTITY_PRODUCT, [300]);

        $this->rollback->rollback('test-orphans-downloads');

        $sql = $this->findDeleteFor('fct_product_downloads');
        $this->assertStringContainsString('post_id IN (300)', $sql);
    }

    /**
     * fct_atts_relations and fct_product_meta both key off the FC variation id.
     * fct_product_meta.object_id is only unique within an object_type, so the
     * variant-info filter must be part of the WHERE clause or the delete could take
     * out unrelated product-level meta that happens to share the id.
     */
    public function testVariationChildRowsAreDeletedWithObjectTypeGuard(): void
    {
        $this->mapOnly(Constants::ENTITY_VARIATION, [400, 401]);

        $this->rollback->rollback('test-orphans-variations');

        $relationsSql = $this->findDeleteFor('fct_atts_relations');
        $this->assertStringContainsString('object_id IN (400,401)', $relationsSql);
        $this->assertStringNotContainsString('object_type', $relationsSql);

        $metaSql = $this->findDeleteFor('fct_product_meta');
        $this->assertStringContainsString('object_id IN (400,401)', $metaSql);
        $this->assertStringContainsString("object_type = 'product_variant_info'", $metaSql);
    }

    /**
     * Deletes are batched into `IN (...)` chunks, not one query per row. A migration
     * of any size would otherwise make rollback take longer than the migration did.
     */
    public function testOrphanDeletesAreBatchedNotPerRow(): void
    {
        $ids = range(1000, 1149); // 150 orders
        $this->mapOnly(Constants::ENTITY_ORDER, $ids);

        $this->rollback->rollback('test-orphans-batching');

        $orderMetaDeletes = array_filter(
            $GLOBALS['_cartshift_test_queries'] ?? [],
            static fn (array $q): bool => $q[0] === 'query'
                && str_contains($q[1], 'DELETE FROM')
                && str_contains($q[1], 'fct_order_meta'),
        );

        $this->assertCount(
            1,
            $orderMetaDeletes,
            '150 ids fit in one chunk — expected a single batched DELETE, not 150.',
        );
    }

    /**
     * No mapped parents means nothing to clean up — and no pointless empty
     * `IN ()` queries, which are a syntax error anyway.
     */
    public function testNoOrphanDeletesWhenNothingWasMigrated(): void
    {
        $GLOBALS['_cartshift_test_get_results_callback'] = fn (): array => [];

        $stats = $this->rollback->rollback('test-orphans-empty');

        $this->assertSame([], $stats);

        // The id-map's own cleanup is a DELETE too, and is expected; no fct_ table
        // should be touched.
        $fctDeletes = array_filter(
            $GLOBALS['_cartshift_test_queries'] ?? [],
            static fn (array $q): bool => $q[0] === 'query'
                && str_contains($q[1], 'DELETE FROM')
                && str_contains($q[1], 'fct_'),
        );

        $this->assertSame([], $fctDeletes, 'No parents mapped means no child cleanup at all.');
    }

    /**
     * Children must go before their parents, so the parent ids are still meaningful
     * when the child deletes are built.
     */
    public function testChildRowsAreDeletedBeforeParents(): void
    {
        $this->mapOnly(Constants::ENTITY_ORDER, [500]);

        $this->rollback->rollback('test-orphans-ordering');

        $childIndex = null;
        $parentIndex = null;

        foreach (array_values($GLOBALS['_cartshift_test_queries'] ?? []) as $i => $entry) {
            if ($childIndex === null && $entry[0] === 'query' && str_contains($entry[1], 'fct_order_meta')) {
                $childIndex = $i;
            }

            if ($parentIndex === null && $entry[0] === 'delete' && str_contains($entry[1], 'fct_orders')) {
                $parentIndex = $i;
            }
        }

        $this->assertNotNull($childIndex, 'Expected an fct_order_meta cleanup query.');
        $this->assertNotNull($parentIndex, 'Expected the parent order delete.');
        $this->assertLessThan($parentIndex, $childIndex);
    }

    // ──────────────────────────────────────────────
    // Price range on products that survive the rollback
    // ──────────────────────────────────────────────
    //
    // The one way rollback used to leave a hand-built product different from
    // how it found it. MappingPromoter adds orphan variants inside the owner's
    // own product and recomputes fct_product_details.min_price/max_price to
    // take them in; rollback deleted those variants and left the widened range
    // behind. Reproduced live: a five-variant product priced 1100–1230 took
    // three migrated variants at 7900, was correctly recomputed to 1100–7900,
    // and after rollback still advertised "up to £79.00" with nothing dearer
    // than £12.30 left to buy.

    /**
     * The range must follow the variants that are still there.
     */
    public function testTheRangeOfASurvivingProductFollowsItsRemainingVariants(): void
    {
        $detail = $this->seedLinkedProduct();
        $this->seedSurvivingVariant(1100.0);
        $this->seedSurvivingVariant(1230.0);

        $this->rollbackDeletingVariantsFrom([900, 901, 902], productWasCreated: false);

        $this->assertSame(1100.0, $detail->min_price);
        $this->assertSame(
            1230.0,
            $detail->max_price,
            'The dearest surviving variant is 1230; 7900 was the migrated one rollback just deleted.',
        );
        $this->assertContains(
            $detail,
            $GLOBALS['_cartshift_test_fc_saved'] ?? [],
            'Mutating the row in memory corrects nothing — it has to be written back.',
        );
    }

    /**
     * Stock availability is refreshed alongside the range, because the add path
     * refreshes both and the two must not drift apart.
     */
    public function testStockAvailabilityIsRefreshedAlongsideTheRange(): void
    {
        $detail = $this->seedLinkedProduct(['manage_stock' => 1, 'stock_availability' => 'in-stock']);
        $this->seedSurvivingVariant(1100.0, 'out-of-stock');

        $this->rollbackDeletingVariantsFrom([900], productWasCreated: false);

        $this->assertSame('out-of-stock', $detail->stock_availability);
    }

    /**
     * A product CartShift created is deleted a few lines later, so recomputing
     * a price range for it is work done on a corpse — and the ID map is what
     * tells the two apart.
     */
    public function testAProductTheMigrationCreatedIsNotRecomputed(): void
    {
        $detail = $this->seedLinkedProduct();
        $this->seedSurvivingVariant(1100.0);

        $this->rollbackDeletingVariantsFrom([900, 901, 902], productWasCreated: true);

        $this->assertSame(7900.0, $detail->max_price, 'Nothing should have touched a product about to be deleted.');
        $this->assertSame([], $GLOBALS['_cartshift_test_fc_saved'] ?? []);
    }

    /**
     * The parent lookup reads `post_id` off the variation rows themselves, so it
     * has to happen before the deletion loop destroys them.
     */
    public function testTheParentProductIsLookedUpBeforeTheVariantsAreDeleted(): void
    {
        $this->seedLinkedProduct();
        $this->seedSurvivingVariant(1100.0);

        $this->rollbackDeletingVariantsFrom([900], productWasCreated: false);

        $lookupIndex = null;
        $deleteIndex = null;

        foreach (array_values($GLOBALS['_cartshift_test_queries'] ?? []) as $i => $entry) {
            if ($lookupIndex === null && $entry[0] === 'get_col' && str_contains($entry[1], 'fct_product_variations')) {
                $lookupIndex = $i;
            }

            if ($deleteIndex === null && $entry[0] === 'delete' && str_contains($entry[1], 'fct_product_variations')) {
                $deleteIndex = $i;
            }
        }

        $this->assertNotNull($lookupIndex, 'Expected a parent-product lookup against fct_product_variations.');
        $this->assertNotNull($deleteIndex, 'Expected the variation rows to be deleted.');
        $this->assertLessThan($deleteIndex, $lookupIndex);
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    /**
     * The owner's hand-built product, as the migration left it: a range widened
     * to 7900 by the variants CartShift added inside it.
     */
    private function seedLinkedProduct(array $overrides = []): object
    {
        \CartShiftFcModelStore::install();

        return \CartShiftFcModelStore::seed('ProductDetail', array_merge([
            'id'                 => 1,
            'post_id'            => 40,
            'variation_type'     => 'simple_variations',
            'min_price'          => 1100.0,
            'max_price'          => 7900.0,
            'manage_stock'       => 0,
            'stock_availability' => 'in-stock',
        ], $overrides));
    }

    /**
     * A variant of that product that rollback does not touch.
     *
     * Created rather than seeded because the fake's aggregates read created
     * rows — which is the right model here: the recompute runs after the
     * deletes, so what it sees is precisely what survived them.
     */
    private function seedSurvivingVariant(float $price, string $stockStatus = 'in-stock'): void
    {
        ProductVariation::query()->create([
            'post_id'      => 40,
            'item_price'   => $price,
            'stock_status' => $stockStatus,
        ]);
    }

    /**
     * Roll back a run that created `$fcVariationIds` inside product 40.
     *
     * `$productWasCreated` is the only difference between the owner's product
     * and one of CartShift's: a created product has its own
     * created_by_migration row in the ID map, a linked one does not.
     *
     * @param int[] $fcVariationIds
     */
    private function rollbackDeletingVariantsFrom(array $fcVariationIds, bool $productWasCreated): void
    {
        $GLOBALS['_cartshift_test_get_results_callback'] =
            static function (string $query) use ($fcVariationIds, $productWasCreated): array {
                if (str_contains($query, "entity_type = '" . Constants::ENTITY_VARIATION . "'")) {
                    return array_map(
                        static fn (int $fcId): object => (object) ['wc_id' => (string) $fcId, 'fc_id' => $fcId],
                        $fcVariationIds,
                    );
                }

                if ($productWasCreated && str_contains($query, "entity_type = '" . Constants::ENTITY_PRODUCT . "'")) {
                    return [(object) ['wc_id' => '7', 'fc_id' => 40]];
                }

                return [];
            };

        // What `SELECT DISTINCT post_id ... WHERE id IN (…)` answers: every one
        // of those variants belongs to product 40.
        $GLOBALS['_cartshift_test_get_col_callback'] = static fn (string $query): array
            => str_contains($query, 'fct_product_variations') ? ['40'] : [];

        $this->rollback->rollback('test-rollback-price-range');
    }

    /**
     * Make the id-map return the given FC ids for exactly one entity type.
     *
     * @param int[] $fcIds
     */
    private function mapOnly(string $entityType, array $fcIds): void
    {
        $GLOBALS['_cartshift_test_get_results_callback'] =
            function (string $query) use ($entityType, $fcIds): array {
                if (!str_contains($query, "entity_type = '{$entityType}'")) {
                    return [];
                }

                return array_map(
                    static fn (int $fcId): object => (object) ['wc_id' => (string) $fcId, 'fc_id' => $fcId],
                    $fcIds,
                );
            };
    }

    private function findDeleteFor(string $table): string
    {
        foreach ($GLOBALS['_cartshift_test_queries'] ?? [] as $entry) {
            if ($entry[0] === 'query' && str_contains($entry[1], 'DELETE FROM') && str_contains($entry[1], $table)) {
                return $entry[1];
            }
        }

        $this->fail("No orphan DELETE recorded for table: {$table}");
    }
}

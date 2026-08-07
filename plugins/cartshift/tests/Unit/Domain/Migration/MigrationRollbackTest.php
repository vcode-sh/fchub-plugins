<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Migration;

use CartShift\Domain\Migration\MigrationRollback;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\Constants;
use CartShift\Tests\Unit\PluginTestCase;

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
    // Helpers
    // ──────────────────────────────────────────────

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

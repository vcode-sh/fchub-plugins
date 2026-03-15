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
}

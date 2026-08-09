<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Migrator\Entity;

use CartShift\Migrator\ProductMigrator;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Tests\Unit\PluginTestCase;

/**
 * The count and the page query must agree, or the progress bar lies about the
 * denominator — the exact failure the type-predicate consolidation fixed. The
 * skip list is a second chance to reintroduce it, so both are asserted here.
 */
final class ProductSkipExclusionTest extends PluginTestCase
{
    private function migrator(): ProductMigrator
    {
        return new ProductMigrator(
            new IdMapRepository(),
            new MigrationLogRepository(),
            new MigrationState(),
        );
    }

    /** @return list<string> */
    private function capturedQueries(): array
    {
        $queries = [];

        foreach ($GLOBALS['_cartshift_test_queries'] ?? [] as $entry) {
            if (is_string($entry)) {
                $queries[] = $entry;
            }
        }

        return $queries;
    }

    public function testNoSkipListLeavesTheCountQueryUnchanged(): void
    {
        $seen = null;

        $GLOBALS['_cartshift_test_get_var_callback'] = static function (string $query) use (&$seen): string {
            $seen = $query;
            return '0';
        };

        $this->migrator()->count();

        $this->assertIsString($seen);

        // Not a bare 'NOT IN': ProductTypes::migratableClause() legitimately
        // contributes its own "pml.product_id NOT IN (...)" branch for products
        // with no product_type term at all, unconditionally and independently of
        // this exclusion list. Asserting against the bare substring would fail
        // on that unrelated clause even when exclusionSql() correctly contributes
        // nothing, so the check is narrowed to the exact shape excludeProductIds()
        // would add — the same shape the other assertions below check for.
        $this->assertStringNotContainsString('p.ID NOT IN', $seen);
    }

    public function testASkipListExcludesThoseIdsFromTheCount(): void
    {
        $seen = null;

        $GLOBALS['_cartshift_test_get_var_callback'] = static function (string $query) use (&$seen): string {
            $seen = $query;
            return '0';
        };

        $migrator = $this->migrator();
        $migrator->excludeProductIds([7, 9]);
        $migrator->count();

        $this->assertIsString($seen);
        $this->assertStringContainsString('p.ID NOT IN (7,9)', $seen);
    }

    public function testASkipListExcludesThoseIdsFromThePageQuery(): void
    {
        $seen = null;

        $GLOBALS['_cartshift_test_get_col_callback'] = static function (string $query) use (&$seen): array {
            $seen = $query;
            return [];
        };

        $migrator = $this->migrator();
        $migrator->excludeProductIds([7, 9]);
        $migrator->fetchBatch(null, 10);

        $this->assertIsString($seen);
        $this->assertStringContainsString('p.ID NOT IN (7,9)', $seen);
    }

    public function testTheSkipListIsSanitisedToIntegers(): void
    {
        $seen = null;

        $GLOBALS['_cartshift_test_get_var_callback'] = static function (string $query) use (&$seen): string {
            $seen = $query;
            return '0';
        };

        $migrator = $this->migrator();
        // Whatever reaches this setter has been through REST sanitisation, but
        // an exclusion list spliced into SQL is not the place to rely on that.
        $migrator->excludeProductIds([7, 0, -3, 9]);
        $migrator->count();

        $this->assertStringContainsString('p.ID NOT IN (7,9)', $seen);
    }
}

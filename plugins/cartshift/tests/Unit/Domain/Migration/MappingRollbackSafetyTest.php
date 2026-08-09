<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Migration;

use CartShift\Storage\IdMapRepository;
use CartShift\Support\Constants;
use CartShift\Tests\Unit\PluginTestCase;

/**
 * The load-bearing invariant of product mapping.
 *
 * A linked FluentCart product was built by the shop owner, not by CartShift.
 * Rollback deletes only what the migration created, and it decides that from
 * created_by_migration alone. If this test ever goes red, a rollback is
 * deleting the owner's catalogue.
 */
final class MappingRollbackSafetyTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['_cartshift_test_id_map_rows'] = [];

        $GLOBALS['_cartshift_test_get_results_callback'] = static function (string $query): array {
            if (!str_contains($query, 'cartshift_id_map')) {
                return [];
            }

            preg_match("/entity_type = '([^']*)'/", $query, $matches);
            $createdOnly = str_contains($query, 'created_by_migration = 1');

            $rows = [];

            foreach ($GLOBALS['_cartshift_test_id_map_rows'] as $row) {
                if ($row['entity_type'] !== $matches[1]) {
                    continue;
                }

                if ($createdOnly && $row['created_by_migration'] !== 1) {
                    continue;
                }

                $rows[] = (object) ['wc_id' => $row['wc_id'], 'fc_id' => $row['fc_id']];
            }

            return $rows;
        };
    }

    private function seed(string $entityType, string $wcId, int $fcId, bool $createdByMigration): void
    {
        $GLOBALS['_cartshift_test_id_map_rows'][] = [
            'entity_type'          => $entityType,
            'wc_id'                => $wcId,
            'fc_id'                => $fcId,
            'migration_id'         => 'run-1',
            'created_by_migration' => $createdByMigration ? 1 : 0,
            'is_simulated'         => 0,
        ];
    }

    public function testRollbackNeverSeesALinkedProduct(): void
    {
        // 900 is the owner's hand-made product, promoted from a link.
        $this->seed(Constants::ENTITY_PRODUCT, '42', 900, false);
        // 901 is a product CartShift created for an unmapped Woo product.
        $this->seed(Constants::ENTITY_PRODUCT, '43', 901, true);

        $deletable = (new IdMapRepository())->getCreatedByMigration(Constants::ENTITY_PRODUCT, 'run-1');

        $ids = array_map(static fn (object $row): int => (int) $row->fc_id, $deletable);

        $this->assertSame([901], $ids, 'Rollback must not delete a product the owner built by hand.');
    }

    public function testAVariantCartShiftAddedIsStillDeletable(): void
    {
        // The "adds XL" case: the product is the owner's, the variant is ours.
        $this->seed(Constants::ENTITY_VARIATION, '11', 501, false);
        $this->seed(Constants::ENTITY_VARIATION, '13', 999, true);

        $deletable = (new IdMapRepository())->getCreatedByMigration(Constants::ENTITY_VARIATION, 'run-1');

        $ids = array_map(static fn (object $row): int => (int) $row->fc_id, $deletable);

        $this->assertSame(
            [999],
            $ids,
            'A variant CartShift added to the owner product is migration output and must roll back.',
        );
    }
}

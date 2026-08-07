<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Storage;

use CartShift\Storage\IdMapRepository;
use CartShift\Support\Constants;
use CartShift\Tests\Unit\PluginTestCase;

/**
 * BUG 2: the ID map used to issue one uncached SELECT per lookup, which on a
 * 50k-order store meant hundreds of thousands of single-row queries.
 */
final class IdMapRepositoryTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['_cartshift_test_id_map_rows'] = [];

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
            $GLOBALS['_cartshift_test_get_var_callback'],
            $GLOBALS['_cartshift_test_get_results_callback'],
            $GLOBALS['_cartshift_test_id_map_rows'],
        );

        parent::tearDown();
    }

    public function testRepeatedHitLookupsQueryOnlyOnce(): void
    {
        $this->seedRow(Constants::ENTITY_PRODUCT, '10', 555);

        $repo = new IdMapRepository();

        $this->assertSame(555, $repo->getFcId(Constants::ENTITY_PRODUCT, '10'));
        $this->assertSame(555, $repo->getFcId(Constants::ENTITY_PRODUCT, '10'));
        $this->assertSame(555, $repo->getFcId(Constants::ENTITY_PRODUCT, '10'));

        $this->assertSame(1, $this->countQueries('get_var'), 'A repeated hit must not re-query');
    }

    public function testRepeatedMissLookupsQueryOnlyOnce(): void
    {
        $repo = new IdMapRepository();

        $this->assertNull($repo->getFcId(Constants::ENTITY_ORDER, '404'));
        $this->assertNull($repo->getFcId(Constants::ENTITY_ORDER, '404'));

        $this->assertSame(1, $this->countQueries('get_var'), 'A repeated miss must not re-query');
    }

    public function testDistinctKeysAreCachedIndependently(): void
    {
        $this->seedRow(Constants::ENTITY_PRODUCT, '1', 11);
        $this->seedRow(Constants::ENTITY_VARIATION, '1', 22);

        $repo = new IdMapRepository();

        $this->assertSame(11, $repo->getFcId(Constants::ENTITY_PRODUCT, '1'));
        $this->assertSame(22, $repo->getFcId(Constants::ENTITY_VARIATION, '1'));
        $this->assertSame(11, $repo->getFcId(Constants::ENTITY_PRODUCT, '1'));

        $this->assertSame(2, $this->countQueries('get_var'));
    }

    public function testStorePopulatesTheMemo(): void
    {
        $repo = new IdMapRepository();

        $repo->store(Constants::ENTITY_VARIATION, '77', 888, 'mig-1');

        $this->assertSame(888, $repo->getFcId(Constants::ENTITY_VARIATION, '77'));
        $this->assertSame(0, $this->countQueries('get_var'), 'A stored mapping must be readable without a query');
    }

    public function testStoreOverwritesAMemoisedMiss(): void
    {
        $repo = new IdMapRepository();

        $this->assertNull($repo->getFcId(Constants::ENTITY_VARIATION, '77'));

        $repo->store(Constants::ENTITY_VARIATION, '77', 888, 'mig-1');

        $this->assertSame(888, $repo->getFcId(Constants::ENTITY_VARIATION, '77'));
    }

    public function testGetMapForEntityTypeLoadsWholeTypeInOneQuery(): void
    {
        $this->seedRow(Constants::ENTITY_CATEGORY, '10', 100);
        $this->seedRow(Constants::ENTITY_CATEGORY, '11', 101);
        $this->seedRow(Constants::ENTITY_BRAND, '12', 102);

        $repo = new IdMapRepository();

        $this->assertSame(['10' => 100, '11' => 101], $repo->getMapForEntityType(Constants::ENTITY_CATEGORY));
        $this->assertSame(1, $this->countQueries('get_results'));
    }

    public function testGetMapForEntityTypeFeedsTheMemo(): void
    {
        $this->seedRow(Constants::ENTITY_CATEGORY, '10', 100);

        $repo = new IdMapRepository();
        $repo->getMapForEntityType(Constants::ENTITY_CATEGORY);

        $this->assertSame(100, $repo->getFcId(Constants::ENTITY_CATEGORY, '10'));
        $this->assertSame(0, $this->countQueries('get_var'));
    }

    public function testGetMapForEntityTypeKeepsTheFirstRowOnDuplicates(): void
    {
        $this->seedRow(Constants::ENTITY_CATEGORY, '10', 100);
        $this->seedRow(Constants::ENTITY_CATEGORY, '10', 999);

        $repo = new IdMapRepository();

        $this->assertSame(['10' => 100], $repo->getMapForEntityType(Constants::ENTITY_CATEGORY));
    }

    public function testTruncateFlushesTheMemo(): void
    {
        $this->seedRow(Constants::ENTITY_PRODUCT, '10', 555);

        $repo = new IdMapRepository();
        $repo->getFcId(Constants::ENTITY_PRODUCT, '10');

        $GLOBALS['_cartshift_test_id_map_rows'] = [];
        $repo->truncate();

        $this->assertNull($repo->getFcId(Constants::ENTITY_PRODUCT, '10'));
    }

    public function testDeleteByMigrationFlushesTheMemo(): void
    {
        $this->seedRow(Constants::ENTITY_PRODUCT, '10', 555);

        $repo = new IdMapRepository();
        $repo->getFcId(Constants::ENTITY_PRODUCT, '10');

        $GLOBALS['_cartshift_test_id_map_rows'] = [];
        $repo->deleteByMigration('mig-1');

        $this->assertNull($repo->getFcId(Constants::ENTITY_PRODUCT, '10'));
    }

    public function testDeleteCreatedByMigrationFlushesTheMemo(): void
    {
        $this->seedRow(Constants::ENTITY_PRODUCT, '10', 555);

        $repo = new IdMapRepository();
        $repo->getFcId(Constants::ENTITY_PRODUCT, '10');

        $GLOBALS['_cartshift_test_id_map_rows'] = [];
        $repo->deleteCreatedByMigration('mig-1');

        $this->assertNull($repo->getFcId(Constants::ENTITY_PRODUCT, '10'));
    }

    public function testSimulationModeKeepsMappingsOutOfTheDatabase(): void
    {
        $repo = new IdMapRepository();
        $repo->enableSimulation();

        $repo->store(Constants::ENTITY_PRODUCT, '101', 5001, 'mig-1', true);

        $this->assertSame(5001, $repo->getFcId(Constants::ENTITY_PRODUCT, '101'));
        $this->assertSame(
            [],
            array_filter(
                $GLOBALS['_cartshift_test_queries'] ?? [],
                static fn (array $q): bool => $q[0] === 'insert',
            ),
            'Simulation must never write to the id-map table.',
        );
    }

    private function seedRow(string $entityType, string $wcId, int $fcId): void
    {
        $GLOBALS['_cartshift_test_id_map_rows'][] = [
            'entity_type' => $entityType,
            'wc_id'       => $wcId,
            'fc_id'       => $fcId,
        ];
    }

    private function countQueries(string $kind): int
    {
        return count(array_filter(
            $GLOBALS['_cartshift_test_queries'] ?? [],
            static fn (array $query): bool => $query[0] === $kind
                && str_contains((string) $query[1], 'cartshift_id_map'),
        ));
    }
}

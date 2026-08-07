<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Migration;

use CartShift\Domain\Migration\Contracts\MigratorInterface;
use CartShift\Domain\Migration\MigrationOrchestrator;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\Constants;
use CartShift\Tests\Unit\PluginTestCase;

final class MigrationOrchestratorTest extends PluginTestCase
{
    private MigrationState $state;
    private IdMapRepository $idMap;
    private MigrationLogRepository $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->state = new MigrationState();
        $this->idMap = new IdMapRepository();
        $this->log   = new MigrationLogRepository();
    }

    public function testStartMigrationReturnsContinueTrue(): void
    {
        $migrator = $this->createFakeMigrator('product', 3, [
            (object) ['id' => 1, 'name' => 'Widget'],
            (object) ['id' => 2, 'name' => 'Gadget'],
            (object) ['id' => 3, 'name' => 'Gizmo'],
        ]);

        $orchestrator = new MigrationOrchestrator(
            [$migrator],
            $this->state,
            $this->idMap,
            $this->log,
        );

        $result = $orchestrator->startMigration(['product']);

        // Under keyset pagination a short batch no longer means "no more rows" —
        // the entity ends when a fetch comes back empty, one batch later.
        $this->assertTrue($result['continue']);
        $this->assertSame('product', $result['entity_type']);
        $this->assertNotNull($result['migration_id']);

        $result = $orchestrator->processBatch();

        $this->assertFalse($result['continue']);
        // Entity index advances past the last entity when done, so entity_type is null.
        $this->assertNull($result['entity_type']);

        // Verify entity progress was tracked.
        $state = $this->state->getCurrent();
        $this->assertSame('completed', $state['entities']['product']['status']);
        $this->assertSame(3, $state['entities']['product']['processed']);
    }

    public function testProcessBatchAdvancesOffset(): void
    {
        // Create a migrator that returns exactly batch-size records on first call,
        // then empty on second call. We override batch size to 2.
        $records = [
            (object) ['id' => 1],
            (object) ['id' => 2],
        ];

        $callCount = 0;
        $migrator = $this->createFakeMigrator('product', 4, $records, function (string|int|null $cursor, int $limit) use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return [(object) ['id' => 1], (object) ['id' => 2]];
            }
            if ($callCount === 2) {
                return [(object) ['id' => 3], (object) ['id' => 4]];
            }
            return [];
        });

        // Set batch size to 2.
        add_filter('cartshift/migration/batch_size', fn () => 2);

        $orchestrator = new MigrationOrchestrator(
            [$migrator],
            $this->state,
            $this->idMap,
            $this->log,
        );

        $result = $orchestrator->startMigration(['product']);

        // First processBatch (called by startMigration) processes 2 records.
        // Batch size == returned count, so more work expected.
        $this->assertTrue($result['continue']);
        $this->assertSame(2, $result['offset']);

        // Second batch.
        $result = $orchestrator->processBatch();
        $this->assertTrue($result['continue']);

        // Third batch returns empty — entity done.
        $result = $orchestrator->processBatch();
        $this->assertFalse($result['continue']);
    }

    public function testProcessBatchAdvancesEntityWhenDone(): void
    {
        $productMigrator = $this->createFakeMigrator('product', 1, [
            (object) ['id' => 10],
        ]);

        $customerMigrator = $this->createFakeMigrator('customer', 1, [
            (object) ['id' => 20],
        ]);

        $orchestrator = new MigrationOrchestrator(
            [$productMigrator, $customerMigrator],
            $this->state,
            $this->idMap,
            $this->log,
        );

        $result = $orchestrator->startMigration(['product', 'customer']);

        // First product batch: one record, still on products.
        $this->assertTrue($result['continue']);
        $this->assertSame(0, $result['entity_index']);

        // Empty product fetch ends the entity and advances to customers.
        $result = $orchestrator->processBatch();
        $this->assertTrue($result['continue']);
        $this->assertSame(1, $result['entity_index']);
        $this->assertSame('customer', $result['entity_type']);

        // Process customers, then drain the closing empty fetch.
        $result = $orchestrator->processBatch();
        $this->assertTrue($result['continue']);

        $result = $orchestrator->processBatch();
        $this->assertFalse($result['continue']);

        // Both entities should be completed.
        $progress = $this->state->getCurrent();
        $this->assertSame('completed', $progress['entities']['product']['status']);
        $this->assertSame('completed', $progress['entities']['customer']['status']);
    }

    public function testCancellationStopsMigration(): void
    {
        $callCount = 0;
        $migrator = $this->createFakeMigrator('product', 100, [], function (string|int|null $cursor, int $limit) use (&$callCount) {
            $callCount++;
            $after = (int) $cursor;
            $batch = [];
            for ($i = 0; $i < $limit; $i++) {
                $batch[] = (object) ['id' => $after + $i + 1];
            }
            return $batch;
        });

        add_filter('cartshift/migration/batch_size', fn () => 10);

        $orchestrator = new MigrationOrchestrator(
            [$migrator],
            $this->state,
            $this->idMap,
            $this->log,
        );

        $result = $orchestrator->startMigration(['product']);
        $this->assertTrue($result['continue']);

        // Cancel the migration.
        $this->state->cancel();

        // Next batch should detect cancellation and stop.
        $result = $orchestrator->processBatch();
        $this->assertFalse($result['continue']);
        $this->assertSame('cancelled', $result['status']);
    }

    public function testEntityTypesFilterApplied(): void
    {
        // Register a filter that adds 'customer' to the entity types.
        add_filter('cartshift/migration/entity_types', function (array $types): array {
            $types[] = 'customer';
            return $types;
        });

        $productMigrator = $this->createFakeMigrator('product', 1, [
            (object) ['id' => 1],
        ]);

        $customerMigrator = $this->createFakeMigrator('customer', 1, [
            (object) ['id' => 2],
        ]);

        $orchestrator = new MigrationOrchestrator(
            [$productMigrator, $customerMigrator],
            $this->state,
            $this->idMap,
            $this->log,
        );

        // Only request 'product' but the filter adds 'customer'.
        $result = $orchestrator->startMigration(['product']);

        $this->assertTrue($result['continue']);
        $this->assertSame(2, $result['entity_count']);

        $stateData = $this->state->getCurrent();
        $this->assertSame(['product', 'customer'], $stateData['entity_types']);
    }

    /**
     * When the last entity finishes, current_entity_index runs off the end of
     * entity_types and the current type is null. buildResult() must not use that null
     * as an array key — PHP 8.5 deprecates coercing it to ''.
     */
    public function testFinalResultDoesNotUseNullAsAnArrayKey(): void
    {
        $migrator = $this->createFakeMigrator('product', 1, [(object) ['id' => 1]]);

        $orchestrator = new MigrationOrchestrator(
            [$migrator],
            $this->state,
            $this->idMap,
            $this->log,
        );

        // Turn the deprecation into a throwable so it cannot pass silently.
        $deprecations = [];
        set_error_handler(
            static function (int $errno, string $message) use (&$deprecations): bool {
                $deprecations[] = $message;

                return true;
            },
            E_DEPRECATED | E_USER_DEPRECATED,
        );

        try {
            $result = $orchestrator->startMigration(['product']);

            // Drain to the end so entity_index passes the last entity.
            $guard = 0;
            while ($result['continue'] && $guard++ < 10) {
                $result = $orchestrator->processBatch();
            }

            $final = $orchestrator->processBatch();
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $deprecations, 'buildResult() must not trip a deprecation.');
        $this->assertNull($final['entity_type']);
        $this->assertSame(0, $final['total']);
        $this->assertSame(0, $final['processed']);
        $this->assertFalse($final['continue']);
    }

    /**
     * buildResult() keeps every key the REST controller and WP-CLI command read.
     */
    public function testResultShapeIsPreservedWhenAllEntitiesAreDone(): void
    {
        $migrator = $this->createFakeMigrator('product', 0, []);

        $orchestrator = new MigrationOrchestrator(
            [$migrator],
            $this->state,
            $this->idMap,
            $this->log,
        );

        $orchestrator->startMigration(['product']);
        $result = $orchestrator->processBatch();

        foreach (
            [
                'continue', 'migration_id', 'status', 'entity_type', 'entity_index',
                'entity_count', 'offset', 'total', 'processed', 'entities',
            ] as $key
        ) {
            $this->assertArrayHasKey($key, $result, "Result key '{$key}' is consumed downstream.");
        }
    }

    /**
     * Mid-migration cache clearing must drop only the in-process cache. On a site with
     * a persistent object cache, wp_cache_flush() wipes Redis or Memcached for the
     * whole site — repeatedly, while the migration runs. wp_cache_flush_runtime() is
     * what the memory-exhaustion comment actually wants.
     */
    public function testRuntimeFlushIsPreferredOverTheSiteWideFlush(): void
    {
        $GLOBALS['_cartshift_test_cache_flush_runtime_calls'] = 0;

        MigrationOrchestrator::flushRuntimeCache();

        $this->assertSame(
            1,
            $GLOBALS['_cartshift_test_cache_flush_runtime_calls'],
            'flushRuntimeCache() must call wp_cache_flush_runtime() when it exists.',
        );
    }

    /**
     * Create a fake MigratorInterface implementation for testing.
     *
     * @param object[] $records Records to return from fetchBatch (when no custom fetcher).
     * @param callable|null $customFetcher Optional (cursor, limit) => records[] callback.
     */
    private function createFakeMigrator(
        string $entityType,
        int $count,
        array $records,
        ?callable $customFetcher = null,
    ): MigratorInterface {
        return new class ($entityType, $count, $records, $customFetcher) implements MigratorInterface {
            private bool $initialized = false;

            public function __construct(
                private readonly string $type,
                private readonly int $total,
                private readonly array $records,
                private readonly ?\Closure $customFetcher,
            ) {
            }

            #[\Override]
            public function entityType(): string
            {
                return $this->type;
            }

            #[\Override]
            public function count(): int
            {
                return $this->total;
            }

            #[\Override]
            public function initialize(): void
            {
                $this->initialized = true;
            }

            #[\Override]
            public function fetchByIds(array $wcIds): array
            {
                $wanted = array_map(intval(...), $wcIds);

                return array_values(array_filter(
                    $this->records,
                    static fn (object $record): bool => in_array((int) $record->id, $wanted, true),
                ));
            }

            #[\Override]
            public function fetchBatch(string|int|null $cursor, int $limit): array
            {
                if ($this->customFetcher !== null) {
                    return ($this->customFetcher)($cursor, $limit);
                }

                $after = (int) $cursor;

                return array_slice(
                    array_values(array_filter(
                        $this->records,
                        static fn (object $record): bool => (int) $record->id > $after,
                    )),
                    0,
                    $limit,
                );
            }

            #[\Override]
            public function cursorFor(mixed $record): string|int
            {
                return (int) $record->id;
            }

            #[\Override]
            public function migratedCount(): int
            {
                return 0;
            }

            #[\Override]
            public function processRecord(mixed $record): int|false
            {
                return (int) $record->id;
            }

            #[\Override]
            public function validateRecord(mixed $record): bool
            {
                return true;
            }

            #[\Override]
            public function getRecordId(mixed $record): string
            {
                return (string) $record->id;
            }
        };
    }
}

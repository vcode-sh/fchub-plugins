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

        // Three records < batch size (50), so entity completes in one batch.
        // No more entity types remain, so continue = false.
        $this->assertFalse($result['continue']);
        // Entity index advances past the last entity when done, so entity_type is null.
        $this->assertNull($result['entity_type']);
        $this->assertNotNull($result['migration_id']);

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
        $migrator = $this->createFakeMigrator('product', 4, $records, function (int $offset, int $limit) use (&$callCount) {
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

        // Products done (1 record < batch size), advances to customers.
        $this->assertTrue($result['continue']);
        $this->assertSame(1, $result['entity_index']);
        $this->assertSame('customer', $result['entity_type']);

        // Process customers.
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
        $migrator = $this->createFakeMigrator('product', 100, [], function (int $offset, int $limit) use (&$callCount) {
            $callCount++;
            $batch = [];
            for ($i = 0; $i < $limit; $i++) {
                $batch[] = (object) ['id' => $offset + $i + 1];
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

        // Product done, customer should be next.
        $this->assertTrue($result['continue']);
        $this->assertSame(2, $result['entity_count']);

        $stateData = $this->state->getCurrent();
        $this->assertSame(['product', 'customer'], $stateData['entity_types']);
    }

    /**
     * Create a fake MigratorInterface implementation for testing.
     *
     * @param object[] $records Records to return from fetchBatch (when no custom fetcher).
     * @param callable|null $customFetcher Optional (offset, limit) => records[] callback.
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
            public function run(): void
            {
            }

            #[\Override]
            public function fetchBatch(int $offset, int $limit): array
            {
                if ($this->customFetcher !== null) {
                    return ($this->customFetcher)($offset, $limit);
                }

                return array_slice($this->records, $offset, $limit);
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

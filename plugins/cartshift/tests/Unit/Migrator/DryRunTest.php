<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Migrator;

use CartShift\Domain\Migration\Contracts\MigratorInterface;
use CartShift\Domain\Migration\MigrationOrchestrator;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Tests\Unit\PluginTestCase;

final class DryRunTest extends PluginTestCase
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

    public function testDryRunSkipsInitialize(): void
    {
        $initialized = false;

        $migrator = $this->createDryRunMigrator(
            'product',
            1,
            [(object) ['id' => 1]],
            onInitialize: function () use (&$initialized): void {
                $initialized = true;
            },
        );

        $orchestrator = new MigrationOrchestrator(
            [$migrator],
            $this->state,
            $this->idMap,
            $this->log,
        );

        $orchestrator->startMigration(['product'], dryRun: true);

        $this->assertFalse($initialized, 'initialize() must NOT be called during dry-run');
    }

    /**
     * Skipping initialize() is only half the answer. Skipping it and putting
     * nothing in its place left ENTITY_CATEGORY empty for the whole run, so every
     * coupon with a category restriction was reported as would-be-disabled
     * regardless — the residual figure the dry run exists to produce was an upper
     * bound wearing a precise number's clothing.
     */
    public function testDryRunCallsTheReadOnlyInitialiserInstead(): void
    {
        $simulated = false;

        $migrator = $this->createDryRunMigrator(
            'product',
            1,
            [(object) ['id' => 1]],
            onInitializeSimulated: function () use (&$simulated): void {
                $simulated = true;
            },
        );

        $orchestrator = new MigrationOrchestrator([$migrator], $this->state, $this->idMap, $this->log);
        $orchestrator->startMigration(['product'], dryRun: true);

        $this->assertTrue($simulated, 'initializeSimulated() must run in place of initialize()');
    }

    public function testARealRunCallsInitializeAndNotTheSimulatedOne(): void
    {
        $initialized = false;
        $simulated = false;

        $migrator = $this->createDryRunMigrator(
            'product',
            1,
            [(object) ['id' => 1]],
            onInitialize: function () use (&$initialized): void {
                $initialized = true;
            },
            onInitializeSimulated: function () use (&$simulated): void {
                $simulated = true;
            },
        );

        $orchestrator = new MigrationOrchestrator([$migrator], $this->state, $this->idMap, $this->log);
        $orchestrator->startMigration(['product'], dryRun: false);

        $this->assertTrue($initialized);
        $this->assertFalse($simulated, 'A real run creates its taxonomies; it does not rehearse them.');
    }

    public function testDryRunCallsValidateRecordNotProcessRecord(): void
    {
        $validateCalled = false;
        $processCalled = false;

        $migrator = $this->createDryRunMigrator(
            'product',
            1,
            [(object) ['id' => 1]],
            onValidate: function () use (&$validateCalled): bool {
                $validateCalled = true;
                return true;
            },
            onProcess: function () use (&$processCalled): int {
                $processCalled = true;
                return 1;
            },
        );

        $orchestrator = new MigrationOrchestrator(
            [$migrator],
            $this->state,
            $this->idMap,
            $this->log,
        );

        $orchestrator->startMigration(['product'], dryRun: true);

        $this->assertTrue($validateCalled, 'validateRecord() must be called during dry-run');
        $this->assertFalse($processCalled, 'processRecord() must NOT be called during dry-run');
    }

    public function testDryRunLogsWithDryRunStatus(): void
    {
        $logEntries = [];

        $migrator = $this->createDryRunMigrator(
            'product',
            1,
            [(object) ['id' => 42]],
            onValidate: function () use (&$logEntries): bool {
                // The orchestrator does not log on success — only on error.
                // But we can check transactions are NOT started.
                return true;
            },
        );

        $orchestrator = new MigrationOrchestrator(
            [$migrator],
            $this->state,
            $this->idMap,
            $this->log,
        );

        $orchestrator->startMigration(['product'], dryRun: true);

        // Verify no transaction queries were issued (no START TRANSACTION / COMMIT).
        $transactionQueries = array_filter(
            $GLOBALS['_cartshift_test_queries'] ?? [],
            fn (array $q) => $q[0] === 'query' && str_contains($q[1], 'TRANSACTION'),
        );

        $this->assertEmpty($transactionQueries, 'Dry-run must NOT use database transactions');

        // Verify state was stored as dry_run.
        $stateData = $this->state->getCurrent();
        $this->assertTrue($stateData['dry_run'], 'Migration state must have dry_run=true');
    }

    public function testDryRunCountsCorrectly(): void
    {
        $migrator = $this->createDryRunMigrator(
            'product',
            3,
            [
                (object) ['id' => 1],
                (object) ['id' => 2],
                (object) ['id' => 3],
            ],
            onValidate: function (mixed $record): bool {
                // Record #2 fails validation.
                return $record->id !== 2;
            },
        );

        $orchestrator = new MigrationOrchestrator(
            [$migrator],
            $this->state,
            $this->idMap,
            $this->log,
        );

        $orchestrator->startMigration(['product'], dryRun: true);

        $stateData = $this->state->getCurrent();
        $entityState = $stateData['entities']['product'];

        $this->assertSame(2, $entityState['processed'], 'Valid records should be counted as processed');
        $this->assertSame(1, $entityState['skipped'], 'Invalid records should be counted as skipped');
    }

    /**
     * Create a fake MigratorInterface for dry-run testing.
     *
     * @param object[] $records
     */
    private function createDryRunMigrator(
        string $entityType,
        int $count,
        array $records,
        ?\Closure $onInitialize = null,
        ?\Closure $onValidate = null,
        ?\Closure $onProcess = null,
        ?\Closure $onInitializeSimulated = null,
    ): MigratorInterface {
        return new class (
            $entityType,
            $count,
            $records,
            $onInitialize,
            $onValidate,
            $onProcess,
            $onInitializeSimulated,
        ) implements MigratorInterface {
            public function __construct(
                private readonly string $type,
                private readonly int $total,
                private readonly array $records,
                private readonly ?\Closure $onInitialize,
                private readonly ?\Closure $onValidate,
                private readonly ?\Closure $onProcess,
                private readonly ?\Closure $onInitializeSimulated = null,
            ) {}

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
                if ($this->onInitialize !== null) {
                    ($this->onInitialize)();
                }
            }

            #[\Override]
            public function initializeSimulated(): void
            {
                if ($this->onInitializeSimulated !== null) {
                    ($this->onInitializeSimulated)();
                }
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
                if ($this->onProcess !== null) {
                    return ($this->onProcess)($record);
                }
                return (int) $record->id;
            }

            #[\Override]
            public function validateRecord(mixed $record): bool
            {
                if ($this->onValidate !== null) {
                    return ($this->onValidate)($record);
                }
                return true;
            }

            #[\Override]
            public function getRecordId(mixed $record): string
            {
                return (string) $record->id;
            }

            #[\Override]
            public function useScope(\CartShift\Domain\Scope\MigrationScope $scope): void
            {
            }
        };
    }
}

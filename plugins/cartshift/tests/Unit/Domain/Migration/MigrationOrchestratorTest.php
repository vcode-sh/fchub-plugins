<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Migration;

use CartShift\Domain\Migration\Contracts\MigratorInterface;
use CartShift\Domain\Migration\MigrationOrchestrator;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\Constants;
use CartShift\Support\Enums\MigrationErrorCode;
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

    // ──────────────────────────────────────────────
    // The error count tells the truth
    // ──────────────────────────────────────────────
    //
    // The loop counts an error when a record throws, and every throw it catches
    // writes a log row — so the two agreed for years and nobody had to think
    // about it. They stop agreeing the moment something fails without throwing,
    // which is exactly what `$wpdb` does. A live run wrote ten
    // `Unknown column 'item_count'` rows and reported
    // `order 10 10 0 0 completed` / `Success: Migration complete`. Told a run
    // succeeded, nobody reads a PHP error log — so the column survived.

    public function testAnErrorLoggedWithoutAThrowStillReachesTheCount(): void
    {
        $this->fakeLogTable();

        $orchestrator = new MigrationOrchestrator(
            [$this->migratorLoggingAnError('product', [(object) ['id' => 1]])],
            $this->state,
            $this->idMap,
            $this->log,
        );

        $orchestrator->startMigration(['product']);
        $orchestrator->processBatch();

        $entity = $this->state->getCurrent()['entities']['product'];

        $this->assertSame(1, $entity['processed'], 'The record itself did migrate.');
        $this->assertSame(
            1,
            $entity['errors'],
            'And something about it went wrong. A run that will not say so is how a bug hides.',
        );
    }

    /**
     * Reconciled per batch, not once at the end. A run of forty thousand orders
     * reports itself every few seconds, and a number that is wrong for twenty
     * minutes and right afterwards is one nobody can act on while there is still
     * time to stop.
     */
    public function testTheCountIsRightAfterTheFirstBatchNotOnlyAtTheEnd(): void
    {
        $this->fakeLogTable();

        add_filter('cartshift/migration/batch_size', static fn (): int => 1);

        $orchestrator = new MigrationOrchestrator(
            [$this->migratorLoggingAnError('product', [(object) ['id' => 1], (object) ['id' => 2]])],
            $this->state,
            $this->idMap,
            $this->log,
        );

        // startMigration() runs the first batch and returns; the entity is very
        // much still going.
        $result = $orchestrator->startMigration(['product']);

        $this->assertTrue($result['continue'], 'Precondition: the run is not over.');
        $this->assertSame(1, $this->state->getCurrent()['entities']['product']['errors']);
    }

    /**
     * The other half of the contract. A count that rises when nothing is wrong
     * is a number the owner learns to scroll past.
     */
    public function testARunWithNoErrorRowsStillReportsNone(): void
    {
        $this->fakeLogTable();

        $orchestrator = new MigrationOrchestrator(
            [$this->createFakeMigrator('product', 1, [(object) ['id' => 1]])],
            $this->state,
            $this->idMap,
            $this->log,
        );

        $orchestrator->startMigration(['product']);
        $orchestrator->processBatch();

        $this->assertSame(0, $this->state->getCurrent()['entities']['product']['errors']);
    }

    /**
     * Warnings stay warnings.
     *
     * A subscription that came across paused because its product is missing is
     * a warning on purpose: the subscriber and the billing history survived and
     * nothing charges until a human decides. Sweeping those into the error count
     * would make almost every real migration read as a failure, and an error
     * count that is always non-zero tells you nothing at all.
     */
    public function testWarningsAreNotQuietlyPromotedToErrors(): void
    {
        $this->fakeLogTable();

        $orchestrator = new MigrationOrchestrator(
            [$this->migratorLogging('product', [(object) ['id' => 1]], 'warning')],
            $this->state,
            $this->idMap,
            $this->log,
        );

        $orchestrator->startMigration(['product']);
        $orchestrator->processBatch();

        $this->assertSame(0, $this->state->getCurrent()['entities']['product']['errors']);
    }

    /**
     * Per entity, not per run. Orders failing must not make the product row read
     * as though products failed — that table is what people scan to decide where
     * to look.
     */
    public function testErrorsAreCountedAgainstTheEntityThatCausedThem(): void
    {
        $this->fakeLogTable();

        $orchestrator = new MigrationOrchestrator(
            [
                $this->createFakeMigrator('product', 1, [(object) ['id' => 1]]),
                $this->migratorLoggingAnError('order', [(object) ['id' => 9]]),
            ],
            $this->state,
            $this->idMap,
            $this->log,
        );

        $orchestrator->startMigration(['product', 'order']);

        while ($orchestrator->processBatch()['continue']) {
            // Drive to the end.
        }

        $entities = $this->state->getCurrent()['entities'];

        $this->assertSame(0, $entities['product']['errors']);
        $this->assertSame(1, $entities['order']['errors']);
    }

    /**
     * The log is the authority, but it cannot be the only one.
     *
     * If the log insert is itself refused there is no channel left to report it
     * — writing "the log write failed" into the log is not a thing that can
     * work. In that one case the loop's own tally is the higher, truer number,
     * and it must survive reconciliation rather than be overwritten by a log
     * that has no idea.
     */
    public function testTheLoopsOwnTallyIsNeverReducedByAnEmptyLog(): void
    {
        // A log that answers "nothing here", which is what a log whose own
        // writes are failing looks like from the orchestrator.
        $GLOBALS['_cartshift_test_get_var_callback'] = static fn (): ?int => null;

        $orchestrator = new MigrationOrchestrator(
            [$this->throwingMigrator('product', [(object) ['id' => 1]])],
            $this->state,
            $this->idMap,
            $this->log,
        );

        $orchestrator->startMigration(['product']);
        $orchestrator->processBatch();

        $this->assertSame(1, $this->state->getCurrent()['entities']['product']['errors']);
    }

    /**
     * Stand in for the log table: inserts are captured, and the reconciliation's
     * COUNT(*) is answered by counting what was captured.
     *
     * A round trip rather than a canned number, so the test exercises the query
     * the repository actually builds. A stub told to answer "1" would pass
     * against a reconciliation that counted the wrong entity, the wrong status,
     * or nothing at all.
     */
    private function fakeLogTable(): void
    {
        $rows = [];

        $GLOBALS['_cartshift_test_insert_callback'] = static function (string $table, array $data) use (&$rows): int {
            if (str_contains($table, 'cartshift_migration_log')) {
                $rows[] = $data;
            }

            return 1;
        };

        // The status the row was written with is honoured rather than ignored:
        // a stub that counted every row alike would pass against a
        // reconciliation that swept warnings in with the errors, which is the
        // one way this change could quietly make every run look like a failure.
        $GLOBALS['_cartshift_test_get_var_callback'] = static function (string $query) use (&$rows): ?int {
            $matched = preg_match(
                "/SELECT COUNT\(\*\).*migration_id = '([^']*)' AND entity_type = '([^']*)' AND status = '([^']*)'/s",
                $query,
                $m,
            );

            if ($matched !== 1) {
                return null;
            }

            return count(array_filter(
                $rows,
                static fn (array $row): bool => $row['migration_id'] === $m[1]
                    && $row['entity_type'] === $m[2]
                    && $row['status'] === $m[3],
            ));
        };
    }

    /**
     * A migrator whose records migrate, and which writes a coded error row about
     * the part of each one that did not — which is exactly what a refused
     * `$wpdb` write looks like from outside the migrator.
     *
     * @param list<object> $records
     */
    private function migratorLoggingAnError(string $entityType, array $records): MigratorInterface
    {
        return $this->migratorLogging($entityType, $records, 'error');
    }

    /**
     * @param list<object> $records
     */
    private function migratorLogging(string $entityType, array $records, string $status): MigratorInterface
    {
        $log = $this->log;
        $state = $this->state;

        return $this->createFakeMigrator(
            $entityType,
            count($records),
            $records,
            null,
            static function (object $record) use ($log, $state, $entityType, $status): void {
                $log->write(
                    (string) $state->getMigrationId(),
                    $entityType,
                    (string) $record->id,
                    $status,
                    "Could not write the item count: Unknown column 'item_count' in 'SET'",
                    null,
                    MigrationErrorCode::DatabaseWriteFailed,
                );
            },
        );
    }

    /**
     * A migrator that throws, so the orchestrator's own catch does the counting.
     *
     * @param list<object> $records
     */
    private function throwingMigrator(string $entityType, array $records): MigratorInterface
    {
        return $this->createFakeMigrator(
            $entityType,
            count($records),
            $records,
            null,
            static function (): void {
                throw new \RuntimeException('Boom.');
            },
        );
    }

    // ──────────────────────────────────────────────
    // The record boundary, which used to end early
    // ──────────────────────────────────────────────

    /**
     * A record that throws AFTER an inner layer has committed still leaves
     * nothing behind.
     *
     * This is the shape of the subscription path. `SubscriptionWriter::stage()`
     * opened its own transaction inside this one and committed it — and MySQL
     * has no nested transactions, so that second `START TRANSACTION` implicitly
     * committed the orchestrator's and the writer's `COMMIT` ended the only
     * transaction there was. `SubscriptionMigrator::linkHistory()` then created
     * orders, order items and transactions outside any transaction at all, and
     * a throw in there left them committed while the `ROLLBACK` below undid
     * nothing.
     */
    public function testAFailureAfterAnInnerCommitStillRollsTheWholeRecordBack(): void
    {
        $migrator = $this->createFakeMigrator(
            'subscription',
            1,
            [(object) ['id' => 1]],
            null,
            static function (): void {
                // `SubscriptionWriter::stage()`: its own boundary, its own commit.
                \CartShift\Support\DatabaseTransaction::begin();
                \CartShift\Support\DatabaseTransaction::commit();

                // `SubscriptionMigrator::linkHistory()`, which runs after it.
                throw new \RuntimeException('The history write failed.');
            },
        );

        $orchestrator = new MigrationOrchestrator(
            [$migrator],
            $this->state,
            $this->idMap,
            $this->log,
        );

        $orchestrator->startMigration(['subscription']);

        $this->assertSame(
            ['START TRANSACTION', 'ROLLBACK'],
            $this->transactionStatements(),
            'The inner commit ended the record boundary, so the history was written outside it.',
        );

        $this->assertSame(0, \CartShift\Support\DatabaseTransaction::depth());
        $this->assertSame(1, $this->state->getCurrent()['entities']['subscription']['errors']);
    }

    /**
     * And the ordinary success path still commits exactly once.
     */
    public function testASuccessfulRecordCommitsOnceHoweverManyLayersAskedForATransaction(): void
    {
        $migrator = $this->createFakeMigrator(
            'subscription',
            1,
            [(object) ['id' => 1]],
            null,
            static function (): void {
                \CartShift\Support\DatabaseTransaction::begin();
                \CartShift\Support\DatabaseTransaction::commit();
            },
        );

        (new MigrationOrchestrator([$migrator], $this->state, $this->idMap, $this->log))
            ->startMigration(['subscription']);

        $this->assertSame(['START TRANSACTION', 'COMMIT'], $this->transactionStatements());
        $this->assertSame(0, \CartShift\Support\DatabaseTransaction::depth());
    }

    /**
     * Every transaction statement the run issued, in order.
     *
     * @return list<string>
     */
    private function transactionStatements(): array
    {
        $wanted = ['START TRANSACTION', 'COMMIT', 'ROLLBACK'];
        $seen   = [];

        foreach ((array) ($GLOBALS['_cartshift_test_queries'] ?? []) as $entry) {
            if (($entry[0] ?? '') === 'query' && in_array($entry[1] ?? '', $wanted, true)) {
                $seen[] = (string) $entry[1];
            }
        }

        return $seen;
    }

    /**
     * Create a fake MigratorInterface implementation for testing.
     *
     * @param object[] $records Records to return from fetchBatch (when no custom fetcher).
     * @param callable|null $customFetcher Optional (cursor, limit) => records[] callback.
     * @param callable|null $onProcess Optional side effect run inside processRecord().
     */
    private function createFakeMigrator(
        string $entityType,
        int $count,
        array $records,
        ?callable $customFetcher = null,
        ?callable $onProcess = null,
    ): MigratorInterface {
        return new class ($entityType, $count, $records, $customFetcher, $onProcess) implements MigratorInterface {
            private bool $initialized = false;

            public function __construct(
                private readonly string $type,
                private readonly int $total,
                private readonly array $records,
                private readonly ?\Closure $customFetcher,
                private readonly ?\Closure $onProcess = null,
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
            public function initializeSimulated(): void
            {
                // No-op.
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
                if ($this->onProcess !== null) {
                    ($this->onProcess)($record);
                }

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

            #[\Override]
            public function useScope(\CartShift\Domain\Scope\MigrationScope $scope): void
            {
            }
        };
    }
}

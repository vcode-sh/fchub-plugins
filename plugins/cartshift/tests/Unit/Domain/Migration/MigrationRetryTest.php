<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Migration;

use CartShift\Domain\Migration\Contracts\MigratorInterface;
use CartShift\Domain\Migration\MigrationOrchestrator;
use CartShift\Domain\Migration\MigrationRollback;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Tests\Unit\PluginTestCase;

/**
 * A retry is a migration in its own right.
 *
 * It seeds a fresh migration ID, records which run it is repairing, writes its
 * own log rows and its own ID-map rows, and is therefore independently
 * rollback-able and independently retryable. The alternative — grafting the
 * repair onto the run it repairs — makes "roll back the retry" unanswerable and
 * "retry the retry" impossible, which is exactly the position a user is in when
 * the first retry only fixed half the problem.
 *
 * These tests drive the whole cycle through the real MigrationLogRepository and
 * IdMapRepository against an in-memory $wpdb, so the ID list a retry works from
 * is genuinely the one the first run's failures produced rather than one the
 * test handed it.
 */
final class MigrationRetryTest extends PluginTestCase
{
    private RetryTestWpdb $wpdb;
    private mixed $originalWpdb = null;

    private MigrationState $state;
    private IdMapRepository $idMap;
    private MigrationLogRepository $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalWpdb = $GLOBALS['wpdb'] ?? null;
        $this->wpdb = new RetryTestWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;

        delete_option('cartshift_migration_retry');

        $this->state = new MigrationState();
        $this->idMap = new IdMapRepository();
        $this->log   = new MigrationLogRepository();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = $this->originalWpdb;

        parent::tearDown();
    }

    /**
     * The headline case: three products, one of them broken, then a retry that
     * picks up exactly the broken one once it has been fixed.
     */
    public function testFailedRecordsAreRetriedAndReachTheIdMap(): void
    {
        $migrator = $this->migrator([1, 2, 3], failing: [2]);

        $first = $this->orchestrator($migrator)->startMigration(['product']);
        $this->drain($migrator, $first);

        $sourceId = (string) $first['migration_id'];

        $this->assertSame(
            ['1', '3'],
            $this->idMapIds('product', $sourceId),
            'The first run maps everything except the broken record.',
        );

        // Fix whatever was wrong, then retry.
        $migrator->failing = [];

        $retry = $this->orchestrator($migrator)->startRetry($sourceId);
        $retryId = (string) $retry['migration_id'];

        $this->assertNotSame($sourceId, $retryId, 'A retry is its own run, with its own ID.');
        $this->assertSame($sourceId, $retry['retry_of']);

        $this->drain($migrator, $retry);

        $this->assertSame(
            [2],
            $migrator->processedDuringRetry,
            'A retry attempts the failures and nothing else.',
        );

        $this->assertSame(
            ['2'],
            $this->idMapIds('product', $retryId),
            'A record that succeeds on retry is mapped exactly as a normal run would map it.',
        );
    }

    public function testTheRetryTotalIsTheSizeOfTheWorkListNotOfTheSourceTable(): void
    {
        $migrator = $this->migrator([1, 2, 3], failing: [2], total: 40000);

        $first = $this->orchestrator($migrator)->startMigration(['product']);
        $this->drain($migrator, $first);

        $retry = $this->orchestrator($migrator)->startRetry((string) $first['migration_id']);

        $this->assertSame(
            1,
            $retry['total'],
            'A retry of one failed record is one of one, not one of forty thousand.',
        );
    }

    /**
     * A retry that fails again is logged against the NEW migration ID, which is
     * the whole point of giving it one: the second retry has a source run to
     * read from, and it is not the original.
     */
    public function testARetryOfARetryWorks(): void
    {
        $migrator = $this->migrator([1, 2], failing: [2]);

        $first = $this->orchestrator($migrator)->startMigration(['product']);
        $this->drain($migrator, $first);

        // Still broken, so the first retry fails on the same record.
        $retry = $this->orchestrator($migrator)->startRetry((string) $first['migration_id']);
        $retryId = (string) $retry['migration_id'];
        $this->drain($migrator, $retry);

        $this->assertSame(
            ['2'],
            $this->loggedErrorIds($retryId),
            'A record that fails again is logged against the retry, not against the run it repairs.',
        );

        // Now fix it and retry the retry.
        $migrator->failing = [];
        $migrator->processedDuringRetry = [];

        $second = $this->orchestrator($migrator)->startRetry($retryId);
        $secondId = (string) $second['migration_id'];

        $this->assertSame($retryId, $second['retry_of']);

        $this->drain($migrator, $second);

        $this->assertSame([2], $migrator->processedDuringRetry);
        $this->assertSame(['2'], $this->idMapIds('product', $secondId));
    }

    /**
     * Rollback works off the ID map's migration_id, so a retry's rows come away
     * on their own and the run it repaired is left standing.
     */
    public function testARetryRunIsIndependentlyRollbackAble(): void
    {
        $migrator = $this->migrator([1, 2, 3], failing: [2]);

        $first = $this->orchestrator($migrator)->startMigration(['product']);
        $this->drain($migrator, $first);
        $sourceId = (string) $first['migration_id'];

        $migrator->failing = [];

        $retry = $this->orchestrator($migrator)->startRetry($sourceId);
        $retryId = (string) $retry['migration_id'];
        $this->drain($migrator, $retry);

        (new MigrationRollback($this->idMap, $this->log))->rollback($retryId);

        $this->assertSame([], $this->idMapIds('product', $retryId), 'The retry rolls back completely.');
        $this->assertSame(
            ['1', '3'],
            $this->idMapIds('product', $sourceId),
            'Rolling back a retry must not touch the run it repaired.',
        );
    }

    public function testAnEntityWithNothingToRetryFinishesWithoutFetchingAnything(): void
    {
        $migrator = $this->migrator([1, 2], failing: []);

        $first = $this->orchestrator($migrator)->startMigration(['product']);
        $this->drain($migrator, $first);

        $migrator->fetchByIdsCalls = [];

        $retry = $this->orchestrator($migrator)->startRetry((string) $first['migration_id']);
        $this->drain($migrator, $retry);

        $this->assertSame([], $migrator->fetchByIdsCalls);
        $this->assertSame(0, $retry['total']);
    }

    /**
     * The plan is keyed on the migration ID it belongs to, so a finished retry
     * cannot quietly restrict the next ordinary run to a stale ID list.
     */
    public function testAStaleRetryPlanDoesNotLeakIntoTheNextOrdinaryRun(): void
    {
        $migrator = $this->migrator([1, 2, 3], failing: [2]);

        $first = $this->orchestrator($migrator)->startMigration(['product']);
        $this->drain($migrator, $first);

        $retry = $this->orchestrator($migrator)->startRetry((string) $first['migration_id']);
        $this->drain($migrator, $retry);

        $migrator->fetchBatchCalls = 0;
        $migrator->fetchByIdsCalls = [];

        $fresh = $this->orchestrator($migrator)->startMigration(['product']);

        $this->assertNull($fresh['retry_of'], 'An ordinary run is not a retry.');
        $this->assertSame([], $migrator->fetchByIdsCalls);
        $this->assertGreaterThan(0, $migrator->fetchBatchCalls, 'An ordinary run keysets as normal.');
    }

    /**
     * fetchByIds() drops IDs that no longer resolve. Advancing by the batch
     * rather than by the slice would leave those in front of the index for ever
     * and the entity would never finish.
     */
    public function testAWorkListOfRecordsThatHaveSinceVanishedStillTerminates(): void
    {
        $migrator = $this->migrator([1, 2, 3], failing: [1, 2, 3]);

        $first = $this->orchestrator($migrator)->startMigration(['product']);
        $this->drain($migrator, $first);

        // Every record has been deleted from WooCommerce since.
        $migrator->records = [];

        $retry = $this->orchestrator($migrator)->startRetry((string) $first['migration_id']);
        $result = $this->drain($migrator, $retry, maxBatches: 20);

        $this->assertFalse($result['continue'], 'The retry must terminate, not spin.');
    }

    /**
     * A dry retry rehearses the repair and creates nothing.
     *
     * The endpoint has always taken `dry_run` and the UI has always offered the
     * checkbox; startRetry() had nowhere to put the flag, so the controller
     * detected the gap by reflection and reported `dry_run: false`. Honest, but
     * the user still ticked a box that did nothing. The parameter exists now, so
     * the box does what it says.
     */
    public function testADryRetryValidatesWithoutWritingAnything(): void
    {
        $migrator = $this->migrator([1, 2, 3], failing: [2, 3]);

        $first = $this->orchestrator($migrator)->startMigration(['product']);
        $this->drain($migrator, $first);
        $sourceId = (string) $first['migration_id'];

        $migrator->failing = [];
        $migrator->processedDuringRetry = [];

        $retry = $this->orchestrator($migrator)->startRetry($sourceId, [], ['error'], true);
        $retryId = (string) $retry['migration_id'];

        $this->assertTrue($this->state->isDryRun(), 'The run must know it is a rehearsal.');

        $this->drain($migrator, $retry);

        $this->assertSame(
            [2, 3],
            $migrator->validated,
            'A dry retry validates exactly the failed records.',
        );

        $this->assertSame(
            [],
            $migrator->processedDuringRetry,
            'A dry retry must not migrate anything.',
        );

        $this->assertSame(
            [],
            $this->idMapIds('product', $retryId),
            'A dry retry writes no real ID-map rows, so there is nothing to roll back.',
        );

        $simulatedInserts = array_values(array_filter(
            $this->wpdb->idMapInserts,
            static fn (array $row): bool => ($row['migration_id'] ?? '') === $retryId,
        ));

        $this->assertNotSame([], $simulatedInserts, 'It still records what it resolved, or later batches cannot see it.');

        foreach ($simulatedInserts as $row) {
            $this->assertSame(1, $row['is_simulated'], 'Every row a rehearsal writes belongs to the simulated realm.');
        }

        $this->assertSame(
            [],
            $this->idMapIds('product', $retryId, simulated: true),
            'And a finished dry run takes its simulated rows with it.',
        );

        $this->assertSame(
            ['2', '3'],
            $this->loggedIdsWithStatus($retryId, 'dry-run'),
            'A dry retry still logs what it would have done — silence is not a report.',
        );
    }

    /**
     * The flag is asked for, never inherited. A dry run creates nothing, so it
     * has nothing to repair; carrying its flag into the retry either way would be
     * wrong in one direction or the other.
     */
    public function testARetryIsRealUnlessTheCallerAsksForADryRun(): void
    {
        $migrator = $this->migrator([1, 2], failing: [2]);

        $first = $this->orchestrator($migrator)->startMigration(['product'], true);
        $this->drain($migrator, $first);

        $retry = $this->orchestrator($migrator)->startRetry((string) $first['migration_id']);

        $this->assertFalse(
            $this->state->isDryRun(),
            'Retrying a dry run for real is a decision the caller makes, not one it inherits.',
        );
        $this->assertFalse($retry['status'] === 'idle');
    }

    public function testRetryOfIsAlwaysPresentInTheResultShape(): void
    {
        $migrator = $this->migrator([1], failing: []);

        $result = $this->orchestrator($migrator)->startMigration(['product']);

        $this->assertArrayHasKey('retry_of', $result);
        $this->assertNull($result['retry_of']);
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    /**
     * @param list<int> $records
     * @param list<int> $failing
     */
    private function migrator(array $records, array $failing = [], int $total = 0): RetryTestMigrator
    {
        return new RetryTestMigrator('product', $records, $this->idMap, $this->log, $this->state, $failing, $total);
    }

    private function orchestrator(MigratorInterface $migrator): MigrationOrchestrator
    {
        // A fresh orchestrator per batch, deliberately: every real driver — the
        // REST controller and the WP-CLI command alike — builds one per request,
        // so a retry that only resumed within one PHP process would be useless.
        return new MigrationOrchestrator([$migrator], $this->state, $this->idMap, $this->log);
    }

    /**
     * Run batches to completion, each through a freshly built orchestrator.
     *
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function drain(MigratorInterface $migrator, array $result, int $maxBatches = 10): array
    {
        $guard = 0;

        while (($result['continue'] ?? false) && $guard++ < $maxBatches) {
            $result = $this->orchestrator($migrator)->processBatch();
        }

        $this->assertLessThan($maxBatches, $guard, 'The run did not terminate.');

        return $result;
    }

    /**
     * The WC IDs this migration created ID-map rows for, in one realm.
     *
     * @return list<string>
     */
    private function idMapIds(string $entityType, string $migrationId, bool $simulated = false): array
    {
        $ids = [];

        foreach ($this->wpdb->idMap as $row) {
            if (
                $row['entity_type'] === $entityType
                && $row['migration_id'] === $migrationId
                && (bool) ($row['is_simulated'] ?? 0) === $simulated
            ) {
                $ids[] = (string) $row['wc_id'];
            }
        }

        sort($ids);

        return $ids;
    }

    /**
     * The WC IDs this migration logged under one status.
     *
     * @return list<string>
     */
    private function loggedIdsWithStatus(string $migrationId, string $status): array
    {
        $ids = [];

        foreach ($this->wpdb->logRows as $row) {
            if ($row['migration_id'] === $migrationId && $row['status'] === $status) {
                $ids[] = (string) $row['wc_id'];
            }
        }

        sort($ids);

        return array_values(array_unique($ids));
    }

    /**
     * The WC IDs this migration logged as errors.
     *
     * @return list<string>
     */
    private function loggedErrorIds(string $migrationId): array
    {
        $ids = [];

        foreach ($this->wpdb->logRows as $row) {
            if ($row['migration_id'] === $migrationId && $row['status'] === 'error') {
                $ids[] = (string) $row['wc_id'];
            }
        }

        sort($ids);

        return array_values(array_unique($ids));
    }
}

/**
 * A migrator whose failures are a property, so a test can fix the underlying
 * problem between the run and the retry the way a store owner would.
 */
final class RetryTestMigrator implements MigratorInterface
{
    /** @var list<int> */
    public array $records;

    /** @var list<int> */
    public array $processedDuringRetry = [];

    /** @var list<int> Records handed to validateRecord() rather than processRecord(). */
    public array $validated = [];

    /** @var list<array<int, string|int>> */
    public array $fetchByIdsCalls = [];

    public int $fetchBatchCalls = 0;

    private bool $retrying = false;

    /**
     * @param list<int> $records
     * @param list<int> $failing
     */
    public function __construct(
        private readonly string $type,
        array $records,
        private readonly IdMapRepository $idMap,
        private readonly MigrationLogRepository $log,
        private readonly MigrationState $state,
        public array $failing = [],
        private readonly int $total = 0,
    ) {
        $this->records = $records;
    }

    #[\Override]
    public function entityType(): string
    {
        return $this->type;
    }

    #[\Override]
    public function count(): int
    {
        return $this->total > 0 ? $this->total : count($this->records);
    }

    #[\Override]
    public function migratedCount(): int
    {
        return 0;
    }

    #[\Override]
    public function initialize(): void
    {
    }

    #[\Override]
    public function initializeSimulated(): void
    {
        // No-op.
    }

    #[\Override]
    public function fetchBatch(string|int|null $cursor, int $limit): array
    {
        $this->fetchBatchCalls++;
        $this->retrying = false;

        $after = (int) $cursor;

        return array_map(
            static fn (int $id): object => (object) ['id' => $id],
            array_slice(
                array_values(array_filter($this->records, static fn (int $id): bool => $id > $after)),
                0,
                $limit,
            ),
        );
    }

    #[\Override]
    public function fetchByIds(array $wcIds): array
    {
        $this->fetchByIdsCalls[] = $wcIds;
        $this->retrying = true;

        $wanted = array_map(intval(...), $wcIds);

        return array_map(
            static fn (int $id): object => (object) ['id' => $id],
            array_values(array_filter($this->records, static fn (int $id): bool => in_array($id, $wanted, true))),
        );
    }

    #[\Override]
    public function cursorFor(mixed $record): string|int
    {
        return (int) $record->id;
    }

    #[\Override]
    public function processRecord(mixed $record): int|false
    {
        $id = (int) $record->id;

        if (in_array($id, $this->failing, true)) {
            throw new \RuntimeException(sprintf('record %d is broken', $id));
        }

        if ($this->retrying) {
            $this->processedDuringRetry[] = $id;
        }

        // Through the real repository, and stamped with the migration ID the
        // state is carrying right now — which is the whole question a retry
        // raises, since a retry's rows must land under the retry's own ID.
        $this->idMap->store(
            $this->type,
            (string) $id,
            1000 + $id,
            (string) $this->state->getMigrationId(),
        );

        return 1000 + $id;
    }

    /**
     * A dry run must leave a trace. Writing nothing at all would be
     * indistinguishable from a run that never happened, which is the one thing a
     * rehearsal must not look like.
     */
    #[\Override]
    public function validateRecord(mixed $record): bool
    {
        $id = (int) $record->id;

        $this->validated[] = $id;

        $this->log->write(
            (string) $this->state->getMigrationId(),
            $this->type,
            $id,
            'dry-run',
            sprintf('dry-run: would migrate %d.', $id),
        );

        return true;
    }

    #[\Override]
    public function getRecordId(mixed $record): string
    {
        return (string) $record->id;
    }
}

/**
 * An in-memory $wpdb that keeps the migration log and the ID map, so
 * getRetryableIds() answers from rows the run under test actually wrote.
 *
 * It is not a SQL engine and does not pretend to be. It reads back only the two
 * queries this test needs — the retry ID lookup and the rollback's ID-map scan —
 * and it reads them out of the interpolated query string, which the shared wpdb
 * stub's prepare() produces.
 */
final class RetryTestWpdb extends \wpdb
{
    /** @var list<array<string, mixed>> */
    public array $logRows = [];

    /** @var list<array<string, mixed>> */
    public array $idMap = [];

    /** Every id-map insert ever issued, including rows later deleted. */
    public array $idMapInserts = [];

    #[\Override]
    public function insert(string $table, array $data, ?array $format = null): int|false
    {
        if (str_contains($table, 'migration_log')) {
            $this->logRows[] = $data;
        }

        if (str_contains($table, 'id_map')) {
            $this->idMap[] = $data;
            $this->idMapInserts[] = $data;
        }

        return parent::insert($table, $data, $format);
    }

    /**
     * purgeSimulated() goes through wpdb::delete(), not a raw query.
     */
    #[\Override]
    public function delete(string $table, array $where, ?array $where_format = null): int|false
    {
        if (str_contains($table, 'id_map')) {
            $this->idMap = array_values(array_filter(
                $this->idMap,
                static function (array $row) use ($where): bool {
                    foreach ($where as $column => $value) {
                        if ((string) ($row[$column] ?? '') !== (string) $value) {
                            return true;
                        }
                    }

                    return false;
                },
            ));
        }

        return parent::delete($table, $where, $where_format);
    }

    /**
     * The rollback deletes ID-map rows with a raw DELETE, not with wpdb::delete().
     */
    #[\Override]
    public function query(string $query): int|false
    {
        if (str_contains($query, 'DELETE FROM') && str_contains($query, 'id_map')) {
            $migrationId = self::firstMatch("/migration_id = '([^']*)'/", $query);

            if ($migrationId !== null) {
                $this->idMap = array_values(array_filter(
                    $this->idMap,
                    static fn (array $row): bool => ($row['migration_id'] ?? null) !== $migrationId,
                ));
            }
        }

        return parent::query($query);
    }

    #[\Override]
    public function get_results(string $query, string $output = OBJECT): array
    {
        $GLOBALS['_cartshift_test_queries'][] = ['get_results', $query, $output];

        if (str_contains($query, 'migration_log')) {
            return $this->retryableRows($query);
        }

        if (str_contains($query, 'id_map')) {
            return $this->idMapRows($query);
        }

        return parent::get_results($query, $output);
    }

    /**
     * getRetryableIds()'s answer: the distinct wc_ids in one of the requested
     * statuses that have no success row of their own.
     *
     * @return list<array{wc_id: string}>
     */
    private function retryableRows(string $query): array
    {
        $migrationId = self::firstMatch("/migration_id = '([^']*)'/", $query);
        $entityType = self::firstMatch("/entity_type = '([^']*)'/", $query);

        if ($migrationId === null || $entityType === null) {
            return [];
        }

        preg_match("/status IN \(([^)]*)\)/", $query, $statusMatch);
        preg_match_all("/'([^']*)'/", $statusMatch[1] ?? '', $statusValues);
        $statuses = $statusValues[1] ?? [];

        $candidates = [];
        $succeeded = [];

        foreach ($this->logRows as $row) {
            if (($row['migration_id'] ?? null) !== $migrationId) {
                continue;
            }

            if (($row['entity_type'] ?? null) !== $entityType) {
                continue;
            }

            $wcId = (string) ($row['wc_id'] ?? '');

            if (($row['status'] ?? '') === 'success') {
                $succeeded[$wcId] = true;

                continue;
            }

            if (in_array($row['status'] ?? '', $statuses, true)) {
                $candidates[$wcId] = true;
            }
        }

        // A record that succeeded is not retryable, however many times it also
        // warned on the way there.
        $ids = array_keys(array_diff_key($candidates, $succeeded));
        sort($ids);

        return array_map(static fn (string $id): array => ['wc_id' => $id], $ids);
    }

    /**
     * The rollback's scan over the rows one migration created.
     *
     * @return list<object>
     */
    private function idMapRows(string $query): array
    {
        $migrationId = self::firstMatch("/migration_id = '([^']*)'/", $query);
        $entityType = self::firstMatch("/entity_type = '([^']*)'/", $query);

        $out = [];

        foreach ($this->idMap as $row) {
            if ($migrationId !== null && ($row['migration_id'] ?? null) !== $migrationId) {
                continue;
            }

            if ($entityType !== null && ($row['entity_type'] ?? null) !== $entityType) {
                continue;
            }

            $out[] = (object) ['wc_id' => $row['wc_id'], 'fc_id' => $row['fc_id']];
        }

        return $out;
    }

    private static function firstMatch(string $pattern, string $subject): string|null
    {
        return preg_match($pattern, $subject, $match) === 1 ? $match[1] : null;
    }
}

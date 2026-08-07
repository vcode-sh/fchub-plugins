<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Migration;

use CartShift\Domain\Migration\MigrationOrchestrator;
use CartShift\Domain\Migration\MigrationRollback;
use CartShift\Migrator\AbstractMigrator;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\Constants;
use CartShift\Tests\Unit\PluginTestCase;

/**
 * Everything the first batch writes must be filed under the run's real ID.
 *
 * This is not a hypothetical. Both drivers — MigrationController::buildOrchestrator()
 * and MigrateCommand — used to build their migrators, and hand each one a migration
 * ID, *before* MigrationOrchestrator::startMigration() called MigrationState::start(),
 * which mints a brand new UUID. startMigration() then runs the first batch itself. So
 * on every fresh run the first batch's ID-map rows and log rows carried an ID that
 * appeared in no state anywhere, while the UI, the CLI summary and rollback all
 * reported the new one.
 *
 * The consequences were the expensive kind. Rollback selects on migration_id, so it
 * skipped the entire first batch and told the user it had succeeded — records left in
 * FluentCart for ever, with no ID-map row pointing at them. Retry reads the log the
 * same way, so first-batch failures were invisible to it too.
 *
 * The migrators here are constructed in the old, dangerous order on purpose: built
 * first, migration started second. That ordering is now harmless because a migrator
 * holds no ID of its own — AbstractMigrator::migrationId() asks MigrationState every
 * time it writes — so there is nothing left to go stale.
 */
final class FirstBatchMigrationIdTest extends PluginTestCase
{
    private FirstBatchWpdb $wpdb;
    private mixed $originalWpdb = null;

    private MigrationState $state;
    private IdMapRepository $idMap;
    private MigrationLogRepository $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalWpdb = $GLOBALS['wpdb'] ?? null;
        $this->wpdb = new FirstBatchWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;

        delete_option('cartshift_migration_retry');

        $this->state = new MigrationState();
        $this->idMap = new IdMapRepository();
        $this->log   = new MigrationLogRepository();

        // Two per batch, so "the first batch" is a genuine subset of the run and
        // an ID that only went stale once has somewhere to show.
        add_filter('cartshift/migration/batch_size', static fn (): int => 2, 99);
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = $this->originalWpdb;

        parent::tearDown();
    }

    /**
     * The regression test proper: build the migrator first, start second, and
     * check what the very first batch stamped on its rows.
     */
    public function testTheFirstBatchWritesUnderTheIdTheRunReports(): void
    {
        $migrator = $this->migrator([11, 12, 13, 14, 15]);

        // Built before the run exists — exactly how the REST controller and the
        // WP-CLI command do it.
        $orchestrator = $this->orchestrator($migrator);

        $first = $orchestrator->startMigration([Constants::ENTITY_COUPON]);
        $migrationId = (string) $first['migration_id'];

        $this->assertNotSame('', $migrationId);
        $this->assertTrue($first['continue'], 'Five records at two per batch must leave work behind.');

        $this->assertSame(
            ['11', '12'],
            $this->idMapIds(Constants::ENTITY_COUPON, $migrationId),
            'The first batch must map its records under the ID the run reports.',
        );

        $this->assertSame(
            ['11', '12'],
            $this->loggedIds($migrationId),
            'The first batch must log under the ID the run reports.',
        );

        $this->assertSame(
            [],
            $this->orphanedMigrationIds($migrationId),
            'No row may be filed under an ID that appears in no state.',
        );

        // And the reported ID is the one the state is actually carrying, not a
        // value assembled for the response.
        $this->assertSame($migrationId, $orchestrator->getProgress()['migration_id']);
    }

    /**
     * The test that would have caught it. Roll the run back and the records the
     * first batch created must go with everything else.
     */
    public function testRollbackRemovesTheRecordsTheFirstBatchCreated(): void
    {
        $migrator = $this->migrator([11, 12, 13, 14, 15]);
        $orchestrator = $this->orchestrator($migrator);

        $result = $orchestrator->startMigration([Constants::ENTITY_COUPON]);
        $migrationId = (string) $result['migration_id'];

        $guard = 0;
        while (($result['continue'] ?? false) && $guard++ < 10) {
            $result = $this->orchestrator($migrator)->processBatch();
        }

        $this->assertSame(
            ['11', '12', '13', '14', '15'],
            $this->idMapIds(Constants::ENTITY_COUPON, $migrationId),
            'Every record, first batch included, is mapped under one ID.',
        );

        $firstBatchFcIds = [1011, 1012];

        $this->assertSame(
            [1011, 1012, 1013, 1014, 1015],
            array_keys($this->wpdb->coupons),
            'All five FluentCart records exist before the rollback.',
        );

        $stats = (new MigrationRollback($this->idMap, $this->log))->rollback($migrationId);

        $this->assertSame(5, $stats[Constants::ENTITY_COUPON] ?? 0);

        $this->assertSame(
            [],
            array_keys($this->wpdb->coupons),
            'Rollback must delete every FluentCart record the run created.',
        );

        foreach ($firstBatchFcIds as $fcId) {
            $this->assertContains(
                $fcId,
                $this->wpdb->deletedCoupons,
                sprintf('The first batch created FluentCart coupon %d and rollback must delete it.', $fcId),
            );
        }

        $this->assertSame(
            [],
            $this->idMapIds(Constants::ENTITY_COUPON, $migrationId),
            'And the ID-map rows go with them.',
        );
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    /**
     * @param list<int> $records
     */
    private function migrator(array $records): FirstBatchMigrator
    {
        return new FirstBatchMigrator($records, $this->idMap, $this->log, $this->state);
    }

    private function orchestrator(FirstBatchMigrator $migrator): MigrationOrchestrator
    {
        return new MigrationOrchestrator([$migrator], $this->state, $this->idMap, $this->log);
    }

    /**
     * @return list<string>
     */
    private function idMapIds(string $entityType, string $migrationId): array
    {
        $ids = [];

        foreach ($this->wpdb->idMap as $row) {
            if ($row['entity_type'] === $entityType && $row['migration_id'] === $migrationId) {
                $ids[] = (string) $row['wc_id'];
            }
        }

        sort($ids);

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function loggedIds(string $migrationId): array
    {
        $ids = [];

        foreach ($this->wpdb->logRows as $row) {
            if (($row['migration_id'] ?? null) === $migrationId && (int) ($row['wc_id'] ?? 0) > 0) {
                $ids[(string) $row['wc_id']] = true;
            }
        }

        $ids = array_map(strval(...), array_keys($ids));
        sort($ids);

        return $ids;
    }

    /**
     * Every migration ID present on a written row that is not the expected one.
     *
     * @return list<string>
     */
    private function orphanedMigrationIds(string $expected): array
    {
        $seen = [];

        foreach ([...$this->wpdb->idMap, ...$this->wpdb->logRows] as $row) {
            $id = (string) ($row['migration_id'] ?? '');

            if ($id !== $expected) {
                $seen[$id] = true;
            }
        }

        return array_keys($seen);
    }
}

/**
 * A migrator built on the real AbstractMigrator, because the base class is where
 * the migration ID is resolved and therefore where the defect lived.
 *
 * It writes through the real IdMapRepository and the real MigrationLogRepository,
 * so the IDs under test are the ones that reach the database rather than ones the
 * test asserted about itself.
 */
final class FirstBatchMigrator extends AbstractMigrator
{
    /** @param list<int> $records */
    public function __construct(
        private readonly array $records,
        IdMapRepository $idMap,
        MigrationLogRepository $log,
        MigrationState $migrationState,
    ) {
        parent::__construct($idMap, $log, $migrationState);
    }

    #[\Override]
    protected function getEntityType(): string
    {
        return Constants::ENTITY_COUPON;
    }

    #[\Override]
    protected function countTotal(): int
    {
        return count($this->records);
    }

    #[\Override]
    public function fetchBatch(string|int|null $cursor, int $limit): array
    {
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
        $wanted = self::normalizeIntIds($wcIds);

        return array_map(
            static fn (int $id): object => (object) ['id' => $id],
            array_values(array_filter($this->records, static fn (int $id): bool => in_array($id, $wanted, true))),
        );
    }

    #[\Override]
    public function processRecord(mixed $record): int|false
    {
        global $wpdb;

        $wcId = (int) $record->id;
        $fcId = 1000 + $wcId;

        $wpdb->insert($wpdb->prefix . 'fct_coupons', ['id' => $fcId], ['%d']);

        $this->idMap->store($this->getEntityType(), (string) $wcId, $fcId, $this->migrationId(), true);
        $this->writeLog($wcId, 'success', sprintf('Migrated coupon %d.', $wcId));

        return $fcId;
    }

    #[\Override]
    public function getRecordId(mixed $record): string
    {
        return (string) $record->id;
    }
}

/**
 * An in-memory $wpdb holding the ID map, the migration log and a stand-in for
 * FluentCart's coupons table, so a rollback can be observed rather than assumed.
 */
final class FirstBatchWpdb extends \wpdb
{
    /** @var list<array<string, mixed>> */
    public array $logRows = [];

    /** @var list<array<string, mixed>> */
    public array $idMap = [];

    /** @var array<int, true> Surviving FluentCart coupon rows, keyed by FC ID. */
    public array $coupons = [];

    /** @var list<int> FC IDs rollback asked to delete. */
    public array $deletedCoupons = [];

    #[\Override]
    public function insert(string $table, array $data, ?array $format = null): int|false
    {
        if (str_contains($table, 'migration_log')) {
            $this->logRows[] = $data;
        }

        if (str_contains($table, 'id_map')) {
            $this->idMap[] = $data;
        }

        if (str_contains($table, 'fct_coupons')) {
            $this->coupons[(int) $data['id']] = true;
        }

        return parent::insert($table, $data, $format);
    }

    #[\Override]
    public function delete(string $table, array $where, ?array $whereFormat = null): int|false
    {
        if (str_contains($table, 'fct_coupons') && isset($where['id'])) {
            $fcId = (int) $where['id'];

            $this->deletedCoupons[] = $fcId;
            unset($this->coupons[$fcId]);
        }

        return parent::delete($table, $where, $whereFormat);
    }

    /**
     * The rollback drops ID-map rows with a raw DELETE rather than wpdb::delete().
     */
    #[\Override]
    public function query(string $query): int|false
    {
        if (str_contains($query, 'DELETE FROM') && str_contains($query, 'id_map')) {
            $migrationId = self::firstMatch("/migration_id = '([^']*)'/", $query);

            if ($migrationId !== null) {
                $this->idMap = array_values(array_filter(
                    $this->idMap,
                    static fn (array $row): bool => ($row['migration_id'] ?? null) !== $migrationId
                        || (int) ($row['created_by_migration'] ?? 0) !== 1,
                ));
            }
        }

        return parent::query($query);
    }

    #[\Override]
    public function get_results(string $query, string $output = OBJECT): array
    {
        $GLOBALS['_cartshift_test_queries'][] = ['get_results', $query, $output];

        if (str_contains($query, 'id_map')) {
            return $this->idMapRows($query);
        }

        return parent::get_results($query, $output);
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
        $createdOnly = str_contains($query, 'created_by_migration = 1');

        $out = [];

        foreach ($this->idMap as $row) {
            if ($migrationId !== null && ($row['migration_id'] ?? null) !== $migrationId) {
                continue;
            }

            if ($entityType !== null && ($row['entity_type'] ?? null) !== $entityType) {
                continue;
            }

            if ($createdOnly && (int) ($row['created_by_migration'] ?? 0) !== 1) {
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

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

/**
 * A dry run must resolve the same dependency questions a real run would, not
 * fail every lookup by construction. If IdMapRepository::getFcId() answers null
 * for everything during a dry run, every downstream validator that asks "does
 * this referenced product exist" — coupon restrictions, order line items,
 * subscriptions — over-reports failures a real migration would never hit.
 *
 * The hard part is that a batch is a request. MigrationOrchestrator handles ONE
 * entity type per processBatch() call, and both the REST batch loop and Action
 * Scheduler run one batch per request, so products are validated in an earlier
 * request than the coupons and orders that reference them. A simulation that
 * lives in a per-request memo therefore works only under WP-CLI, which drives
 * every batch in a single process — and that is exactly the shape of test that
 * passed while the feature was broken everywhere else. Hence the fake wpdb
 * below: these tests cross the request boundary for real.
 */
final class DryRunSimulationTest extends PluginTestCase
{
    private MigrationState $state;
    private MigrationLogRepository $log;
    private SimulationTestWpdb $wpdb;
    private mixed $originalWpdb = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalWpdb = $GLOBALS['wpdb'] ?? null;
        $this->wpdb = new SimulationTestWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;

        $this->state = new MigrationState();
        $this->log   = new MigrationLogRepository();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = $this->originalWpdb;

        parent::tearDown();
    }

    public function testACouponSeesProductsValidatedEarlierInTheSameDryRun(): void
    {
        $seen = [];
        $idMap = new IdMapRepository();

        $orchestrator = $this->orchestratorFor(
            [$this->productMigrator(), $this->couponMigrator($idMap, $seen)],
            $idMap,
        );

        $orchestrator->startMigration(['product', 'coupon'], dryRun: true);
        $this->drain($orchestrator);

        $this->assertNotNull(
            $seen['101'] ?? null,
            'A dry run must resolve products it already validated, or every downstream '
            . 'restriction check fails and the run over-reports.',
        );
    }

    /**
     * The case the old memo-backed simulation structurally could not survive, and
     * the reason every scoped review passed a broken feature.
     *
     * Batch 1 runs in one "request": its own orchestrator, its own repositories.
     * Batch 2 arrives in a brand new one — fresh MigrationState reading the
     * persisted option, fresh IdMapRepository with an empty memo, fresh
     * orchestrator that never calls startMigration() — and calls processBatch()
     * directly, which is precisely what the REST loop and Action Scheduler do.
     * The coupon validated there must still see the product validated in the
     * first request.
     */
    public function testDryRunMappingsSurviveAcrossTheRequestBoundary(): void
    {
        // ── Request 1: start the run, validate the products. ──
        $firstIdMap = new IdMapRepository();
        $firstState = new MigrationState();

        $first = new MigrationOrchestrator(
            [$this->productMigrator(), $this->couponMigrator($firstIdMap, $ignored)],
            $firstState,
            $firstIdMap,
            new MigrationLogRepository(),
        );

        $first->startMigration(['product', 'coupon'], dryRun: true);

        // ── Request 2 onward: nothing survives but the database and the option. ──
        $seen = [];
        $guard = 0;

        do {
            $idMap = new IdMapRepository();
            $state = new MigrationState();

            $orchestrator = new MigrationOrchestrator(
                [$this->productMigrator(), $this->couponMigrator($idMap, $seen)],
                $state,
                $idMap,
                new MigrationLogRepository(),
            );

            $result = $orchestrator->processBatch();
        } while (($result['continue'] ?? false) && $guard++ < 50);

        $this->assertArrayHasKey(
            '101',
            $seen,
            'The coupon batch must have run, or this test proves nothing.',
        );

        $this->assertNotNull(
            $seen['101'],
            'A coupon validated in a later request must still resolve a product validated '
            . 'in an earlier one. A per-request memo cannot do this, which is why the dry '
            . 'run only ever worked under WP-CLI.',
        );
    }

    /**
     * Every synthetic ID the orchestrator hands out is unique, non-zero, and
     * obviously fake — so distinct records never collide even within one run.
     */
    public function testEachValidatedRecordGetsADistinctSyntheticId(): void
    {
        $idMap = new IdMapRepository();
        $orchestrator = $this->orchestratorFor(
            [$this->productMigrator(), $this->couponMigrator($idMap, $ignored)],
            $idMap,
        );

        $orchestrator->startMigration(['product', 'coupon'], dryRun: true);
        $this->drain($orchestrator);

        $productId = $this->insertedFcId(Constants::ENTITY_PRODUCT, '101');
        $couponId  = $this->insertedFcId(Constants::ENTITY_COUPON, '501');

        $this->assertNotNull($productId);
        $this->assertNotNull($couponId);
        $this->assertNotSame($productId, $couponId);
        $this->assertGreaterThan(900_000_000, $productId);
        $this->assertGreaterThan(900_000_000, $couponId);
    }

    /**
     * A dry run creates nothing. It persists its mappings — it has to, or they
     * die with the request — but only into CartShift's own id-map table, flagged,
     * and never into WordPress or FluentCart.
     */
    public function testADryRunCreatesNothingOutsideTheIdMapTable(): void
    {
        $idMap = new IdMapRepository();
        $orchestrator = $this->orchestratorFor(
            [$this->productMigrator(), $this->couponMigrator($idMap, $ignored)],
            $idMap,
        );

        $orchestrator->startMigration(['product', 'coupon'], dryRun: true);
        $this->drain($orchestrator);

        foreach ($this->wpdb->inserts as [$table, $data]) {
            $this->assertMatchesRegularExpression(
                '/cartshift_(id_map|migration_log)$/',
                $table,
                sprintf('A dry run wrote to %s, which it has no business touching.', $table),
            );

            if (str_contains($table, 'id_map')) {
                $this->assertSame(1, $data['is_simulated'], 'And every row it writes is flagged as a rehearsal.');
            }
        }

        $this->assertSame([], $GLOBALS['_cartshift_test_deleted_posts']);
        $this->assertSame([], $GLOBALS['_cartshift_test_post_meta']);
    }

    /**
     * The safety-critical invariant. Simulated rows point at IDs no FluentCart
     * record has; a real migration resolving one would write a reference to
     * nothing, silently.
     */
    public function testARealMigrationNeverResolvesASimulatedRow(): void
    {
        $this->wpdb->seedIdMapRow(Constants::ENTITY_PRODUCT, '101', 900_000_042, simulated: true);

        $seen = [];
        $idMap = new IdMapRepository();

        $orchestrator = $this->orchestratorFor(
            [$this->couponMigrator($idMap, $seen)],
            $idMap,
        );

        $orchestrator->startMigration(['coupon'], dryRun: false);
        $this->drain($orchestrator);

        $this->assertArrayHasKey('101', $seen);
        $this->assertNull(
            $seen['101'],
            'A real migration must not resolve a dry run\'s leftovers.',
        );
    }

    public function testAFinishedDryRunLeavesNoSimulatedRows(): void
    {
        $idMap = new IdMapRepository();
        $orchestrator = $this->orchestratorFor(
            [$this->productMigrator(), $this->couponMigrator($idMap, $ignored)],
            $idMap,
        );

        $orchestrator->startMigration(['product', 'coupon'], dryRun: true);
        $this->drain($orchestrator);

        $this->assertSame(
            [],
            $this->wpdb->simulatedRows(),
            'A finished dry run takes its scaffolding with it.',
        );
    }

    public function testAStartingDryRunDiscardsAnAbandonedOnesRows(): void
    {
        $this->wpdb->seedIdMapRow(Constants::ENTITY_PRODUCT, '999', 900_000_001, simulated: true);
        $this->wpdb->seedIdMapRow(Constants::ENTITY_PRODUCT, '888', 4242, simulated: false);

        $idMap = new IdMapRepository();
        $orchestrator = $this->orchestratorFor([$this->productMigrator()], $idMap);

        $orchestrator->startMigration(['product'], dryRun: true);

        $stale = array_filter(
            $this->wpdb->simulatedRows(),
            static fn (array $row): bool => $row['wc_id'] === '999',
        );

        $this->assertSame([], $stale, 'A rehearsal begins from a clean slate.');
        $this->assertNotSame(
            [],
            array_filter(
                $this->wpdb->idMapRows,
                static fn (array $row): bool => $row['wc_id'] === '888',
            ),
            'Real rows are none of a dry run\'s business.',
        );
    }

    /**
     * The FC ID recorded for one entity, read from the inserts rather than the
     * live rows — a finished dry run has already cleaned the rows away.
     */
    private function insertedFcId(string $entityType, string $wcId): ?int
    {
        foreach ($this->wpdb->inserts as [$table, $data]) {
            if (
                str_contains($table, 'id_map')
                && ($data['entity_type'] ?? null) === $entityType
                && (string) ($data['wc_id'] ?? '') === $wcId
            ) {
                return (int) $data['fc_id'];
            }
        }

        return null;
    }

    /**
     * @param MigratorInterface[] $migrators
     */
    private function orchestratorFor(array $migrators, IdMapRepository $idMap): MigrationOrchestrator
    {
        return new MigrationOrchestrator($migrators, $this->state, $idMap, $this->log);
    }

    /**
     * Drive the orchestrator to completion.
     */
    private function drain(MigrationOrchestrator $orchestrator): void
    {
        $result = ['continue' => true];
        $guard = 0;

        while (($result['continue'] ?? false) && $guard++ < 50) {
            $result = $orchestrator->processBatch();
        }
    }

    private function productMigrator(): MigratorInterface
    {
        return $this->fakeMigrator(Constants::ENTITY_PRODUCT, [
            (object) ['id' => 101, 'name' => 'Widget'],
        ]);
    }

    /**
     * A coupon migrator that records, per validated coupon, what the id map said
     * about the product it depends on. That answer is the whole point of
     * simulation, so it is the thing worth asserting on.
     *
     * @param array<string, int|null> $seen
     */
    private function couponMigrator(IdMapRepository $idMap, mixed &$seen): MigratorInterface
    {
        // Accumulates rather than resets: the cross-request test rebuilds this
        // migrator once per simulated request, and only one of those requests is
        // the one that actually reaches the coupon entity.
        $seen ??= [];

        return $this->fakeMigrator(
            Constants::ENTITY_COUPON,
            [(object) ['id' => 501, 'name' => 'SAVE10']],
            static function () use ($idMap, &$seen): void {
                $seen['101'] = $idMap->getFcId(Constants::ENTITY_PRODUCT, '101');
            },
        );
    }

    /**
     * A minimal MigratorInterface fake — every record validates successfully,
     * which is all these tests need to exercise the simulation path.
     *
     * @param object[]      $records
     * @param callable|null $onValidate Called for each record during validation.
     */
    private function fakeMigrator(string $entityType, array $records, ?callable $onValidate = null): MigratorInterface
    {
        return new class ($entityType, $records, $onValidate) implements MigratorInterface {
            /**
             * @param object[] $records
             */
            public function __construct(
                private readonly string $type,
                private readonly array $records,
                private readonly mixed $onValidate = null,
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
                return count($this->records);
            }

            #[\Override]
            public function migratedCount(): int
            {
                return 0;
            }

            #[\Override]
            public function initialize(): void
            {
                // No-op.
            }

            #[\Override]
            public function initializeSimulated(): void
            {
                // No-op.
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
            public function fetchByIds(array $wcIds): array
            {
                $wanted = array_map(intval(...), $wcIds);

                return array_values(array_filter(
                    $this->records,
                    static fn (object $record): bool => in_array((int) $record->id, $wanted, true),
                ));
            }

            #[\Override]
            public function cursorFor(mixed $record): string|int
            {
                return (int) $record->id;
            }

            #[\Override]
            public function processRecord(mixed $record): int|false
            {
                if ($this->onValidate !== null) {
                    ($this->onValidate)($record);
                }

                return (int) $record->id;
            }

            #[\Override]
            public function validateRecord(mixed $record): bool
            {
                if ($this->onValidate !== null) {
                    ($this->onValidate)($record);
                }

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

/**
 * A wpdb that actually stores the id-map rows, so a lookup issued in a later
 * "request" — a fresh IdMapRepository with an empty memo — has something real to
 * resolve against. Honours the `is_simulated = 0` predicate, without which no
 * test here could tell "excludes the simulated realm" from "found nothing".
 */
final class SimulationTestWpdb extends \wpdb
{
    /** @var list<array{0: string, 1: array<string, mixed>}> Every insert, including rows since deleted. */
    public array $inserts = [];

    /** @var list<array<string, mixed>> Live id-map rows. */
    public array $idMapRows = [];

    private int $nextId = 1;

    public function seedIdMapRow(string $entityType, string $wcId, int $fcId, bool $simulated): void
    {
        $this->idMapRows[] = [
            'id'                   => $this->nextId++,
            'entity_type'          => $entityType,
            'wc_id'                => $wcId,
            'fc_id'                => $fcId,
            'migration_id'         => 'seeded',
            'created_by_migration' => 1,
            'is_simulated'         => $simulated ? 1 : 0,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function simulatedRows(): array
    {
        return array_values(array_filter(
            $this->idMapRows,
            static fn (array $row): bool => (int) $row['is_simulated'] === 1,
        ));
    }

    #[\Override]
    public function insert(string $table, array $data, ?array $format = null): int|false
    {
        $this->inserts[] = [$table, $data];

        if (str_contains($table, 'cartshift_id_map')) {
            $this->idMapRows[] = ['id' => $this->nextId++] + $data;
        }

        return parent::insert($table, $data, $format);
    }

    #[\Override]
    public function delete(string $table, array $where, ?array $where_format = null): int|false
    {
        if (str_contains($table, 'cartshift_id_map')) {
            $this->idMapRows = array_values(array_filter(
                $this->idMapRows,
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

    #[\Override]
    public function get_var(string $query): string|int|float|null
    {
        $GLOBALS['_cartshift_test_queries'][] = ['get_var', $query];

        if (!str_contains($query, 'cartshift_id_map')) {
            return 0;
        }

        if (str_contains($query, 'COUNT(')) {
            return 0;
        }

        preg_match("/entity_type = '([^']*)' AND wc_id = '([^']*)'/", $query, $matches);

        if ($matches === []) {
            return null;
        }

        foreach ($this->matchingRows($query, $matches[1]) as $row) {
            if ((string) $row['wc_id'] === $matches[2]) {
                return (string) $row['fc_id'];
            }
        }

        return null;
    }

    #[\Override]
    public function get_results(string $query, string $output = OBJECT): array
    {
        $GLOBALS['_cartshift_test_queries'][] = ['get_results', $query, $output];

        if (!str_contains($query, 'cartshift_id_map')) {
            return [];
        }

        preg_match("/entity_type = '([^']*)'/", $query, $matches);

        if ($matches === []) {
            return [];
        }

        return array_map(
            static fn (array $row): object => (object) ['wc_id' => (string) $row['wc_id'], 'fc_id' => $row['fc_id']],
            $this->matchingRows($query, $matches[1]),
        );
    }

    /**
     * Rows of one entity type visible to this query's realm, real rows first —
     * mirroring `ORDER BY is_simulated ASC, id ASC`.
     *
     * @return list<array<string, mixed>>
     */
    private function matchingRows(string $query, string $entityType): array
    {
        $realOnly = str_contains($query, 'is_simulated = 0');

        $rows = array_values(array_filter(
            $this->idMapRows,
            static function (array $row) use ($entityType, $realOnly): bool {
                if ($row['entity_type'] !== $entityType) {
                    return false;
                }

                return !$realOnly || (int) $row['is_simulated'] === 0;
            },
        ));

        usort(
            $rows,
            static fn (array $a, array $b): int => [$a['is_simulated'], $a['id']] <=> [$b['is_simulated'], $b['id']],
        );

        return $rows;
    }
}

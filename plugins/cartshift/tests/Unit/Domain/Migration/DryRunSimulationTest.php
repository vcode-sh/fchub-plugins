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
 * fail every lookup by construction. Before MigrationOrchestrator learned to
 * register a synthetic FluentCart ID for every record that validated
 * successfully, IdMapRepository::getFcId() returned null for everything
 * during a dry run — so any downstream validator that checks "does this
 * referenced product exist" (coupon restrictions, order line items,
 * subscriptions) over-reported failures that a real migration would never hit.
 */
final class DryRunSimulationTest extends PluginTestCase
{
    private MigrationState $state;
    private MigrationLogRepository $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->state = new MigrationState();
        $this->log   = new MigrationLogRepository();
    }

    public function testACouponSeesProductsValidatedEarlierInTheSameDryRun(): void
    {
        $idMap = new IdMapRepository();
        $orchestrator = $this->orchestratorFor([$this->productMigrator(), $this->couponMigrator()], $idMap);

        $orchestrator->startMigration(['product', 'coupon'], dryRun: true);
        $this->drain($orchestrator);

        $this->assertNotNull(
            $idMap->getFcId(Constants::ENTITY_PRODUCT, '101'),
            'A dry run must resolve products it already validated, or every downstream '
            . 'restriction check fails and the run over-reports.',
        );
    }

    /**
     * Every synthetic ID the orchestrator hands out is unique, non-zero, and
     * obviously fake — so distinct records never collide even within one run.
     */
    public function testEachValidatedRecordGetsADistinctSyntheticId(): void
    {
        $idMap = new IdMapRepository();
        $orchestrator = $this->orchestratorFor([$this->productMigrator(), $this->couponMigrator()], $idMap);

        $orchestrator->startMigration(['product', 'coupon'], dryRun: true);
        $this->drain($orchestrator);

        $productId = $idMap->getFcId(Constants::ENTITY_PRODUCT, '101');
        $couponId  = $idMap->getFcId(Constants::ENTITY_COUPON, '501');

        $this->assertNotNull($productId);
        $this->assertNotNull($couponId);
        $this->assertNotSame($productId, $couponId);
        $this->assertGreaterThan(900_000_000, $productId);
        $this->assertGreaterThan(900_000_000, $couponId);
    }

    /**
     * Simulation must never leak into the real table — that is the entire
     * point of IdMapRepository::enableSimulation().
     */
    public function testSimulationNeverWritesToTheDatabase(): void
    {
        $idMap = new IdMapRepository();
        $orchestrator = $this->orchestratorFor([$this->productMigrator(), $this->couponMigrator()], $idMap);

        $orchestrator->startMigration(['product', 'coupon'], dryRun: true);
        $this->drain($orchestrator);

        global $wpdb;
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cartshift_id_map");

        $this->assertSame(0, $count, 'A dry run must never persist rows to the id map table.');
    }

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

    private function couponMigrator(): MigratorInterface
    {
        return $this->fakeMigrator(Constants::ENTITY_COUPON, [
            (object) ['id' => 501, 'name' => 'SAVE10'],
        ]);
    }

    /**
     * A minimal MigratorInterface fake, mirroring the one in
     * MigrationOrchestratorTest — every record validates successfully, which
     * is all this test needs to exercise the simulation path.
     *
     * @param object[] $records
     */
    private function fakeMigrator(string $entityType, array $records): MigratorInterface
    {
        return new class ($entityType, $records) implements MigratorInterface {
            /**
             * @param object[] $records
             */
            public function __construct(
                private readonly string $type,
                private readonly array $records,
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

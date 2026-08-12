<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Storage;

use CartShift\Domain\Transfer\Identity\IdentityConflict;
use CartShift\Domain\Transfer\Identity\MapState;
use CartShift\Domain\Transfer\Identity\MappingRecord;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Storage\IdMapRepository;
use CartShift\Support\DatabaseTransaction;
use CartShift\Tests\Unit\PluginTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class IdMapRepositoryAtomicTest extends PluginTestCase
{
    /** @var list<array<string, mixed>> */
    private array $rows = [];
    private bool $failInsert = false;
    private int|false $updateResult = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $self = $this;
        $GLOBALS['_cartshift_test_insert_callback'] = static function (string $table, array $data) use ($self): int|false {
            if (!str_ends_with($table, 'cartshift_id_map')) {
                return 1;
            }

            if ($self->failInsert || $self->findRow($data) !== null) {
                return false;
            }

            $self->rows[] = ['id' => count($self->rows) + 1, ...$data];

            return 1;
        };
        $GLOBALS['_cartshift_test_get_results_callback'] = static fn (string $query): array => $self->selectRows($query);
        $GLOBALS['_cartshift_test_update_callback'] = static function (
            string $table,
            array $data,
            array $where,
        ) use ($self): int|false {
            if (!str_ends_with($table, 'cartshift_id_map')) {
                return 1;
            }

            if ($self->updateResult !== 1) {
                return $self->updateResult;
            }

            foreach ($self->rows as &$row) {
                if ($self->rowMatches($row, $where)) {
                    $row = [...$row, ...$data];

                    return 1;
                }
            }

            return 0;
        };
    }

    public function testFailedInsertDoesNotPopulateMemo(): void
    {
        $this->failInsert = true;
        $repository = $this->repository();

        try {
            $repository->storeOrThrow(...$this->mappingArgs());
            self::fail('Expected checked insert failure.');
        } catch (\RuntimeException) {
            self::assertNull($repository->get($this->identity()));
        }
    }

    public function testSuccessfulInsertIsReadBackAndMemoisedOnlyAfterVerification(): void
    {
        $repository = $this->repository();
        $actual = $repository->storeOrThrow(...$this->mappingArgs());
        $readsAfterStore = $this->countQueries('get_results');

        self::assertSame(10, $actual->targetId);
        self::assertSame(MapState::Claimed, $actual->state);
        self::assertSame($actual, $repository->get($this->identity()));
        self::assertSame($readsAfterStore, $this->countQueries('get_results'));
    }

    public function testCompatibleDuplicateReturnsExistingMapping(): void
    {
        $existing = $this->seedMapping();

        $actual = $this->repository()->storeOrThrow(...$this->mappingArgs());

        self::assertTrue($existing->isCompatibleWith($actual));
    }

    #[DataProvider('incompatibleDuplicateProvider')]
    public function testIncompatibleDuplicateThrowsIdentityConflict(array $changes): void
    {
        $this->seedMapping();
        $arguments = [...$this->mappingArgs(), ...$changes];

        try {
            $this->repository()->storeOrThrow(...$arguments);
            self::fail('Expected incompatible identity conflict.');
        } catch (IdentityConflict $exception) {
            self::assertStringNotContainsString('lapka-web', $exception->getMessage());
            self::assertStringNotContainsString('42', $exception->getMessage());
        }
    }

    public static function incompatibleDuplicateProvider(): iterable
    {
        yield 'target ID' => [['targetId' => 11]];
        yield 'source fingerprint' => [['sourceFingerprint' => str_repeat('c', 64)]];
        yield 'target fingerprint' => [['targetFingerprint' => str_repeat('d', 64)]];
        yield 'migration owner' => [['migrationId' => 'run-2']];
        yield 'creation owner' => [['createdByMigration' => false]];
        yield 'state' => [['state' => MapState::Staged]];
    }

    public function testGetExcludesRolledBackMapping(): void
    {
        $this->seedMapping(state: MapState::RolledBack);

        self::assertNull($this->repository()->get($this->identity()));
    }

    public function testLaterGenerationReclaimsTheSameRolledBackRowThroughClaimedState(): void
    {
        $this->seedMapping(state: MapState::RolledBack);
        $repository = $this->repository();
        $arguments = array_replace($this->mappingArgs(), [
            'targetId' => 77,
            'migrationId' => 'run-2',
            'sourceFingerprint' => str_repeat('c', 64),
            'targetFingerprint' => str_repeat('d', 64),
            'state' => MapState::Staged,
            'generation' => 2,
        ]);

        DatabaseTransaction::begin();
        $actual = $repository->storeOrThrow(...$arguments);
        DatabaseTransaction::commit();

        self::assertCount(1, $this->rows, 'Reclaim inserted an ambiguous second source map.');
        self::assertSame(77, $actual->targetId);
        self::assertSame(MapState::Staged, $actual->state);
        self::assertSame('run-2', $this->rows[0]['migration_id']);
        self::assertSame(2, $this->countQueries('update'), 'Reclaim did not prove rolled_back -> claimed -> staged.');
    }

    public function testRolledBackMapCannotBeReclaimedWithoutANewGenerationAndActiveTransaction(): void
    {
        foreach ([1, 2] as $generation) {
            $this->rows = [];
            $this->seedMapping(state: MapState::RolledBack);
            $arguments = array_replace($this->mappingArgs(), [
                'migrationId' => 'run-2',
                'generation' => $generation,
            ]);
            if ($generation === 2) {
                $expected = 'identity_map_reclaim_requires_transaction';
            } else {
                DatabaseTransaction::begin();
                $expected = 'identity_map_reclaim_requires_new_generation';
            }
            try {
                $this->repository()->storeOrThrow(...$arguments);
                self::fail('Unsafe rolled-back map reclaim was accepted.');
            } catch (\RuntimeException $exception) {
                self::assertSame($expected, $exception->getMessage());
            } finally {
                DatabaseTransaction::rollback();
            }
            self::assertCount(1, $this->rows);
            self::assertSame(MapState::RolledBack->value, $this->rows[0]['record_state']);
        }
    }

    public function testTransitionUsesExpectedStateAndFingerprintAndReadsBackResult(): void
    {
        $this->seedMapping();

        $actual = $this->repository()->transitionOrThrow(
            $this->identity(),
            MapState::Claimed,
            MapState::Staged,
            str_repeat('b', 64),
            str_repeat('c', 64),
        );

        self::assertSame(MapState::Staged, $actual->state);
        self::assertSame(str_repeat('c', 64), $actual->targetFingerprint);
    }

    public function testTransitionAffectedRowMismatchIsAConflict(): void
    {
        $this->seedMapping();
        $this->updateResult = 0;
        $this->expectException(IdentityConflict::class);

        $this->repository()->transitionOrThrow(
            $this->identity(),
            MapState::Claimed,
            MapState::Staged,
            str_repeat('b', 64),
            str_repeat('c', 64),
        );
    }

    public function testTransitionDatabaseFailureThrowsAndDoesNotChangeMemo(): void
    {
        $this->seedMapping();
        $repository = $this->repository();
        $before = $repository->get($this->identity());
        $this->updateResult = false;

        try {
            $repository->transitionOrThrow(
                $this->identity(),
                MapState::Claimed,
                MapState::Staged,
                str_repeat('b', 64),
                str_repeat('c', 64),
            );
            self::fail('Expected checked update failure.');
        } catch (\RuntimeException) {
            self::assertSame($before, $repository->get($this->identity()));
        }
    }

    public function testTransactionRollbackInvalidatesAReadBackMapping(): void
    {
        $repository = $this->repository();
        DatabaseTransaction::begin();
        $repository->storeOrThrow(...$this->mappingArgs());
        DatabaseTransaction::rollback(new \RuntimeException('later record write failed'));
        $this->rows = [];

        self::assertNull($repository->get($this->identity()));
    }

    /** @return array<string, mixed> */
    private function mappingArgs(): array
    {
        return [
            'identity' => $this->identity(),
            'targetId' => 10,
            'migrationId' => 'run-1',
            'sourceFingerprint' => str_repeat('a', 64),
            'targetFingerprint' => str_repeat('b', 64),
            'state' => MapState::Claimed,
            'createdByMigration' => true,
        ];
    }

    private function repository(): IdMapRepository
    {
        return new IdMapRepository('lapka-web');
    }

    private function identity(): SourceIdentity
    {
        return new SourceIdentity('lapka-web', 'product', '42');
    }

    private function seedMapping(MapState $state = MapState::Claimed): MappingRecord
    {
        $fingerprint = $state === MapState::Legacy ? null : str_repeat('a', 64);
        $targetFingerprint = $state === MapState::Legacy ? null : str_repeat('b', 64);
        $this->rows[] = [
            'id' => count($this->rows) + 1,
            'source_key' => 'lapka-web',
            'entity_type' => 'product',
            'wc_id' => '42',
            'fc_id' => 10,
            'migration_id' => 'run-1',
            'created_by_migration' => 1,
            'is_simulated' => 0,
            'source_fingerprint' => $fingerprint,
            'target_fingerprint' => $targetFingerprint,
            'record_state' => $state->value,
        ];

        return new MappingRecord($this->identity(), 10, $fingerprint, $targetFingerprint, $state);
    }

    /** @param array<string, mixed> $identity */
    public function findRow(array $identity): ?array
    {
        foreach ($this->rows as $row) {
            if ($this->rowMatches($row, [
                'source_key' => $identity['source_key'],
                'entity_type' => $identity['entity_type'],
                'wc_id' => $identity['wc_id'],
                'is_simulated' => $identity['is_simulated'],
            ])) {
                return $row;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $where */
    public function rowMatches(array $row, array $where): bool
    {
        foreach ($where as $key => $value) {
            if ((string) ($row[$key] ?? '') !== (string) $value) {
                return false;
            }
        }

        return true;
    }

    /** @return list<object> */
    public function selectRows(string $query): array
    {
        $matches = [];
        preg_match("/source_key = '([^']+)'/", $query, $matches['source']);
        preg_match("/entity_type = '([^']+)'/", $query, $matches['type']);
        preg_match("/wc_id = '([^']+)'/", $query, $matches['id']);

        $rows = array_filter($this->rows, static fn (array $row): bool =>
            ($row['source_key'] ?? null) === ($matches['source'][1] ?? null)
            && ($row['entity_type'] ?? null) === ($matches['type'][1] ?? null)
            && (string) ($row['wc_id'] ?? '') === ($matches['id'][1] ?? null)
            && (int) ($row['is_simulated'] ?? 0) === 0
            && (!str_contains($query, "record_state <> 'rolled_back'") || $row['record_state'] !== 'rolled_back')
        );

        return array_map(static fn (array $row): object => (object) $row, array_values($rows));
    }

    private function countQueries(string $operation): int
    {
        return count(array_filter(
            $GLOBALS['_cartshift_test_queries'] ?? [],
            static fn (array $query): bool => ($query[0] ?? '') === $operation,
        ));
    }
}

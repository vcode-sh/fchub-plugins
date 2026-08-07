<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Migration;

use CartShift\Domain\Migration\Contracts\MigratorInterface;
use CartShift\Domain\Migration\MigrationOrchestrator;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Tests\Unit\PluginTestCase;

/**
 * Keyset pagination replaced LIMIT/OFFSET, which turned an O(n^2) scan into an
 * index seek. These tests pin the properties that make the swap safe: the
 * cursor always moves forward, a resumed run picks up exactly where it left
 * off, a run that predates cursors restarts rather than skipping, and a record
 * that can never be migrated does not wedge the entity.
 */
final class KeysetPaginationTest extends PluginTestCase
{
    private const string OPTION_KEY = 'cartshift_migration_state';

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

    public function testCursorAdvancesAndNoRecordIsReadTwice(): void
    {
        $migrator = $this->keysetMigrator('product', [1, 2, 3, 4, 5]);

        add_filter('cartshift/migration/batch_size', fn (): int => 2);

        $orchestrator = $this->orchestrator($migrator);

        $result = $orchestrator->startMigration(['product']);
        $this->assertSame(2, $this->state->getEntityCursor('product'));
        $this->assertSame(2, $result['cursor'], 'The cursor is exposed on the batch result.');

        $orchestrator->processBatch();
        $this->assertSame(4, $this->state->getEntityCursor('product'));

        $orchestrator->processBatch();
        $this->assertSame(5, $this->state->getEntityCursor('product'));

        // Empty fetch closes the entity.
        $result = $orchestrator->processBatch();
        $this->assertFalse($result['continue']);

        $this->assertSame([1, 2, 3, 4, 5], $migrator->fetchedIds());
        $this->assertSame([1, 2, 3, 4, 5], $migrator->processedIds());
    }

    public function testEveryFetchQueriesAfterTheCursorRatherThanAtAnOffset(): void
    {
        $migrator = $this->keysetMigrator('product', [1, 2, 3, 4]);

        add_filter('cartshift/migration/batch_size', fn (): int => 2);

        $orchestrator = $this->orchestrator($migrator);
        $orchestrator->startMigration(['product']);
        $orchestrator->processBatch();

        $this->assertSame([null, 2], $migrator->cursorsSeen());
    }

    public function testResumesFromAPersistedCursor(): void
    {
        $migrator = $this->keysetMigrator('product', [10, 20, 30, 40]);

        add_filter('cartshift/migration/batch_size', fn (): int => 10);

        $orchestrator = $this->orchestrator($migrator);
        $orchestrator->startMigration(['product']);

        // Pretend the run stopped after the second record and came back later.
        $this->state->setEntityCursor('product', 20);
        $migrator->reset();

        $orchestrator->processBatch();

        $this->assertSame([30, 40], $migrator->fetchedIds());
        $this->assertSame([20], $migrator->cursorsSeen());
    }

    public function testAMissingCursorRestartsTheEntityAndSaysSo(): void
    {
        $migrator = $this->keysetMigrator('product', [1, 2, 3]);

        add_filter('cartshift/migration/batch_size', fn (): int => 10);

        $orchestrator = $this->orchestrator($migrator);
        $orchestrator->startMigration(['product']);
        $migrator->reset();

        // A run persisted before cursors existed: an offset, but no cursors key.
        $persisted = get_option(self::OPTION_KEY);
        unset($persisted['cursors']);
        $persisted['current_offset'] = 3;
        update_option(self::OPTION_KEY, $persisted, false);

        // A fresh state object, so the in-request memo cannot serve the old array.
        $this->state = new MigrationState();
        $orchestrator = $this->orchestrator($migrator);
        $orchestrator->processBatch();

        $this->assertSame([null], $migrator->cursorsSeen(), 'A missing cursor restarts the entity.');
        $this->assertSame([1, 2, 3], $migrator->fetchedIds());

        $logged = $this->loggedMessages();
        $this->assertNotEmpty(array_filter(
            $logged,
            static fn (string $message): bool => str_contains($message, 'No keyset cursor found'),
        ), 'The restart decision must be logged: ' . implode(' | ', $logged));
    }

    public function testAPermanentlySkippedRecordDoesNotWedgeTheEntity(): void
    {
        // Nothing can ever be migrated — processRecord() returns false for every
        // record. A "next N unmigrated" cursor would spin here for ever.
        $migrator = $this->keysetMigrator('product', [1, 2, 3, 4, 5], alwaysSkip: true);

        add_filter('cartshift/migration/batch_size', fn (): int => 2);

        $orchestrator = $this->orchestrator($migrator);

        $result = $orchestrator->startMigration(['product']);

        $guard = 0;
        while ($result['continue'] && $guard++ < 20) {
            $result = $orchestrator->processBatch();
        }

        $this->assertLessThan(20, $guard, 'The entity must terminate, not loop.');
        $this->assertSame([1, 2, 3, 4, 5], $migrator->fetchedIds());

        $entity = $this->state->getCurrent()['entities']['product'];
        $this->assertSame(5, $entity['skipped']);
        $this->assertSame(0, $entity['processed']);
        $this->assertSame('completed', $entity['status']);
    }

    public function testAStalledCursorStopsTheEntityInsteadOfLooping(): void
    {
        // A deliberately broken migrator: always returns the same record, and a
        // cursor that never moves. Without the stall guard this never returns.
        $migrator = new class implements MigratorInterface {
            public int $calls = 0;

            #[\Override]
            public function entityType(): string
            {
                return 'product';
            }

            #[\Override]
            public function count(): int
            {
                return 1;
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
            public function fetchByIds(array $wcIds): array
            {
                return [];
            }

            #[\Override]
            public function fetchBatch(string|int|null $cursor, int $limit): array
            {
                $this->calls++;

                return [(object) ['id' => 7]];
            }

            #[\Override]
            public function cursorFor(mixed $record): string|int
            {
                return 7;
            }

            #[\Override]
            public function processRecord(mixed $record): int|false
            {
                return 7;
            }

            #[\Override]
            public function validateRecord(mixed $record): bool
            {
                return true;
            }

            #[\Override]
            public function getRecordId(mixed $record): string
            {
                return '7';
            }
        };

        $orchestrator = $this->orchestrator($migrator);

        $result = $orchestrator->startMigration(['product']);

        $guard = 0;
        while ($result['continue'] && $guard++ < 10) {
            $result = $orchestrator->processBatch();
        }

        $this->assertLessThan(10, $guard);
        $this->assertSame('completed', $this->state->getCurrent()['entities']['product']['status']);
        $this->assertNotEmpty(array_filter(
            $this->loggedMessages(),
            static fn (string $message): bool => str_contains($message, 'Cursor did not advance'),
        ));
    }

    public function testTheBatchResultCarriesTheAlreadyMigratedCount(): void
    {
        $migrator = $this->keysetMigrator('product', [1, 2], migrated: 1204);

        $orchestrator = $this->orchestrator($migrator);
        $result = $orchestrator->startMigration(['product']);

        $this->assertSame(1204, $result['migrated']);
    }

    /**
     * The keys the REST controller, the WP-CLI command and the Vue UI read.
     */
    public function testResultKeysAreOnlyEverAddedTo(): void
    {
        $migrator = $this->keysetMigrator('product', [1]);

        $orchestrator = $this->orchestrator($migrator);
        $result = $orchestrator->startMigration(['product']);

        foreach (
            [
                'continue', 'migration_id', 'status', 'entity_type', 'entity_index',
                'entity_count', 'offset', 'total', 'processed', 'entities',
            ] as $key
        ) {
            $this->assertArrayHasKey($key, $result);
        }

        $this->assertArrayHasKey('cursor', $result);
        $this->assertArrayHasKey('migrated', $result);
    }

    public function testOffsetStaysAMonotonicProcessedCount(): void
    {
        $migrator = $this->keysetMigrator('product', [5, 6, 7, 8]);

        add_filter('cartshift/migration/batch_size', fn (): int => 2);

        $orchestrator = $this->orchestrator($migrator);

        $result = $orchestrator->startMigration(['product']);
        $this->assertSame(2, $result['offset'], 'offset counts records, not source IDs.');

        $result = $orchestrator->processBatch();
        $this->assertSame(4, $result['offset']);
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    private function orchestrator(MigratorInterface $migrator): MigrationOrchestrator
    {
        return new MigrationOrchestrator([$migrator], $this->state, $this->idMap, $this->log);
    }

    /**
     * @return list<string>
     */
    private function loggedMessages(): array
    {
        $messages = [];

        foreach ($GLOBALS['_cartshift_test_queries'] ?? [] as $entry) {
            if ($entry[0] === 'insert' && str_contains((string) $entry[1], 'migration_log')) {
                $messages[] = (string) ($entry[2]['message'] ?? '');
            }
        }

        return $messages;
    }

    /**
     * A migrator over a fixed, ascending list of source IDs, paginated the way
     * every real keyset migrator paginates.
     *
     * @param list<int> $ids
     */
    private function keysetMigrator(
        string $entityType,
        array $ids,
        bool $alwaysSkip = false,
        int $migrated = 0,
    ): MigratorInterface {
        return new class ($entityType, $ids, $alwaysSkip, $migrated) implements MigratorInterface {
            /** @var list<int> */
            private array $fetched = [];

            /** @var list<int> */
            private array $processed = [];

            /** @var list<string|int|null> */
            private array $cursors = [];

            /** @param list<int> $ids */
            public function __construct(
                private readonly string $type,
                private readonly array $ids,
                private readonly bool $alwaysSkip,
                private readonly int $migrated,
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
                return count($this->ids);
            }

            #[\Override]
            public function migratedCount(): int
            {
                return $this->migrated;
            }

            #[\Override]
            public function initialize(): void
            {
            }

            #[\Override]
            public function fetchByIds(array $wcIds): array
            {
                $wanted = array_map(intval(...), $wcIds);

                return array_map(
                    static fn (int $id): object => (object) ['id' => $id],
                    array_values(array_filter(
                        $this->ids,
                        static fn (int $id): bool => in_array($id, $wanted, true),
                    )),
                );
            }

            #[\Override]
            public function fetchBatch(string|int|null $cursor, int $limit): array
            {
                $this->cursors[] = $cursor;

                $after = (int) $cursor;

                $page = array_slice(
                    array_values(array_filter($this->ids, static fn (int $id): bool => $id > $after)),
                    0,
                    $limit,
                );

                foreach ($page as $id) {
                    $this->fetched[] = $id;
                }

                return array_map(static fn (int $id): object => (object) ['id' => $id], $page);
            }

            #[\Override]
            public function cursorFor(mixed $record): string|int
            {
                return (int) $record->id;
            }

            #[\Override]
            public function processRecord(mixed $record): int|false
            {
                if ($this->alwaysSkip) {
                    return false;
                }

                $this->processed[] = (int) $record->id;

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

            /** @return list<int> */
            public function fetchedIds(): array
            {
                return $this->fetched;
            }

            /** @return list<int> */
            public function processedIds(): array
            {
                return $this->processed;
            }

            /** @return list<string|int|null> */
            public function cursorsSeen(): array
            {
                return $this->cursors;
            }

            public function reset(): void
            {
                $this->fetched = [];
                $this->processed = [];
                $this->cursors = [];
            }
        };
    }
}

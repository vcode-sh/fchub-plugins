<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Migrator\Entity;

use CartShift\Migrator\CustomerMigrator;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Tests\Unit\PluginTestCase;

require_once dirname(__DIR__, 3) . '/stubs/EntityMigratorStubs.php';

/**
 * Customers are spliced out of two sources — registered user IDs, then guest
 * billing emails — and a single integer cursor cannot express that. The cursor
 * therefore carries a phase marker, and the handover between the two phases is
 * the part that is easy to get subtly wrong: too eager and the tail of the
 * registered users is skipped, too lazy and every guest batch re-reads them.
 */
final class CustomerCursorBoundaryTest extends PluginTestCase
{
    private ?\wpdb $originalWpdb = null;

    protected function tearDown(): void
    {
        if ($this->originalWpdb !== null) {
            $GLOBALS['wpdb'] = $this->originalWpdb;
            $this->originalWpdb = null;
        }

        parent::tearDown();
    }

    public function testAShortRegisteredPageIsToppedUpFromTheStartOfTheGuests(): void
    {
        $db = $this->stubSources([7, 9], ['ann@example.com', 'bob@example.com', 'cid@example.com']);
        $migrator = $this->migrator();

        $batch = $migrator->fetchBatch(null, 4);

        $this->assertSame(
            [
                ['registered', '7'],
                ['registered', '9'],
                ['guest', 'ann@example.com'],
                ['guest', 'bob@example.com'],
            ],
            $this->describe($batch),
        );

        // The guest query started at the beginning, not at some offset carried
        // over from the registered phase.
        $this->assertNull($db->lastGuestAfter);
        $this->assertSame(0, $db->lastRegisteredAfter);

        // The last record is a guest, so the run never looks at registered again.
        $this->assertSame('guest:bob@example.com', $migrator->cursorFor(end($batch)));
    }

    public function testAFullRegisteredPageStaysInTheRegisteredPhase(): void
    {
        $db = $this->stubSources([7, 9], ['ann@example.com']);
        $migrator = $this->migrator();

        $batch = $migrator->fetchBatch(null, 2);

        $this->assertSame([['registered', '7'], ['registered', '9']], $this->describe($batch));
        $this->assertSame('registered:9', $migrator->cursorFor(end($batch)));
        $this->assertFalse($db->guestQueried, 'A full registered page must not touch the guest source.');
    }

    public function testTheNextCallAfterAFullRegisteredPageCrossesTheBoundary(): void
    {
        $db = $this->stubSources([7, 9], ['ann@example.com']);
        $migrator = $this->migrator();

        $batch = $migrator->fetchBatch('registered:9', 2);

        $this->assertSame(9, $db->lastRegisteredAfter, 'Registered keysets on customer_id.');
        $this->assertSame([['guest', 'ann@example.com']], $this->describe($batch));
        $this->assertNull($db->lastGuestAfter, 'Guests start from the beginning exactly once.');
    }

    public function testAGuestCursorNeverRevisitsRegisteredUsers(): void
    {
        $db = $this->stubSources([7, 9], ['ann@example.com', 'bob@example.com']);
        $migrator = $this->migrator();

        $batch = $migrator->fetchBatch('guest:ann@example.com', 5);

        $this->assertFalse($db->registeredQueried, 'The registered source is behind us.');
        $this->assertSame('ann@example.com', $db->lastGuestAfter);
        $this->assertSame([['guest', 'bob@example.com']], $this->describe($batch));
    }

    public function testDrivingTheWholeSequenceReadsEachRecordExactlyOnce(): void
    {
        $this->stubSources([3, 5, 8], ['ann@example.com', 'bob@example.com', 'cid@example.com']);
        $migrator = $this->migrator();

        $cursor = null;
        $seen = [];
        $guard = 0;

        while ($guard++ < 20) {
            $batch = $migrator->fetchBatch($cursor, 2);

            if ($batch === []) {
                break;
            }

            foreach ($batch as $record) {
                $seen[] = $migrator->getRecordId($record);
            }

            $cursor = $migrator->cursorFor($batch[array_key_last($batch)]);
        }

        $this->assertLessThan(20, $guard, 'The sequence must terminate.');
        $this->assertSame(
            ['3', '5', '8', 'ann@example.com', 'bob@example.com', 'cid@example.com'],
            $seen,
        );
        $this->assertSame(count($seen), count(array_unique($seen)), 'No record may be read twice.');
    }

    public function testAnUnrecognisedCursorRestartsFromTheRegisteredPhase(): void
    {
        // A bare integer is what a run persisted before phase-tagged cursors
        // would leave behind. Restarting is safe — every processRecord()
        // short-circuits on an ID-map hit.
        $db = $this->stubSources([7], ['ann@example.com']);
        $migrator = $this->migrator();

        $migrator->fetchBatch(42, 2);

        $this->assertSame(0, $db->lastRegisteredAfter);
        $this->assertTrue($db->registeredQueried);
    }

    public function testGuestPaginationOmitsTheRangeClauseOnTheFirstPage(): void
    {
        $db = $this->stubSources([], ['ann@example.com']);
        $migrator = $this->migrator();

        $migrator->fetchBatch(null, 5);

        $this->assertStringNotContainsString('billing_email >', $db->lastGuestQuery);
        $this->assertStringContainsString('ORDER BY billing_email ASC', $db->lastGuestQuery);
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    private function migrator(): CustomerMigrator
    {
        return new CustomerMigrator(
            new IdMapRepository(),
            new MigrationLogRepository(),
            new MigrationState(),
        );
    }

    /**
     * @param list<array<string, mixed>> $batch
     * @return list<array{0: string, 1: string}>
     */
    private function describe(array $batch): array
    {
        return array_map(
            static fn (array $record): array => [
                $record['type'],
                (string) ($record['data']['user_id'] ?? $record['data']['email']),
            ],
            $batch,
        );
    }

    /**
     * Stand in for the two wc_orders queries the customer migrator issues,
     * honouring the keyset range clause and the limit the way MySQL would.
     *
     * @param list<int> $registered
     * @param list<string> $guests
     */
    private function stubSources(array $registered, array $guests): object
    {
        $this->originalWpdb ??= $GLOBALS['wpdb'];

        $db = new class ($registered, $guests) extends \wpdb {
            public bool $registeredQueried = false;
            public bool $guestQueried = false;
            public int $lastRegisteredAfter = -1;
            public ?string $lastGuestAfter = null;
            public string $lastGuestQuery = '';

            /**
             * @param list<int> $registered
             * @param list<string> $guests
             */
            public function __construct(
                private readonly array $registered,
                private readonly array $guests,
            ) {
            }

            #[\Override]
            public function get_col(string $query): array
            {
                $GLOBALS['_cartshift_test_queries'][] = ['get_col', $query];

                $limit = preg_match('/LIMIT (\d+)/', $query, $m) === 1 ? (int) $m[1] : 0;

                if (str_contains($query, 'SELECT DISTINCT customer_id')) {
                    $this->registeredQueried = true;
                    $after = preg_match('/customer_id > (\d+)/', $query, $m) === 1 ? (int) $m[1] : 0;
                    $this->lastRegisteredAfter = $after;

                    return array_map(strval(...), array_slice(
                        array_values(array_filter($this->registered, static fn (int $id): bool => $id > $after)),
                        0,
                        $limit,
                    ));
                }

                $this->guestQueried = true;
                $this->lastGuestQuery = $query;
                $after = preg_match("/billing_email > '([^']*)'/", $query, $m) === 1 ? $m[1] : null;
                $this->lastGuestAfter = $after;

                $rows = $after === null
                    ? $this->guests
                    : array_filter($this->guests, static fn (string $email): bool => $email > $after);

                return array_slice(array_values($rows), 0, $limit);
            }
        };

        $GLOBALS['wpdb'] = $db;

        return $db;
    }
}

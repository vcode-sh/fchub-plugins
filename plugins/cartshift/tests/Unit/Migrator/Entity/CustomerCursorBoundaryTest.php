<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Migrator\Entity;

use CartShift\Domain\Scope\MigrationScope;
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

    public function testAScopedRegisteredPhaseCarriesThePredicateIntoTheKeysetQuery(): void
    {
        $db = $this->stubSources([7, 9, 19], ['ann@example.com']);
        $migrator = $this->scopedMigrator(['mode' => 'explicit', 'customer_ids' => [7, 19]]);

        $migrator->fetchBatch(null, 5);

        // The scope and the cursor are conjuncts of one WHERE, not two queries
        // and a post-filter.
        $this->assertStringContainsString('customer_id > 0', $db->lastRegisteredQuery);
        $this->assertStringContainsString('customer_id IN (7, 19)', $db->lastRegisteredQuery);
        $this->assertStringContainsString('ORDER BY customer_id ASC', $db->lastRegisteredQuery);
    }

    public function testAScopedRunStillHandsOverToGuestsExactlyOnce(): void
    {
        $db = $this->stubSources([7, 19], ['ann@example.com', 'bob@example.com']);
        $migrator = $this->scopedMigrator([
            'mode'         => 'explicit',
            'customer_ids' => [7, 19],
            'guest_emails' => ['bob@example.com'],
        ]);

        $batch = $migrator->fetchBatch('registered:19', 5);

        $this->assertFalse($db->registeredQueriedTwice ?? false);
        $this->assertStringContainsString("billing_email IN ('bob@example.com')", $db->lastGuestQuery);
        $this->assertSame([['guest', 'bob@example.com']], $this->describe($batch));
    }

    public function testAScopedSequenceReadsEveryScopedCustomerExactlyOnceAndNothingElse(): void
    {
        // The regression this whole task exists to prevent: a scoped run that
        // skips a customer at the phase boundary looks identical to a correct
        // one, because the total shrinks to match.
        $this->stubSources([3, 5, 8, 13], ['ann@example.com', 'bob@example.com', 'cid@example.com']);
        $migrator = $this->scopedMigrator([
            'mode'         => 'explicit',
            'customer_ids' => [3, 8, 13],
            'guest_emails' => ['ann@example.com', 'cid@example.com'],
        ]);

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

        $this->assertSame(['3', '8', '13', 'ann@example.com', 'cid@example.com'], $seen);
        $this->assertSame(count($seen), count(array_unique($seen)), 'No record may be read twice.');
    }

    public function testAnExplicitScopePickingNoCustomersFetchesNobody(): void
    {
        // '1 = 0', not "no clause". This is the assertion that catches an empty
        // IN list being rendered away into a full migration.
        $db = $this->stubSources([7, 9], ['ann@example.com']);
        $migrator = $this->scopedMigrator(['mode' => 'explicit', 'product_ids' => [12]]);

        $this->assertSame([], $migrator->fetchBatch(null, 5));
        $this->assertStringContainsString('1 = 0', $db->lastRegisteredQuery);
    }

    public function testTheRegisteredCountMemoDoesNotSurviveAScopeChange(): void
    {
        // The registered total is memoised, and the memo is now scope-dependent.
        // A stale unscoped total surviving useScope() is invisible: the progress
        // bar simply reports a size nobody asked for.
        $this->stubSources([], []);

        $GLOBALS['_cartshift_test_get_var_callback'] = static function (string $query): int {
            if (!str_contains($query, 'COUNT(DISTINCT customer_id)')) {
                return 0;
            }

            return str_contains($query, 'customer_id IN (') ? 2 : 11;
        };

        $migrator = $this->migrator();

        $this->assertSame(11, $migrator->count());

        $migrator->useScope(MigrationScope::fromArray(['mode' => 'explicit', 'customer_ids' => [7, 19]]));

        $this->assertSame(2, $migrator->count());
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    /**
     * @param array<string, mixed> $scope
     */
    private function scopedMigrator(array $scope): CustomerMigrator
    {
        $migrator = $this->migrator();
        $migrator->useScope(MigrationScope::fromArray($scope));

        return $migrator;
    }

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
            public bool $registeredQueriedTwice = false;
            public bool $guestQueried = false;
            public int $lastRegisteredAfter = -1;
            public ?string $lastGuestAfter = null;
            public string $lastGuestQuery = '';
            public string $lastRegisteredQuery = '';

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
                    $this->registeredQueriedTwice = $this->registeredQueried;
                    $this->registeredQueried = true;
                    $this->lastRegisteredQuery = $query;
                    $after = preg_match('/customer_id > (\d+)/', $query, $m) === 1 ? (int) $m[1] : 0;
                    $this->lastRegisteredAfter = $after;

                    if (self::selectsNothing($query)) {
                        return [];
                    }

                    $selected = self::intSelection($query);

                    $rows = array_filter(
                        $this->registered,
                        static fn (int $id): bool => $id > $after
                            && ($selected === null || in_array($id, $selected, true)),
                    );

                    return array_map(strval(...), array_slice(array_values($rows), 0, $limit));
                }

                $this->guestQueried = true;
                $this->lastGuestQuery = $query;
                $after = preg_match("/billing_email > '([^']*)'/", $query, $m) === 1 ? $m[1] : null;
                $this->lastGuestAfter = $after;

                if (self::selectsNothing($query)) {
                    return [];
                }

                $selected = self::stringSelection($query);

                $rows = array_filter(
                    $this->guests,
                    static fn (string $email): bool => ($after === null || $email > $after)
                        && ($selected === null || in_array($email, $selected, true)),
                );

                return array_slice(array_values($rows), 0, $limit);
            }

            /**
             * The spliced-in empty set, ` AND (1 = 0)`. Deliberately narrower
             * than a bare `1 = 0`, which WooStorage's own status template also
             * emits when a site registers no migratable statuses.
             */
            private static function selectsNothing(string $query): bool
            {
                return str_contains($query, 'AND (1 = 0)');
            }

            /**
             * Crude stand-in for MySQL, not for a query planner: pull the ID
             * list out of a spliced `customer_id IN (…)` predicate. Null means
             * the query carried no such predicate and selects everything.
             *
             * @return list<int>|null
             */
            private static function intSelection(string $query): ?array
            {
                if (preg_match('/customer_id IN \(([^)]*)\)/', $query, $m) !== 1) {
                    return null;
                }

                return array_map(intval(...), array_map(trim(...), explode(',', $m[1])));
            }

            /**
             * @return list<string>|null
             */
            private static function stringSelection(string $query): ?array
            {
                if (preg_match('/billing_email IN \(([^)]*)\)/', $query, $m) !== 1) {
                    return null;
                }

                return array_map(
                    static fn (string $value): string => trim(trim($value), "'"),
                    explode(',', $m[1]),
                );
            }
        };

        $GLOBALS['wpdb'] = $db;

        return $db;
    }
}

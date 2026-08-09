<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Migrator\Entity;

use CartShift\Domain\Scope\MigrationScope;
use CartShift\Migrator\CouponMigrator;
use CartShift\Migrator\OrderMigrator;
use CartShift\Migrator\ProductMigrator;
use CartShift\Migrator\SubscriptionMigrator;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Tests\Unit\PluginTestCase;

require_once dirname(__DIR__, 3) . '/stubs/EntityMigratorStubs.php';
require_once dirname(__DIR__, 3) . '/stubs/ProductMigratorStubs.php';
// wc_get_products() lives in HttpCliStubs despite the name — see
// KeysetSourceQueryTest's header comment.
require_once dirname(__DIR__, 3) . '/stubs/HttpCliStubs.php';

/**
 * A scope predicate is an extra conjunct in the keyset query, never a filter
 * applied to what came back. The difference shows up at a batch boundary: a
 * post-fetch filter returns a short page, the orchestrator reads a short page
 * as "carry on", and the run quietly drops every record after the first
 * out-of-scope one in the page.
 */
final class ScopedKeysetTest extends PluginTestCase
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

    public function testTheOrderIdPageCarriesTheDateBoundAndTheCursorInOneWhere(): void
    {
        $db = $this->recordingWpdb([101, 205, 307]);
        $migrator = $this->orderMigrator(['mode' => 'since', 'since' => '2024-03-01']);

        $migrator->fetchBatch(101, 2);

        $this->assertStringContainsString('id > 101', $db->lastQuery);
        $this->assertStringContainsString("date_created_gmt >= '2024-03-01 00:00:00'", $db->lastQuery);
        $this->assertStringContainsString('ORDER BY id ASC', $db->lastQuery);
        $this->assertStringContainsString('LIMIT 2', $db->lastQuery);
    }

    public function testAnUnscopedRunAddsNoClauseAtAll(): void
    {
        $db = $this->recordingWpdb([101]);
        $migrator = $this->orderMigrator(['mode' => 'everything']);

        $migrator->fetchBatch(null, 2);

        $this->assertStringNotContainsString('date_created_gmt', $db->lastQuery);
    }

    public function testAnExplicitScopeSelectingNoOrdersMatchesNothing(): void
    {
        $db = $this->recordingWpdb([]);
        $migrator = $this->orderMigrator(['mode' => 'explicit', 'product_ids' => [12]]);

        $this->assertSame([], $migrator->fetchBatch(null, 2));
        $this->assertStringContainsString('1 = 0', $db->lastQuery);
    }

    public function testAMultiBatchWalkUnderADateScopeSkipsAndRepeatsNothing(): void
    {
        // The design spec's testing requirement, stated directly: "no record
        // skipped or repeated at a batch boundary when a scope predicate is
        // active". Asserting the query string proves the predicate is *in* the
        // WHERE; only a walk proves the cursor and the predicate agree across
        // pages. Rows either side of the bound are interleaved on purpose, so an
        // off-by-one at the boundary shows up as a gap or a duplicate rather
        // than as a suspiciously round number.
        $this->keysetWpdb([
            101 => '2023-11-02 00:00:00',
            205 => '2024-03-01 00:00:00',
            307 => '2023-12-31 23:59:59',
            409 => '2024-03-01 00:00:01',
            512 => '2024-06-30 12:00:00',
            613 => '2022-01-01 00:00:00',
            714 => '2024-12-31 00:00:00',
        ]);
        $this->hydrateOrdersFromPostIn();

        $migrator = $this->orderMigrator(['mode' => 'since', 'since' => '2024-03-01']);

        $cursor = null;
        $seen   = [];
        $guard  = 0;

        while ($guard++ < 20) {
            $batch = $migrator->fetchBatch($cursor, 2);

            if ($batch === []) {
                break;
            }

            foreach ($batch as $order) {
                $seen[] = $order->get_id();
            }

            $cursor = $migrator->cursorFor($batch[array_key_last($batch)]);
        }

        $this->assertSame([205, 409, 512, 714], $seen);
        $this->assertSame($seen, array_values(array_unique($seen)), 'No order may be read twice.');
        $this->assertLessThan(20, $guard, 'The walk must terminate.');
    }

    public function testTheProductIdPageQualifiesTheScopeColumnAgainstTheJoin(): void
    {
        $db = $this->recordingWpdb([12, 44]);
        $migrator = $this->productMigrator(['mode' => 'explicit', 'product_ids' => [12, 44]]);

        $migrator->fetchBatch(null, 5);

        $this->assertStringContainsString('p.ID IN (12, 44)', $db->lastQuery);
        $this->assertStringContainsString('ORDER BY p.ID ASC', $db->lastQuery);
    }

    public function testADateScopeLeavesTheCatalogueAlone(): void
    {
        // Open question 1 in the design spec, answered: "everything from a date"
        // takes the whole catalogue. An order pointing at a product that never
        // arrived is a worse outcome than an unused product in the catalogue.
        $db = $this->recordingWpdb([12]);
        $migrator = $this->productMigrator(['mode' => 'since', 'since' => '2024-03-01']);

        $migrator->fetchBatch(null, 5);

        $this->assertStringNotContainsString('date_created_gmt', $db->lastQuery);
        $this->assertStringNotContainsString('p.ID IN', $db->lastQuery);
    }

    public function testTheCouponIdPageIsUnchangedUnderEveryScope(): void
    {
        // Coupons travel whole under every mode. The predicate is called so the
        // rule lives in code, and it must contribute nothing to the query.
        $db = $this->recordingWpdb([]);
        $this->couponMigrator(['mode' => 'explicit', 'product_ids' => [12]])->fetchBatch(null, 5);
        $scoped = $db->lastQuery;

        $db = $this->recordingWpdb([]);
        $this->couponMigrator(['mode' => 'everything'])->fetchBatch(null, 5);

        $this->assertSame($scoped, $db->lastQuery);
        $this->assertStringNotContainsString('1 = 0', $scoped);
        $this->assertStringNotContainsString('IN (12)', $scoped);
    }

    public function testASubscriptionSeveralPagesInIsReturnedRatherThanEndingTheEntity(): void
    {
        // The regression this test exists to prevent: an in-scope subscriber
        // sitting a long way into the source must be returned rather than
        // silently ending the entity. An empty batch is the orchestrator's
        // *only* end-of-entity signal, so a page that filtered away to nothing
        // used to mark subscriptions complete and lose a paying customer with
        // no counter showing it.
        //
        // It cannot happen any more, and the reason is worth stating: the
        // dataset source applies the scope to the whole selection BEFORE
        // slicing a page out of it, so there is no such thing as a page that
        // filters to nothing while the source still has rows. The cursor is
        // therefore a position in the selected sequence, not in the raw one.
        $GLOBALS['_cartshift_test_wcs_pages'] = [
            new \CartShiftTestSubscription(9001, [], 11),
            new \CartShiftTestSubscription(9002, [], 12),
            new \CartShiftTestSubscription(9003, [], 13),
            new \CartShiftTestSubscription(9004, [], 14),
            new \CartShiftTestSubscription(9005, [], 7),
        ];

        $migrator = $this->subscriptionMigrator(['mode' => 'explicit', 'customer_ids' => [7]]);

        $batch = $migrator->fetchBatch(null, 2);

        $this->assertCount(1, $batch);
        $this->assertSame(9005, $batch[0]->get_id());
        $this->assertSame(1, $migrator->cursorFor($batch[0]));
    }

    public function testTheEntityEndsOnlyWhenTheSelectionItselfRunsOut(): void
    {
        $GLOBALS['_cartshift_test_wcs_pages'] = [
            new \CartShiftTestSubscription(9001, [], 11),
            new \CartShiftTestSubscription(9002, [], 12),
        ];

        $migrator = $this->subscriptionMigrator(['mode' => 'explicit', 'customer_ids' => [7]]);

        // Nothing in scope anywhere in the source. The batch is empty because
        // the selection is empty, which is the one honest reason for it — and
        // the cursor stays at the start rather than being advanced past rows
        // that were never in.
        $this->assertSame([], $migrator->fetchBatch(null, 2));
        $this->assertSame(0, $migrator->cursorFor(null));
    }

    public function testTheOffsetAdvancesByRowsReturned(): void
    {
        $GLOBALS['_cartshift_test_wcs_pages'] = [
            new \CartShiftTestSubscription(9001, [], 7),
            new \CartShiftTestSubscription(9002, [], 12),
        ];

        $migrator = $this->subscriptionMigrator(['mode' => 'explicit', 'customer_ids' => [7]]);

        $batch = $migrator->fetchBatch(null, 2);

        // One row selected, so the next page starts at one. Advancing by two —
        // the size of the raw page — would skip the second selected
        // subscription on a source that had one.
        $this->assertCount(1, $batch);
        $this->assertSame(1, $migrator->cursorFor($batch[0]));
    }

    /**
     * The cursor counts rows consumed, not subscriptions handed back.
     *
     * A page of three where the middle row will not hydrate returns two
     * objects. Advancing by two starts the next page on a row already handed
     * over, and the orchestrator processes that subscription twice.
     */
    public function testTheCursorAdvancesPastARowThatCouldNotBeHydrated(): void
    {
        $GLOBALS['_cartshift_test_wcs_pages'] = [
            new \CartShiftTestSubscription(9001, [], 7),
            new \CartShiftTestSubscription(9002, [], 7),
            new \CartShiftTestSubscription(9003, [], 7),
        ];
        $GLOBALS['_cartshift_test_wcs_unhydratable'] = [9002];

        $migrator = $this->subscriptionMigrator(['mode' => 'everything']);

        $batch = $migrator->fetchBatch(null, 3);

        $this->assertCount(2, $batch);
        $this->assertSame(3, $migrator->cursorFor($batch[0]));
    }

    /**
     * A page in which nothing hydrates must not end the entity.
     *
     * An empty batch is the orchestrator's ONLY end-of-entity signal, so
     * returning one here would finish the migration on page one and leave every
     * later subscriber untouched — with a green run to show for it.
     */
    public function testAWholeUnhydratablePageDoesNotEndTheMigrationEarly(): void
    {
        $GLOBALS['_cartshift_test_wcs_pages'] = [
            new \CartShiftTestSubscription(9001, [], 7),
            new \CartShiftTestSubscription(9002, [], 7),
            new \CartShiftTestSubscription(9003, [], 7),
        ];
        $GLOBALS['_cartshift_test_wcs_unhydratable'] = [9001, 9002];

        $migrator = $this->subscriptionMigrator(['mode' => 'everything']);

        $batch = $migrator->fetchBatch(null, 2);

        $this->assertCount(1, $batch, 'The subscriber behind the dead page must still arrive.');
        $this->assertSame(9003, $batch[0]->get_id());
        $this->assertSame(3, $migrator->cursorFor($batch[0]));
    }

    /**
     * A row the source lists and cannot hand back is counted and said out loud.
     *
     * countTotal() counts index rows, so an unhydratable one keeps the total
     * above the processed count. That difference is real — a selected
     * subscription genuinely did not migrate — so it gets a log line rather
     * than being quietly written out of the arithmetic.
     */
    public function testAnUnhydratableRowIsLoggedRatherThanSilentlyDropped(): void
    {
        $GLOBALS['_cartshift_test_wcs_pages'] = [
            new \CartShiftTestSubscription(9001, [], 7),
            new \CartShiftTestSubscription(9002, [], 7),
        ];
        $GLOBALS['_cartshift_test_wcs_unhydratable'] = [9001];

        $migrator = $this->subscriptionMigrator(['mode' => 'everything']);

        $this->assertSame(2, $migrator->count(), 'It is still one of the selected subscriptions.');

        $GLOBALS['_cartshift_test_queries'] = [];
        $migrator->fetchBatch(null, 2);

        $messages = array_map(
            static fn (array $row): string => (string) ($row[2]['message'] ?? ''),
            array_filter(
                $GLOBALS['_cartshift_test_queries'],
                static fn (array $row): bool => ($row[0] ?? '') === 'insert',
            ),
        );

        $this->assertNotEmpty(array_filter(
            $messages,
            static fn (string $m): bool => str_contains($m, 'WC-#9001') && str_contains($m, 'could not hydrate'),
        ));
    }

    public function testAnUnscopedRunHandsBackThePageUntouched(): void
    {
        $GLOBALS['_cartshift_test_wcs_pages'] = [
            new \CartShiftTestSubscription(9001, [], 7),
            new \CartShiftTestSubscription(9002, [], 12),
        ];

        $migrator = $this->subscriptionMigrator(['mode' => 'everything']);

        $this->assertCount(2, $migrator->fetchBatch(null, 2));
    }

    public function testAProductOnlyScopeSelectsNoSubscriptionsRatherThanAllOfThem(): void
    {
        // The migrator-level pairing of the resolver guard: an explicit scope
        // that picked only products closes over no customers, and every
        // subscription must fall outside it. The failure mode without the guard
        // is the whole shop's subscriptions migrating.
        $GLOBALS['_cartshift_test_wcs_pages'] = [
            new \CartShiftTestSubscription(9001, [], 7),
            new \CartShiftTestSubscription(9002, [], 12),
        ];

        $migrator = $this->subscriptionMigrator(['mode' => 'explicit', 'product_ids' => [12]]);

        $this->assertSame([], $migrator->fetchBatch(null, 2));
    }

    public function testAnUnscopedCountReadsThroughThePublicApiRatherThanTheOrdersTable(): void
    {
        // This used to assert that countTotal() issued a COUNT(*) against
        // {prefix}wc_orders. It did, and that was the defect: on a store whose
        // authoritative order storage is the posts table — which is Lapka's —
        // that table is empty, the count comes back zero, and the migration
        // reports a total of nothing while fetchBatch() happily hydrates
        // hundreds of subscriptions through WCS. Counting now goes through the
        // same dataset source the fetch does, so it issues no SQL at all.
        $GLOBALS['_cartshift_test_wcs_pages'] = [
            new \CartShiftTestSubscription(9001, [], 7),
            new \CartShiftTestSubscription(9002, [], 12),
        ];

        $migrator = $this->subscriptionMigrator(['mode' => 'everything']);

        $GLOBALS['_cartshift_test_queries'] = [];

        $this->assertSame(2, $migrator->count());
        $this->assertNull(
            $this->lastGetVarQuery(),
            'The subscription count must not read an order table on any storage backend.',
        );
    }

    public function testAProductOnlyScopeCountsNoSubscriptionsRatherThanAllOfThem(): void
    {
        // The counting half of the resolver guard. An explicit scope that
        // picked only products closes over no customers, so every subscription
        // is outside it — and the count has to say so, or the progress bar
        // promises work the fetch will never do.
        $GLOBALS['_cartshift_test_wcs_pages'] = [
            new \CartShiftTestSubscription(9001, [], 7),
            new \CartShiftTestSubscription(9002, [], 12),
        ];

        $migrator = $this->subscriptionMigrator(['mode' => 'explicit', 'product_ids' => [12]]);

        $this->assertSame(0, $migrator->count());
        $this->assertSame([], $migrator->fetchBatch(null, 2));
    }

    private function lastGetVarQuery(): ?string
    {
        $queries = array_values(array_filter(
            $GLOBALS['_cartshift_test_queries'] ?? [],
            static fn (array $entry): bool => $entry[0] === 'get_var',
        ));

        if ($queries === []) {
            return null;
        }

        return (string) end($queries)[1];
    }

    public function testAGuestSubscriptionMatchesOnLowerCasedBillingEmail(): void
    {
        $GLOBALS['_cartshift_test_wcs_pages'] = [
            new \CartShiftTestSubscription(9001, [], 0, 'active', 'BOB@example.com'),
            new \CartShiftTestSubscription(9002, [], 0, 'active', 'eve@example.com'),
        ];

        $migrator = $this->subscriptionMigrator([
            'mode'         => 'explicit',
            'guest_emails' => ['bob@example.com'],
        ]);

        $batch = $migrator->fetchBatch(null, 2);

        $this->assertCount(1, $batch);
        $this->assertSame(9001, $batch[0]->get_id());
    }

    public function testAPickedGuestEmailOnARegisteredAccountIsCountedAndMigratedAlike(): void
    {
        // The scope is `customer_id IN (…) OR billing_email IN (…)` with no
        // `customer_id = 0` guard on the email side. Owner-typed guest_emails
        // are never filtered against the registered accounts, so an owner who
        // types the email of a registered buyer selects that buyer's
        // subscription. If the count and the fetch read that scope differently
        // — "email only when customer_id is 0" on one side — the row would be
        // counted and never fetched: total above processed, silently and for
        // ever.
        //
        // They cannot read it differently any more, because there is one
        // predicate and both of them apply it. That is the assertion; which way
        // it reads the scope is the resolver's business, and asserting on the
        // rendered SQL was only ever a proxy for it.
        $GLOBALS['_cartshift_test_wcs_pages'] = [
            new \CartShiftTestSubscription(9001, [], 42, 'active', 'bob@example.com'),
            new \CartShiftTestSubscription(9002, [], 43, 'active', 'eve@example.com'),
        ];

        $migrator = $this->subscriptionMigrator([
            'mode'         => 'explicit',
            'customer_ids' => [7],
            'guest_emails' => ['bob@example.com'],
        ]);

        // The count selects it: the email disjunct is unguarded, so customer 42
        // is inside the counted set on the strength of the address alone.
        $this->assertSame(1, $migrator->count());

        // And the fetch selects exactly the same row.
        $batch = $migrator->fetchBatch(null, 2);

        $this->assertCount(1, $batch);
        $this->assertSame(9001, $batch[0]->get_id());
    }

    public function testADateScopeKeepsOnlySubscriptionsCreatedOnOrAfterTheBound(): void
    {
        $GLOBALS['_cartshift_test_wcs_pages'] = [
            new \CartShiftTestSubscription(9001, [], 11, 'active', '', '2024-02-29 23:59:59'),
            new \CartShiftTestSubscription(9002, [], 12, 'active', '', '2024-03-01 00:00:00'),
        ];

        $migrator = $this->subscriptionMigrator(['mode' => 'since', 'since' => '2024-03-01']);

        $batch = $migrator->fetchBatch(null, 2);

        $this->assertCount(1, $batch);
        $this->assertSame(9002, $batch[0]->get_id());
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function subscriptionMigrator(array $scope): SubscriptionMigrator
    {
        $migrator = new SubscriptionMigrator(
            new IdMapRepository(),
            new MigrationLogRepository(),
            new MigrationState(),
        );
        $migrator->useScope(MigrationScope::fromArray($scope));

        return $migrator;
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function orderMigrator(array $scope): OrderMigrator
    {
        $migrator = new OrderMigrator(
            new IdMapRepository(),
            new MigrationLogRepository(),
            new MigrationState(),
        );
        $migrator->useScope(MigrationScope::fromArray($scope));

        return $migrator;
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function productMigrator(array $scope): ProductMigrator
    {
        $migrator = new ProductMigrator(
            new IdMapRepository(),
            new MigrationLogRepository(),
            new MigrationState(),
        );
        $migrator->useScope(MigrationScope::fromArray($scope));

        return $migrator;
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function couponMigrator(array $scope): CouponMigrator
    {
        $migrator = new CouponMigrator(
            new IdMapRepository(),
            new MigrationLogRepository(),
            new MigrationState(),
        );
        $migrator->useScope(MigrationScope::fromArray($scope));

        return $migrator;
    }

    /**
     * @param list<int> $ids
     */
    private function recordingWpdb(array $ids): object
    {
        $this->originalWpdb ??= $GLOBALS['wpdb'];

        // Single-shot: the page is handed back once, then empty forever after —
        // same idiom KeysetSourceQueryTest::recordingWpdb() uses. This stub does
        // not honour the keyset cursor at all, so without it fetchBatch()'s
        // hydration-retry loop would keep re-reading the same fixed page for
        // ever whenever wc_get_orders() hands back nothing (the default here),
        // rather than ending the walk the moment the ID page runs dry.
        $db = new class ($ids) extends \wpdb {
            public string $lastQuery = '';

            /** @param list<int> $ids */
            public function __construct(private array $ids)
            {
            }

            #[\Override]
            public function get_col(string $query): array
            {
                $this->lastQuery = $query;

                $page = $this->ids;
                $this->ids = [];

                return array_map(strval(...), $page);
            }
        };

        $GLOBALS['wpdb'] = $db;

        // Hydrate the page on the first attempt so fetchBatch() returns
        // straight after the one get_col() call this helper is built to
        // capture — otherwise wc_get_orders()'s empty default sends the
        // hydration-retry loop back for a second, empty page, and db->lastQuery
        // would carry that second query instead of the one under test.
        if ($ids !== []) {
            $GLOBALS['_cartshift_test_wc_get_orders_return'] = array_map(
                static fn (int $id): object => new class ($id) {
                    public function __construct(private readonly int $id)
                    {
                    }

                    public function get_id(): int
                    {
                        return $this->id;
                    }
                },
                $ids,
            );
        }

        return $db;
    }

    /**
     * A wpdb that actually honours the keyset range, the date bound and the
     * LIMIT, so a multi-page walk means something.
     *
     * Crude on purpose. The suite's wpdb stub interpolates prepare()'s values
     * straight into the string — `%d` as a bare integer, `%s` single-quoted — so
     * the assembled query carries real numbers and dates and three regexes are
     * enough to stand in for MySQL. It stands in for a row set, not for a query
     * planner; if it ever needs a fourth regex, the test is asking the wrong
     * question.
     *
     * @param array<int, string> $rows id => date_created_gmt, ordered by id ascending
     */
    private function keysetWpdb(array $rows): object
    {
        $this->originalWpdb ??= $GLOBALS['wpdb'];

        $db = new class ($rows) extends \wpdb {
            public string $lastQuery = '';

            /** @var list<string> */
            public array $queries = [];

            /** @param array<int, string> $rows */
            public function __construct(private readonly array $rows)
            {
            }

            #[\Override]
            public function get_col(string $query): array
            {
                $this->lastQuery = $query;
                $this->queries[] = $query;

                $after = preg_match('/id > (\d+)/', $query, $m) === 1 ? (int) $m[1] : 0;
                $since = preg_match("/date_created_gmt >= '([^']+)'/", $query, $m) === 1 ? $m[1] : null;
                $limit = preg_match('/LIMIT (\d+)/', $query, $m) === 1 ? (int) $m[1] : count($this->rows);

                $ids = [];

                foreach ($this->rows as $id => $createdGmt) {
                    if ($id <= $after) {
                        continue;
                    }

                    if ($since !== null && $createdGmt < $since) {
                        continue;
                    }

                    $ids[] = (string) $id;

                    if (count($ids) === $limit) {
                        break;
                    }
                }

                return $ids;
            }
        };

        $GLOBALS['wpdb'] = $db;

        return $db;
    }

    /**
     * Make wc_get_orders() hydrate exactly the IDs it was handed, so a walk sees
     * the page the ID query produced rather than one fixed array on every call.
     */
    private function hydrateOrdersFromPostIn(): void
    {
        $GLOBALS['_cartshift_test_wc_get_orders_callback'] = static fn (array $args): array => array_map(
            static fn (int $id): object => new class ($id) {
                public function __construct(private readonly int $id)
                {
                }

                public function get_id(): int
                {
                    return $this->id;
                }
            },
            array_map(intval(...), (array) ($args['post__in'] ?? [])),
        );
    }
}

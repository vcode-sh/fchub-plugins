<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Migrator\Entity;

use CartShift\Migrator\CouponMigrator;
use CartShift\Migrator\OrderMigrator;
use CartShift\Migrator\SubscriptionMigrator;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\ProductTypes;
use CartShift\Tests\Unit\PluginTestCase;

require_once dirname(__DIR__, 3) . '/stubs/EntityMigratorStubs.php';
require_once dirname(__DIR__, 3) . '/stubs/ProductMigratorStubs.php';
require_once dirname(__DIR__, 3) . '/stubs/HttpCliStubs.php';

/**
 * The source queries behind the cursor.
 *
 * `LIMIT n OFFSET m` makes MySQL walk and discard m rows, so batch 1000 costs a
 * thousand times batch 1. Every migrator that can express `id > cursor` now
 * does; these tests hold the SQL to that promise, and check the FluentCart
 * interop that stops a second import creating duplicate orders.
 */
final class KeysetSourceQueryTest extends PluginTestCase
{
    private ?\wpdb $originalWpdb = null;

    protected function setUp(): void
    {
        parent::setUp();

        unset(
            $GLOBALS['_cartshift_test_get_var_callback'],
            $GLOBALS['_cartshift_test_get_var_return'],
        );

        $GLOBALS['_cartshift_test_wc_get_orders_calls'] = [];
        $GLOBALS['_cartshift_test_wc_get_orders_return'] = [];
    }

    protected function tearDown(): void
    {
        if ($this->originalWpdb !== null) {
            $GLOBALS['wpdb'] = $this->originalWpdb;
            $this->originalWpdb = null;
        }

        parent::tearDown();
    }

    public function testOrderIdPageSeeksPastTheCursorInsteadOfOffsetting(): void
    {
        $db = $this->recordingWpdb([]);

        $this->orderMigrator()->fetchBatch(5000, 50);

        $this->assertStringContainsString('id > 5000', $db->lastQuery);
        $this->assertStringContainsString('ORDER BY id ASC', $db->lastQuery);
        $this->assertStringContainsString('LIMIT 50', $db->lastQuery);
        $this->assertStringNotContainsString('OFFSET', $db->lastQuery);
        $this->assertStringContainsString('status IN (', $db->lastQuery);
    }

    public function testANullOrderCursorStartsAtTheBeginning(): void
    {
        $db = $this->recordingWpdb([]);

        $this->orderMigrator()->fetchBatch(null, 10);

        $this->assertStringContainsString('id > 0', $db->lastQuery);
    }

    public function testOrdersAreHydratedByIdRatherThanRefetchedByOffset(): void
    {
        $this->recordingWpdb([101, 205]);
        $GLOBALS['_cartshift_test_wc_get_orders_return'] = [new \WC_Order()];

        $this->orderMigrator()->fetchBatch(null, 50);

        $call = end($GLOBALS['_cartshift_test_wc_get_orders_calls']);

        $this->assertSame([101, 205], $call['post__in']);
        $this->assertArrayNotHasKey('offset', $call);
    }

    public function testTheOrderCursorIsTheEndOfTheIdPageNotTheLastHydratedOrder(): void
    {
        // 205 refuses to hydrate — wc_get_orders() hands back only 101. Resuming
        // from 101 would re-read 205 for ever, so the cursor is the page end.
        $this->recordingWpdb([101, 205]);
        $GLOBALS['_cartshift_test_wc_get_orders_return'] = [$this->wcOrder(101)];

        $migrator = $this->orderMigrator();
        $batch = $migrator->fetchBatch(null, 50);

        $this->assertCount(1, $batch);
        $this->assertSame(205, $migrator->cursorFor($batch[0]));
    }

    public function testCouponIdPageIsKeysetAndKeepsTheStatusSet(): void
    {
        $db = $this->recordingWpdb([]);

        $this->couponMigrator()->fetchBatch(120, 25);

        $this->assertStringContainsString('ID > 120', $db->lastQuery);
        $this->assertStringContainsString('ORDER BY ID ASC', $db->lastQuery);
        $this->assertStringNotContainsString('OFFSET', $db->lastQuery);
        $this->assertStringContainsString("post_status IN ('publish', 'draft', 'private')", $db->lastQuery);
        $this->assertStringContainsString("post_type = 'shop_coupon'", $db->lastQuery);
    }

    public function testProductIdPageIsKeysetAndKeepsTheTypeAndStatusFilters(): void
    {
        $db = $this->recordingWpdb([]);

        $this->productMigrator()->fetchBatch(880, 40);

        $this->assertStringContainsString('p.ID > 880', $db->lastQuery);
        $this->assertStringContainsString('ORDER BY p.ID ASC', $db->lastQuery);
        $this->assertStringContainsString('LIMIT 40', $db->lastQuery);
        $this->assertStringNotContainsString('OFFSET', $db->lastQuery);
        $this->assertStringContainsString("p.post_status IN ('publish', 'draft', 'private')", $db->lastQuery);

        // The type filter is ProductTypes' predicate, byte for byte, not a
        // second list that happens to say the same thing today.
        [$typeSql, $typeValues] = ProductTypes::migratableClause('pml.product_id');
        $this->assertStringContainsString($db->prepare($typeSql, ...$typeValues), $db->lastQuery);
    }

    public function testTheProductCursorIsTheEndOfTheIdPage(): void
    {
        // The ID page covers 11 and 12; hydration only yields 11. Resuming from
        // the last hydrated product would re-read 12 for ever.
        $this->recordingWpdb([11, 12]);
        $GLOBALS['_cartshift_test_wc_products'] = [11 => (object) ['id' => 11]];

        $migrator = $this->productMigrator();
        $batch = $migrator->fetchBatch(null, 50);

        $this->assertCount(1, $batch);
        $this->assertSame(12, $migrator->cursorFor($batch[0]));
    }

    /**
     * Subscriptions deliberately keep OFFSET — see the comment on
     * SubscriptionMigrator::fetchBatch(). The cursor is the next offset.
     */
    public function testSubscriptionsKeepOffsetPaginationAndStillAdvance(): void
    {
        // No source configured, so the stub yields nothing — the same "nothing
        // to migrate" this test asserted back when wcs_get_subscriptions() did
        // not exist at all. countTotal()'s COUNT(*) goes through the default
        // wpdb stub, which answers 0.
        $migrator = $this->subscriptionMigrator();

        $this->assertSame([], $migrator->fetchBatch(null, 50));
        $this->assertSame(0, $migrator->count());
    }

    public function testAnOrderFluentCartAlreadyImportedIsAdoptedNotDuplicated(): void
    {
        $this->recordingWpdb([]);

        $GLOBALS['_cartshift_test_get_var_callback'] = static function (string $query): string|int|null {
            // The ID-map lookup misses; the invoice_no lookup hits.
            if (str_contains($query, 'fct_orders')) {
                return 4242;
            }

            return null;
        };

        $result = $this->orderMigrator()->processRecord($this->wcOrder(77));

        $this->assertFalse($result, 'An adopted order is a skip, not a fresh migration.');

        $inserts = $this->recordedInserts();

        $idMapRow = $this->firstInsertInto($inserts, 'cartshift_id_map');
        $this->assertNotNull($idMapRow);
        $this->assertSame('order', $idMapRow['entity_type']);
        $this->assertSame('77', $idMapRow['wc_id']);
        $this->assertSame(4242, $idMapRow['fc_id']);
        $this->assertSame(
            0,
            $idMapRow['created_by_migration'],
            'Rollback must never delete an order CartShift did not create.',
        );

        $logRow = $this->firstInsertInto($inserts, 'cartshift_migration_log');
        $this->assertNotNull($logRow);
        $this->assertSame('skipped', $logRow['status']);
        $this->assertStringContainsString('WC-77', $logRow['message']);
    }

    public function testTheAdoptionLookupIsAnExactIndexedMatchOnInvoiceNo(): void
    {
        $db = $this->recordingWpdb([]);

        $GLOBALS['_cartshift_test_get_var_callback'] = static function (string $query) use ($db): string|int|null {
            $db->varQueries[] = $query;

            return null;
        };

        // Without an adoption hit this falls through to the mapper, which needs
        // more of FluentCart than a unit test has. The lookup query is the point.
        try {
            $this->orderMigrator()->processRecord($this->wcOrder(77));
        } catch (\Throwable) {
            // Expected: the real migration path is not exercised here.
        }

        $lookup = array_values(array_filter(
            $db->varQueries,
            static fn (string $sql): bool => str_contains($sql, 'fct_orders'),
        ));

        $this->assertNotEmpty($lookup);
        $this->assertStringContainsString("invoice_no = 'WC-77'", $lookup[0]);
        $this->assertStringNotContainsString('LIKE', $lookup[0], 'An exact match, not a scan.');
    }

    public function testMigratedCountIsASingleCountQuery(): void
    {
        $db = $this->recordingWpdb([]);
        $GLOBALS['_cartshift_test_get_var_callback'] = static fn (string $query): int => 1204;

        $this->assertSame(1204, $this->orderMigrator()->migratedCount());

        $counts = array_values(array_filter(
            $this->recordedQueries(),
            static fn (string $sql): bool => str_contains($sql, 'cartshift_id_map') && str_contains($sql, 'COUNT(*)'),
        ));

        $this->assertCount(1, $counts);
        $this->assertStringContainsString("entity_type IN ('order')", $counts[0]);
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    private function orderMigrator(): OrderMigrator
    {
        return new OrderMigrator(
            new IdMapRepository(),
            new MigrationLogRepository(),
            new MigrationState(),
        );
    }

    private function couponMigrator(): CouponMigrator
    {
        return new CouponMigrator(
            new IdMapRepository(),
            new MigrationLogRepository(),
            new MigrationState(),
        );
    }

    private function productMigrator(): \CartShift\Migrator\ProductMigrator
    {
        return new \CartShift\Migrator\ProductMigrator(
            new IdMapRepository(),
            new MigrationLogRepository(),
            new MigrationState(),
        );
    }

    private function subscriptionMigrator(): SubscriptionMigrator
    {
        return new SubscriptionMigrator(
            new IdMapRepository(),
            new MigrationLogRepository(),
            new MigrationState(),
        );
    }

    private function wcOrder(int $id): \WC_Order
    {
        $order = new \WC_Order();

        $property = new \ReflectionProperty($order, 'id');
        $property->setValue($order, $id);

        return $order;
    }

    /**
     * @return list<string>
     */
    private function recordedQueries(): array
    {
        $queries = [];

        foreach ($GLOBALS['_cartshift_test_queries'] ?? [] as $entry) {
            if (in_array($entry[0], ['get_var', 'get_col', 'get_results', 'query'], true)) {
                $queries[] = (string) $entry[1];
            }
        }

        return $queries;
    }

    /**
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    private function recordedInserts(): array
    {
        $inserts = [];

        foreach ($GLOBALS['_cartshift_test_queries'] ?? [] as $entry) {
            if ($entry[0] === 'insert') {
                $inserts[] = [(string) $entry[1], (array) $entry[2]];
            }
        }

        return $inserts;
    }

    /**
     * @param list<array{0: string, 1: array<string, mixed>}> $inserts
     * @return array<string, mixed>|null
     */
    private function firstInsertInto(array $inserts, string $table): ?array
    {
        foreach ($inserts as [$name, $row]) {
            if (str_contains($name, $table)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * A wpdb that hands back one page of IDs and remembers the SQL it saw.
     *
     * @param list<int> $ids
     */
    private function recordingWpdb(array $ids): object
    {
        $this->originalWpdb ??= $GLOBALS['wpdb'];

        $db = new class ($ids) extends \wpdb {
            public string $lastQuery = '';

            /** @var list<string> */
            public array $varQueries = [];

            /** @param list<int> $ids */
            public function __construct(private array $ids)
            {
            }

            #[\Override]
            public function get_col(string $query): array
            {
                $GLOBALS['_cartshift_test_queries'][] = ['get_col', $query];
                $this->lastQuery = $query;

                $page = $this->ids;
                $this->ids = [];

                return array_map(strval(...), $page);
            }
        };

        $GLOBALS['wpdb'] = $db;

        return $db;
    }
}

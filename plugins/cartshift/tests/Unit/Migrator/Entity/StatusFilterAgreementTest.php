<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Migrator\Entity;

use CartShift\Migrator\CustomerMigrator;
use CartShift\Migrator\OrderMigrator;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\WooStorage;
use CartShift\Tests\Unit\PluginTestCase;

require_once dirname(__DIR__, 3) . '/stubs/EntityMigratorStubs.php';

/**
 * The COUNT queries and the batch fetches must look at exactly the same rows.
 *
 * When they disagree, two things go wrong: the progress bar never reaches 100%,
 * and — the expensive one — abandoned checkout-draft emails get imported into
 * FluentCart as real customers.
 */
final class StatusFilterAgreementTest extends PluginTestCase
{
    private IdMapRepository $idMap;
    private MigrationLogRepository $log;
    private MigrationState $state;
    private ?\wpdb $originalWpdb = null;

    protected function setUp(): void
    {
        parent::setUp();

        unset(
            $GLOBALS['_cartshift_test_wc_order_statuses'],
            $GLOBALS['_cartshift_test_post_stati'],
            $GLOBALS['_cartshift_test_get_var_callback'],
            $GLOBALS['_cartshift_test_get_var_return'],
        );

        $GLOBALS['_cartshift_test_wc_get_orders_calls'] = [];
        $GLOBALS['_cartshift_test_wc_get_orders_return'] = [];

        $this->idMap = new IdMapRepository();
        $this->log   = new MigrationLogRepository();
        $this->state = new MigrationState();
    }

    public function testOrderCountAgreesWithOrderFetch(): void
    {
        $migrator = $this->orderMigrator();
        $this->stubOrderIdPage([101, 102]);

        $migrator->count();
        $migrator->fetchBatch(null, 50);

        $counted = $this->statusesInLastQuery('COUNT(*)');
        $fetched = $this->statusesPassedToWcGetOrders();

        $this->assertNotEmpty($counted, 'The COUNT query must carry a status filter');
        $this->assertSame(
            $fetched,
            $counted,
            'countTotal() must count exactly the statuses wc_get_orders() returns',
        );
    }

    public function testOrderCountExcludesCheckoutDraftsAndTrash(): void
    {
        $migrator = $this->orderMigrator();
        $migrator->count();

        $sql = $this->lastQueryContaining('COUNT(*)');

        $this->assertStringNotContainsString('checkout-draft', $sql);
        $this->assertStringNotContainsString("'trash'", $sql);
        $this->assertStringContainsString("type = 'shop_order'", $sql);
    }

    public function testOrderCountTracksACustomStatusSet(): void
    {
        $GLOBALS['_cartshift_test_wc_order_statuses'] = [
            'wc-pending'   => 'Pending payment',
            'wc-completed' => 'Completed',
            'wc-bespoke'   => 'Bespoke',
        ];

        $migrator = $this->orderMigrator();
        $this->stubOrderIdPage([101, 102]);
        $migrator->count();
        $migrator->fetchBatch(null, 50);

        $this->assertSame(
            $this->statusesPassedToWcGetOrders(),
            $this->statusesInLastQuery('COUNT(*)'),
            'A site that registers extra order statuses must still count what it fetches',
        );
    }

    public function testCustomerCountQueriesAreStatusScoped(): void
    {
        $migrator = $this->customerMigrator();
        $migrator->count();

        $registered = $this->lastQueryContaining('COUNT(DISTINCT customer_id)');
        $guest      = $this->lastQueryContaining('COUNT(DISTINCT billing_email)');

        $expected = WooStorage::migratableOrderStatuses();

        $this->assertSame($expected, $this->extractStatuses($registered));
        $this->assertSame($expected, $this->extractStatuses($guest));
    }

    public function testGuestCustomerCountExcludesAbandonedCheckoutDrafts(): void
    {
        $migrator = $this->customerMigrator();
        $migrator->count();

        $guest = $this->lastQueryContaining('COUNT(DISTINCT billing_email)');

        $this->assertStringNotContainsString(
            'checkout-draft',
            $guest,
            'Abandoned cart emails must never become FluentCart customers',
        );
    }

    public function testCustomerFetchQueriesUseTheSameStatusScopeAsTheCounts(): void
    {
        $migrator = $this->customerMigrator();

        $this->invoke($migrator, 'fetchRegisteredBatch', 0, 50);
        $this->invoke($migrator, 'fetchGuestBatch', null, 50);

        $registeredFetch = $this->lastQueryContaining('SELECT DISTINCT customer_id');
        $guestFetch      = $this->lastQueryContaining('SELECT DISTINCT billing_email');

        $expected = WooStorage::migratableOrderStatuses();

        $this->assertSame($expected, $this->extractStatuses($registeredFetch));
        $this->assertSame($expected, $this->extractStatuses($guestFetch));
    }

    public function testEveryWcOrdersQueryIsStatusFiltered(): void
    {
        $orders = $this->orderMigrator();
        $orders->count();

        $customers = $this->customerMigrator();
        $customers->count();
        $this->invoke($customers, 'fetchRegisteredBatch', 0, 50);
        $this->invoke($customers, 'fetchGuestBatch', null, 50);

        $wcOrdersQueries = array_filter(
            $this->recordedQueries(),
            static fn (string $sql): bool => str_contains($sql, 'wp_wc_orders'),
        );

        $this->assertNotEmpty($wcOrdersQueries);

        foreach ($wcOrdersQueries as $sql) {
            $this->assertStringContainsString(
                'status IN (',
                $sql,
                'Unfiltered wc_orders query: ' . $sql,
            );
        }
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    protected function tearDown(): void
    {
        if ($this->originalWpdb !== null) {
            $GLOBALS['wpdb'] = $this->originalWpdb;
            $this->originalWpdb = null;
        }

        parent::tearDown();
    }

    /**
     * Hand the order migrator one page of IDs, then nothing.
     *
     * Keyset pagination fetches the ID page itself and only then hydrates via
     * wc_get_orders(); the shared wpdb stub always returns an empty get_col, so
     * without this the hydration call never happens.
     *
     * @param list<int> $ids
     */
    private function stubOrderIdPage(array $ids): void
    {
        $this->originalWpdb ??= $GLOBALS['wpdb'];

        $GLOBALS['wpdb'] = new class ($ids) extends \wpdb {
            /** @param list<int> $ids */
            public function __construct(private array $ids)
            {
            }

            #[\Override]
            public function get_col(string $query): array
            {
                $GLOBALS['_cartshift_test_queries'][] = ['get_col', $query];

                $page = $this->ids;
                $this->ids = [];

                return array_map(strval(...), $page);
            }
        };
    }

    private function orderMigrator(): OrderMigrator
    {
        return new OrderMigrator($this->idMap, $this->log, $this->state);
    }

    private function customerMigrator(): CustomerMigrator
    {
        return new CustomerMigrator($this->idMap, $this->log, $this->state);
    }

    private function invoke(object $target, string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod($target, $method);

        return $reflection->invoke($target, ...$args);
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

    private function lastQueryContaining(string $needle): string
    {
        $matches = array_values(array_filter(
            $this->recordedQueries(),
            static fn (string $sql): bool => str_contains($sql, $needle),
        ));

        $this->assertNotEmpty($matches, sprintf('No recorded query contains "%s"', $needle));

        return end($matches);
    }

    /**
     * @return list<string>
     */
    private function statusesInLastQuery(string $needle): array
    {
        return $this->extractStatuses($this->lastQueryContaining($needle));
    }

    /**
     * Pull the values out of a `status IN ('a', 'b')` fragment.
     *
     * @return list<string>
     */
    private function extractStatuses(string $sql): array
    {
        if (preg_match("/status IN \(([^)]*)\)/", $sql, $matches) !== 1) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $value): string => trim(trim($value), "'"),
            explode(',', $matches[1]),
        )));
    }

    /**
     * The statuses wc_get_orders() actually resolved 'any' to.
     *
     * @return list<string>
     */
    private function statusesPassedToWcGetOrders(): array
    {
        $calls = $GLOBALS['_cartshift_test_wc_get_orders_calls'] ?? [];

        $this->assertNotEmpty($calls, 'wc_get_orders() was never called');

        return array_values((array) end($calls)['status']);
    }
}

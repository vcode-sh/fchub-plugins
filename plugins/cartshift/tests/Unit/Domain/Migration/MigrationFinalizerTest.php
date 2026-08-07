<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Migration;

use CartShift\Domain\Migration\MigrationFinalizer;
use CartShift\Storage\IdMapRepository;
use CartShift\Support\Constants;
use CartShift\Tests\Unit\PluginTestCase;

require_once dirname(__DIR__, 3) . '/stubs/DomainStorageStubs.php';

final class MigrationFinalizerTest extends PluginTestCase
{
    private IdMapRepository $idMap;
    private MigrationFinalizer $finalizer;

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['_cartshift_test_cache_flush_runtime_calls'] = 0;

        $this->idMap = new IdMapRepository();
        $this->finalizer = new MigrationFinalizer($this->idMap);
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['_cartshift_test_get_results_callback'],
            $GLOBALS['_cartshift_test_get_results_return'],
            $GLOBALS['_cartshift_test_get_var_callback'],
            $GLOBALS['_cartshift_test_get_var_return'],
        );
        parent::tearDown();
    }

    /**
     * purchase_value must be a JSON string keyed by currency, e.g. {"USD": 12345}.
     * This is how FluentCart's fct_customers table stores per-currency totals.
     */
    public function testPurchaseValueIsJsonByCurrency(): void
    {
        $migrationId = 'test-finalizer-001';

        // Simulate one migrated customer with fc_id = 10.
        $GLOBALS['_cartshift_test_get_results_callback'] = function (string $query) use ($migrationId): array {
            // getAllByEntityType for 'customer'
            if (str_contains($query, Constants::ENTITY_CUSTOMER) && str_contains($query, $migrationId)) {
                return [(object) ['wc_id' => '1', 'fc_id' => 10]];
            }

            // getAllByEntityType for 'guest_customer'
            if (str_contains($query, Constants::ENTITY_GUEST_CUSTOMER) && str_contains($query, $migrationId)) {
                return [];
            }

            // Order-level stats: 2 orders, LTV = 200
            if (str_contains($query, 'COUNT(*)') && str_contains($query, 'customer_id IN')) {
                return [(object) [
                    'customer_id' => 10,
                    'order_count' => 2,
                    'ltv'         => 15000, // 150.00 in cents
                    'last_order'  => '2024-06-15 10:00:00',
                    'first_order' => '2024-01-10 09:00:00',
                ]];
            }

            // Per-currency breakdown
            if (str_contains($query, 'currency') && str_contains($query, 'GROUP BY customer_id, currency')) {
                return [
                    (object) ['customer_id' => 10, 'currency' => 'USD', 'currency_total' => 10000],
                    (object) ['customer_id' => 10, 'currency' => 'EUR', 'currency_total' => 5000],
                ];
            }

            return [];
        };

        $this->finalizer->recalculateCustomerStats($migrationId);

        // Find the update query for fct_customers.
        $queries = $GLOBALS['_cartshift_test_queries'] ?? [];
        $updateQueries = array_filter($queries, fn(array $q) => $q[0] === 'update');
        $this->assertNotEmpty($updateQueries, 'Expected at least one update query');

        $updateQuery = array_values($updateQueries)[0];
        $table = $updateQuery[1];
        $data  = $updateQuery[2];
        $where = $updateQuery[3];

        // Correct table
        $this->assertStringContainsString('fct_customers', $table);

        // Where clause targets the right customer
        $this->assertSame(['id' => 10], $where);

        // purchase_value is a JSON string, not a raw number
        $this->assertArrayHasKey('purchase_value', $data);
        $decoded = json_decode($data['purchase_value'], true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('USD', $decoded);
        $this->assertArrayHasKey('EUR', $decoded);
        $this->assertSame(10000, $decoded['USD']);
        $this->assertSame(5000, $decoded['EUR']);
    }

    /**
     * The update call must use FC's column names:
     * purchase_count, purchase_value, aov, ltv, first_purchase_date, last_purchase_date
     *
     * NOT WooCommerce-style names like total_orders, total_spent, last_order_at.
     */
    public function testCustomerColumnsMatchFcSchema(): void
    {
        $migrationId = 'test-finalizer-002';

        $GLOBALS['_cartshift_test_get_results_callback'] = function (string $query) use ($migrationId): array {
            if (str_contains($query, Constants::ENTITY_CUSTOMER) && str_contains($query, $migrationId)) {
                return [(object) ['wc_id' => '5', 'fc_id' => 20]];
            }

            if (str_contains($query, Constants::ENTITY_GUEST_CUSTOMER) && str_contains($query, $migrationId)) {
                return [];
            }

            if (str_contains($query, 'COUNT(*)') && str_contains($query, 'customer_id IN')) {
                return [(object) [
                    'customer_id' => 20,
                    'order_count' => 3,
                    'ltv'         => 30000,
                    'last_order'  => '2024-12-01 12:00:00',
                    'first_order' => '2024-01-01 08:00:00',
                ]];
            }

            if (str_contains($query, 'currency') && str_contains($query, 'GROUP BY customer_id, currency')) {
                return [
                    (object) ['customer_id' => 20, 'currency' => 'PLN', 'currency_total' => 30000],
                ];
            }

            return [];
        };

        $this->finalizer->recalculateCustomerStats($migrationId);

        $queries = $GLOBALS['_cartshift_test_queries'] ?? [];
        $updateQueries = array_filter($queries, fn(array $q) => $q[0] === 'update');
        $this->assertNotEmpty($updateQueries);

        $data = array_values($updateQueries)[0][2];

        // FC schema column names — MUST be present
        $this->assertArrayHasKey('purchase_count', $data);
        $this->assertArrayHasKey('purchase_value', $data);
        $this->assertArrayHasKey('aov', $data);
        $this->assertArrayHasKey('ltv', $data);
        $this->assertArrayHasKey('first_purchase_date', $data);
        $this->assertArrayHasKey('last_purchase_date', $data);

        // WooCommerce-style column names — MUST NOT be present
        $this->assertArrayNotHasKey('total_orders', $data);
        $this->assertArrayNotHasKey('total_spent', $data);
        $this->assertArrayNotHasKey('last_order_at', $data);
        $this->assertArrayNotHasKey('first_order_at', $data);
        $this->assertArrayNotHasKey('order_count', $data);

        // Verify correct values
        $this->assertSame(3, $data['purchase_count']);
        $this->assertSame(30000, $data['ltv']);
        $this->assertSame(10000, $data['aov']); // 30000 / 3 = 10000
        $this->assertSame('2024-01-01 08:00:00', $data['first_purchase_date']);
        $this->assertSame('2024-12-01 12:00:00', $data['last_purchase_date']);
    }

    /**
     * When no customers were migrated, recalculate should return 0 and not error.
     */
    public function testRecalculateReturnsZeroWhenNoCustomers(): void
    {
        $migrationId = 'test-finalizer-empty';

        $GLOBALS['_cartshift_test_get_results_callback'] = fn(): array => [];

        $updated = $this->finalizer->recalculateCustomerStats($migrationId);

        $this->assertSame(0, $updated);
    }

    /**
     * Guest customers must also be included in the recalculation.
     */
    public function testRecalculateIncludesGuestCustomers(): void
    {
        $migrationId = 'test-finalizer-guests';

        $GLOBALS['_cartshift_test_get_results_callback'] = function (string $query) use ($migrationId): array {
            // No registered customers
            if (str_contains($query, Constants::ENTITY_CUSTOMER) && str_contains($query, $migrationId)
                && !str_contains($query, 'guest_customer')) {
                return [];
            }

            // One guest customer
            if (str_contains($query, Constants::ENTITY_GUEST_CUSTOMER) && str_contains($query, $migrationId)) {
                return [(object) ['wc_id' => 'guest@test.com', 'fc_id' => 50]];
            }

            if (str_contains($query, 'COUNT(*)') && str_contains($query, 'customer_id IN')) {
                return [(object) [
                    'customer_id' => 50,
                    'order_count' => 1,
                    'ltv'         => 5000,
                    'last_order'  => '2024-03-01 12:00:00',
                    'first_order' => '2024-03-01 12:00:00',
                ]];
            }

            if (str_contains($query, 'currency') && str_contains($query, 'GROUP BY customer_id, currency')) {
                return [
                    (object) ['customer_id' => 50, 'currency' => 'GBP', 'currency_total' => 5000],
                ];
            }

            return [];
        };

        $updated = $this->finalizer->recalculateCustomerStats($migrationId);

        $this->assertSame(1, $updated);

        // Verify the update happened for the guest customer.
        $queries = $GLOBALS['_cartshift_test_queries'] ?? [];
        $updateQueries = array_filter($queries, fn(array $q) => $q[0] === 'update');
        $this->assertNotEmpty($updateQueries);

        $data = array_values($updateQueries)[0][2];
        $this->assertSame(1, $data['purchase_count']);
        $this->assertSame(5000, $data['ltv']);

        $decoded = json_decode($data['purchase_value'], true);
        $this->assertArrayHasKey('GBP', $decoded);
        $this->assertSame(5000, $decoded['GBP']);
    }

    /**
     * AOV calculation: ltv / purchase_count, rounded to integer.
     * Edge case: a customer with zero orders should have aov = 0.
     */
    public function testAovIsZeroWhenNoOrders(): void
    {
        $migrationId = 'test-finalizer-zero-aov';

        $GLOBALS['_cartshift_test_get_results_callback'] = function (string $query) use ($migrationId): array {
            if (str_contains($query, Constants::ENTITY_CUSTOMER) && str_contains($query, $migrationId)) {
                return [(object) ['wc_id' => '99', 'fc_id' => 77]];
            }

            if (str_contains($query, Constants::ENTITY_GUEST_CUSTOMER) && str_contains($query, $migrationId)) {
                return [];
            }

            // No paid orders found for this customer
            if (str_contains($query, 'COUNT(*)') && str_contains($query, 'customer_id IN')) {
                return [];
            }

            if (str_contains($query, 'currency') && str_contains($query, 'GROUP BY customer_id, currency')) {
                return [];
            }

            return [];
        };

        $this->finalizer->recalculateCustomerStats($migrationId);

        $queries = $GLOBALS['_cartshift_test_queries'] ?? [];
        $updateQueries = array_filter($queries, fn(array $q) => $q[0] === 'update');
        $this->assertNotEmpty($updateQueries);

        $data = array_values($updateQueries)[0][2];
        $this->assertSame(0, $data['aov']);
        $this->assertSame(0, $data['purchase_count']);
        $this->assertSame(0, $data['ltv']);
    }

    /**
     * finalize() should fire the cartshift/migration/finalized action.
     */
    public function testFinalizeFiresAction(): void
    {
        $migrationId = 'test-finalizer-action';
        $firedWith = null;

        $GLOBALS['_cartshift_test_get_results_callback'] = fn(): array => [];

        add_action('cartshift/migration/finalized', function (string $id) use (&$firedWith): void {
            $firedWith = $id;
        });

        $this->finalizer->finalize($migrationId);

        $this->assertSame($migrationId, $firedWith);
    }

    /**
     * finalize() return value has the expected shape.
     */
    public function testFinalizeReturnShape(): void
    {
        $migrationId = 'test-finalizer-shape';

        $GLOBALS['_cartshift_test_get_results_callback'] = fn(): array => [];

        $result = $this->finalizer->finalize($migrationId);

        $this->assertArrayHasKey('customers_updated', $result);
        $this->assertArrayHasKey('caches_cleared', $result);
        $this->assertIsInt($result['customers_updated']);
        $this->assertTrue($result['caches_cleared']);
    }

    // ──────────────────────────────────────────────
    // LTV formula
    // ──────────────────────────────────────────────

    /**
     * LTV must NOT subtract total_refund.
     *
     * FluentCart stores total_paid already net of refunds — Order::recountTotalPaid()
     * writes max(0, paid - refunded) and every refund path decrements it in place —
     * and CartShift's OrderMapper::getTotalPaid() writes the same invariant
     * (total - refunded). Subtracting total_refund again deducts every refund twice,
     * understating LTV and AOV for every partially refunded customer.
     */
    public function testLtvDoesNotSubtractTotalRefundTwice(): void
    {
        $this->captureStatsQueries('test-ltv-formula');

        $statsQuery = $this->findQueryContaining('AS ltv');

        $this->assertStringNotContainsString(
            'total_paid - total_refund',
            $statsQuery,
            'LTV must not subtract total_refund — total_paid is already net of refunds.',
        );
        $this->assertStringNotContainsString(
            'total_refund',
            $statsQuery,
            'total_refund has no place in the LTV sum at all.',
        );
        $this->assertStringContainsString(
            'SUM(GREATEST(total_paid, 0))',
            $statsQuery,
            'LTV is the sum of net total_paid, floored per order at zero.',
        );
    }

    /**
     * purchase_value is gross turnover per currency; ltv is net. Different figures,
     * deliberately — FluentCart's own WooCommerce importer builds purchase_value
     * from gross order totals (CustomerMigrationService.php:254, 303).
     */
    public function testPurchaseValueUsesGrossTotalAmount(): void
    {
        $this->captureStatsQueries('test-ltv-gross');

        $currencyQuery = $this->findQueryContaining('GROUP BY customer_id, currency');

        $this->assertStringContainsString('SUM(total_amount)', $currencyQuery);
        $this->assertStringNotContainsString('total_paid', $currencyQuery);
    }

    /**
     * Both stat queries must filter on FluentCart's successful payment statuses —
     * Status::getOrderPaymentSuccessStatuses(): paid, partially_refunded,
     * partially_paid. partially_paid was previously missing.
     */
    public function testStatsFilterOnFluentCartSuccessStatuses(): void
    {
        $this->captureStatsQueries('test-ltv-statuses');

        foreach (['AS ltv', 'GROUP BY customer_id, currency'] as $marker) {
            $query = $this->findQueryContaining($marker);

            foreach (['paid', 'partially_refunded', 'partially_paid'] as $status) {
                $this->assertStringContainsString(
                    "'{$status}'",
                    $query,
                    "Query for '{$marker}' must include payment status '{$status}'.",
                );
            }
        }
    }

    /**
     * End to end on the arithmetic: a customer with 2 orders, one of which was
     * partially refunded.
     *
     * Order A: total_amount 10000, paid in full, never refunded  -> total_paid 10000
     * Order B: total_amount 10000, 4000 refunded                 -> total_paid  6000,
     *                                                               total_refund 4000
     *
     * ltv            = 10000 + 6000 = 16000  (NOT 16000 - 4000 = 12000)
     * aov            = 16000 / 2    =  8000  (NOT 6000)
     * purchase_value = 10000 + 10000 = 20000 gross
     */
    public function testPartiallyRefundedCustomerGetsCorrectLtvAndAov(): void
    {
        $migrationId = 'test-ltv-partial-refund';

        $GLOBALS['_cartshift_test_get_results_callback'] =
            function (string $query) use ($migrationId): array {
                if (str_contains($query, Constants::ENTITY_CUSTOMER) && str_contains($query, $migrationId)) {
                    return [(object) ['wc_id' => '7', 'fc_id' => 70]];
                }

                if (str_contains($query, Constants::ENTITY_GUEST_CUSTOMER)) {
                    return [];
                }

                // SUM(GREATEST(total_paid, 0)) over the two orders.
                if (str_contains($query, 'AS ltv')) {
                    return [(object) [
                        'customer_id' => 70,
                        'order_count' => 2,
                        'ltv'         => 16000,
                        'last_order'  => '2024-08-01 10:00:00',
                        'first_order' => '2024-02-01 10:00:00',
                    ]];
                }

                if (str_contains($query, 'GROUP BY customer_id, currency')) {
                    return [
                        (object) ['customer_id' => 70, 'currency' => 'USD', 'currency_total' => 20000],
                    ];
                }

                return [];
            };

        $this->finalizer->recalculateCustomerStats($migrationId);

        $data = $this->firstCustomerUpdate();

        $this->assertSame(16000, $data['ltv'], 'Refund must be deducted once, not twice.');
        $this->assertSame(8000, $data['aov'], 'AOV is ltv / purchase_count.');
        $this->assertSame(2, $data['purchase_count']);

        $decoded = json_decode($data['purchase_value'], true);
        $this->assertSame(20000, $decoded['USD'], 'purchase_value is gross turnover.');
    }

    /**
     * clearCaches() does a full flush by default, but the filter can decline it —
     * on a shared Redis or Memcached a site-wide flush is somebody else's problem too.
     */
    public function testFullCacheFlushIsFilterable(): void
    {
        $seen = null;

        add_filter('cartshift/finalize/flush_object_cache', function (bool $flush) use (&$seen): bool {
            $seen = $flush;

            return false;
        });

        $this->finalizer->clearCaches();

        $this->assertTrue($seen, 'Default must be a full flush.');
        $this->assertGreaterThan(
            0,
            $GLOBALS['_cartshift_test_cache_flush_runtime_calls'] ?? 0,
            'Declining the full flush must still clear the in-process cache.',
        );
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    /**
     * Run recalculateCustomerStats() for one customer so the stat queries are recorded.
     */
    private function captureStatsQueries(string $migrationId): void
    {
        $GLOBALS['_cartshift_test_get_results_callback'] =
            function (string $query) use ($migrationId): array {
                if (str_contains($query, Constants::ENTITY_CUSTOMER) && str_contains($query, $migrationId)) {
                    return [(object) ['wc_id' => '1', 'fc_id' => 11]];
                }

                return [];
            };

        $this->finalizer->recalculateCustomerStats($migrationId);
    }

    private function findQueryContaining(string $needle): string
    {
        foreach ($GLOBALS['_cartshift_test_queries'] ?? [] as $entry) {
            if ($entry[0] === 'get_results' && str_contains($entry[1], $needle)) {
                return $entry[1];
            }
        }

        $this->fail("No query recorded containing: {$needle}");
    }

    /**
     * @return array<string, mixed>
     */
    private function firstCustomerUpdate(): array
    {
        foreach ($GLOBALS['_cartshift_test_queries'] ?? [] as $entry) {
            if ($entry[0] === 'update' && str_contains($entry[1], 'fct_customers')) {
                return $entry[2];
            }
        }

        $this->fail('No fct_customers update recorded.');
    }
}

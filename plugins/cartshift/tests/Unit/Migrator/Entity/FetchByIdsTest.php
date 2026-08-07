<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Migrator\Entity;

use CartShift\Migrator\CouponMigrator;
use CartShift\Migrator\CustomerMigrator;
use CartShift\Migrator\OrderMigrator;
use CartShift\Migrator\ProductMigrator;
use CartShift\Migrator\SubscriptionMigrator;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Tests\Unit\PluginTestCase;

require_once dirname(__DIR__, 3) . '/stubs/EntityMigratorStubs.php';
require_once dirname(__DIR__, 3) . '/stubs/ProductMigratorStubs.php';
require_once dirname(__DIR__, 3) . '/stubs/HttpCliStubs.php';

/**
 * fetchByIds() is the retry counterpart of fetchBatch(): the caller already
 * knows which records it wants, because it read them out of a previous run's
 * log, so there is no cursor and no ordering promise.
 *
 * Two properties matter across every migrator and are asserted here one by one.
 * The IDs must be read in the form the log stores — which is what getRecordId()
 * returned, not what the ID map is keyed on — and pagination state must not
 * move, because a retry paginates an ID list and a nudged cursor would corrupt
 * an ordinary run sharing the instance.
 */
final class FetchByIdsTest extends PluginTestCase
{
    private IdMapRepository $idMap;
    private MigrationLogRepository $log;
    private MigrationState $state;

    protected function setUp(): void
    {
        parent::setUp();

        $this->idMap = new IdMapRepository();
        $this->log   = new MigrationLogRepository();
        $this->state = new MigrationState();

        $GLOBALS['_cartshift_test_wc_product_batches'] = [];
        $GLOBALS['_cartshift_test_wc_get_orders_calls'] = [];
        $GLOBALS['_cartshift_test_wc_get_orders_return'] = [];
    }

    // ──────────────────────────────────────────────
    // Products
    // ──────────────────────────────────────────────

    public function testProductFetchByIdsHydratesTheRequestedRecords(): void
    {
        $GLOBALS['_cartshift_test_wc_product_batches'] = [
            [(object) ['id' => 11], (object) ['id' => 12]],
        ];

        $records = $this->productMigrator()->fetchByIds(['11', '12']);

        $this->assertCount(2, $records);
    }

    public function testProductFetchByIdsShortCircuitsOnAnEmptyList(): void
    {
        $GLOBALS['_cartshift_test_wc_product_batches'] = [
            [(object) ['id' => 11]],
        ];

        $this->assertSame([], $this->productMigrator()->fetchByIds([]));

        $this->assertCount(
            1,
            $GLOBALS['_cartshift_test_wc_product_batches'],
            'An empty ID list must not cost a query.',
        );
    }

    public function testProductFetchByIdsDropsNonObjectsTheQueryLayerHandsBack(): void
    {
        $GLOBALS['_cartshift_test_wc_product_batches'] = [
            [(object) ['id' => 11], false, null, (object) ['id' => 13]],
        ];

        $this->assertCount(2, $this->productMigrator()->fetchByIds([11, 13]));
    }

    public function testProductFetchByIdsLeavesTheKeysetCursorAlone(): void
    {
        $GLOBALS['_cartshift_test_wc_product_batches'] = [
            [(object) ['id' => 11]],
        ];

        $migrator = $this->productMigrator();
        $migrator->fetchByIds([11]);

        $cursor = new \ReflectionProperty(ProductMigrator::class, 'pageEndCursor');

        $this->assertNull(
            $cursor->getValue($migrator),
            'A retry paginates an ID list; moving the keyset cursor would corrupt a normal run.',
        );
    }

    // ──────────────────────────────────────────────
    // Orders
    // ──────────────────────────────────────────────

    public function testOrderFetchByIdsAsksForExactlyTheGivenIds(): void
    {
        $GLOBALS['_cartshift_test_wc_get_orders_return'] = [new \WC_Order()];

        $this->orderMigrator()->fetchByIds(['31', '31', '0', 'nonsense', 32]);

        $call = end($GLOBALS['_cartshift_test_wc_get_orders_calls']);

        $this->assertSame([31, 32], $call['post__in'], 'IDs are deduplicated and coerced to ints.');
        $this->assertSame(2, $call['limit']);
        $this->assertSame('shop_order', $call['type'], 'Retry must keep the normal type scoping.');
    }

    public function testOrderFetchByIdsShortCircuitsOnAnEmptyList(): void
    {
        $this->assertSame([], $this->orderMigrator()->fetchByIds([]));
        $this->assertSame([], $GLOBALS['_cartshift_test_wc_get_orders_calls']);
    }

    // ──────────────────────────────────────────────
    // Coupons
    // ──────────────────────────────────────────────

    public function testCouponFetchByIdsHydratesOneCouponPerId(): void
    {
        $coupons = $this->couponMigrator()->fetchByIds(['41', '42']);

        $this->assertCount(2, $coupons);
        $this->assertSame([41, 42], array_map(static fn (\WC_Coupon $c): int => $c->get_id(), $coupons));
    }

    public function testCouponFetchByIdsDropsIdsThatCannotBeACoupon(): void
    {
        $coupons = $this->couponMigrator()->fetchByIds(['0', '', 'SAVE10', '-3']);

        $this->assertSame([], $coupons);
    }

    public function testCouponFetchByIdsLeavesTheKeysetCursorAlone(): void
    {
        $migrator = $this->couponMigrator();
        $migrator->fetchByIds([41]);

        $cursor = new \ReflectionProperty(CouponMigrator::class, 'pageEndCursor');

        $this->assertNull($cursor->getValue($migrator));
    }

    // ──────────────────────────────────────────────
    // Subscriptions
    // ──────────────────────────────────────────────

    /**
     * WooCommerce Subscriptions is a paid add-on and is not installed here, so
     * the honest answer is "nothing to hydrate" — but said out loud. A silent
     * empty result is indistinguishable from "nothing failed last time", which
     * is the one thing a retry must never imply.
     */
    public function testSubscriptionFetchByIdsSaysWhyItCannotHydrateWithoutTheAddon(): void
    {
        if (function_exists('wcs_get_subscription')) {
            $this->markTestSkipped('WooCommerce Subscriptions is available in this environment.');
        }

        $GLOBALS['_cartshift_test_queries'] = [];

        $this->assertSame([], $this->subscriptionMigrator()->fetchByIds([51, 52]));

        $messages = array_map(
            static fn (array $row): string => (string) ($row[2]['message'] ?? ''),
            array_filter(
                $GLOBALS['_cartshift_test_queries'],
                static fn (array $row): bool => ($row[0] ?? '') === 'insert',
            ),
        );

        $this->assertNotEmpty(
            array_filter(
                $messages,
                static fn (string $m): bool => str_contains($m, 'WooCommerce Subscriptions is not active'),
            ),
            'The reason must reach the log, not just the return value.',
        );
    }

    public function testSubscriptionFetchByIdsIsSilentOnAnEmptyList(): void
    {
        $GLOBALS['_cartshift_test_queries'] = [];

        $this->assertSame([], $this->subscriptionMigrator()->fetchByIds([]));

        $inserts = array_filter(
            $GLOBALS['_cartshift_test_queries'],
            static fn (array $row): bool => ($row[0] ?? '') === 'insert',
        );

        $this->assertSame([], $inserts, 'Nothing to retry is not a problem worth a log row.');
    }

    // ──────────────────────────────────────────────
    // Customers — the fiddly one
    // ──────────────────────────────────────────────

    /**
     * The ID map keys customers by phase (`customer` on the user ID,
     * `guest_customer` on the email) but the LOG keys them by getRecordId(),
     * which returns the bare value with no phase attached. A retry list comes
     * from the log, so that is the form fetchByIds() has to read.
     */
    public function testCustomerRecordIdFormIsWhatFetchByIdsAccepts(): void
    {
        $migrator = $this->customerMigrator();

        $registered = ['type' => 'registered', 'data' => ['user_id' => 7]];
        $guest      = ['type' => 'guest', 'data' => ['email' => 'bob@example.com']];

        $this->assertSame('7', $migrator->getRecordId($registered));
        $this->assertSame('bob@example.com', $migrator->getRecordId($guest));

        $this->assertSame(
            [$registered, $guest],
            $migrator->fetchByIds([
                $migrator->getRecordId($registered),
                $migrator->getRecordId($guest),
            ]),
            'Round-tripping getRecordId() through fetchByIds() must give the record back.',
        );
    }

    public function testCustomerFetchByIdsSplitsDigitsFromEmails(): void
    {
        $batch = $this->customerMigrator()->fetchByIds(['12', 'zoe@example.com', 34]);

        $this->assertSame(
            [
                ['type' => 'registered', 'data' => ['user_id' => 12]],
                ['type' => 'registered', 'data' => ['user_id' => 34]],
                ['type' => 'guest', 'data' => ['email' => 'zoe@example.com']],
            ],
            $batch,
            'Digits are always a user ID; an email never is.',
        );
    }

    public function testCustomerFetchByIdsAlsoAcceptsThePhaseTaggedCursorForm(): void
    {
        $batch = $this->customerMigrator()->fetchByIds([
            'registered:12',
            'guest:zoe@example.com',
        ]);

        $this->assertSame(
            [
                ['type' => 'registered', 'data' => ['user_id' => 12]],
                ['type' => 'guest', 'data' => ['email' => 'zoe@example.com']],
            ],
            $batch,
        );
    }

    /**
     * An email whose local part is all digits is still an email, because it
     * still contains the '@' that a user ID never can.
     */
    public function testCustomerFetchByIdsDoesNotMistakeANumericEmailForAUserId(): void
    {
        $batch = $this->customerMigrator()->fetchByIds(['12345@example.com']);

        $this->assertSame(
            [['type' => 'guest', 'data' => ['email' => '12345@example.com']]],
            $batch,
        );
    }

    public function testCustomerFetchByIdsDeduplicatesAndDropsEmpties(): void
    {
        $batch = $this->customerMigrator()->fetchByIds([
            '12', '12', 'registered:12', '', '   ', '0', 'zoe@example.com', 'guest:zoe@example.com',
        ]);

        $this->assertSame(
            [
                ['type' => 'registered', 'data' => ['user_id' => 12]],
                ['type' => 'guest', 'data' => ['email' => 'zoe@example.com']],
            ],
            $batch,
        );
    }

    /**
     * A user ID whose WP row has since been deleted still comes back as a
     * record. processRegistered() logs it as user_not_found against the retry
     * run, which is the answer the user asked for; dropping it here would leave
     * the retry looking like it had fixed something.
     */
    public function testCustomerFetchByIdsDoesNotPreScreenForExistence(): void
    {
        $batch = $this->customerMigrator()->fetchByIds(['999999']);

        $this->assertSame([['type' => 'registered', 'data' => ['user_id' => 999999]]], $batch);
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    private function productMigrator(): ProductMigrator
    {
        return new ProductMigrator($this->idMap, $this->log, $this->state);
    }

    private function orderMigrator(): OrderMigrator
    {
        return new OrderMigrator($this->idMap, $this->log, $this->state);
    }

    private function couponMigrator(): CouponMigrator
    {
        return new CouponMigrator($this->idMap, $this->log, $this->state);
    }

    private function subscriptionMigrator(): SubscriptionMigrator
    {
        return new SubscriptionMigrator($this->idMap, $this->log, $this->state);
    }

    private function customerMigrator(): CustomerMigrator
    {
        return new CustomerMigrator($this->idMap, $this->log, $this->state);
    }
}

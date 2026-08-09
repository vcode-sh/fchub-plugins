<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Migrator\Entity;

use CartShift\Domain\Subscription\Source\WooDatasetRecordFactory;
use CartShift\Domain\Subscription\SubscriptionHistoryIndex;
use CartShift\Domain\Subscription\SubscriptionRecordFactory;
use CartShift\Migrator\SubscriptionMigrator;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\Constants;
use CartShift\Support\Enums\MigrationErrorCode;
use CartShift\Tests\Unit\PluginTestCase;

require_once dirname(__DIR__, 3) . '/stubs/EntityMigratorStubs.php';
require_once dirname(__DIR__, 3) . '/stubs/FluentCartModelStubs.php';

/**
 * The history phase, wired into the migrator that runs it.
 *
 * It is opt-in, and the two halves of that are both worth pinning. Without a
 * history index the migrator behaves exactly as it always has — assess, stage,
 * log, no orders, no transactions — because section 6.2's import order belongs
 * to the staging command rather than to a per-record batch tick, and a migrator
 * that built its own dataset would own that order twice.
 *
 * With one, the parent and renewal orders are imported with the FluentCart
 * types that make them visible, their succeeded charges are attached to the
 * subscription, and three counts are compared. A disagreement is a warning
 * coded `history_count_mismatch` and a `bill_count` deliberately left alone —
 * not a number picked to make the row look finished.
 */
final class SubscriptionHistoryPhaseTest extends PluginTestCase
{
    /** @var array<string, callable> */
    private array $shapes;

    private ?object $originalWpdb = null;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->originalWpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb']    = new \CartShiftTestWpdb();

        \CartShiftFcModelStore::install();

        $GLOBALS['_cartshift_test_id_map']           = [];
        $GLOBALS['_cartshift_test_get_var_callback'] = cartshift_test_id_map_reader();
        $GLOBALS['_cartshift_test_insert_callback']  = static function (string $table, array $data): int {
            if (isset($data['entity_type'], $data['wc_id'], $data['fc_id'])) {
                $GLOBALS['_cartshift_test_id_map'][(string) $data['entity_type']][(string) $data['wc_id']]
                    ??= (int) $data['fc_id'];
            }

            return 1;
        };

        cartshift_test_accept_manual_fallback();

        $this->shapes = require dirname(__DIR__, 3) . '/fixtures/lapka-subscription-shapes.php';
    }

    #[\Override]
    protected function tearDown(): void
    {
        if ($this->originalWpdb !== null) {
            $GLOBALS['wpdb'] = $this->originalWpdb;
        }

        parent::tearDown();
    }

    public function testWithoutAHistoryIndexNoRenewalOrdersOrLinksAreWritten(): void
    {
        $subscription = $this->shapes['monthlyPln29'](['related_orders' => ['renewal' => [880_501]]]);
        $this->mapReferencesFor($subscription);

        $this->migrator(null)->processRecord($subscription);

        $this->assertCount(1, \CartShiftFcModelStore::all('Subscription'));

        // Only the parent order the ordinary order run already imported.
        $this->assertCount(1, \CartShiftFcModelStore::all('Order'));
        $this->assertCount(1, \CartShiftFcModelStore::all('OrderTransaction'));
        $this->assertNull(\CartShiftFcModelStore::all('OrderTransaction')[0]->subscription_id);
    }

    public function testWithAHistoryIndexTheRenewalIsImportedAndEveryChargeIsLinked(): void
    {
        // One renewal, so the source's payment count of two is provable.
        $subscription = $this->shapes['monthlyPln29']([
            'payment_count'  => 2,
            'related_orders' => ['renewal' => [880_501]],
        ]);

        $this->mapReferencesFor($subscription);

        $this->migrator($this->historyFor($subscription))->processRecord($subscription);

        $orders = \CartShiftFcModelStore::all('Order');

        $this->assertCount(2, $orders);
        $this->assertSame(['subscription', 'renewal'], array_column($orders, 'type'));
        $this->assertSame((int) $orders[0]->id, $orders[1]->parent_id);

        $subscriptionId = (int) \CartShiftFcModelStore::all('Subscription')[0]->id;
        $transactions   = \CartShiftFcModelStore::all('OrderTransaction');

        $this->assertCount(2, $transactions);

        foreach ($transactions as $transaction) {
            $this->assertSame($subscriptionId, $transaction->subscription_id);
        }

        $this->assertSame(['subscription', 'renewal'], array_column($transactions, 'order_type'));
        $this->assertSame(2, \CartShiftFcModelStore::all('Subscription')[0]->bill_count);
        $this->assertSame(0, $this->countLogged(MigrationErrorCode::SubscriptionHistoryCountMismatch));
    }

    /**
     * The source says seven payments and the dataset carries two paid orders.
     * The row is written, staged paused, and its bill count is left where the
     * writer put it — because a number chosen here is a number FluentCart
     * overwrites at the next recompute.
     */
    public function testADisagreementIsLoggedUnderTheStableCodeAndNothingIsForced(): void
    {
        $subscription = $this->shapes['monthlyPln29'](['related_orders' => ['renewal' => [880_501]]]);

        $this->mapReferencesFor($subscription);

        $this->migrator($this->historyFor($subscription))->processRecord($subscription);

        $this->assertCount(1, \CartShiftFcModelStore::all('Subscription'));
        $this->assertSame('paused', \CartShiftFcModelStore::all('Subscription')[0]->status);
        $this->assertGreaterThan(
            0,
            $this->countLogged(MigrationErrorCode::SubscriptionHistoryCountMismatch),
        );
        $this->assertSame(
            'history_count_mismatch',
            MigrationErrorCode::SubscriptionHistoryCountMismatch->value,
        );
    }

    /**
     * A reference the dataset names and does not carry stops the history phase
     * before it writes anything, with the section 9.4 dataset code rather than
     * a partial import nobody can tell from a complete one.
     */
    public function testAnOrderReferenceWithNoPayloadStopsTheHistoryPhase(): void
    {
        $subscription = $this->shapes['monthlyPln29'](['related_orders' => ['renewal' => [880_501]]]);

        $this->mapReferencesFor($subscription);

        // A history carrying the subscription and nothing behind its orders.
        $records = new SubscriptionRecordFactory();
        $index   = SubscriptionHistoryIndex::fromRecords(Constants::DEFAULT_SOURCE_KEY, [
            $records->subscriptionFromWoo(
                Constants::DEFAULT_SOURCE_KEY,
                $subscription,
                (new WooDatasetRecordFactory($records))->relatedOrdersByType($subscription),
            ),
        ]);

        $this->migrator($index)->processRecord($subscription);

        $this->assertCount(1, \CartShiftFcModelStore::all('Subscription'));

        // The parent the ordinary order run imported, and nothing else: the
        // history phase wrote no renewal and linked no charge.
        $this->assertCount(1, \CartShiftFcModelStore::all('Order'));
        $this->assertNull(\CartShiftFcModelStore::all('OrderTransaction')[0]->subscription_id);
        $this->assertGreaterThan(
            0,
            $this->countLogged(MigrationErrorCode::SubscriptionDatasetMissingParentOrder),
        );
    }

    /**
     * A record staged by an earlier run has its history repaired by a re-run.
     *
     * The failure this replaces was permanent and silent. Run 1 stages the
     * subscription and reports `dataset_missing_parent_order`; the operator
     * exports the missing order and runs again; `processRecord()` sees
     * `already_migrated` and returns before touching the history. Orders never
     * imported, charges never linked, `bill_count` never revisited — the
     * operator's remedy could not take effect through the door a re-run comes in
     * by, and §10 requires the reconciliation to be rerunnable.
     */
    public function testASubscriptionStagedByAnEarlierRunHasItsHistoryRepairedOnTheNextRun(): void
    {
        $subscription = $this->shapes['monthlyPln29']([
            'payment_count'  => 2,
            'related_orders' => ['renewal' => [880_501]],
        ]);

        $this->mapReferencesFor($subscription);

        // Run 1: no dataset, so the subscription is staged and no history is
        // imported — exactly the state a missing-payload report leaves behind.
        $this->migrator(null)->processRecord($subscription);

        $staged = \CartShiftFcModelStore::all('Subscription')[0];

        $this->assertCount(1, \CartShiftFcModelStore::all('Order'));
        $this->assertNull(\CartShiftFcModelStore::all('OrderTransaction')[0]->subscription_id);

        $GLOBALS['_cartshift_test_id_map'][Constants::ENTITY_SUBSCRIPTION][(string) $subscription->get_id()]
            = (int) $staged->id;

        // Run 2, with the dataset the operator has since exported.
        $result = $this->migrator($this->historyFor($subscription))->processRecord($subscription);

        $this->assertFalse($result, 'An already-staged record still reports no new destination ID.');
        $this->assertCount(1, \CartShiftFcModelStore::all('Subscription'), 'And no second row.');
        $this->assertSame((int) $staged->id, (int) \CartShiftFcModelStore::all('Subscription')[0]->id);

        $orders = \CartShiftFcModelStore::all('Order');

        $this->assertCount(2, $orders);
        $this->assertSame(['subscription', 'renewal'], array_column($orders, 'type'));

        foreach (\CartShiftFcModelStore::all('OrderTransaction') as $transaction) {
            $this->assertSame((int) $staged->id, $transaction->subscription_id);
        }

        $this->assertSame(2, \CartShiftFcModelStore::all('Subscription')[0]->bill_count);
    }

    /**
     * And a third run over a history that is already correct changes nothing.
     */
    public function testAThirdRunOverARepairedRecordDuplicatesNothing(): void
    {
        $subscription = $this->shapes['monthlyPln29']([
            'payment_count'  => 2,
            'related_orders' => ['renewal' => [880_501]],
        ]);

        $this->mapReferencesFor($subscription);

        $history = $this->historyFor($subscription);

        $this->migrator($history)->processRecord($subscription);

        $staged = \CartShiftFcModelStore::all('Subscription')[0];

        $GLOBALS['_cartshift_test_id_map'][Constants::ENTITY_SUBSCRIPTION][(string) $subscription->get_id()]
            = (int) $staged->id;

        $orders       = count(\CartShiftFcModelStore::all('Order'));
        $transactions = count(\CartShiftFcModelStore::all('OrderTransaction'));

        $this->migrator($history)->processRecord($subscription);

        $this->assertCount($orders, \CartShiftFcModelStore::all('Order'));
        $this->assertCount($transactions, \CartShiftFcModelStore::all('OrderTransaction'));
        $this->assertCount(1, \CartShiftFcModelStore::all('Subscription'));
        $this->assertSame(2, \CartShiftFcModelStore::all('Subscription')[0]->bill_count);
    }

    /**
     * How many charges were actually attached, in the mismatch message.
     *
     * It is the first number an operator wants, and the one that separates a
     * history that never came across from a history that came across and did not
     * link.
     */
    public function testTheMismatchMessageSaysHowManyChargesWereLinked(): void
    {
        $subscription = $this->shapes['monthlyPln29'](['related_orders' => ['renewal' => [880_501]]]);

        $this->mapReferencesFor($subscription);

        $this->migrator($this->historyFor($subscription))->processRecord($subscription);

        $this->assertStringContainsString(
            '2 of 2 succeeded charge(s) in the imported history are linked',
            $this->messageFor(MigrationErrorCode::SubscriptionHistoryCountMismatch),
        );
    }

    // ──────────────────────────────────────────────
    // The order-typing fix, on the path a real run takes
    // ──────────────────────────────────────────────

    /**
     * The P1 fix, live rather than test-only.
     *
     * `MigrationOrchestratorFactory` is the single place a run is assembled, and
     * until it handed `OrderMigrator` a relationship index, every WooCommerce
     * Subscriptions renewal order was written `type = checkout` on every real
     * migration — `RenewalController`, `CustomerOrderController` and
     * `Subscription::renewalOrders()` all filter on the type, so the subscriber
     * lost every renewal they had ever paid from their own invoice list.
     *
     * Asserted through the assembled migrator rather than through a
     * hand-constructed one, because "the seam exists" and "the seam is wired"
     * are different claims and only the second one ships.
     */
    public function testTheAssembledOrderMigratorTypesARenewalOrderAsARenewal(): void
    {
        $subscription = $this->shapes['monthlyPln29'](['related_orders' => ['renewal' => [880_501]]]);

        $GLOBALS['_cartshift_test_wcs_pages'] = [$subscription];

        $this->mapReferencesFor($subscription);

        $parentFcId = $GLOBALS['_cartshift_test_id_map'][Constants::ENTITY_ORDER]['880001'];

        $migrated = $this->assembledOrderMigrator()->processRecord($this->renewalOrder(880_501));

        $this->assertIsInt($migrated);

        $written = $this->orderRow($migrated);

        $this->assertSame('renewal', $written->type);
        $this->assertSame($parentFcId, $written->parent_id, 'A renewal hangs off the subscription\'s parent order.');
    }

    /**
     * Assembling migrators must not read the subscription source.
     *
     * `migratorsForCounting()` builds every migrator including the order one,
     * and `PreviewController` calls it inside a read-only REST request before
     * any entity-type filter is applied — so an eagerly-built relationship index
     * made a products-only preview page `wcs_get_subscriptions()` in full and
     * hydrate one `WC_Subscription` per row. On the reference dataset that is
     * 564 hydrations for a preview that never maps an order.
     */
    public function testAssemblingMigratorsForCountingReadsNoSubscriptionsAtAll(): void
    {
        $GLOBALS['_cartshift_test_wcs_pages'] = [$this->shapes['monthlyPln29']()];

        $migrator = $this->assembledOrderMigrator();

        $this->assertSame(
            0,
            $GLOBALS['_cartshift_test_wcs_query_count'] ?? 0,
            'Building a migrator must not page the subscription source.',
        );

        // And the index is still there when it is finally needed. A source order
        // the ID map has never seen, or `processRecord()` short-circuits on
        // "already migrated" before it reaches the mapper.
        $this->mapReferencesFor($GLOBALS['_cartshift_test_wcs_pages'][0]);
        $migrator->processRecord($this->renewalOrder(880_501));

        $this->assertGreaterThan(0, $GLOBALS['_cartshift_test_wcs_query_count'] ?? 0);
    }

    /**
     * And the deferred build runs at most once, however many orders are mapped.
     */
    public function testTheRelationshipIndexIsBuiltOnceForTheWholeRun(): void
    {
        $subscription = $this->shapes['monthlyPln29'](['related_orders' => ['renewal' => [880_501]]]);

        $GLOBALS['_cartshift_test_wcs_pages'] = [$subscription];

        $this->mapReferencesFor($subscription);

        $migrator = $this->assembledOrderMigrator();

        $migrator->processRecord($this->renewalOrder(880_501));

        $afterFirst = (int) ($GLOBALS['_cartshift_test_wcs_query_count'] ?? 0);

        $migrator->processRecord($this->renewalOrder(880_502));
        $migrator->processRecord($this->renewalOrder(880_503));

        $this->assertSame($afterFirst, (int) ($GLOBALS['_cartshift_test_wcs_query_count'] ?? 0));
    }

    /**
     * And the dispute reaches the log on the path where nothing else would
     * report it — a run migrating orders with no closure validator anywhere.
     */
    public function testADisputedOrderIsLoggedOnTheLiveOrderPath(): void
    {
        $subscription = $this->shapes['monthlyPln29']([
            'related_orders' => ['renewal' => [880_501], 'switch' => [880_501]],
        ]);

        $GLOBALS['_cartshift_test_wcs_pages'] = [$subscription];

        $this->mapReferencesFor($subscription);

        $migrated = $this->assembledOrderMigrator()->processRecord($this->renewalOrder(880_501));

        $this->assertSame('checkout', $this->orderRow((int) $migrated)->type);
        $this->assertGreaterThan(
            0,
            $this->countLogged(MigrationErrorCode::SubscriptionAmbiguousOrderRelationship),
        );
    }

    public function testTheAssembledOrderMigratorLeavesAnUnrelatedOrderACheckout(): void
    {
        $subscription = $this->shapes['monthlyPln29'](['related_orders' => ['renewal' => [880_501]]]);

        $GLOBALS['_cartshift_test_wcs_pages'] = [$subscription];

        $this->mapReferencesFor($subscription);

        $migrated = $this->assembledOrderMigrator()->processRecord($this->renewalOrder(770_777));

        $this->assertSame('checkout', $this->orderRow((int) $migrated)->type);
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    private function assembledOrderMigrator(): \CartShift\Migrator\OrderMigrator
    {
        $factory = \CartShift\Domain\Migration\MigrationOrchestratorFactory::standalone(
            new IdMapRepository(),
            new MigrationLogRepository(),
            new MigrationState(),
        );

        foreach ($factory->migratorsForCounting() as $migrator) {
            if ($migrator instanceof \CartShift\Migrator\OrderMigrator) {
                return $migrator;
            }
        }

        $this->fail('The factory assembled no order migrator.');
    }

    private function renewalOrder(int $wcOrderId): \WC_Order
    {
        $order = new \WC_Order();

        foreach ([
            'id'             => $wcOrderId,
            'status'         => 'completed',
            'total'          => '29.00',
            'subtotal'       => '29.00',
            'currency'       => 'PLN',
            'customer_id'    => 660_001,
            'billing_email'  => 'subscriber-660001@example.invalid',
            'payment_method' => 'stripe',
            // A WCS renewal order carries no useful post_parent. If the mapper
            // were reading one, this order would come out unparented.
            'parent_id'      => 0,
        ] as $property => $value) {
            (new \ReflectionProperty(\WC_Order::class, $property))->setValue($order, $value);
        }

        return $order;
    }

    private function orderRow(int $fcOrderId): object
    {
        foreach (\CartShiftFcModelStore::all('Order') as $row) {
            if ((int) $row->id === $fcOrderId) {
                return $row;
            }
        }

        $this->fail(sprintf('No FluentCart order #%d was written.', $fcOrderId));
    }

    private function migrator(?SubscriptionHistoryIndex $history): SubscriptionMigrator
    {
        return new SubscriptionMigrator(
            new IdMapRepository(),
            new MigrationLogRepository(),
            new MigrationState(),
            Constants::DEFAULT_BATCH_SIZE,
            null,
            $history,
        );
    }

    /**
     * The dataset a staging command would have built for this subscription:
     * its own record plus every related order, read through the same typed
     * calls the export uses.
     */
    private function historyFor(object $subscription): SubscriptionHistoryIndex
    {
        $records = new SubscriptionRecordFactory();
        $factory = new WooDatasetRecordFactory($records);
        $byType  = $factory->relatedOrdersByType($subscription);

        $dataset = [
            $records->subscriptionFromWoo(Constants::DEFAULT_SOURCE_KEY, $subscription, $byType),
        ];

        foreach ($subscription->get_related_orders('all', 'any') as $order) {
            $dataset[] = $factory->order(Constants::DEFAULT_SOURCE_KEY, $order);
        }

        return SubscriptionHistoryIndex::fromRecords(Constants::DEFAULT_SOURCE_KEY, $dataset);
    }

    /**
     * The state section 6.2's import order leaves behind before subscriptions
     * are reached: customers, products, and the parent order — already imported
     * by the ordinary order run, as a plain `checkout` with its charge
     * unattached, which is exactly what the history phase has to correct.
     */
    private function mapReferencesFor(object $subscription): void
    {
        $GLOBALS['_cartshift_test_id_map'][Constants::ENTITY_CUSTOMER][(string) $subscription->get_customer_id()] = 501;

        foreach ($subscription->get_items() as $item) {
            $productId = (string) $item->get_product_id();

            $GLOBALS['_cartshift_test_id_map'][Constants::ENTITY_PRODUCT][$productId]   = 701;
            $GLOBALS['_cartshift_test_id_map'][Constants::ENTITY_VARIATION][$productId] = 801;

            cartshift_test_own_variation(801, 701);
        }

        $parentSourceId = (int) $subscription->get_parent_id();

        $order = \FluentCart\App\Models\Order::query()->create([
            'type'        => 'checkout',
            'status'      => 'completed',
            'customer_id' => 501,
            'invoice_no'  => 'WC-' . $parentSourceId,
        ]);

        $transaction = \FluentCart\App\Models\OrderTransaction::query()->create([
            'order_id'         => (int) $order->id,
            'order_type'       => 'order',
            'transaction_type' => 'charge',
            'status'           => 'succeeded',
            'total'            => 2900,
            'subscription_id'  => null,
        ]);

        $GLOBALS['_cartshift_test_id_map'][Constants::ENTITY_ORDER][(string) $parentSourceId]
            = (int) $order->id;
        $GLOBALS['_cartshift_test_id_map'][Constants::ENTITY_ORDER_TRANSACTION][$parentSourceId . '_charge']
            = (int) $transaction->id;
    }

    private function messageFor(MigrationErrorCode $code): string
    {
        foreach ($GLOBALS['_cartshift_test_queries'] ?? [] as $query) {
            if (($query[0] ?? '') === 'insert' && ($query[2]['error_code'] ?? null) === $code->value) {
                return (string) ($query[2]['message'] ?? '');
            }
        }

        $this->fail(sprintf('Nothing was logged under "%s".', $code->value));
    }

    private function countLogged(MigrationErrorCode $code): int
    {
        $count = 0;

        foreach ($GLOBALS['_cartshift_test_queries'] ?? [] as $query) {
            if (($query[0] ?? '') === 'insert' && ($query[2]['error_code'] ?? null) === $code->value) {
                $count++;
            }
        }

        return $count;
    }
}

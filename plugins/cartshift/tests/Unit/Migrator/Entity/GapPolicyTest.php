<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Migrator\Entity;

use CartShift\Migrator\OrderMigrator;
use CartShift\Migrator\SubscriptionMigrator;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\Enums\MigrationErrorCode;
use CartShift\Tests\Unit\PluginTestCase;

require_once dirname(__DIR__, 3) . '/stubs/EntityMigratorStubs.php';
require_once dirname(__DIR__, 3) . '/stubs/MapperStubs.php';
require_once dirname(__DIR__, 3) . '/stubs/FluentCartModelStubs.php';

/**
 * One rule, applied to every missing reference:
 *
 *   History migrates complete; live instructions never migrate broken.
 *
 * A past order is a record. It happened, the money has to add up, and a missing
 * link to a customer or a product does not make it untrue — so it migrates, and
 * the gap is written down. A subscription is an instruction that will execute
 * against the shop tomorrow, so it must never migrate live with a hole in it —
 * it comes across paused instead of vanishing.
 *
 * Three things used to happen instead, none of them visible: the order was
 * dropped and its revenue with it, the product link was quietly zeroed, and the
 * paying subscriber disappeared. This file is the guard on all three.
 */
final class GapPolicyTest extends PluginTestCase
{
    private IdMapRepository $idMap;
    private MigrationLogRepository $log;
    private MigrationState $state;
    private ?object $originalWpdb = null;

    protected function setUp(): void
    {
        parent::setUp();

        // The order path reads $wpdb->comments and $wpdb->commentmeta, which the
        // shared stub does not declare.
        $this->originalWpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = new \CartShiftTestWpdb();

        \CartShiftFcModelStore::install();

        $GLOBALS['_cartshift_test_id_map'] = [];
        $GLOBALS['_cartshift_test_get_var_callback'] = static function (string $query): int|null {
            if (preg_match("/entity_type = '([^']*)' AND wc_id = '([^']*)'/", $query, $matches) === 1) {
                return $GLOBALS['_cartshift_test_id_map'][$matches[1]][$matches[2]] ?? null;
            }

            // Everything else — the invoice_no adoption lookup, the counts —
            // misses, which is what an empty FluentCart install looks like.
            return null;
        };

        $this->idMap = new IdMapRepository();
        $this->log   = new MigrationLogRepository();
        $this->state = new MigrationState();
    }

    protected function tearDown(): void
    {
        if ($this->originalWpdb !== null) {
            $GLOBALS['wpdb'] = $this->originalWpdb;
        }

        parent::tearDown();
    }

    // ──────────────────────────────────────────────
    // Case 1 — the order's customer was never migrated
    // ──────────────────────────────────────────────

    public function testAnOrderWhoseCustomerWasNotMigratedIsStillMigrated(): void
    {
        $result = $this->orderMigrator()->processRecord($this->order(501, [
            'customer_id'         => 7,
            'billing_email'       => 'ada@example.com',
            'billing_first_name'  => 'Ada',
            'billing_last_name'   => 'Lovelace',
            'billing_country'     => 'GB',
            'total'               => '99.00',
        ]));

        $this->assertNotFalse($result, 'The order — and its revenue — must not be dropped.');
        $this->assertCount(1, \CartShiftFcModelStore::all('Order'));
        $this->assertLogged(MigrationErrorCode::CustomerRebuiltFromOrder);
    }

    public function testTheRebuiltBuyerIsReachableInTheIdMapUnderItsEmail(): void
    {
        $this->orderMigrator()->processRecord($this->order(502, [
            'customer_id'        => 7,
            'billing_email'      => 'ada@example.com',
            'billing_first_name' => 'Ada',
            'billing_country'    => 'GB',
        ]));

        $customers = \CartShiftFcModelStore::all('Customer');
        $this->assertCount(1, $customers, 'The buyer must be rebuilt from the order.');
        $this->assertSame('ada@example.com', $customers[0]->email);

        $mapRow = $this->firstIdMapRow('guest_customer');
        $this->assertNotNull($mapRow, 'The rebuilt buyer must be keyed by email, so later orders find it.');
        $this->assertSame('ada@example.com', $mapRow['wc_id']);
        $this->assertSame($customers[0]->id, $mapRow['fc_id']);
    }

    public function testASecondOrderFromTheSameBuyerReusesTheRebuiltCustomer(): void
    {
        $migrator = $this->orderMigrator();

        $migrator->processRecord($this->order(503, [
            'customer_id'        => 7,
            'billing_email'      => 'ada@example.com',
            'billing_first_name' => 'Ada',
            'billing_country'    => 'GB',
        ]));
        $migrator->processRecord($this->order(504, [
            'customer_id'        => 7,
            'billing_email'      => 'ada@example.com',
            'billing_first_name' => 'Ada',
            'billing_country'    => 'GB',
        ]));

        $customers = \CartShiftFcModelStore::all('Customer');
        $this->assertCount(1, $customers, 'A second order must reuse the buyer, not duplicate it.');

        $orders = \CartShiftFcModelStore::all('Order');
        $this->assertCount(2, $orders);
        $this->assertSame($customers[0]->id, $orders[0]->customer_id);
        $this->assertSame($customers[0]->id, $orders[1]->customer_id);

        $this->assertSame(
            1,
            $this->countLogged(MigrationErrorCode::CustomerRebuiltFromOrder),
            'The rebuild happened once, so it is reported once.',
        );
    }

    public function testAnOrderWithNoBillingEmailMigratesWithNoCustomerRatherThanBeingSkipped(): void
    {
        $result = $this->orderMigrator()->processRecord($this->order(505, [
            'customer_id' => 7,
            'total'       => '42.00',
        ]));

        $this->assertNotFalse($result, 'fct_orders.customer_id is nullable. Never skip.');

        $orders = \CartShiftFcModelStore::all('Order');
        $this->assertCount(1, $orders);
        $this->assertNull($orders[0]->customer_id);

        $this->assertSame([], \CartShiftFcModelStore::all('Customer'), 'Nothing to rebuild a buyer from.');
        $this->assertLogged(MigrationErrorCode::CustomerNotFound);
    }

    // ──────────────────────────────────────────────
    // Case 2 — the order contains a product that was never migrated
    // ──────────────────────────────────────────────

    public function testAnOrderWithUnmappedProductsLogsExactlyOneEntryHoweverManyItemsAreAffected(): void
    {
        $this->mapEntity('product', [101]);

        $order = $this->order(506, [
            'customer_id'   => 0,
            'billing_email' => 'ada@example.com',
            'billing_country' => 'GB',
        ]);
        $this->setItems($order, [
            $this->item(101, 'Mapped thing', 2, '20.00'),
            $this->item(202, 'Retired thing', 1, '15.00'),
            $this->item(303, 'Discontinued thing', 3, '30.00'),
            $this->item(404, 'Another gone thing', 1, '5.00'),
        ]);

        $this->orderMigrator()->processRecord($order);

        $this->assertSame(
            1,
            $this->countLogged(MigrationErrorCode::ProductLinkMissing),
            'One order with four unmapped items is one countable event, not four log rows.',
        );
    }

    public function testUnlinkedOrderItemsStillCarryTheirNameAndPrice(): void
    {
        $this->mapEntity('product', [101]);

        $order = $this->order(507, [
            'customer_id'     => 0,
            'billing_email'   => 'ada@example.com',
            'billing_country' => 'GB',
        ]);
        $this->setItems($order, [
            $this->item(202, 'Retired thing', 1, '15.00'),
        ]);

        $this->orderMigrator()->processRecord($order);

        $items = \CartShiftFcModelStore::all('OrderItem');
        $this->assertCount(1, $items);
        $this->assertSame(0, $items[0]->post_id, 'The product link is what is lost, and only that.');
        $this->assertSame('Retired thing', $items[0]->post_title);
        $this->assertSame(1500, $items[0]->line_total, 'The money must still add up.');
        $this->assertSame(1500, $items[0]->unit_price);
    }

    public function testAnOrderWhoseProductsAllMigratedRaisesNothing(): void
    {
        $this->mapEntity('product', [101]);
        // And its variation, keyed by the product ID — what ProductMigrator
        // actually writes for a simple product (ProductMigrator::processRecord()
        // stores ENTITY_VARIATION under $wcId when the product is not variable).
        // Mapping the product alone models half a migration, and the order line
        // it produces carries object_id = 0.
        $this->mapEntity('variation', [101]);

        $order = $this->order(508, [
            'customer_id'     => 0,
            'billing_email'   => 'ada@example.com',
            'billing_country' => 'GB',
        ]);
        $this->setItems($order, [$this->item(101, 'Mapped thing', 1, '10.00')]);

        $this->orderMigrator()->processRecord($order);

        $this->assertSame(0, $this->countLogged(MigrationErrorCode::ProductLinkMissing));
        $this->assertSame(0, $this->countLogged(MigrationErrorCode::VariationLinkMissing));
    }

    // ──────────────────────────────────────────────
    // Case 3 — the subscription's product was never migrated
    // ──────────────────────────────────────────────

    public function testASubscriptionWithAnUnmappedProductMigratesPaused(): void
    {
        $this->mapEntity('customer', [1]);

        $result = $this->subscriptionMigrator()->processRecord(
            new \CartShiftTestSubscription(601, [new \CartShiftTestOrderItem(202, 0, 'Monthly Tea')], 1, 'active'),
        );

        $this->assertNotFalse($result, 'A paying subscriber must not disappear.');

        $subscriptions = \CartShiftFcModelStore::all('Subscription');
        $this->assertCount(1, $subscriptions);
        $this->assertSame('paused', $subscriptions[0]->status, 'Nothing charges until a human decides.');
        $this->assertLogged(MigrationErrorCode::SubscriptionPausedMissingProduct);
    }

    public function testThePausedSubscriptionKeepsItsCustomer(): void
    {
        $this->mapEntity('customer', [1]);

        $this->subscriptionMigrator()->processRecord(
            new \CartShiftTestSubscription(602, [new \CartShiftTestOrderItem(202, 0, 'Monthly Tea')], 1, 'active'),
        );

        $subscriptions = \CartShiftFcModelStore::all('Subscription');
        $this->assertSame(
            $GLOBALS['_cartshift_test_id_map']['customer']['1'],
            $subscriptions[0]->customer_id,
            'The subscriber is the whole point of migrating it at all.',
        );
    }

    public function testTheOriginalSubscriptionStatusIsRecoverableFromTheConfig(): void
    {
        $this->mapEntity('customer', [1]);

        $this->subscriptionMigrator()->processRecord(
            new \CartShiftTestSubscription(603, [new \CartShiftTestOrderItem(202, 0, 'Monthly Tea')], 1, 'active'),
        );

        $config = \CartShiftFcModelStore::all('Subscription')[0]->config;

        $this->assertIsArray($config);
        $this->assertSame('active', $config['cartshift_original_status']);
        $this->assertSame('product_not_migrated', $config['cartshift_paused_reason']);
        $this->assertSame(603, $config['wc_subscription_id'], 'The existing bookkeeping must survive.');
    }

    public function testASubscriptionWhoseProductsAllMigratedKeepsItsOwnStatus(): void
    {
        $this->mapEntity('customer', [1]);
        $this->mapEntity('product', [101]);
        // The variation too, keyed by the product ID, because that is what a
        // real migration of a simple product writes. Without it this fixture
        // describes a subscription with nothing to bill against, which is now
        // caught rather than waved through.
        $this->mapEntity('variation', [101]);

        $this->subscriptionMigrator()->processRecord(
            new \CartShiftTestSubscription(604, [new \CartShiftTestOrderItem(101, 0, 'Monthly Coffee')], 1, 'active'),
        );

        $subscriptions = \CartShiftFcModelStore::all('Subscription');
        $this->assertSame('active', $subscriptions[0]->status);
        $this->assertArrayNotHasKey('cartshift_original_status', (array) $subscriptions[0]->config);
        $this->assertSame(0, $this->countLogged(MigrationErrorCode::SubscriptionPausedMissingProduct));
        $this->assertSame(0, $this->countLogged(MigrationErrorCode::SubscriptionPausedMissingVariation));
    }

    /**
     * The defect this pair of assertions exists for: a simple product's
     * subscription used to skip the variation check outright, because
     * missingProductReference() gated it on `$wcVariationId > 0` and a simple
     * product's line item carries no variation ID. A mapped product whose
     * ENTITY_VARIATION row never landed therefore migrated *active* pointing at
     * nothing, and FluentCart billed the customer a blank line for ever
     * (RenewalService::createRenewalOrders() copies variation_id into the
     * renewal invoice's object_id and titles the line from the variation).
     */
    public function testASubscriptionOnASimpleProductWithNoMigratedVariationMigratesPaused(): void
    {
        $this->mapEntity('customer', [1]);
        // The product resolves and the variation does not — exactly what a
        // half-finished promotion of a `link` decision leaves behind.
        $this->mapEntity('product', [101]);

        $result = $this->subscriptionMigrator()->processRecord(
            new \CartShiftTestSubscription(607, [new \CartShiftTestOrderItem(101, 0, 'Monthly Coffee')], 1, 'active'),
        );

        $this->assertNotFalse($result, 'A paying subscriber must not disappear.');

        $subscriptions = \CartShiftFcModelStore::all('Subscription');
        $this->assertCount(1, $subscriptions);
        $this->assertSame('paused', $subscriptions[0]->status, 'Nothing bills against a null variant.');
        $this->assertLogged(MigrationErrorCode::SubscriptionPausedMissingProduct);
    }

    /**
     * The belt-and-braces half: a line item with no product ID at all clears
     * missingProductReference() — both of its checks are gated on
     * `$wcProductId > 0` — and the mapper then resolves neither reference. The
     * refusal has to read what the mapper produced, not what WooCommerce said.
     */
    public function testASubscriptionThatResolvesToNoVariantAtAllMigratesPaused(): void
    {
        $this->mapEntity('customer', [1]);

        $result = $this->subscriptionMigrator()->processRecord(
            new \CartShiftTestSubscription(608, [new \CartShiftTestOrderItem(0, 0, 'Mystery item')], 1, 'active'),
        );

        $this->assertNotFalse($result);

        $subscriptions = \CartShiftFcModelStore::all('Subscription');
        $this->assertSame('paused', $subscriptions[0]->status);
        $this->assertSame(
            'variation_not_resolved',
            ((array) $subscriptions[0]->config)['cartshift_paused_reason'],
            'The two pause reasons have different fixes and must stay distinguishable.',
        );
        $this->assertLogged(MigrationErrorCode::SubscriptionPausedMissingVariation);
    }

    /**
     * A subscription WooCommerce had already cancelled is history, not a live
     * instruction. Forcing it to 'paused' would dress a dead record up as a
     * resumable one, and nothing renews a cancelled subscription anyway.
     */
    public function testACancelledSubscriptionWithNoVariantKeepsItsOwnStatus(): void
    {
        $this->mapEntity('customer', [1]);

        $this->subscriptionMigrator()->processRecord(
            new \CartShiftTestSubscription(609, [new \CartShiftTestOrderItem(0, 0, 'Mystery item')], 1, 'cancelled'),
        );

        $subscriptions = \CartShiftFcModelStore::all('Subscription');
        $this->assertSame('canceled', $subscriptions[0]->status);
        $this->assertSame(0, $this->countLogged(MigrationErrorCode::SubscriptionPausedMissingVariation));
    }

    public function testASubscriptionWithNoCustomerIsStillSkippedAndCoded(): void
    {
        $result = $this->subscriptionMigrator()->processRecord(
            new \CartShiftTestSubscription(605, [new \CartShiftTestOrderItem(101, 0, 'Monthly Coffee')], 0),
        );

        $this->assertFalse($result, 'A subscription with nobody to bill is not recoverable.');
        $this->assertSame([], \CartShiftFcModelStore::all('Subscription'));
        $this->assertLogged(MigrationErrorCode::CustomerNotFound);
    }

    public function testASubscriptionWhoseCustomerWasNotMigratedIsStillSkippedAndCoded(): void
    {
        $result = $this->subscriptionMigrator()->processRecord(
            new \CartShiftTestSubscription(606, [new \CartShiftTestOrderItem(101, 0, 'Monthly Coffee')], 9),
        );

        $this->assertFalse($result);
        $this->assertSame([], \CartShiftFcModelStore::all('Subscription'));
        $this->assertLogged(MigrationErrorCode::CustomerNotFound);
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    private function orderMigrator(): OrderMigrator
    {
        return new OrderMigrator($this->idMap, $this->log, $this->state);
    }

    private function subscriptionMigrator(): SubscriptionMigrator
    {
        return new SubscriptionMigrator($this->idMap, $this->log, $this->state);
    }

    /**
     * A WC_Order with the given properties set through reflection, the way the
     * rest of the suite builds one.
     *
     * @param array<string, mixed> $properties
     */
    private function order(int $id, array $properties = []): \WC_Order
    {
        $order = new \WC_Order();

        foreach (['id' => $id] + $properties as $property => $value) {
            (new \ReflectionProperty(\WC_Order::class, $property))->setValue($order, $value);
        }

        return $order;
    }

    /**
     * @param list<\CartShiftTestOrderItem> $items
     */
    private function setItems(\WC_Order $order, array $items): void
    {
        (new \ReflectionProperty(\WC_Order::class, 'items'))->setValue($order, $items);
    }

    private function item(int $productId, string $name, int $quantity, string $lineTotal): \CartShiftTestOrderItem
    {
        $item = new \CartShiftTestOrderItem($productId, 0, $name);

        foreach (['quantity' => $quantity, 'total' => $lineTotal, 'subtotal' => $lineTotal] as $property => $value) {
            (new \ReflectionProperty(\WC_Order_Item_Product::class, $property))->setValue($item, $value);
        }

        return $item;
    }

    /**
     * Teach the ID-map stub which WooCommerce IDs already resolve.
     *
     * @param list<int> $wcIds
     */
    private function mapEntity(string $entityType, array $wcIds): void
    {
        foreach ($wcIds as $wcId) {
            $GLOBALS['_cartshift_test_id_map'][$entityType][(string) $wcId] = $wcId + 10_000;
        }
    }

    /**
     * The first ID-map row written for an entity type, as {wc_id, fc_id}.
     *
     * @return array{wc_id: string, fc_id: int}|null
     */
    private function firstIdMapRow(string $entityType): ?array
    {
        foreach ($GLOBALS['_cartshift_test_queries'] ?? [] as $query) {
            if (($query[0] ?? '') !== 'insert' || !str_contains((string) ($query[1] ?? ''), 'cartshift_id_map')) {
                continue;
            }

            if (($query[2]['entity_type'] ?? null) === $entityType) {
                return ['wc_id' => (string) $query[2]['wc_id'], 'fc_id' => (int) $query[2]['fc_id']];
            }
        }

        return null;
    }

    private function assertLogged(MigrationErrorCode $code): void
    {
        $this->assertGreaterThan(
            0,
            $this->countLogged($code),
            sprintf('Expected a log row coded "%s".', $code->value),
        );
    }

    private function countLogged(MigrationErrorCode $code): int
    {
        $count = 0;

        foreach ($GLOBALS['_cartshift_test_queries'] ?? [] as $query) {
            if (($query[0] ?? '') !== 'insert') {
                continue;
            }

            if ((string) ($query[2][MigrationLogRepository::CODE_COLUMN] ?? '') === $code->value) {
                $count++;
            }
        }

        return $count;
    }
}

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
 *   History migrates complete; a subscription migrates whole or not at all.
 *
 * A past order is a record. It happened, the money has to add up, and a missing
 * link to a customer or a product does not make it untrue — so it migrates, and
 * the gap is written down. `fct_orders.customer_id` is nullable and an order
 * line keeps its name and price with no product behind it, so the loss is a link
 * rather than the record.
 *
 * A subscription has no such room. `fct_subscriptions` declares customer, parent
 * order, product, variation, item name and quantity NOT NULL, so a record short
 * of any of them is refused before the write. That used to be a pause — migrate
 * it anyway, flip the status, note the original in `config` — which sounded
 * cautious and was not: `paused` is a lifecycle state, it satisfies no NOT NULL
 * column, and the row it left behind billed against a blank line the moment
 * anybody pressed resume.
 *
 * Three things used to happen instead, none of them visible: the order was
 * dropped and its revenue with it, the product link was quietly zeroed, and the
 * paying subscriber was written out pointing at nothing. This file is the guard
 * on all three.
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
        $GLOBALS['_cartshift_test_get_var_callback'] = cartshift_test_id_map_reader();

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

    public function testASubscriptionWithAnUnmappedProductIsBlockedRatherThanWrittenPaused(): void
    {
        $this->mapEntity('customer', [1]);
        $this->mapEntity('order', [701]);

        $result = $this->subscriptionMigrator()->processRecord(
            new \CartShiftTestSubscription(
                601,
                [new \CartShiftTestOrderItem(202, 0, 'Monthly Tea')],
                1,
                'active',
                '',
                null,
                701,
            ),
        );

        $this->assertFalse($result, 'FluentCart will not hold a subscription with no product.');
        $this->assertSame(
            [],
            \CartShiftFcModelStore::all('Subscription'),
            'A status has never satisfied a NOT NULL column. Write nothing.',
        );
        $this->assertLogged(MigrationErrorCode::SubscriptionRequiredReferenceMissing);
    }

    /**
     * The blocked record is not a lost record. The source is untouched, and the
     * log carries the WooCommerce subscription ID, the product that is missing
     * and the item it sits on — which is what the owner needs to go and fix it.
     */
    public function testABlockedSubscriptionIsTraceableToTheSourceRecordAndTheMissingPiece(): void
    {
        $this->mapEntity('customer', [1]);
        $this->mapEntity('order', [702]);

        $this->subscriptionMigrator()->processRecord(
            new \CartShiftTestSubscription(
                603,
                [new \CartShiftTestOrderItem(202, 0, 'Monthly Tea')],
                1,
                'active',
                '',
                null,
                702,
            ),
        );

        $message = $this->firstMessageFor(MigrationErrorCode::SubscriptionRequiredReferenceMissing);

        $this->assertNotNull($message);
        $this->assertStringContainsString('202', $message, 'The missing product must be named.');
        $this->assertStringContainsString('Monthly Tea', $message);
        $this->assertSame(603, $this->firstLoggedWcIdFor(MigrationErrorCode::SubscriptionRequiredReferenceMissing));
    }

    public function testASubscriptionWhoseReferencesAllResolveMigratesWithItsOwnStatus(): void
    {
        // WooCommerce was renewing this record automatically, and section 8.4
        // holds such a record at `confirmation_required` until the operator
        // accepts that FluentCart will invoice its customer instead. This test
        // is about references, so it accepts explicitly rather than relying on
        // a default — the migrator has none.
        cartshift_test_accept_manual_fallback();

        $this->mapEntity('customer', [1]);
        $this->mapEntity('order', [704]);
        $this->mapEntity('product', [101]);
        // The variation too, keyed by the product ID, because that is what a
        // real migration of a simple product writes. Without it this fixture
        // describes a subscription with nothing to bill against, which is now
        // caught rather than waved through.
        $this->mapEntity('variation', [101]);

        $result = $this->subscriptionMigrator()->processRecord(
            new \CartShiftTestSubscription(
                604,
                [new \CartShiftTestOrderItem(101, 0, 'Monthly Coffee')],
                1,
                'active',
                '',
                null,
                704,
                // An active record has to say when it bills next, or section
                // 9.3 refuses it — nothing would own the charge. This test is
                // about the references, so the schedule is made unambiguous.
                ['next_payment' => '2099-01-01 00:00:00'],
            ),
        );

        $this->assertNotFalse($result, 'A complete subscription must still migrate.');

        $subscriptions = \CartShiftFcModelStore::all('Subscription');

        // Staged paused with `active` recorded as the status it is destined
        // for. Plan section 11 Phase B creates every validated live record
        // paused and Phase D activates it once the source has released
        // ownership of the next charge — a subscription that arrives already
        // active is one two systems believe they are billing.
        $this->assertSame('paused', $subscriptions[0]->status);
        $this->assertSame('active', $subscriptions[0]->config['intended_status']);
        $this->assertSame(
            $GLOBALS['_cartshift_test_id_map']['customer']['1'],
            $subscriptions[0]->customer_id,
            'The subscriber is the whole point of migrating it at all.',
        );
        $this->assertSame(
            $GLOBALS['_cartshift_test_id_map']['order']['704'],
            $subscriptions[0]->parent_order_id,
        );
        $this->assertSame(0, $this->countLogged(MigrationErrorCode::SubscriptionRequiredReferenceMissing));
    }

    /**
     * The parent order is as NOT NULL as the product, and nothing else on the
     * record can stand in for it.
     */
    public function testASubscriptionWithNoMigratedParentOrderIsBlocked(): void
    {
        $this->mapEntity('customer', [1]);
        $this->mapEntity('product', [101]);
        $this->mapEntity('variation', [101]);

        $result = $this->subscriptionMigrator()->processRecord(
            new \CartShiftTestSubscription(
                610,
                [new \CartShiftTestOrderItem(101, 0, 'Monthly Coffee')],
                1,
                'active',
                '',
                null,
                705,
            ),
        );

        $this->assertFalse($result);
        $this->assertSame([], \CartShiftFcModelStore::all('Subscription'));
        $this->assertLogged(MigrationErrorCode::SubscriptionRequiredReferenceMissing);
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
    public function testASubscriptionOnASimpleProductWithNoMigratedVariationIsBlocked(): void
    {
        $this->mapEntity('customer', [1]);
        $this->mapEntity('order', [706]);
        // The product resolves and the variation does not — exactly what a
        // half-finished promotion of a `link` decision leaves behind.
        $this->mapEntity('product', [101]);

        $result = $this->subscriptionMigrator()->processRecord(
            new \CartShiftTestSubscription(
                607,
                [new \CartShiftTestOrderItem(101, 0, 'Monthly Coffee')],
                1,
                'active',
                '',
                null,
                706,
            ),
        );

        $this->assertFalse($result, 'Nothing bills against a null variant, and nothing writes one either.');
        $this->assertSame([], \CartShiftFcModelStore::all('Subscription'));
        $this->assertLogged(MigrationErrorCode::SubscriptionRequiredReferenceMissing);
    }

    /**
     * The belt-and-braces half: a line item with no product ID at all clears
     * missingProductReference() — both of its checks are gated on
     * `$wcProductId > 0` — and the mapper then resolves neither reference. The
     * refusal has to read what the mapper produced, not what WooCommerce said.
     */
    public function testASubscriptionThatResolvesToNoVariantAtAllIsBlocked(): void
    {
        $this->mapEntity('customer', [1]);
        $this->mapEntity('order', [707]);

        $result = $this->subscriptionMigrator()->processRecord(
            new \CartShiftTestSubscription(
                608,
                [new \CartShiftTestOrderItem(0, 0, 'Mystery item')],
                1,
                'active',
                '',
                null,
                707,
            ),
        );

        $this->assertFalse($result);
        $this->assertSame([], \CartShiftFcModelStore::all('Subscription'));
        $this->assertLogged(MigrationErrorCode::SubscriptionRequiredReferenceMissing);
    }

    /**
     * A subscription WooCommerce had already cancelled is history rather than a
     * live instruction, and it used to be allowed through on that reasoning:
     * nothing renews a cancelled subscription, so a null variant could not hurt
     * anybody.
     *
     * It still cannot bill anybody. It also still cannot be stored:
     * `fct_subscriptions.variation_id` is NOT NULL whatever the status column
     * says, and a terminal record with a null variant is a row MySQL refuses —
     * or, with a permissive SQL mode, silently zeroes. History is admitted in
     * its own terminal status once its references resolve, and not before.
     */
    public function testACancelledSubscriptionWithNoVariantIsBlockedToo(): void
    {
        $this->mapEntity('customer', [1]);
        $this->mapEntity('order', [708]);

        $result = $this->subscriptionMigrator()->processRecord(
            new \CartShiftTestSubscription(
                609,
                [new \CartShiftTestOrderItem(0, 0, 'Mystery item')],
                1,
                'cancelled',
                '',
                null,
                708,
            ),
        );

        $this->assertFalse($result, 'Terminal is not an exemption from the schema.');
        $this->assertSame([], \CartShiftFcModelStore::all('Subscription'));
        $this->assertLogged(MigrationErrorCode::SubscriptionRequiredReferenceMissing);
    }

    public function testACancelledSubscriptionWhoseReferencesResolveIsWrittenTerminal(): void
    {
        $this->mapEntity('customer', [1]);
        $this->mapEntity('order', [709]);
        $this->mapEntity('product', [101]);
        $this->mapEntity('variation', [101]);

        $this->subscriptionMigrator()->processRecord(
            new \CartShiftTestSubscription(
                611,
                [new \CartShiftTestOrderItem(101, 0, 'Monthly Coffee')],
                1,
                'cancelled',
                '',
                null,
                709,
            ),
        );

        $subscriptions = \CartShiftFcModelStore::all('Subscription');
        $this->assertCount(1, $subscriptions, 'Complete history is not a hazard.');
        $this->assertSame('canceled', $subscriptions[0]->status);
    }

    /**
     * Everything else resolves, so the customer is provably the one thing
     * missing. A guest subscription — `_customer_user = 0`, which is 349 of the
     * 564 preserved Lapka records — still needs a FluentCart customer, and
     * CartShift files those under the guest namespace keyed by email. Without
     * one there is nobody to bill.
     */
    public function testASubscriptionWithNoCustomerIsStillSkippedAndCoded(): void
    {
        $this->mapEntity('order', [705]);
        $this->mapEntity('product', [101]);
        $this->mapEntity('variation', [101]);

        $result = $this->subscriptionMigrator()->processRecord(
            new \CartShiftTestSubscription(
                605,
                [new \CartShiftTestOrderItem(101, 0, 'Monthly Coffee')],
                0,
                'active',
                '',
                null,
                705,
            ),
        );

        $this->assertFalse($result, 'A subscription with nobody to bill is not recoverable.');
        $this->assertSame([], \CartShiftFcModelStore::all('Subscription'));
        $this->assertLogged(MigrationErrorCode::CustomerNotFound);
    }

    public function testASubscriptionWhoseCustomerWasNotMigratedIsStillSkippedAndCoded(): void
    {
        $this->mapEntity('order', [706]);
        $this->mapEntity('product', [101]);
        $this->mapEntity('variation', [101]);

        $result = $this->subscriptionMigrator()->processRecord(
            new \CartShiftTestSubscription(
                606,
                [new \CartShiftTestOrderItem(101, 0, 'Monthly Coffee')],
                9,
                'active',
                '',
                null,
                706,
            ),
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

        foreach (['id' => $id, 'status' => 'completed'] + $properties as $property => $value) {
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

            // The target catalogue, stated rather than derived from the map:
            // a simple product's variant carries the product's own ID on both
            // sides, which is what ProductMigrator and MappingPromoter write.
            // Said separately because a mapping row is not a catalogue row, and
            // the ownership gate exists to stop treating them as one.
            if ($entityType === 'variation') {
                cartshift_test_own_variation($wcId + 10_000, $wcId + 10_000);
            }
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

    /**
     * The message on the first log row carrying this code, or null.
     */
    private function firstMessageFor(MigrationErrorCode $code): ?string
    {
        $row = $this->firstRowFor($code);

        return $row === null ? null : (string) ($row['message'] ?? '');
    }

    /**
     * The WooCommerce ID the first log row carrying this code was filed under.
     */
    private function firstLoggedWcIdFor(MigrationErrorCode $code): ?int
    {
        $row = $this->firstRowFor($code);

        return $row === null ? null : (int) ($row['wc_id'] ?? 0);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function firstRowFor(MigrationErrorCode $code): ?array
    {
        foreach ($GLOBALS['_cartshift_test_queries'] ?? [] as $query) {
            if (($query[0] ?? '') !== 'insert') {
                continue;
            }

            if ((string) ($query[2][MigrationLogRepository::CODE_COLUMN] ?? '') === $code->value) {
                return $query[2];
            }
        }

        return null;
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

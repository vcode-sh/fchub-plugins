<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Migrator;

use CartShift\Migrator\ProductMigrator;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\Enums\MigrationErrorCode;
use CartShift\Tests\Unit\PluginTestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

require_once dirname(__DIR__, 2) . '/stubs/ProductMigratorStubs.php';

/**
 * The catalogue half of the cadence hazard.
 *
 * `VariationMapper` writes a FluentCart variation's `repeat_interval` through
 * `FcBillingInterval::fromWooCommerce()`, which collapses: `week/2` becomes
 * weekly, `year/2` yearly, `month/2` and `month/12` monthly. On a catalogue row
 * that is a wrong number the owner can edit — but the owner is not told, and
 * the product they then sell from claims a contract the WooCommerce one never
 * offered.
 *
 * The subscription writer is unaffected either way: `SubscriptionAssessor`
 * refuses an unrepresentable cadence whatever the catalogue ended up saying, so
 * no customer is billed on the wrong schedule. This is about not shipping a
 * product that lies.
 *
 * Every test here is process-isolated. `WC_Subscriptions_Product` cannot be
 * undeclared once loaded, and its presence flips `VariationMapper
 * ::isSubscription()` for every later test in the run.
 */
final class ProductMigratorSubscriptionCadenceTest extends PluginTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['_cartshift_test_get_var_callback'] = static fn (string $query): null => null;
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testASubscriptionProductWithAnUnrepresentableCadenceIsNotMigrated(): void
    {
        require_once dirname(__DIR__, 2) . '/stubs/WcSubscriptionsProductStub.php';

        $result = $this->migrator()->processRecord($this->subscriptionProduct(71, 'Two-monthly box', 'month', 2));

        $this->assertFalse($result, 'Creating it would write "monthly", which is a different contract.');
        $this->assertTrue($this->logged(MigrationErrorCode::SubscriptionUnsupportedBillingCadence));
    }

    /**
     * The preview has to refuse what the run refuses, or an owner discovers the
     * gap only once the run has finished.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheDryRunRefusesItToo(): void
    {
        require_once dirname(__DIR__, 2) . '/stubs/WcSubscriptionsProductStub.php';

        $this->assertFalse(
            $this->migrator()->validateRecord($this->subscriptionProduct(72, 'Biennial box', 'year', 2)),
        );
        $this->assertTrue($this->logged(MigrationErrorCode::SubscriptionUnsupportedBillingCadence));
    }

    /**
     * A gate that refused every subscription product would be a different bug
     * in the same coat.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testASupportedCadenceIsStillMigrated(): void
    {
        require_once dirname(__DIR__, 2) . '/stubs/WcSubscriptionsProductStub.php';

        $this->assertFalse(
            $this->logged(MigrationErrorCode::SubscriptionUnsupportedBillingCadence),
            'Nothing is logged before the run.',
        );

        $this->migrator()->validateRecord($this->subscriptionProduct(73, 'Quarterly box', 'month', 3));

        $this->assertFalse($this->logged(MigrationErrorCode::SubscriptionUnsupportedBillingCadence));
    }

    /**
     * WooCommerce stores the schedule per variation, so a parent reading
     * `month/1` can still carry a `month/2` child. Checking only the parent
     * would let exactly the product this gate exists for through.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAVariableSubscriptionIsRefusedForAChildsCadence(): void
    {
        require_once dirname(__DIR__, 2) . '/stubs/WcSubscriptionsProductStub.php';

        $child = $this->subscriptionProduct(75, 'Every other month', 'month', 2);
        $GLOBALS['_cartshift_test_wc_products'][75] = $child;

        $parent = $this->subscriptionProduct(74, 'Boxes', 'month', 1);
        (new \ReflectionProperty(\WC_Product::class, 'type'))->setValue($parent, 'variable-subscription');
        (new \ReflectionProperty(\WC_Product::class, 'children'))->setValue($parent, [75]);

        $this->assertFalse($this->migrator()->processRecord($parent));
        $this->assertTrue($this->logged(MigrationErrorCode::SubscriptionUnsupportedBillingCadence));
    }

    /**
     * What happens to the ORDERS that sold it.
     *
     * Refusing the product widened the gate, and a widened gate has a
     * downstream: every historical order containing that product now has a line
     * whose `product_id` and `variation_id` cannot resolve. That is a real cost
     * and it is worth being explicit about, because the alternative reading —
     * "we skipped a product, orders are somebody else's problem" — is how a
     * shop discovers its revenue reporting is missing a cohort.
     *
     * The answer is CartShift's existing one for any unmigrated product and is
     * deliberately not special-cased: the order migrates, the line keeps its
     * name and its money, the product link is what is lost, and it is coded
     * `product_link_missing` so the owner can find every affected order. The
     * money is the part that must not move.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAnOrderSellingARefusedProductStillMigratesWithItsMoneyIntact(): void
    {
        require_once dirname(__DIR__, 2) . '/stubs/WcSubscriptionsProductStub.php';
        require_once dirname(__DIR__, 2) . '/stubs/EntityMigratorStubs.php';
        require_once dirname(__DIR__, 2) . '/stubs/FluentCartModelStubs.php';

        \CartShiftFcModelStore::install();

        $originalWpdb    = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = new \CartShiftTestWpdb();

        $GLOBALS['_cartshift_test_id_map'] = [];
        $GLOBALS['_cartshift_test_get_var_callback'] = cartshift_test_id_map_reader();

        // The product is refused for its cadence, so nothing maps it.
        $this->assertFalse(
            $this->migrator()->processRecord($this->subscriptionProduct(77, 'Two-monthly box', 'month', 2)),
        );

        $order = new \WC_Order();

        foreach ([
            'id'              => 5077,
            'status'          => 'completed',
            'billing_email'   => 'ada@example.invalid',
            'billing_country' => 'GB',
            'currency'        => 'GBP',
            'total'           => '38.00',
            'customer_id'     => 0,
            'items'           => [new \CartShiftTestOrderItem(77, 0, 'Two-monthly box')],
        ] as $property => $value) {
            (new \ReflectionProperty(\WC_Order::class, $property))->setValue($order, $value);
        }

        (new \CartShift\Migrator\OrderMigrator(
            new IdMapRepository(),
            new MigrationLogRepository(),
            new MigrationState(),
        ))->processRecord($order);

        $items = \CartShiftFcModelStore::all('OrderItem');

        $this->assertCount(1, $items, 'The order is not dropped because its product was.');
        $this->assertSame(0, $items[0]->post_id, 'The product link is what is lost, and only that.');
        $this->assertSame('Two-monthly box', $items[0]->post_title);
        $this->assertTrue(
            $this->logged(MigrationErrorCode::ProductLinkMissing),
            'The owner has to be able to find every order affected by the refusal.',
        );

        if ($originalWpdb !== null) {
            $GLOBALS['wpdb'] = $originalWpdb;
        }
    }

    /**
     * And a one-time product has no cadence to be wrong about, so nothing here
     * touches the ordinary catalogue path.
     */
    public function testAnOrdinaryProductIsUnaffected(): void
    {
        $product = new \WC_Product();
        (new \ReflectionProperty(\WC_Product::class, 'id'))->setValue($product, 76);
        (new \ReflectionProperty(\WC_Product::class, 'name'))->setValue($product, 'A mug');

        $this->migrator()->validateRecord($product);

        $this->assertFalse($this->logged(MigrationErrorCode::SubscriptionUnsupportedBillingCadence));
    }

    // ── helpers ─────────────────────────────────────────────

    private function migrator(): ProductMigrator
    {
        return new ProductMigrator(
            new IdMapRepository(),
            new MigrationLogRepository(),
            new MigrationState(),
        );
    }

    private function subscriptionProduct(int $id, string $name, string $period, int $interval): \WC_Product
    {
        $product = new \WC_Product();

        (new \ReflectionProperty(\WC_Product::class, 'id'))->setValue($product, $id);
        (new \ReflectionProperty(\WC_Product::class, 'name'))->setValue($product, $name);
        (new \ReflectionProperty(\WC_Product::class, 'meta'))->setValue($product, [
            '_subscription_period'          => $period,
            '_subscription_period_interval' => (string) $interval,
        ]);

        return $product;
    }

    private function logged(MigrationErrorCode $code): bool
    {
        foreach ($GLOBALS['_cartshift_test_queries'] ?? [] as $query) {
            if (($query[0] ?? '') !== 'insert') {
                continue;
            }

            if ((string) ($query[2][MigrationLogRepository::CODE_COLUMN] ?? '') === $code->value) {
                return true;
            }
        }

        return false;
    }
}

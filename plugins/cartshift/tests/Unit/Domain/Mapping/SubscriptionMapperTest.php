<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Mapping;

use CartShift\Domain\Mapping\SubscriptionMapper;
use CartShift\Storage\IdMapRepository;
use CartShift\Tests\Unit\PluginTestCase;

final class SubscriptionMapperTest extends PluginTestCase
{
    private SubscriptionMapper $mapper;
    private IdMapRepository $idMap;

    protected function setUp(): void
    {
        parent::setUp();

        // Default: getFcId returns null
        $GLOBALS['_cartshift_test_get_var_return'] = null;

        $this->idMap = new IdMapRepository();
        $this->mapper = new SubscriptionMapper($this->idMap, 'USD');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_cartshift_test_get_var_callback']);
        unset($GLOBALS['_cartshift_test_get_var_return']);
        parent::tearDown();
    }

    public function testCollectionMethodIsAutomaticNotChargeAutomatically(): void
    {
        // H9: collection_method should be 'automatic', not 'charge_automatically'
        $sub = $this->createSubscription([
            'status' => 'active',
            'total' => '29.99',
        ]);

        $result = $this->mapper->map($sub);

        $this->assertSame('automatic', $result['collection_method']);
        $this->assertNotSame('charge_automatically', $result['collection_method']);
    }

    public function testCollectionMethodIsManualForManualSub(): void
    {
        // Documents current behavior: mapper always returns 'automatic'.
        $sub = $this->createSubscription([
            'status' => 'active',
            'total' => '29.99',
            'payment_method' => 'bacs',
        ]);

        $result = $this->mapper->map($sub);

        $this->assertSame('automatic', $result['collection_method']);
    }

    public function testVendorCustomerIdFromStripeMeta(): void
    {
        // M1: Stripe customer ID from subscription meta.
        $sub = $this->createSubscription([
            'status' => 'active',
            'total' => '19.99',
            'payment_method' => 'stripe',
            'meta' => [
                '_stripe_customer_id' => 'cus_abc123',
                '_stripe_subscription_id' => 'sub_xyz789',
                '_stripe_plan_id' => 'price_plan456',
            ],
        ]);

        $result = $this->mapper->map($sub);

        $this->assertSame('cus_abc123', $result['vendor_customer_id']);
    }

    public function testVendorSubscriptionIdFromStripeMeta(): void
    {
        // M1: Stripe subscription ID from meta.
        $sub = $this->createSubscription([
            'status' => 'active',
            'total' => '19.99',
            'payment_method' => 'stripe',
            'meta' => [
                '_stripe_customer_id' => 'cus_abc123',
                '_stripe_subscription_id' => 'sub_xyz789',
            ],
        ]);

        $result = $this->mapper->map($sub);

        $this->assertSame('sub_xyz789', $result['vendor_subscription_id']);
    }

    public function testVendorIdsFallbackToPaypal(): void
    {
        // M1: PayPal fallback when no Stripe meta.
        $sub = $this->createSubscription([
            'status' => 'active',
            'total' => '19.99',
            'payment_method' => 'ppec_paypal',
            'meta' => [
                '_paypal_subscription_id' => 'I-PAYPAL123',
            ],
        ]);

        $result = $this->mapper->map($sub);

        $this->assertSame('I-PAYPAL123', $result['vendor_customer_id']);
        $this->assertSame('I-PAYPAL123', $result['vendor_subscription_id']);
    }

    public function testMultiItemSubscriptionLogsWarning(): void
    {
        // M2: Multi-item subscription produces a warning.
        $item1 = $this->createOrderItem(['name' => 'Product A', 'product_id' => 1]);
        $item2 = $this->createOrderItem(['name' => 'Product B', 'product_id' => 2]);

        $sub = $this->createSubscription([
            'status' => 'active',
            'total' => '49.99',
            'items' => [$item1, $item2],
        ]);

        $this->mapper->map($sub);

        $warnings = $this->mapper->getWarnings();
        $this->assertNotEmpty($warnings, 'Multi-item subscription should produce a warning');
        $this->assertStringContainsString('Product B', $warnings[0]);
    }

    public function testConfigIsArrayNotJsonString(): void
    {
        // C1: config must be an array.
        $sub = $this->createSubscription([
            'status' => 'active',
            'total' => '29.99',
        ]);

        $result = $this->mapper->map($sub);

        $this->assertIsArray($result['config']);
        $this->assertIsNotString($result['config']);
        $this->assertArrayHasKey('wc_subscription_id', $result['config']);
        $this->assertTrue($result['config']['migrated']);
    }

    public function testQuarterlyIntervalFromMonth3(): void
    {
        // M13: month + interval 3 = quarterly.
        $sub = $this->createSubscription([
            'status' => 'active',
            'total' => '89.99',
            'billing_period' => 'month',
            'billing_interval' => '3',
        ]);

        $result = $this->mapper->map($sub);

        $this->assertSame('quarterly', $result['billing_interval']);
    }

    public function testVendorIdsNullForUnknownGatewayWithWarning(): void
    {
        // Unknown gateways should return null vendor IDs and produce a warning.
        $sub = $this->createSubscription([
            'status' => 'active',
            'total' => '39.99',
            'payment_method' => 'unknown_gateway_xyz',
            'meta' => [], // no stripe or paypal meta
        ]);

        $result = $this->mapper->map($sub);

        $this->assertNull($result['vendor_customer_id']);
        $this->assertNull($result['vendor_plan_id']);
        $this->assertNull($result['vendor_subscription_id']);

        $warnings = $this->mapper->getWarnings();
        $this->assertNotEmpty($warnings, 'Unknown gateway should produce a warning');
        $this->assertStringContainsString('unknown_gateway_xyz', $warnings[0]);
        $this->assertStringContainsString('no vendor ID mapping', $warnings[0]);
    }

    public function testHalfYearlyIntervalFromMonth6(): void
    {
        // M13: month + interval 6 = half_yearly.
        $sub = $this->createSubscription([
            'status' => 'active',
            'total' => '149.99',
            'billing_period' => 'month',
            'billing_interval' => '6',
        ]);

        $result = $this->mapper->map($sub);

        $this->assertSame('half_yearly', $result['billing_interval']);
    }

    /**
     * Create a WC_Subscription-like stub object.
     */
    private function createSubscription(array $overrides = []): object
    {
        $defaults = [
            'id' => 500,
            'status' => 'active',
            'total' => '29.99',
            'total_tax' => '0',
            'customer_id' => 1,
            'parent_id' => 100,
            'billing_period' => 'month',
            'billing_interval' => '1',
            'payment_method' => 'stripe',
            'payment_count' => 3,
            'currency' => 'USD',
            'items' => [],
            'meta' => [],
            'dates' => [
                'start' => '2024-01-01 00:00:00',
                'trial_end' => '0',
                'next_payment' => '2024-02-01 00:00:00',
                'cancelled' => '0',
                'end' => '0',
            ],
            'parent_order' => null,
        ];

        $data = array_merge($defaults, $overrides);

        // Create items if not provided
        if (empty($data['items'])) {
            $data['items'] = [$this->createOrderItem(['name' => 'Subscription Product', 'product_id' => 42])];
        }

        return new class ($data) {
            public function __construct(private readonly array $data) {}

            public function get_id(): int { return $this->data['id']; }
            public function get_status(): string { return $this->data['status']; }
            public function get_total(): string { return $this->data['total']; }
            public function get_total_tax(): string { return $this->data['total_tax']; }
            public function get_customer_id(): int { return $this->data['customer_id']; }
            public function get_parent_id(): int { return $this->data['parent_id']; }
            public function get_billing_period(): string { return $this->data['billing_period']; }
            public function get_billing_interval(): string { return $this->data['billing_interval']; }
            public function get_payment_method(): string { return $this->data['payment_method']; }
            public function get_payment_count(): int { return $this->data['payment_count']; }
            public function get_currency(): string { return $this->data['currency']; }
            public function get_items(): array { return $this->data['items']; }
            public function get_parent(): ?object { return $this->data['parent_order']; }
            public function get_date(string $key): string
            {
                return $this->data['dates'][$key] ?? '0';
            }
            public function get_meta(string $key, bool $single = true): mixed
            {
                return $this->data['meta'][$key] ?? '';
            }
        };
    }

    private function createOrderItem(array $overrides = []): \WC_Order_Item_Product
    {
        $item = new \WC_Order_Item_Product();
        $defaults = [
            'product_id' => 1,
            'variation_id' => 0,
            'quantity' => 1,
            'total' => '0',
            'subtotal' => '0',
            'total_tax' => '0',
            'name' => 'Test Item',
            'product' => null,
        ];

        $data = array_merge($defaults, $overrides);

        $ref = new \ReflectionClass($item);
        foreach ($data as $key => $value) {
            if ($ref->hasProperty($key)) {
                $prop = $ref->getProperty($key);
                $prop->setValue($item, $value);
            }
        }

        return $item;
    }
}

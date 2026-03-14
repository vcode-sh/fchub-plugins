<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Mapping;

use CartShift\Domain\Mapping\OrderMapper;
use CartShift\Storage\IdMapRepository;
use CartShift\Support\Enums\FcOrderType;
use CartShift\Tests\Unit\PluginTestCase;

final class OrderMapperTest extends PluginTestCase
{
    private OrderMapper $mapper;
    private IdMapRepository $idMap;

    protected function setUp(): void
    {
        parent::setUp();

        // Default: getFcId returns null (no mapping found)
        $GLOBALS['_cartshift_test_get_var_return'] = null;

        $this->idMap = new IdMapRepository();
        $this->mapper = new OrderMapper($this->idMap, 'USD');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_cartshift_test_get_var_callback']);
        unset($GLOBALS['_cartshift_test_get_var_return']);
        parent::tearDown();
    }

    public function testMapReturnsPaymentTypeForNormalOrder(): void
    {
        $order = $this->createOrder(['status' => 'completed', 'total' => '50.00']);

        $result = $this->mapper->map($order);

        $this->assertSame(FcOrderType::Payment->value, $result['order']['type']);
    }

    public function testMapReturnsSubscriptionTypeForSubscriptionOrder(): void
    {
        // Without WCS functions defined, default to 'payment'.
        $order = $this->createOrder(['status' => 'completed', 'total' => '50.00']);

        $result = $this->mapper->map($order);

        $this->assertSame('payment', $result['order']['type']);
    }

    public function testMapNeverReturnsOnetimeOrRefundType(): void
    {
        // C3: type must never be 'onetime' or 'refund'.
        $wcStatuses = [
            'pending', 'processing', 'on-hold', 'completed',
            'cancelled', 'refunded', 'failed',
        ];

        foreach ($wcStatuses as $status) {
            $order = $this->createOrder(['status' => $status, 'total' => '100.00']);
            $result = $this->mapper->map($order);

            $type = $result['order']['type'];
            $this->assertNotSame('onetime', $type, "Status '{$status}' must not produce 'onetime' type");
            $this->assertNotSame('refund', $type, "Status '{$status}' must not produce 'refund' type");
            $this->assertContains($type, ['payment', 'subscription', 'renewal'],
                "Status '{$status}' produced unexpected type '{$type}'");
        }
    }

    public function testTaxBehaviorIsZeroWhenNoTax(): void
    {
        $order = $this->createOrder([
            'status' => 'completed',
            'total' => '50.00',
            'total_tax' => '0',
        ]);

        $result = $this->mapper->map($order);

        $this->assertSame(0, $result['order']['tax_behavior']);
    }

    public function testTaxBehaviorIsOneForExclusiveTax(): void
    {
        $order = $this->createOrder([
            'status' => 'completed',
            'total' => '60.00',
            'total_tax' => '10.00',
            'prices_include_tax' => false,
        ]);

        $result = $this->mapper->map($order);

        $this->assertSame(1, $result['order']['tax_behavior']);
    }

    public function testTaxBehaviorIsTwoForInclusiveTax(): void
    {
        $order = $this->createOrder([
            'status' => 'completed',
            'total' => '50.00',
            'total_tax' => '8.33',
            'prices_include_tax' => true,
        ]);

        $result = $this->mapper->map($order);

        $this->assertSame(2, $result['order']['tax_behavior']);
    }

    public function testUnitPriceFromSubtotalNotTotal(): void
    {
        // H8: unit_price from subtotal (before discount), not total (after discount).
        $item = $this->createOrderItem([
            'product_id' => 1,
            'quantity' => 2,
            'subtotal' => '40.00', // $20 each before discount
            'total' => '30.00',    // $15 each after discount
            'total_tax' => '0',
        ]);

        $order = $this->createOrder([
            'status' => 'completed',
            'total' => '30.00',
            'items' => [$item],
        ]);

        $result = $this->mapper->map($order);

        $this->assertNotEmpty($result['items']);
        // subtotal / qty = 4000 / 2 = 2000 cents ($20)
        $this->assertSame(2000, $result['items'][0]['unit_price']);
        $this->assertNotSame(1500, $result['items'][0]['unit_price']);
    }

    public function testResolveCustomerIdUsesEmailNotCrc32(): void
    {
        // C6: customer lookup uses email, not crc32.
        $GLOBALS['_cartshift_test_get_var_callback'] = function (string $query): ?string {
            // Guest customer lookup by email
            if (str_contains($query, 'guest_customer') && str_contains($query, 'customer@example.com')) {
                return '777';
            }
            return null;
        };

        $order = $this->createOrder([
            'status' => 'completed',
            'total' => '50.00',
            'customer_id' => 0,
            'billing_email' => 'customer@example.com',
        ]);

        $result = $this->mapper->map($order);

        $this->assertSame(777, $result['order']['customer_id']);
    }

    public function testFreeCompletedOrderGetsTransaction(): void
    {
        // M8: Free completed orders get a transaction record.
        $order = $this->createOrder([
            'status' => 'completed',
            'total' => '0',
        ]);

        $result = $this->mapper->map($order);

        $this->assertNotNull($result['transaction'], 'Free completed order must have a transaction');
    }

    public function testFreeOrderTransactionHasZeroTotal(): void
    {
        // M8: Transaction total is zero for free orders.
        $order = $this->createOrder([
            'status' => 'completed',
            'total' => '0',
        ]);

        $result = $this->mapper->map($order);

        $this->assertNotNull($result['transaction']);
        $this->assertSame(0, $result['transaction']['total']);
        $this->assertSame('succeeded', $result['transaction']['status']);
    }

    public function testAddressMetaIsArrayNotJsonString(): void
    {
        $order = $this->createOrder([
            'status' => 'completed',
            'total' => '50.00',
            'billing_first_name' => 'John',
            'billing_last_name' => 'Doe',
            'billing_phone' => '+1234567890',
        ]);

        $result = $this->mapper->map($order);

        $this->assertNotEmpty($result['addresses']);
        $billingAddress = $result['addresses'][0];
        $this->assertSame('billing', $billingAddress['type']);
        $this->assertIsArray($billingAddress['meta']);
        $this->assertIsNotString($billingAddress['meta']);
    }

    public function testTransactionMetaIsArrayNotJsonString(): void
    {
        $order = $this->createOrder([
            'status' => 'completed',
            'total' => '50.00',
        ]);

        $result = $this->mapper->map($order);

        $this->assertNotNull($result['transaction']);
        $this->assertIsArray($result['transaction']['meta']);
        $this->assertIsNotString($result['transaction']['meta']);
        $this->assertArrayHasKey('wc_order_id', $result['transaction']['meta']);
    }

    public function testExchangeRateFromWcmlMeta(): void
    {
        // M12: Exchange rate from WCML meta.
        $order = $this->createOrder([
            'status' => 'completed',
            'total' => '50.00',
            'meta' => ['_wcml_order_currency_rate' => '1.25'],
        ]);

        $result = $this->mapper->map($order);

        $this->assertSame('1.25', $result['order']['rate']);
    }

    public function testExchangeRateDefaultsToOne(): void
    {
        // M12: No multi-currency meta defaults to '1'.
        $order = $this->createOrder([
            'status' => 'completed',
            'total' => '50.00',
        ]);

        $result = $this->mapper->map($order);

        $this->assertSame('1', $result['order']['rate']);
    }

    public function testShippingLinesIncludedInConfig(): void
    {
        // M9: Shipping method details preserved in config.shipping_lines.
        $shippingItem = new \WC_Order_Item_Shipping();
        $ref = new \ReflectionClass($shippingItem);
        $ref->getProperty('method_title')->setValue($shippingItem, 'Flat Rate');
        $ref->getProperty('total')->setValue($shippingItem, '10.00');
        $ref->getProperty('total_tax')->setValue($shippingItem, '2.00');

        $order = $this->createOrder([
            'status' => 'completed',
            'total' => '60.00',
            'shipping_total' => '10.00',
            'shipping_items' => [$shippingItem],
        ]);

        $result = $this->mapper->map($order);

        $this->assertArrayHasKey('shipping_lines', $result['order']['config']);
        $this->assertCount(1, $result['order']['config']['shipping_lines']);
        $this->assertSame('Flat Rate', $result['order']['config']['shipping_lines'][0]['method_title']);
        $this->assertSame(1000, $result['order']['config']['shipping_lines'][0]['total']);
        $this->assertSame(200, $result['order']['config']['shipping_lines'][0]['tax']);
    }

    public function testFeeLinesIncludedInConfig(): void
    {
        // Fee line items preserved in config.fee_lines.
        $feeItem = new \WC_Order_Item_Fee();
        $ref = new \ReflectionClass($feeItem);
        $ref->getProperty('name')->setValue($feeItem, 'Gift Wrapping');
        $ref->getProperty('total')->setValue($feeItem, '5.00');
        $ref->getProperty('total_tax')->setValue($feeItem, '1.00');

        $order = $this->createOrder([
            'status' => 'completed',
            'total' => '55.00',
            'fee_items' => [$feeItem],
        ]);

        $result = $this->mapper->map($order);

        $this->assertArrayHasKey('fee_lines', $result['order']['config']);
        $this->assertCount(1, $result['order']['config']['fee_lines']);
        $this->assertSame('Gift Wrapping', $result['order']['config']['fee_lines'][0]['name']);
        $this->assertSame(500, $result['order']['config']['fee_lines'][0]['total']);
        $this->assertSame(100, $result['order']['config']['fee_lines'][0]['tax']);
    }

    public function testItemMetaFiltersInternalKeys(): void
    {
        // Internal WC meta (prefixed with _) must be stripped from line_meta.
        $meta = [
            (object) ['key' => '_internal_key', 'value' => 'hidden'],
            (object) ['key' => '_qty', 'value' => '2'],
            (object) ['key' => 'Colour', 'value' => 'Red'],
        ];

        $item = $this->createOrderItem([
            'product_id' => 1,
            'quantity' => 1,
            'subtotal' => '20.00',
            'total' => '20.00',
            'total_tax' => '0',
            'meta_data' => $meta,
        ]);

        $order = $this->createOrder([
            'status' => 'completed',
            'total' => '20.00',
            'items' => [$item],
        ]);

        $result = $this->mapper->map($order);

        $this->assertNotEmpty($result['items']);
        $lineMeta = $result['items'][0]['line_meta'];
        $this->assertCount(1, $lineMeta, 'Only non-internal meta should remain');
        $this->assertSame('Colour', $lineMeta[0]['key']);
        $this->assertSame('Red', $lineMeta[0]['value']);
    }

    public function testItemMetaIncludesVisibleMeta(): void
    {
        // Visible meta data (no _ prefix) must be included in line_meta.
        $meta = [
            (object) ['key' => 'Size', 'value' => 'Large'],
            (object) ['key' => 'Gift Message', 'value' => 'Happy Birthday'],
        ];

        $item = $this->createOrderItem([
            'product_id' => 1,
            'quantity' => 1,
            'subtotal' => '30.00',
            'total' => '30.00',
            'total_tax' => '0',
            'meta_data' => $meta,
        ]);

        $order = $this->createOrder([
            'status' => 'completed',
            'total' => '30.00',
            'items' => [$item],
        ]);

        $result = $this->mapper->map($order);

        $lineMeta = $result['items'][0]['line_meta'];
        $this->assertCount(2, $lineMeta);
        $this->assertSame('Size', $lineMeta[0]['key']);
        $this->assertSame('Large', $lineMeta[0]['value']);
        $this->assertSame('Gift Message', $lineMeta[1]['key']);
        $this->assertSame('Happy Birthday', $lineMeta[1]['value']);
    }

    private function createOrder(array $overrides = []): \WC_Order
    {
        $order = new \WC_Order();
        $defaults = [
            'id' => 100,
            'status' => 'completed',
            'total' => '100.00',
            'subtotal' => '100.00',
            'total_tax' => '0',
            'shipping_total' => '0',
            'shipping_tax' => '0',
            'discount_total' => '0',
            'total_refunded' => '0',
            'billing_email' => 'test@example.com',
            'billing_first_name' => 'John',
            'billing_last_name' => 'Doe',
            'billing_address_1' => '123 Main St',
            'billing_city' => 'Anytown',
            'billing_state' => 'CA',
            'billing_postcode' => '90210',
            'billing_country' => 'US',
            'billing_phone' => '',
            'billing_company' => '',
            'shipping_first_name' => '',
            'shipping_last_name' => '',
            'shipping_company' => '',
            'payment_method' => 'stripe',
            'payment_method_title' => 'Credit Card',
            'transaction_id' => '',
            'customer_note' => '',
            'customer_ip_address' => '',
            'customer_id' => 0,
            'parent_id' => 0,
            'prices_include_tax' => false,
            'currency' => 'USD',
            'items' => [],
            'meta' => [],
        ];

        $data = array_merge($defaults, $overrides);

        $ref = new \ReflectionClass($order);
        foreach ($data as $key => $value) {
            if ($ref->hasProperty($key)) {
                $prop = $ref->getProperty($key);
                $prop->setValue($order, $value);
            }
        }

        return $order;
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

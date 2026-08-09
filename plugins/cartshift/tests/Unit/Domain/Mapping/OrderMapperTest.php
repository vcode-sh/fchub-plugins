<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Mapping;

use CartShift\Domain\Mapping\OrderMapper;
use CartShift\Storage\IdMapRepository;
use CartShift\Tests\Unit\PluginTestCase;

require_once __DIR__ . '/../../../stubs/MapperStubs.php';

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

    public function testMapAlwaysReturnsCheckoutType(): void
    {
        $order = $this->createOrder(['status' => 'completed', 'total' => '50.00']);

        $result = $this->mapper->map($order);

        $this->assertSame('checkout', $result['order']['type']);
    }

    public function testMapReturnsCheckoutTypeForAllStatuses(): void
    {
        $wcStatuses = [
            'pending', 'processing', 'on-hold', 'completed',
            'cancelled', 'refunded', 'failed',
        ];

        foreach ($wcStatuses as $status) {
            $order = $this->createOrder(['status' => $status, 'total' => '100.00']);
            $result = $this->mapper->map($order);

            $this->assertSame('checkout', $result['order']['type'],
                "Status '{$status}' must produce 'checkout' type");
        }
    }

    public function testMapIncludesInvoiceNo(): void
    {
        $order = $this->createOrder(['id' => 42, 'status' => 'completed', 'total' => '50.00']);

        $result = $this->mapper->map($order);

        $this->assertSame('WC-42', $result['order']['invoice_no']);
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

    // ──────────────────────────────────────────────
    // Adversarial FC schema alignment tests
    // ──────────────────────────────────────────────

    public function testOrderTypeIsCheckoutNotPayment(): void
    {
        // FC schema uses 'checkout' as the order type, NOT 'payment'.
        // This was a bug where WC's "payment" concept leaked into FC data.
        $order = $this->createOrder(['status' => 'completed', 'total' => '99.99']);

        $result = $this->mapper->map($order);

        $this->assertSame('checkout', $result['order']['type']);
        $this->assertNotSame('payment', $result['order']['type']);
        $this->assertNotSame('order', $result['order']['type']);
    }

    public function testInvoiceNoFormat(): void
    {
        // invoice_no must follow 'WC-{id}' pattern — not just the numeric ID.
        $order = $this->createOrder(['id' => 1337, 'status' => 'completed', 'total' => '50.00']);

        $result = $this->mapper->map($order);

        $this->assertSame('WC-1337', $result['order']['invoice_no']);
        $this->assertMatchesRegularExpression('/^WC-\d+$/', $result['order']['invoice_no']);
    }

    public function testModeIsLive(): void
    {
        // Migrated orders are real historical data — mode must always be 'live'.
        $order = $this->createOrder(['status' => 'completed', 'total' => '50.00']);

        $result = $this->mapper->map($order);

        $this->assertSame('live', $result['order']['mode']);
        $this->assertNotSame('test', $result['order']['mode']);
        $this->assertNotSame('sandbox', $result['order']['mode']);
    }

    public function testTransactionPaymentModeIsLive(): void
    {
        // Transaction payment_mode must also be 'live' for migrated orders.
        $order = $this->createOrder(['status' => 'completed', 'total' => '50.00']);

        $result = $this->mapper->map($order);

        $this->assertNotNull($result['transaction']);
        $this->assertSame('live', $result['transaction']['payment_mode']);
    }

    public function testConfigContainsMigratedFlag(): void
    {
        // config.migrated must be true so FC knows this is imported data.
        $order = $this->createOrder(['status' => 'completed', 'total' => '50.00']);

        $result = $this->mapper->map($order);

        $this->assertArrayHasKey('migrated', $result['order']['config']);
        $this->assertTrue($result['order']['config']['migrated']);
    }

    public function testConfigContainsWcOrderId(): void
    {
        // config.wc_order_id preserves traceability back to WooCommerce.
        $order = $this->createOrder(['id' => 999, 'status' => 'completed', 'total' => '50.00']);

        $result = $this->mapper->map($order);

        $this->assertArrayHasKey('wc_order_id', $result['order']['config']);
        $this->assertSame(999, $result['order']['config']['wc_order_id']);
    }

    public function testMoneyFieldsAreInCentsNotDecimals(): void
    {
        // All money fields must be integers (cents), not floats or string decimals.
        $order = $this->createOrder([
            'status' => 'completed',
            'total' => '123.45',
            'subtotal' => '100.00',
            'shipping_total' => '10.00',
            'total_tax' => '13.45',
            'discount_total' => '5.00',
        ]);

        $result = $this->mapper->map($order);

        $this->assertSame(12345, $result['order']['total_amount']);
        $this->assertSame(10000, $result['order']['subtotal']);
        $this->assertSame(1000, $result['order']['shipping_total']);
        $this->assertSame(1345, $result['order']['tax_total']);
        $this->assertSame(500, $result['order']['coupon_discount_total']);

        // Verify they are integers, not floats.
        $this->assertIsInt($result['order']['total_amount']);
        $this->assertIsInt($result['order']['subtotal']);
        $this->assertIsInt($result['order']['shipping_total']);
        $this->assertIsInt($result['order']['tax_total']);
    }

    public function testTotalPaidIsNetOfRefundsForCompletedOrders(): void
    {
        // total_paid = total - refunded for completed orders.
        $order = $this->createOrder([
            'status' => 'completed',
            'total' => '100.00',
            'total_refunded' => '25.00',
        ]);

        $result = $this->mapper->map($order);

        $this->assertSame(7500, $result['order']['total_paid']); // 10000 - 2500
    }

    public function testTotalPaidIsZeroForPendingOrders(): void
    {
        // Unpaid orders have total_paid = 0.
        $order = $this->createOrder([
            'status' => 'pending',
            'total' => '100.00',
        ]);

        $result = $this->mapper->map($order);

        $this->assertSame(0, $result['order']['total_paid']);
    }

    public function testFreePendingOrderHasNoTransaction(): void
    {
        // Free pending orders should NOT get a transaction (only completed/processing do).
        $order = $this->createOrder([
            'status' => 'pending',
            'total' => '0',
        ]);

        $result = $this->mapper->map($order);

        $this->assertNull($result['transaction']);
    }

    public function testFreeProcessingOrderGetsTransaction(): void
    {
        // Free processing orders DO get a transaction (like completed).
        $order = $this->createOrder([
            'status' => 'processing',
            'total' => '0',
        ]);

        $result = $this->mapper->map($order);

        $this->assertNotNull($result['transaction']);
        $this->assertSame('succeeded', $result['transaction']['status']);
        $this->assertSame(0, $result['transaction']['total']);
    }

    public function testOrderHasUuid(): void
    {
        // Every mapped order must have a non-empty uuid.
        $order = $this->createOrder(['status' => 'completed', 'total' => '50.00']);

        $result = $this->mapper->map($order);

        $this->assertArrayHasKey('uuid', $result['order']);
        $this->assertNotEmpty($result['order']['uuid']);
        // UUID v4 format
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $result['order']['uuid'],
        );
    }

    public function testShippingStatusDefaultsToUnshipped(): void
    {
        // FC expects shipping_status to be 'unshipped' by default.
        $order = $this->createOrder(['status' => 'completed', 'total' => '50.00']);

        $result = $this->mapper->map($order);

        $this->assertSame('unshipped', $result['order']['shipping_status']);
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

    // ──────────────────────────────────────────────
    // Timestamps — every fct_* column is UTC
    // ──────────────────────────────────────────────

    public function testOrderTimestampsAreUtcNotSiteLocal(): void
    {
        // Site at UTC+2. FluentCart's own importer fills fct_orders.created_at from
        // wc_orders.date_created_gmt (OrderMigrationService.php:225), so UTC it is.
        $order = $this->createOrder([
            'status' => 'completed',
            'total' => '50.00',
            'date_created' => cartshift_test_wc_date('2024-01-15 10:30:00', 2),
            'date_completed' => cartshift_test_wc_date('2024-01-16 08:00:00', 2),
            'date_paid' => cartshift_test_wc_date('2024-01-15 11:00:00', 2),
        ]);

        $result = $this->mapper->map($order);

        $this->assertSame('2024-01-15 10:30:00', $result['order']['created_at']);
        $this->assertSame('2024-01-16 08:00:00', $result['order']['completed_at']);
        $this->assertSame('2024-01-15 11:00:00', $result['transaction']['created_at']);
    }

    public function testOrderTimestampsHandleNegativeOffset(): void
    {
        $order = $this->createOrder([
            'status' => 'completed',
            'total' => '50.00',
            'date_created' => cartshift_test_wc_date('2024-01-15 03:00:00', -5),
        ]);

        $result = $this->mapper->map($order);

        // Site-local would have been 2024-01-14 22:00:00 — the previous day.
        $this->assertSame('2024-01-15 03:00:00', $result['order']['created_at']);
    }

    public function testLineItemCreatedAtIsUtc(): void
    {
        $order = $this->createOrder([
            'status' => 'completed',
            'total' => '50.00',
            'date_created' => cartshift_test_wc_date('2024-01-15 10:30:00', 2),
            'items' => [
                $this->createOrderItem([
                    'product_id' => 1,
                    'quantity' => 1,
                    'total' => '50.00',
                    'subtotal' => '50.00',
                ]),
            ],
        ]);

        $result = $this->mapper->map($order);

        $this->assertNotEmpty($result['items']);
        $this->assertSame('2024-01-15 10:30:00', $result['items'][0]['created_at']);
    }

    public function testTransactionFallsBackToCreatedAtWhenUnpaid(): void
    {
        // No date_paid — the transaction timestamp falls back to the order's created_at,
        // and the fallback must be UTC too rather than swapping conventions mid-column.
        $order = $this->createOrder([
            'status' => 'completed',
            'total' => '50.00',
            'date_created' => cartshift_test_wc_date('2024-01-15 10:30:00', 2),
            'date_paid' => null,
        ]);

        $result = $this->mapper->map($order);

        $this->assertSame('2024-01-15 10:30:00', $result['transaction']['created_at']);
        $this->assertSame($result['order']['created_at'], $result['transaction']['created_at']);
    }

    public function testCompletedAtIsNullWhenOrderNeverCompleted(): void
    {
        $order = $this->createOrder([
            'status' => 'pending',
            'total' => '50.00',
            'date_created' => cartshift_test_wc_date('2024-01-15 10:30:00', 2),
            'date_completed' => null,
        ]);

        $result = $this->mapper->map($order);

        $this->assertNull($result['order']['completed_at']);
    }

    public function testMissingDateCreatedFallsBackToUtcNow(): void
    {
        $order = $this->createOrder([
            'status' => 'completed',
            'total' => '50.00',
            'date_created' => null,
        ]);

        $result = $this->mapper->map($order);

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $result['order']['created_at'],
        );
        // Within a minute of "now" in UTC.
        $this->assertLessThan(
            60,
            abs(strtotime($result['order']['created_at'] . ' UTC') - time()),
        );
    }

    /**
     * The Polish tax ID is not decoration. fchub-fakturownia decides business
     * versus consumer invoice purely on whether it finds one at
     * `other_data.nip` on the billing address (app/Handler/InvoiceHandler.php:124),
     * and Polish B2B invoicing legally requires the number — so an order that
     * arrives without it can never be invoiced to a company.
     */
    public function testTheBillingNipTravelsToTheBillingAddressMeta(): void
    {
        $order = $this->createOrder(['meta' => ['_billing_nip' => '5291831115']]);

        $billing = $this->billingAddress($this->mapper->map($order));

        $this->assertSame('5291831115', $billing['meta']['other_data']['nip']);
    }

    public function testAnOrderWithNoNipGetsNoNipKeyAtAll(): void
    {
        $order = $this->createOrder(['meta' => []]);

        $billing = $this->billingAddress($this->mapper->map($order));

        $this->assertArrayNotHasKey('nip', $billing['meta']['other_data'] ?? []);
    }

    public function testAnEmptyNipIsNotCarriedAcrossAsAnEmptyString(): void
    {
        $order = $this->createOrder(['meta' => ['_billing_nip' => '  ']]);

        $billing = $this->billingAddress($this->mapper->map($order));

        $this->assertArrayNotHasKey('nip', $billing['meta']['other_data'] ?? []);
    }

    public function testTheNipSitsAlongsidePhoneAndCompanyRatherThanReplacingThem(): void
    {
        $order = $this->createOrder([
            'billing_phone'   => '+48 500 100 200',
            'billing_company' => 'Analytical Engines sp. z o.o.',
            'meta'            => ['_billing_nip' => '5291831115'],
        ]);

        $otherData = $this->billingAddress($this->mapper->map($order))['meta']['other_data'];

        $this->assertSame('+48 500 100 200', $otherData['phone']);
        $this->assertSame('Analytical Engines sp. z o.o.', $otherData['company_name']);
        $this->assertSame('5291831115', $otherData['nip']);
    }

    public function testTheShippingAddressIsNotGivenANip(): void
    {
        $order = $this->createOrder([
            'shipping_first_name' => 'John',
            'shipping_address_1'  => '123 Main St',
            'shipping_country'    => 'PL',
            'meta'                => ['_billing_nip' => '5291831115'],
        ]);

        $shipping = null;
        foreach ($this->mapper->map($order)['addresses'] as $address) {
            if ($address['type'] === 'shipping') {
                $shipping = $address;
            }
        }

        $this->assertNotNull($shipping);
        $this->assertArrayNotHasKey('nip', $shipping['meta']['other_data'] ?? []);
    }

    // ──────────────────────────────────────────────
    // A line item that found its product but not its variant
    // ──────────────────────────────────────────────

    /**
     * `object_id` falls back to 0, and that used to be completely silent — the
     * only warning in mapItems() fired on a missing *product*. FluentCart's
     * product reporting groups by `object_id` (ProductReportService), so a
     * zeroed line disappears from per-variant sales while the order detail page
     * goes on showing the right name and the right money.
     */
    public function testAnItemWhoseProductResolvesButVariationDoesNotIsWarnedAbout(): void
    {
        $this->resolveOnly(['product' => [101]]);

        $result = $this->mapper->map($this->orderWithItem(101, 0, 'Koszulka WordPress Developer'));

        $this->assertSame(0, $result['items'][0]['object_id'], 'The zeroed line is the thing being warned about.');
        $this->assertSame(
            [\CartShift\Support\Enums\MigrationErrorCode::VariationLinkMissing],
            array_column($this->mapper->getCodedWarnings(), 'code'),
        );
    }

    /**
     * The live playground data that reaches this: Woo order 119 carries two
     * `Koszulka WordPress Developer` lines with `_variation_id = 0` on a
     * *variable* product, so the variation lookup has nothing to key on and the
     * product-ID fallback misses too (a variable product's map is keyed by
     * variation).
     */
    public function testTheWarningNamesTheProductWhenTheLineCarriesNoVariationId(): void
    {
        $this->resolveOnly(['product' => [101]]);

        $this->mapper->map($this->orderWithItem(101, 0, 'Koszulka WordPress Developer'));

        $message = $this->mapper->getWarnings()[0];

        $this->assertStringContainsString('Koszulka WordPress Developer', $message);
        $this->assertStringContainsString('WC product 101', $message);
        $this->assertStringNotContainsString('WC variation 0', $message, 'Variation 0 is not something to go and look at.');
    }

    public function testTheWarningNamesTheVariationWhenTheLineHasOne(): void
    {
        $this->resolveOnly(['product' => [101]]);

        $this->mapper->map($this->orderWithItem(101, 105, 'Koszulka — XL'));

        $this->assertStringContainsString('WC variation 105', $this->mapper->getWarnings()[0]);
    }

    public function testAFullyResolvedItemWarnsAboutNothing(): void
    {
        $this->resolveOnly(['product' => [101], 'variation' => [101]]);

        $this->mapper->map($this->orderWithItem(101, 0, 'Kurs WordPress'));

        $this->assertSame([], $this->mapper->getCodedWarnings());
    }

    /**
     * An item whose product did not resolve either already has its own warning,
     * and a second one saying the variant is missing too would be noise — the
     * fix is the same fix.
     */
    public function testAnItemWithNoProductAtAllRaisesOnlyTheProductWarning(): void
    {
        $this->resolveOnly([]);

        $this->mapper->map($this->orderWithItem(101, 0, 'Retired thing'));

        $this->assertSame(
            [\CartShift\Support\Enums\MigrationErrorCode::ProductLinkMissing],
            array_column($this->mapper->getCodedWarnings(), 'code'),
        );
    }

    /**
     * Fee lines are written with post_id = 0 and object_id = 0 by design —
     * they are not products and never were. Warning about them would put a
     * false positive on every order carrying a surcharge.
     */
    public function testFeeLinesAreNotWarnedAbout(): void
    {
        $this->resolveOnly(['product' => [101], 'variation' => [101]]);

        $order = $this->orderWithItem(101, 0, 'Kurs WordPress');

        $fee = new \WC_Order_Item_Fee();
        $ref = new \ReflectionClass(\WC_Order::class);
        $ref->getProperty('fee_items')->setValue($order, [$fee]);

        $this->mapper->map($order);

        $this->assertSame([], $this->mapper->getCodedWarnings());
    }

    /**
     * Teach the ID map which keys resolve, and only those.
     *
     * @param array<string, list<int>> $byEntity entity type => WooCommerce IDs
     */
    private function resolveOnly(array $byEntity): void
    {
        $GLOBALS['_cartshift_test_get_var_callback'] = static function (string $query) use ($byEntity): int|null {
            if (preg_match("/entity_type = '([^']*)' AND wc_id = '([^']*)'/", $query, $matches) !== 1) {
                return null;
            }

            return in_array((int) $matches[2], $byEntity[$matches[1]] ?? [], true)
                ? (int) $matches[2] + 10_000
                : null;
        };
    }

    private function orderWithItem(int $productId, int $variationId, string $name): \WC_Order
    {
        return $this->createOrder([
            'id'     => 119,
            'status' => 'completed',
            'total'  => '50.00',
            'items'  => [$this->createOrderItem([
                'product_id'   => $productId,
                'variation_id' => $variationId,
                'name'         => $name,
                'quantity'     => 1,
                'subtotal'     => '50.00',
                'total'        => '50.00',
            ])],
        ]);
    }

    /**
     * The billing address out of a mapped order.
     *
     * @param array{addresses: array<int, array<string, mixed>>} $mapped
     *
     * @return array<string, mixed>
     */
    private function billingAddress(array $mapped): array
    {
        foreach ($mapped['addresses'] as $address) {
            if (($address['type'] ?? '') === 'billing') {
                return $address;
            }
        }

        $this->fail('The mapper produced no billing address.');
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

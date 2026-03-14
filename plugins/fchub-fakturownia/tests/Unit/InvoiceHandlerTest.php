<?php

declare(strict_types=1);

namespace FChubFakturownia\Tests\Unit;

use FChubFakturownia\API\FakturowniaAPI;
use FChubFakturownia\Handler\InvoiceHandler;
use FChubFakturownia\Tests\PluginTestCase;

/**
 * Tests for InvoiceHandler — covers BUG 1, 2, 3, 5, 7, 10, 12
 */
final class InvoiceHandlerTest extends PluginTestCase
{
    private array $lastApiPayload = [];

    private function createHandlerWithCapture(): InvoiceHandler
    {
        // Only set defaults if settings aren't already configured by the test
        if (empty($GLOBALS['_fchub_test_options']['_integration_api_fakturownia'])) {
            $this->setSettings();
        }

        // Capture the POST body sent to the API
        $this->mockApiHandler(function ($method, $url, $args) {
            if ($method === 'POST') {
                $this->lastApiPayload = json_decode($args['body'], true);
            }
            return [
                'response' => ['code' => 200],
                'body'     => json_encode([
                    'id'     => 777,
                    'number' => 'FV 1/2025',
                ]),
                'headers'  => [],
            ];
        });

        return new InvoiceHandler(new FakturowniaAPI('testfirma', 'test-token'));
    }

    // ──────────────────────────────────────────────────────────
    // BUG 1: B2B detection uses NIP presence, not checkbox state
    // ──────────────────────────────────────────────────────────

    public function testB2bInvoiceWhenNipIsPresent(): void
    {
        $handler = $this->createHandlerWithCapture();

        $order = $this->createOrder([
            'billing_address' => $this->createBillingAddress([
                'company_name' => 'ACME Sp. z o.o.',
                'meta' => [
                    'other_data' => [
                        'nip' => '5213017228',
                        // No 'wants_company_invoice' key at all — checkbox never persisted
                    ],
                ],
            ]),
        ]);

        $handler->createInvoice($order);

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        $this->assertTrue($invoice['buyer_company'], 'Should be B2B when NIP is present');
        $this->assertSame('5213017228', $invoice['buyer_tax_no']);
        $this->assertSame('ACME Sp. z o.o.', $invoice['buyer_name']);
    }

    public function testB2cInvoiceWhenNipIsEmpty(): void
    {
        $handler = $this->createHandlerWithCapture();

        $order = $this->createOrder([
            'billing_address' => $this->createBillingAddress([
                'meta' => ['other_data' => ['nip' => '']],
            ]),
        ]);

        $handler->createInvoice($order);

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        $this->assertFalse($invoice['buyer_company'], 'Should be B2C when NIP is empty');
        $this->assertArrayNotHasKey('buyer_tax_no', $invoice);
    }

    public function testB2cInvoiceWhenNoOtherData(): void
    {
        $handler = $this->createHandlerWithCapture();

        $order = $this->createOrder([
            'billing_address' => $this->createBillingAddress([
                'meta' => [],
            ]),
        ]);

        $handler->createInvoice($order);

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        $this->assertFalse($invoice['buyer_company']);
    }

    // ──────────────────────────────────────────────────────────
    // BUG 2: Null $billingAddress — no crash
    // ──────────────────────────────────────────────────────────

    public function testNullBillingAddressDoesNotCrash(): void
    {
        $handler = $this->createHandlerWithCapture();

        $order = $this->createOrder([
            'billing_address' => null,
            'customer'        => $this->createCustomer(),
        ]);

        // Should not throw TypeError
        $result = $handler->createInvoice($order);

        $this->assertArrayHasKey('id', $result);
        $invoice = $this->lastApiPayload['invoice'] ?? [];
        $this->assertFalse($invoice['buyer_company']);
        $this->assertSame('Jan', $invoice['buyer_first_name']);
        $this->assertSame('Kowalski', $invoice['buyer_last_name']);
        // No buyer_street, buyer_city etc. when no billing address
        $this->assertArrayNotHasKey('buyer_street', $invoice);
        $this->assertArrayNotHasKey('buyer_city', $invoice);
    }

    public function testNullBillingAddressNullCustomer(): void
    {
        $handler = $this->createHandlerWithCapture();

        $order = $this->createOrder([
            'billing_address' => null,
            'customer'        => null,
        ]);

        $result = $handler->createInvoice($order);

        $this->assertArrayHasKey('id', $result);
        $invoice = $this->lastApiPayload['invoice'] ?? [];
        $this->assertSame('-', $invoice['buyer_first_name']);
        $this->assertSame('-', $invoice['buyer_last_name']);
        $this->assertArrayNotHasKey('buyer_email', $invoice);
    }

    // ──────────────────────────────────────────────────────────
    // BUG 3: Null $customer — null-safe property access
    // ──────────────────────────────────────────────────────────

    public function testNullCustomerDoesNotCrash(): void
    {
        $handler = $this->createHandlerWithCapture();

        $order = $this->createOrder([
            'customer' => null,
        ]);

        $result = $handler->createInvoice($order);

        $this->assertArrayHasKey('id', $result);
        $invoice = $this->lastApiPayload['invoice'] ?? [];
        // Should fall back to billing address names
        $this->assertSame('Jan', $invoice['buyer_first_name']);
        $this->assertSame('Kowalski', $invoice['buyer_last_name']);
        // No email from null customer
        $this->assertArrayNotHasKey('buyer_email', $invoice);
    }

    // ──────────────────────────────────────────────────────────
    // BUG 5: Tax rate uses line_total (post-discount) as denominator
    // ──────────────────────────────────────────────────────────

    public function testTaxRateCalculatedFromDiscountedAmount(): void
    {
        $handler = $this->createHandlerWithCapture();

        // Product: 10000¢ subtotal, 5000¢ discount → 5000¢ line_total, 23% tax on 5000 = 1150¢
        $order = $this->createOrder([
            'order_items' => [
                $this->createOrderItem([
                    'subtotal'   => 10000,
                    'line_total' => 5000,
                    'tax_amount' => 1150,
                ]),
            ],
        ]);

        $handler->createInvoice($order);

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        $position = $invoice['positions'][0] ?? [];

        // Tax rate: 1150/5000 * 100 = 23% → should normalize to 23
        $this->assertSame(23, $position['tax']);
    }

    public function testTaxRateWithLargeDiscountNoLongerSnapsToWrongRate(): void
    {
        $handler = $this->createHandlerWithCapture();

        // Before fix: 1150/10000 = 11.5% → snapped to 8% (WRONG)
        // After fix:  1150/5000  = 23%  → correctly snaps to 23%
        $order = $this->createOrder([
            'order_items' => [
                $this->createOrderItem([
                    'subtotal'   => 10000,
                    'line_total' => 5000,
                    'tax_amount' => 1150,
                ]),
            ],
        ]);

        $handler->createInvoice($order);

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        $position = $invoice['positions'][0] ?? [];
        $this->assertNotSame(8, $position['tax'], 'Must NOT snap to 8% with old subtotal denominator');
        $this->assertSame(23, $position['tax']);
    }

    public function testTaxRateWithNoDiscount(): void
    {
        $handler = $this->createHandlerWithCapture();

        // No discount: subtotal = line_total = 10000, tax = 2300 → 23%
        $order = $this->createOrder([
            'order_items' => [
                $this->createOrderItem([
                    'subtotal'   => 10000,
                    'line_total' => 10000,
                    'tax_amount' => 2300,
                ]),
            ],
        ]);

        $handler->createInvoice($order);

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        $this->assertSame(23, $invoice['positions'][0]['tax']);
    }

    public function testFivePercentVatRate(): void
    {
        $handler = $this->createHandlerWithCapture();

        $order = $this->createOrder([
            'order_items' => [
                $this->createOrderItem([
                    'line_total' => 10000,
                    'tax_amount' => 500,
                ]),
            ],
        ]);

        $handler->createInvoice($order);

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        $this->assertSame(5, $invoice['positions'][0]['tax']);
    }

    public function testEightPercentVatRate(): void
    {
        $handler = $this->createHandlerWithCapture();

        $order = $this->createOrder([
            'order_items' => [
                $this->createOrderItem([
                    'line_total' => 10000,
                    'tax_amount' => 800,
                ]),
            ],
        ]);

        $handler->createInvoice($order);

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        $this->assertSame(8, $invoice['positions'][0]['tax']);
    }

    // ──────────────────────────────────────────────────────────
    // BUG 7: wp_date() instead of date()
    // ──────────────────────────────────────────────────────────

    public function testInvoiceDateUsesWordPressTimezone(): void
    {
        // Simulate a timezone where it's already the next day
        $GLOBALS['_fchub_test_wp_timezone'] = 'Pacific/Auckland'; // NZ = UTC+12/+13

        $handler = $this->createHandlerWithCapture();

        $order = $this->createOrder([
            'paid_at' => '2025-03-10 14:30:00',
        ]);

        $handler->createInvoice($order);

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        // The issue_date should be formatted via wp_date, not PHP date()
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $invoice['issue_date']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $invoice['sell_date']);
    }

    // ──────────────────────────────────────────────────────────
    // BUG 10: Single-word name fallback
    // ──────────────────────────────────────────────────────────

    public function testSingleWordNameUseDashForLastName(): void
    {
        $handler = $this->createHandlerWithCapture();

        $order = $this->createOrder([
            'billing_address' => $this->createBillingAddress([
                'first_name' => '',
                'last_name'  => '',
                'name'       => 'Madonna',
            ]),
            'customer' => $this->createCustomer([
                'first_name' => '',
                'last_name'  => '',
            ]),
        ]);

        $handler->createInvoice($order);

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        $this->assertSame('Madonna', $invoice['buyer_first_name']);
        $this->assertSame('-', $invoice['buyer_last_name'], 'Single name should use dash, not repeat first name');
    }

    public function testTwoWordNameSplitsCorrectly(): void
    {
        $handler = $this->createHandlerWithCapture();

        $order = $this->createOrder([
            'billing_address' => $this->createBillingAddress([
                'first_name' => '',
                'last_name'  => '',
                'name'       => 'Anna Nowak',
            ]),
            'customer' => $this->createCustomer([
                'first_name' => '',
                'last_name'  => '',
            ]),
        ]);

        $handler->createInvoice($order);

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        $this->assertSame('Anna', $invoice['buyer_first_name']);
        $this->assertSame('Nowak', $invoice['buyer_last_name']);
    }

    // ──────────────────────────────────────────────────────────
    // BUG 12: No post_title fallback
    // ──────────────────────────────────────────────────────────

    public function testItemWithNoTitleFallsBackToTranslation(): void
    {
        $handler = $this->createHandlerWithCapture();

        $order = $this->createOrder([
            'order_items' => [
                $this->createOrderItem(['title' => '']),
            ],
        ]);

        $handler->createInvoice($order);

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        $this->assertSame('Product', $invoice['positions'][0]['name']);
    }

    public function testItemWithTitleUsesTitle(): void
    {
        $handler = $this->createHandlerWithCapture();

        $order = $this->createOrder([
            'order_items' => [
                $this->createOrderItem(['title' => 'Premium Widget']),
            ],
        ]);

        $handler->createInvoice($order);

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        $this->assertSame('Premium Widget', $invoice['positions'][0]['name']);
    }

    // ──────────────────────────────────────────────────────────
    // Duplicate invoice prevention
    // ──────────────────────────────────────────────────────────

    public function testDuplicateInvoicePrevention(): void
    {
        $handler = $this->createHandlerWithCapture();

        $order = $this->createOrder([
            'meta' => ['_fakturownia_invoice_id' => 123],
        ]);

        $result = $handler->createInvoice($order);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('already exists', $result['error']);
    }

    // ──────────────────────────────────────────────────────────
    // Shipping position
    // ──────────────────────────────────────────────────────────

    public function testShippingAddedAsPosition(): void
    {
        $handler = $this->createHandlerWithCapture();

        $order = $this->createOrder([
            'shipping_total' => 1500,
            'shipping_tax'   => 345,
        ]);

        $handler->createInvoice($order);

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        $positions = $invoice['positions'];
        $this->assertCount(2, $positions);
        $this->assertSame('Shipping', $positions[1]['name']);
        $this->assertSame(18.45, $positions[1]['total_price_gross']); // (1500+345)/100
        $this->assertSame(23, $positions[1]['tax']);
    }

    public function testNoShippingPositionWhenZero(): void
    {
        $handler = $this->createHandlerWithCapture();

        $order = $this->createOrder([
            'shipping_total' => 0,
            'shipping_tax'   => 0,
        ]);

        $handler->createInvoice($order);

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        $this->assertCount(1, $invoice['positions']);
    }

    // ──────────────────────────────────────────────────────────
    // Payment type mapping
    // ──────────────────────────────────────────────────────────

    public function testPaymentMethodMapping(): void
    {
        $handler = $this->createHandlerWithCapture();

        $order = $this->createOrder(['payment_method' => 'stripe']);
        $handler->createInvoice($order);
        $this->assertSame('card', $this->lastApiPayload['invoice']['payment_type']);

        // Reset meta to allow re-creation
        $order->setTestMeta([]);
        $order->payment_method = 'paypal';
        $handler->createInvoice($order);
        $this->assertSame('paypal', $this->lastApiPayload['invoice']['payment_type']);
    }

    public function testUnknownPaymentMethodFallsBackToSettings(): void
    {
        $this->setSettings(['payment_type' => 'cash']);
        $handler = $this->createHandlerWithCapture();

        $order = $this->createOrder(['payment_method' => 'unknown_gateway']);
        $handler->createInvoice($order);

        $this->assertSame('cash', $this->lastApiPayload['invoice']['payment_type']);
    }

    // ──────────────────────────────────────────────────────────
    // API error handling
    // ──────────────────────────────────────────────────────────

    public function testApiErrorIsLoggedAndReturned(): void
    {
        $this->setSettings();

        $this->mockApiResponse(['code' => 'error', 'message' => 'Invalid data'], 422);

        $handler = new InvoiceHandler(new FakturowniaAPI('testfirma', 'test-token'));
        $order = $this->createOrder();

        $result = $handler->createInvoice($order);

        $this->assertArrayHasKey('error', $result);
        $logs = $order->getTestLogs();
        $this->assertNotEmpty($logs);
        $this->assertSame('error', $logs[0]['level']);
    }

    // ──────────────────────────────────────────────────────────
    // Adversarial inputs
    // ──────────────────────────────────────────────────────────

    public function testXssInBuyerNameIsSentAsIs(): void
    {
        $handler = $this->createHandlerWithCapture();

        $order = $this->createOrder([
            'billing_address' => $this->createBillingAddress([
                'first_name' => '<script>alert("xss")</script>',
                'last_name'  => 'Normal',
            ]),
        ]);

        $handler->createInvoice($order);

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        // InvoiceHandler sends raw to Fakturownia API — sanitization is API's job
        $this->assertSame('<script>alert("xss")</script>', $invoice['buyer_first_name']);
    }

    public function testVeryLongProductNameIsTruncated(): void
    {
        $handler = $this->createHandlerWithCapture();

        $longName = str_repeat('A', 500);
        $order = $this->createOrder([
            'order_items' => [
                $this->createOrderItem(['title' => $longName]),
            ],
        ]);

        $handler->createInvoice($order);

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        $this->assertSame(256, strlen($invoice['positions'][0]['name']));
    }

    public function testEmptyOrderItemsProducesNoPositions(): void
    {
        $handler = $this->createHandlerWithCapture();

        $order = $this->createOrder(['order_items' => null]);

        $handler->createInvoice($order);

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        $this->assertEmpty($invoice['positions']);
    }

    public function testPhoneNumberTruncatedToKsefLimit(): void
    {
        $handler = $this->createHandlerWithCapture();

        $order = $this->createOrder([
            'billing_address' => $this->createBillingAddress([
                'meta' => [
                    'other_data' => [
                        'phone' => '+48 123 456 789 012 345',
                    ],
                ],
            ]),
        ]);

        $handler->createInvoice($order);

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        $this->assertLessThanOrEqual(16, strlen($invoice['buyer_phone']));
    }

    public function testZeroQuantityItemStillProcessed(): void
    {
        $handler = $this->createHandlerWithCapture();

        $order = $this->createOrder([
            'order_items' => [
                $this->createOrderItem([
                    'quantity'   => 0,
                    'line_total' => 0,
                    'tax_amount' => 0,
                ]),
            ],
        ]);

        // Should not throw or produce NaN
        $handler->createInvoice($order);

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        $position = $invoice['positions'][0] ?? [];
        $this->assertSame(0, $position['quantity']);
        $this->assertSame(0, $position['tax']); // 0% VAT, not 'zw'
    }

    public function testNoPaidAtUsesCreatedAt(): void
    {
        $handler = $this->createHandlerWithCapture();

        $order = $this->createOrder([
            'paid_at'    => null,
            'created_at' => '2025-06-15 10:00:00',
        ]);

        $handler->createInvoice($order);

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        // sell_date should still be a valid date
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $invoice['sell_date']);
    }

    public function testBothDatesNullUsesToday(): void
    {
        $handler = $this->createHandlerWithCapture();

        $order = $this->createOrder([
            'paid_at'    => null,
            'created_at' => null,
        ]);

        $handler->createInvoice($order);

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        $this->assertSame(wp_date('Y-m-d'), $invoice['sell_date']);
        $this->assertSame(wp_date('Y-m-d'), $invoice['issue_date']);
    }
}

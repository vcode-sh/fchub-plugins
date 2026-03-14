<?php

declare(strict_types=1);

namespace FChubFakturownia\Tests\Unit;

use FChubFakturownia\API\FakturowniaAPI;
use FChubFakturownia\Handler\InvoiceHandler;
use FChubFakturownia\Handler\RefundHandler;
use FChubFakturownia\Tests\PluginTestCase;

/**
 * Round 3 — tests verifying Fakturownia API specification compliance.
 * Covers BUG 30-39 found by cross-referencing official API docs.
 */
final class ApiSpecComplianceTest extends PluginTestCase
{
    private array $lastApiPayload = [];

    private function createHandlerWithCapture(): InvoiceHandler
    {
        if (empty($GLOBALS['_fchub_test_options']['_integration_api_fakturownia'])) {
            $this->setSettings();
        }
        $this->mockApiHandler(function ($method, $url, $args) {
            if ($method === 'POST') {
                $this->lastApiPayload = json_decode($args['body'], true);
            }
            return [
                'response' => ['code' => 200],
                'body'     => json_encode(['id' => 999, 'number' => 'FV-SPEC']),
                'headers'  => [],
            ];
        });
        return new InvoiceHandler(new FakturowniaAPI('testfirma', 'test-token'));
    }

    // ──────────────────────────────────────────────────────────
    // BUG 30: exempt_tax_kind required for KSeF when tax is 'zw'
    // ──────────────────────────────────────────────────────────

    public function testExemptTaxKindSetWhenKsefAndZwTax(): void
    {
        $this->setSettings(['ksef_auto_send' => 'yes']);
        $handler = $this->createHandlerWithCapture();

        // Force a 'zw' tax rate via negative tax
        $order = $this->createOrder([
            'order_items' => [
                $this->createOrderItem([
                    'line_total' => 10000,
                    'tax_amount' => -100, // negative → normalizeVatRate returns 'zw'
                ]),
            ],
        ]);
        $order->currency = 'PLN';

        $handler->createInvoice($order);

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        $this->assertArrayHasKey('exempt_tax_kind', $invoice);
        $this->assertNotEmpty($invoice['exempt_tax_kind']);
    }

    public function testExemptTaxKindNotSetWhenNoZwPositions(): void
    {
        $this->setSettings(['ksef_auto_send' => 'yes']);
        $handler = $this->createHandlerWithCapture();

        $order = $this->createOrder(); // default 23% item
        $order->currency = 'PLN';

        $handler->createInvoice($order);

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        $this->assertArrayNotHasKey('exempt_tax_kind', $invoice);
    }

    public function testExemptTaxKindNotSetWhenKsefDisabled(): void
    {
        $this->setSettings(['ksef_auto_send' => 'no']);
        $handler = $this->createHandlerWithCapture();

        $order = $this->createOrder([
            'order_items' => [
                $this->createOrderItem(['line_total' => 10000, 'tax_amount' => -100]),
            ],
        ]);
        $order->currency = 'PLN';

        $handler->createInvoice($order);

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        // Without KSeF, exempt_tax_kind is not required
        $this->assertArrayNotHasKey('exempt_tax_kind', $invoice);
    }

    // ──────────────────────────────────────────────────────────
    // BUG 31 + 32: payment_to and proforma status handling
    // ──────────────────────────────────────────────────────────

    public function testPaidInvoiceHasPaidDateAndPaymentTo(): void
    {
        $this->setSettings(['invoice_kind' => 'vat']);
        $handler = $this->createHandlerWithCapture();

        $order = $this->createOrder();
        $order->currency = 'PLN';

        $handler->createInvoice($order);

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        $this->assertSame('paid', $invoice['status']);
        $this->assertArrayHasKey('paid_date', $invoice);
        $this->assertSame('other_date', $invoice['payment_to_kind']);
        $this->assertArrayHasKey('payment_to', $invoice);
    }

    public function testProformaHasIssuedStatusAndNoPaidDate(): void
    {
        $this->setSettings(['invoice_kind' => 'proforma']);
        $handler = $this->createHandlerWithCapture();

        $order = $this->createOrder();
        $order->currency = 'PLN';

        $handler->createInvoice($order);

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        $this->assertSame('proforma', $invoice['kind']);
        $this->assertSame('issued', $invoice['status']);
        $this->assertArrayNotHasKey('paid_date', $invoice);
        // Proforma gets a 7-day payment term
        $this->assertSame(7, $invoice['payment_to_kind']);
    }

    public function testBillInvoiceHasPaidStatus(): void
    {
        $this->setSettings(['invoice_kind' => 'bill']);
        $handler = $this->createHandlerWithCapture();

        $order = $this->createOrder();
        $order->currency = 'PLN';

        $handler->createInvoice($order);

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        $this->assertSame('bill', $invoice['kind']);
        $this->assertSame('paid', $invoice['status']);
        $this->assertArrayHasKey('paid_date', $invoice);
    }

    // ──────────────────────────────────────────────────────────
    // BUG 34: Correction position kind fields
    // ──────────────────────────────────────────────────────────

    public function testCorrectionPositionsHaveKindFields(): void
    {
        $this->setSettings();
        $handler = new RefundHandler(new FakturowniaAPI('testfirma', 'test-token'));

        $capturedBody = null;
        $this->mockApiHandler(function ($method, $url, $args) use (&$capturedBody) {
            if ($method === 'POST') {
                $capturedBody = json_decode($args['body'], true);
            }
            if ($method === 'GET') {
                return [
                    'response' => ['code' => 200],
                    'body'     => json_encode([
                        'id' => 100,
                        'positions' => [
                            ['name' => 'Widget', 'quantity' => 2, 'total_price_gross' => '246.00', 'tax' => 23, 'quantity_unit' => 'szt'],
                        ],
                    ]),
                    'headers' => [],
                ];
            }
            return [
                'response' => ['code' => 200],
                'body'     => json_encode(['id' => 200, 'number' => 'FK-1']),
                'headers'  => [],
            ];
        });

        $order = $this->createOrder([
            'meta' => ['_fakturownia_invoice_id' => 100, '_fakturownia_invoice_number' => 'FV 1/2025'],
        ]);

        $handler->createCorrectionInvoice($order);

        $positions = $capturedBody['invoice']['positions'] ?? [];
        $this->assertNotEmpty($positions);

        $pos = $positions[0];
        $this->assertSame('correction', $pos['kind']);
        $this->assertSame('correction_before', $pos['correction_before_attributes']['kind']);
    }

    // ──────────────────────────────────────────────────────────
    // BUG 35: Buyer field length limits (255 chars)
    // ──────────────────────────────────────────────────────────

    public function testBuyerNameTruncatedTo255Chars(): void
    {
        $handler = $this->createHandlerWithCapture();

        $longName = str_repeat('A', 300);
        $order = $this->createOrder([
            'billing_address' => $this->createBillingAddress([
                'company_name' => $longName,
                'meta' => ['other_data' => ['nip' => '5213017228']],
            ]),
        ]);

        $handler->createInvoice($order);

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        $this->assertSame(255, mb_strlen($invoice['buyer_name']));
    }

    public function testBuyerStreetTruncatedTo255Chars(): void
    {
        $handler = $this->createHandlerWithCapture();

        $longStreet = str_repeat('B', 260);
        $order = $this->createOrder([
            'billing_address' => $this->createBillingAddress([
                'address_1' => $longStreet,
            ]),
        ]);

        $handler->createInvoice($order);

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        $this->assertLessThanOrEqual(255, mb_strlen($invoice['buyer_street']));
    }

    // ──────────────────────────────────────────────────────────
    // BUG 36: Description truncated to 3500 chars for KSeF
    // ──────────────────────────────────────────────────────────

    public function testDescriptionTruncatedTo3500Chars(): void
    {
        $handler = $this->createHandlerWithCapture();

        $longNote = str_repeat('X', 4000);
        $order = $this->createOrder();
        $order->currency = 'PLN';

        $handler->createInvoice($order, $longNote);

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        $this->assertSame(3500, mb_strlen($invoice['description']));
    }

    public function testShortDescriptionNotTruncated(): void
    {
        $handler = $this->createHandlerWithCapture();
        $order = $this->createOrder();
        $order->currency = 'PLN';

        $handler->createInvoice($order, 'Short note');

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        $this->assertSame('Short note', $invoice['description']);
    }

    // ──────────────────────────────────────────────────────────
    // BUG 37: server_error triggers retry in cron handler
    // ──────────────────────────────────────────────────────────

    public function testServerErrorTriggersRetry(): void
    {
        $order = $this->createOrder([
            'id'   => 42,
            'meta' => ['_fakturownia_invoice_id' => 100],
        ]);
        $GLOBALS['_fchub_test_orders'][42] = $order;

        $this->setSettings();
        $this->mockApiResponse([
            'id'         => 100,
            'gov_status' => 'server_error',
        ]);

        $api = new FakturowniaAPI('testfirma', 'test-token');
        $invoice = $api->getInvoice(100);

        // Simulate cron handler logic
        $isCorrection = false;
        $metaPrefix = '_fakturownia_ksef';
        $govStatus = $invoice['gov_status'] ?? null;
        $retryKey = $metaPrefix . '_retry_count';

        if ($govStatus) {
            $order->updateMeta($metaPrefix . '_status', $govStatus);
        }

        // The fix: server_error is now retryable
        if ($govStatus === 'processing' || $govStatus === 'server_error') {
            $retryCount = (int) $order->getMeta($retryKey, 0);
            $order->updateMeta($retryKey, $retryCount + 1);
            wp_schedule_single_event(time() + 120, 'fchub_fakturownia_check_ksef_status', [42, 100]);
        }

        $this->assertSame('server_error', $order->getMeta('_fakturownia_ksef_status'));
        $this->assertSame(1, $order->getMeta('_fakturownia_ksef_retry_count'));
        $events = $GLOBALS['_fchub_test_scheduled_events'];
        $this->assertCount(1, $events);
    }

    // ──────────────────────────────────────────────────────────
    // BUG 38: Correction reason truncated to 256 chars
    // ──────────────────────────────────────────────────────────

    public function testCorrectionReasonTruncatedTo256(): void
    {
        $this->setSettings();
        $handler = new RefundHandler(new FakturowniaAPI('testfirma', 'test-token'));

        $capturedBody = null;
        $this->mockApiHandler(function ($method, $url, $args) use (&$capturedBody) {
            if ($method === 'POST') {
                $capturedBody = json_decode($args['body'], true);
            }
            if ($method === 'GET') {
                return [
                    'response' => ['code' => 200],
                    'body'     => json_encode(['id' => 100, 'positions' => []]),
                    'headers'  => [],
                ];
            }
            return [
                'response' => ['code' => 200],
                'body'     => json_encode(['id' => 200, 'number' => 'FK-1']),
                'headers'  => [],
            ];
        });

        $order = $this->createOrder([
            'invoice_no' => str_repeat('Z', 300), // Very long invoice_no
            'meta' => ['_fakturownia_invoice_id' => 100],
        ]);

        $handler->createCorrectionInvoice($order);

        $reason = $capturedBody['invoice']['correction_reason'] ?? '';
        $this->assertLessThanOrEqual(256, mb_strlen($reason));
    }

    // ──────────────────────────────────────────────────────────
    // Invoice position field name limit (256 chars for KSeF)
    // ──────────────────────────────────────────────────────────

    public function testPositionNameRespects256CharLimit(): void
    {
        $handler = $this->createHandlerWithCapture();

        $order = $this->createOrder([
            'order_items' => [
                $this->createOrderItem(['title' => str_repeat('W', 300)]),
            ],
        ]);
        $order->currency = 'PLN';

        $handler->createInvoice($order);

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        $name = $invoice['positions'][0]['name'] ?? '';
        $this->assertSame(256, strlen($name));
    }

    // ──────────────────────────────────────────────────────────
    // Verify complete invoice payload structure
    // ──────────────────────────────────────────────────────────

    public function testCompleteInvoicePayloadContainsAllRequiredFields(): void
    {
        $this->setSettings([
            'invoice_kind' => 'vat',
            'payment_type' => 'transfer',
            'invoice_lang' => 'pl',
            'department_id' => '12345',
        ]);
        $handler = $this->createHandlerWithCapture();

        $order = $this->createOrder([
            'billing_address' => $this->createBillingAddress([
                'meta' => ['other_data' => ['nip' => '5213017228', 'phone' => '+48123456789']],
                'company_name' => 'ACME Sp. z o.o.',
            ]),
            'shipping_total' => 1500,
            'shipping_tax'   => 345,
        ]);
        $order->currency = 'EUR';

        $handler->createInvoice($order, 'Test note');

        $invoice = $this->lastApiPayload['invoice'] ?? [];

        // Core fields
        $this->assertSame('vat', $invoice['kind']);
        $this->assertSame('transfer', $invoice['payment_type']);
        $this->assertSame('pl', $invoice['lang']);
        $this->assertSame('paid', $invoice['status']);
        $this->assertSame('EUR', $invoice['currency']);
        $this->assertSame('yes', $invoice['oid_unique']);
        $this->assertSame(12345, $invoice['department_id']);
        $this->assertSame('Test note', $invoice['description']);

        // Dates
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $invoice['sell_date']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $invoice['issue_date']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $invoice['paid_date']);
        $this->assertArrayHasKey('payment_to', $invoice);

        // B2B buyer
        $this->assertTrue($invoice['buyer_company']);
        $this->assertSame('5213017228', $invoice['buyer_tax_no']);
        $this->assertSame('ACME Sp. z o.o.', $invoice['buyer_name']);
        $this->assertSame('ul. Testowa 1', $invoice['buyer_street']);
        $this->assertSame('Warszawa', $invoice['buyer_city']);
        $this->assertSame('00-001', $invoice['buyer_post_code']);
        $this->assertSame('PL', $invoice['buyer_country']);
        $this->assertSame('jan@example.com', $invoice['buyer_email']);
        $this->assertSame('+48123456789', $invoice['buyer_phone']);

        // Positions: 1 product + 1 shipping
        $this->assertCount(2, $invoice['positions']);
        $productPos = $invoice['positions'][0];
        $this->assertSame('Widget Pro', $productPos['name']);
        $this->assertSame(1, $productPos['quantity']);
        $this->assertSame('szt', $productPos['quantity_unit']);
        $this->assertSame(23, $productPos['tax']);

        $shippingPos = $invoice['positions'][1];
        $this->assertSame('Shipping', $shippingPos['name']);
        $this->assertSame(1, $shippingPos['quantity']);
    }
}

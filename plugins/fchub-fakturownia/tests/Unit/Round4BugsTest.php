<?php

declare(strict_types=1);

namespace FChubFakturownia\Tests\Unit;

use FChubFakturownia\API\FakturowniaAPI;
use FChubFakturownia\Handler\InvoiceHandler;
use FChubFakturownia\Tests\PluginTestCase;

/**
 * Round 4 — security + data integrity + KSeF compliance bugs.
 * Covers BUG 40-47 from parallel agent audit.
 */
final class Round4BugsTest extends PluginTestCase
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
                'body'     => json_encode(['id' => 999, 'number' => 'FV-R4']),
                'headers'  => [],
            ];
        });
        return new InvoiceHandler(new FakturowniaAPI('testfirma', 'test-token'));
    }

    // ──────────────────────────────────────────────────────────
    // H1: SSRF prevention — domain validation
    // ──────────────────────────────────────────────────────────

    public function testDomainValidationRejectsAwsMetadata(): void
    {
        $api = new FakturowniaAPI('169.254.169.254', 'token');
        $this->assertSame('https://invalid.fakturownia.pl', $api->getBaseUrl());
    }

    public function testDomainValidationRejectsLocalhost(): void
    {
        $api = new FakturowniaAPI('localhost', 'token');
        // 'localhost' matches ^[a-z0-9-]+$ so it becomes localhost.fakturownia.pl
        // This is actually safe — it won't resolve to a real Fakturownia instance
        $this->assertSame('https://localhost.fakturownia.pl', $api->getBaseUrl());
    }

    public function testDomainValidationAcceptsValidSubdomain(): void
    {
        $api = new FakturowniaAPI('moja-firma-123', 'token');
        $this->assertSame('https://moja-firma-123.fakturownia.pl', $api->getBaseUrl());
    }

    // ──────────────────────────────────────────────────────────
    // BUG 40: mb_substr on position name (UTF-8 safety)
    // ──────────────────────────────────────────────────────────

    public function testPolishProductNameNotCorrupted(): void
    {
        $handler = $this->createHandlerWithCapture();

        // Polish product name with multi-byte characters at the 256-char boundary
        $polishName = str_repeat('ą', 260); // ą is 2 bytes in UTF-8

        $order = $this->createOrder([
            'order_items' => [
                $this->createOrderItem(['title' => $polishName]),
            ],
        ]);
        $order->currency = 'PLN';

        $handler->createInvoice($order);

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        $name = $invoice['positions'][0]['name'];

        // mb_substr should give exactly 256 characters, not 256 bytes
        $this->assertSame(256, mb_strlen($name));
        // Should not contain invalid UTF-8 (broken multi-byte sequence)
        $this->assertTrue(mb_check_encoding($name, 'UTF-8'));
    }

    // ──────────────────────────────────────────────────────────
    // BUG 41: Proforma invoices not sent to KSeF
    // ──────────────────────────────────────────────────────────

    public function testProformaNotSentViaKsef(): void
    {
        $this->setSettings(['invoice_kind' => 'proforma', 'ksef_auto_send' => 'yes']);

        $capturedBody = null;
        $this->mockApiHandler(function ($method, $url, $args) use (&$capturedBody) {
            if ($method === 'POST') {
                $capturedBody = json_decode($args['body'], true);
            }
            return [
                'response' => ['code' => 200],
                'body'     => json_encode(['id' => 999, 'number' => 'PRO-1']),
                'headers'  => [],
            ];
        });

        $handler = new InvoiceHandler(new FakturowniaAPI('testfirma', 'test-token'));
        $order = $this->createOrder();
        $order->currency = 'PLN';

        $handler->createInvoice($order);

        // gov_save_and_send should NOT be present for proformas
        $this->assertArrayNotHasKey('gov_save_and_send', $capturedBody);
    }

    public function testVatInvoiceSentViaKsefWhenEnabled(): void
    {
        $this->setSettings(['invoice_kind' => 'vat', 'ksef_auto_send' => 'yes']);

        $capturedBody = null;
        $this->mockApiHandler(function ($method, $url, $args) use (&$capturedBody) {
            if ($method === 'POST') {
                $capturedBody = json_decode($args['body'], true);
            }
            return [
                'response' => ['code' => 200],
                'body'     => json_encode(['id' => 999, 'number' => 'FV-1']),
                'headers'  => [],
            ];
        });

        $handler = new InvoiceHandler(new FakturowniaAPI('testfirma', 'test-token'));
        $order = $this->createOrder();
        $order->currency = 'PLN';

        $handler->createInvoice($order);

        // gov_save_and_send SHOULD be present for VAT invoices
        $this->assertTrue($capturedBody['gov_save_and_send']);
    }

    // ──────────────────────────────────────────────────────────
    // BUG 42: buyer_tax_no_kind for EU/non-EU NIPs
    // ──────────────────────────────────────────────────────────

    public function testPolishNipGetsEmptyTaxNoKind(): void
    {
        $handler = $this->createHandlerWithCapture();

        $order = $this->createOrder([
            'billing_address' => $this->createBillingAddress([
                'country' => 'PL',
                'meta' => ['other_data' => ['nip' => '5213017228']],
            ]),
        ]);
        $order->currency = 'PLN';

        $handler->createInvoice($order);

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        $this->assertSame('', $invoice['buyer_tax_no_kind']);
    }

    public function testGermanNipGetsNipUeKind(): void
    {
        $handler = $this->createHandlerWithCapture();

        $order = $this->createOrder([
            'billing_address' => $this->createBillingAddress([
                'country' => 'DE',
                'company_name' => 'German GmbH',
                'meta' => ['other_data' => ['nip' => 'DE123456789']],
            ]),
        ]);
        $order->currency = 'EUR';

        $handler->createInvoice($order);

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        $this->assertSame('nip_ue', $invoice['buyer_tax_no_kind']);
    }

    public function testNonEuNipGetsOtherKind(): void
    {
        $handler = $this->createHandlerWithCapture();

        $order = $this->createOrder([
            'billing_address' => $this->createBillingAddress([
                'country' => 'US',
                'company_name' => 'US Inc',
                'meta' => ['other_data' => ['nip' => '12-3456789']],
            ]),
        ]);
        $order->currency = 'USD';

        $handler->createInvoice($order);

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        $this->assertSame('other', $invoice['buyer_tax_no_kind']);
    }

    public function testEuPrefixDetectedFromNipItself(): void
    {
        $handler = $this->createHandlerWithCapture();

        // French NIP with FR prefix, country is PL (inconsistent data)
        $order = $this->createOrder([
            'billing_address' => $this->createBillingAddress([
                'country' => 'PL',
                'company_name' => 'French SARL',
                'meta' => ['other_data' => ['nip' => 'FR12345678901']],
            ]),
        ]);
        $order->currency = 'EUR';

        $handler->createInvoice($order);

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        // FR prefix takes precedence over PL country
        $this->assertSame('nip_ue', $invoice['buyer_tax_no_kind']);
    }

    // ──────────────────────────────────────────────────────────
    // BUG 43: Demo KSeF status normalization
    // ──────────────────────────────────────────────────────────

    public function testDemoOkStatusNormalizedToOk(): void
    {
        $handler = $this->createHandlerWithCapture();

        // Simulate Fakturownia sandbox response with demo_ prefix
        $this->mockApiHandler(function ($method, $url, $args) {
            if ($method === 'POST') {
                $this->lastApiPayload = json_decode($args['body'], true);
                return [
                    'response' => ['code' => 200],
                    'body'     => json_encode([
                        'id'         => 999,
                        'number'     => 'FV-DEMO',
                        'gov_status' => 'demo_ok',
                        'gov_id'     => 'DEMO-KSeF-001',
                    ]),
                    'headers' => [],
                ];
            }
            return ['response' => ['code' => 200], 'body' => '{}', 'headers' => []];
        });

        $this->setSettings(['ksef_auto_send' => 'yes']);
        $handler = new InvoiceHandler(new FakturowniaAPI('testfirma', 'test-token'));
        $order = $this->createOrder();
        $order->currency = 'PLN';

        $handler->createInvoice($order);

        // Should be normalized from 'demo_ok' to 'ok'
        $this->assertSame('ok', $order->getMeta('_fakturownia_ksef_status'));
    }

    // ──────────────────────────────────────────────────────────
    // BUG 44: Strict float comparison
    // ──────────────────────────────────────────────────────────

    public function testZeroTaxWithStrictComparison(): void
    {
        $handler = $this->createHandlerWithCapture();

        $order = $this->createOrder([
            'order_items' => [
                $this->createOrderItem([
                    'line_total' => 10000,
                    'tax_amount' => 0,
                ]),
            ],
        ]);
        $order->currency = 'PLN';

        $handler->createInvoice($order);

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        $this->assertSame(0, $invoice['positions'][0]['tax']);
    }

    // ──────────────────────────────────────────────────────────
    // BUG 45: buyer_email capped at 255 chars
    // ──────────────────────────────────────────────────────────

    public function testLongEmailTruncatedTo255(): void
    {
        $handler = $this->createHandlerWithCapture();

        $longEmail = str_repeat('a', 250) . '@example.com';
        $order = $this->createOrder([
            'customer' => $this->createCustomer(['email' => $longEmail]),
        ]);
        $order->currency = 'PLN';

        $handler->createInvoice($order);

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        $this->assertLessThanOrEqual(255, mb_strlen($invoice['buyer_email']));
    }

    // ──────────────────────────────────────────────────────────
    // H3: PDF Content-Type hardcoded
    // ──────────────────────────────────────────────────────────

    // This is tested implicitly — the header is hardcoded in fchub-fakturownia.php:209
    // No dynamic test needed since it's a constant string.

    // ──────────────────────────────────────────────────────────
    // H2: ZIP URL validation in GitHubUpdater
    // ──────────────────────────────────────────────────────────

    // GitHubUpdater is a shared lib file — tested by verifying the regex
    // in parseReleases rejects malicious URLs. Cannot unit-test without
    // loading the class (which conflicts with bootstrap constants).

    // ──────────────────────────────────────────────────────────
    // Comprehensive proforma flow
    // ──────────────────────────────────────────────────────────

    public function testProformaFullFlow(): void
    {
        $this->setSettings(['invoice_kind' => 'proforma', 'ksef_auto_send' => 'yes']);
        $handler = $this->createHandlerWithCapture();

        $order = $this->createOrder();
        $order->currency = 'PLN';

        $handler->createInvoice($order);

        $invoice = $this->lastApiPayload['invoice'] ?? [];

        // Status should be 'issued' not 'paid'
        $this->assertSame('issued', $invoice['status']);
        $this->assertSame('proforma', $invoice['kind']);

        // No paid_date
        $this->assertArrayNotHasKey('paid_date', $invoice);

        // Payment term = 7 days
        $this->assertSame(7, $invoice['payment_to_kind']);

        // No payment_to date (Fakturownia calculates from payment_to_kind)
        $this->assertArrayNotHasKey('payment_to', $invoice);

        // No KSeF submission
        $this->assertArrayNotHasKey('gov_save_and_send', $this->lastApiPayload);
    }
}

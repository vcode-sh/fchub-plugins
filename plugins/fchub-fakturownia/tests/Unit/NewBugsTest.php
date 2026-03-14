<?php

declare(strict_types=1);

namespace FChubFakturownia\Tests\Unit;

use FChubFakturownia\API\FakturowniaAPI;
use FChubFakturownia\Checkout\CheckoutFields;
use FChubFakturownia\Handler\InvoiceHandler;
use FChubFakturownia\Handler\RefundHandler;
use FChubFakturownia\Integration\FakturowniaIntegration;
use FChubFakturownia\Integration\FakturowniaSettings;
use FChubFakturownia\Tests\PluginTestCase;
use FChubFakturownia\Tests\WpSendJsonException;

/**
 * Tests for bugs discovered in the second audit round (BUG 23-29).
 */
final class NewBugsTest extends PluginTestCase
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
                'body'     => json_encode(['id' => 999, 'number' => 'FV-NEW']),
                'headers'  => [],
            ];
        });
        return new InvoiceHandler(new FakturowniaAPI('testfirma', 'test-token'));
    }

    // ──────────────────────────────────────────────────────────
    // BUG 23: Missing currency on invoices
    // ──────────────────────────────────────────────────────────

    public function testInvoiceIncludesOrderCurrency(): void
    {
        $handler = $this->createHandlerWithCapture();

        $order = $this->createOrder();
        $order->currency = 'EUR';

        $handler->createInvoice($order);

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        $this->assertSame('EUR', $invoice['currency']);
    }

    public function testInvoiceDefaultsToPLNWhenCurrencyNull(): void
    {
        $handler = $this->createHandlerWithCapture();

        $order = $this->createOrder();
        $order->currency = null;

        $handler->createInvoice($order);

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        $this->assertSame('PLN', $invoice['currency']);
    }

    public function testInvoiceWithUSDCurrency(): void
    {
        $handler = $this->createHandlerWithCapture();

        $order = $this->createOrder();
        $order->currency = 'USD';

        $handler->createInvoice($order);

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        $this->assertSame('USD', $invoice['currency']);
    }

    // ──────────────────────────────────────────────────────────
    // BUG 24: Feed note wired to invoice description
    // ──────────────────────────────────────────────────────────

    public function testNotePassedToInvoiceDescription(): void
    {
        $handler = $this->createHandlerWithCapture();
        $order = $this->createOrder();

        $handler->createInvoice($order, 'Thank you for your order!');

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        $this->assertSame('Thank you for your order!', $invoice['description']);
    }

    public function testEmptyNoteNotIncludedInInvoice(): void
    {
        $handler = $this->createHandlerWithCapture();
        $order = $this->createOrder();

        $handler->createInvoice($order, '');

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        $this->assertArrayNotHasKey('description', $invoice);
    }

    public function testNotePassedWithoutSecondArg(): void
    {
        $handler = $this->createHandlerWithCapture();
        $order = $this->createOrder();

        $handler->createInvoice($order);

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        $this->assertArrayNotHasKey('description', $invoice);
    }

    public function testIntegrationPassesFeedNoteToHandler(): void
    {
        $this->setSettings();
        $integration = new FakturowniaIntegration();

        $this->mockApiHandler(function ($method, $url, $args) {
            if ($method === 'POST') {
                $this->lastApiPayload = json_decode($args['body'], true);
            }
            return [
                'response' => ['code' => 200],
                'body'     => json_encode(['id' => 500, 'number' => 'FV-NOTE']),
                'headers'  => [],
            ];
        });

        $order = $this->createOrder();
        $order->currency = 'PLN';

        $integration->processAction($order, [
            'trigger'        => 'order_paid_done',
            'is_revoke_hook' => 'no',
            'feed'           => ['note' => 'Custom invoice note'],
        ]);

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        $this->assertSame('Custom invoice note', $invoice['description']);
    }

    // ──────────────────────────────────────────────────────────
    // BUG 25: API token masked in getApiSettings
    // ──────────────────────────────────────────────────────────

    public function testApiTokenMaskedInApiSettings(): void
    {
        $this->setSettings(['api_token' => 'super-secret-token-abc123']);
        $integration = new FakturowniaIntegration();

        $result = $integration->getApiSettings();

        $this->assertStringStartsWith('****', $result['api_key']);
        $this->assertStringEndsWith('c123', $result['api_key']);
        $this->assertStringNotContainsString('super-secret', $result['api_key']);
    }

    public function testEmptyApiTokenMaskedAsEmpty(): void
    {
        $this->setSettings(['api_token' => '']);
        $integration = new FakturowniaIntegration();

        $result = $integration->getApiSettings();

        $this->assertSame('', $result['api_key']);
    }

    public function testShortApiTokenMasked(): void
    {
        $this->setSettings(['api_token' => 'ab']);
        $integration = new FakturowniaIntegration();

        $result = $integration->getApiSettings();

        // Even short tokens should be masked
        $this->assertStringStartsWith('****', $result['api_key']);
    }

    // ──────────────────────────────────────────────────────────
    // BUG 26: NIP mod-11=10 explicitly rejected
    // ──────────────────────────────────────────────────────────

    public function testNipWithMod11Equals10IsRejected(): void
    {
        // 8040000000: sum = 8*6 + 0*5 + 4*7 + 0... = 48 + 28 = 76, 76%11 = 10
        // Mod-11=10 means no valid check digit exists — all variants must be rejected
        $this->assertFalse(CheckoutFields::validateNip('8040000000'));
        $this->assertFalse(CheckoutFields::validateNip('8040000001'));
        $this->assertFalse(CheckoutFields::validateNip('8040000009'));
    }

    // ──────────────────────────────────────────────────────────
    // BUG 27: authenticateCredentials returns after wp_send_json
    // ──────────────────────────────────────────────────────────

    public function testAuthenticateEmptyCredentialsThrowsJsonError(): void
    {
        $this->setSettings();

        try {
            FakturowniaSettings::authenticateCredentials([
                'integration' => ['domain' => '', 'api_token' => ''],
            ]);
            $this->fail('Should have thrown WpSendJsonException');
        } catch (WpSendJsonException $e) {
            $this->assertSame(422, $e->statusCode);
            $this->assertFalse($e->data['status']);
        }
    }

    public function testAuthenticateFailedConnectionThrowsJsonError(): void
    {
        $this->setSettings();

        // Mock a failed connection
        $this->mockApiResponse(['error' => 'Unauthorized'], 401);

        try {
            FakturowniaSettings::authenticateCredentials([
                'integration' => ['domain' => 'testfirma', 'api_token' => 'bad-token'],
            ]);
            $this->fail('Should have thrown WpSendJsonException');
        } catch (WpSendJsonException $e) {
            $this->assertSame(422, $e->statusCode);
            $this->assertStringContainsString('Connection failed', $e->data['message']);
        }
    }

    public function testAuthenticateSuccessReturnsStatus200(): void
    {
        $this->setSettings();

        // Mock successful API response
        $this->mockApiResponse([['id' => 1, 'number' => 'FV 1']]);

        try {
            FakturowniaSettings::authenticateCredentials([
                'integration' => ['domain' => 'testfirma', 'api_token' => 'valid-token'],
            ]);
            $this->fail('Should have thrown WpSendJsonException');
        } catch (WpSendJsonException $e) {
            $this->assertSame(200, $e->statusCode);
            $this->assertTrue($e->data['data']['status']);
        }
    }

    // ──────────────────────────────────────────────────────────
    // BUG 28: Correction KSeF initial gov_id/gov_link stored
    // ──────────────────────────────────────────────────────────

    public function testCorrectionStoresInitialKsefData(): void
    {
        $this->setSettings(['ksef_auto_send' => 'yes']);
        $handler = new RefundHandler(new FakturowniaAPI('testfirma', 'test-token'));

        $this->mockApiHandler(function ($method, $url) {
            if ($method === 'GET') {
                return [
                    'response' => ['code' => 200],
                    'body'     => json_encode([
                        'id' => 100, 'positions' => [
                            ['name' => 'X', 'quantity' => 1, 'total_price_gross' => '10', 'tax' => 23, 'quantity_unit' => 'szt'],
                        ],
                    ]),
                    'headers'  => [],
                ];
            }
            // POST: correction with instant KSeF acceptance
            return [
                'response' => ['code' => 200],
                'body'     => json_encode([
                    'id'                    => 200,
                    'number'                => 'FK 1/2025',
                    'gov_status'            => 'ok',
                    'gov_id'                => 'KSeF-INSTANT-001',
                    'gov_verification_link' => 'https://ksef.gov.pl/verify/instant',
                ]),
                'headers' => [],
            ];
        });

        $order = $this->createOrder([
            'meta' => ['_fakturownia_invoice_id' => 100, '_fakturownia_invoice_number' => 'FV 1/2025'],
        ]);

        $handler->createCorrectionInvoice($order);

        $this->assertSame('ok', $order->getMeta('_fakturownia_correction_ksef_status'));
        $this->assertSame('KSeF-INSTANT-001', $order->getMeta('_fakturownia_correction_ksef_id'));
        $this->assertSame('https://ksef.gov.pl/verify/instant', $order->getMeta('_fakturownia_correction_ksef_link'));
    }

    public function testCorrectionWithoutKsefDoesNotStoreGovData(): void
    {
        $this->setSettings(['ksef_auto_send' => 'no']);
        $handler = new RefundHandler(new FakturowniaAPI('testfirma', 'test-token'));

        $this->mockApiHandler(function ($method) {
            if ($method === 'GET') {
                return [
                    'response' => ['code' => 200],
                    'body'     => json_encode(['id' => 100, 'positions' => []]),
                    'headers'  => [],
                ];
            }
            return [
                'response' => ['code' => 200],
                'body'     => json_encode(['id' => 200, 'number' => 'FK 1/2025']),
                'headers'  => [],
            ];
        });

        $order = $this->createOrder([
            'meta' => ['_fakturownia_invoice_id' => 100],
        ]);

        $handler->createCorrectionInvoice($order);

        $this->assertNull($order->getMeta('_fakturownia_correction_ksef_status'));
        $this->assertNull($order->getMeta('_fakturownia_correction_ksef_id'));
    }

    // ──────────────────────────────────────────────────────────
    // BUG 29: department_id validated as numeric
    // ──────────────────────────────────────────────────────────

    public function testValidNumericDepartmentIdAccepted(): void
    {
        $this->setSettings();

        try {
            FakturowniaSettings::saveGlobalSettings([
                'integration' => [
                    'domain'        => 'test',
                    'api_token'     => 'token',
                    'department_id' => '12345',
                ],
            ]);
        } catch (WpSendJsonException $e) {
            // Expected
        }

        $saved = $GLOBALS['_fchub_test_options']['_integration_api_fakturownia'];
        $this->assertSame('12345', $saved['department_id']);
    }

    public function testNonNumericDepartmentIdRejected(): void
    {
        $this->setSettings();

        try {
            FakturowniaSettings::saveGlobalSettings([
                'integration' => [
                    'domain'        => 'test',
                    'api_token'     => 'token',
                    'department_id' => 'abc; DROP TABLE invoices',
                ],
            ]);
        } catch (WpSendJsonException $e) {
            // Expected
        }

        $saved = $GLOBALS['_fchub_test_options']['_integration_api_fakturownia'];
        $this->assertSame('', $saved['department_id']);
    }

    public function testEmptyDepartmentIdAccepted(): void
    {
        $this->setSettings();

        try {
            FakturowniaSettings::saveGlobalSettings([
                'integration' => [
                    'domain'        => 'test',
                    'api_token'     => 'token',
                    'department_id' => '',
                ],
            ]);
        } catch (WpSendJsonException $e) {
            // Expected
        }

        $saved = $GLOBALS['_fchub_test_options']['_integration_api_fakturownia'];
        $this->assertSame('', $saved['department_id']);
    }

    public function testDepartmentIdWithMixedContentRejected(): void
    {
        $this->setSettings();

        try {
            FakturowniaSettings::saveGlobalSettings([
                'integration' => [
                    'domain'        => 'test',
                    'api_token'     => 'token',
                    'department_id' => '123abc',
                ],
            ]);
        } catch (WpSendJsonException $e) {
            // Expected
        }

        $saved = $GLOBALS['_fchub_test_options']['_integration_api_fakturownia'];
        $this->assertSame('', $saved['department_id']);
    }

    // ──────────────────────────────────────────────────────────
    // Adversarial: multi-currency + note + masked token combo
    // ──────────────────────────────────────────────────────────

    public function testFullInvoiceFlowWithAllNewFields(): void
    {
        $this->setSettings(['ksef_auto_send' => 'no']);
        $handler = $this->createHandlerWithCapture();

        $order = $this->createOrder();
        $order->currency = 'GBP';

        $handler->createInvoice($order, 'Please pay within 14 days');

        $invoice = $this->lastApiPayload['invoice'] ?? [];
        $this->assertSame('GBP', $invoice['currency']);
        $this->assertSame('Please pay within 14 days', $invoice['description']);
        $this->assertSame('vat', $invoice['kind']);
    }
}

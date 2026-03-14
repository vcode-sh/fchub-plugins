<?php

declare(strict_types=1);

namespace FChubFakturownia\Tests\Unit;

use FChubFakturownia\Integration\FakturowniaIntegration;
use FChubFakturownia\Tests\PluginTestCase;

/**
 * Tests for FakturowniaIntegration — covers BUG 6 (KSeF polling for corrections)
 * and BUG 9 (partial refund routing).
 */
final class FakturowniaIntegrationTest extends PluginTestCase
{
    private function createIntegration(): FakturowniaIntegration
    {
        // Don't call setSettings() here — let each test configure its own settings
        // before calling this method so it doesn't override test-specific values
        return new FakturowniaIntegration();
    }

    private function mockSuccessfulApi(): void
    {
        $this->mockApiHandler(function ($method, $url) {
            if (strpos($url, '.json') !== false && $method === 'GET') {
                return [
                    'response' => ['code' => 200],
                    'body'     => json_encode([
                        'id'        => 100,
                        'number'    => 'FV 1/2025',
                        'positions' => [
                            ['name' => 'Item', 'quantity' => 1, 'total_price_gross' => '123.00', 'tax' => 23, 'quantity_unit' => 'szt'],
                        ],
                    ]),
                    'headers' => [],
                ];
            }
            return [
                'response' => ['code' => 200],
                'body'     => json_encode(['id' => 200, 'number' => 'FV-K 1/2025']),
                'headers'  => [],
            ];
        });
    }

    // ──────────────────────────────────────────────────────────
    // BUG 6: KSeF polling scheduled for correction invoices
    // ──────────────────────────────────────────────────────────

    public function testKsefPollingScheduledForCorrectionWhenKsefEnabled(): void
    {
        $this->setSettings(['ksef_auto_send' => 'yes']);
        $integration = $this->createIntegration();
        $this->mockSuccessfulApi();

        $order = $this->createOrder([
            'meta' => [
                '_fakturownia_invoice_id'     => 100,
                '_fakturownia_invoice_number' => 'FV 1/2025',
            ],
        ]);

        $integration->processAction($order, [
            'trigger'       => 'order_fully_refunded',
            'is_revoke_hook' => 'no',
        ]);

        $events = $GLOBALS['_fchub_test_scheduled_events'];
        $this->assertNotEmpty($events, 'Should schedule KSeF polling for correction invoice');

        $ksefEvent = $events[0];
        $this->assertSame('fchub_fakturownia_check_ksef_status', $ksefEvent['hook']);
        $this->assertSame(42, $ksefEvent['args'][0]); // order id
        $this->assertSame(200, $ksefEvent['args'][1]); // correction invoice id
    }

    public function testNoKsefPollingForCorrectionWhenKsefDisabled(): void
    {
        $this->setSettings(['ksef_auto_send' => 'no']);
        $integration = $this->createIntegration();
        $this->mockSuccessfulApi();

        $order = $this->createOrder([
            'meta' => [
                '_fakturownia_invoice_id'     => 100,
                '_fakturownia_invoice_number' => 'FV 1/2025',
            ],
        ]);

        $integration->processAction($order, [
            'trigger'       => 'order_fully_refunded',
            'is_revoke_hook' => 'no',
        ]);

        $events = $GLOBALS['_fchub_test_scheduled_events'];
        $this->assertEmpty($events, 'Should NOT schedule KSeF polling when KSeF is disabled');
    }

    // ──────────────────────────────────────────────────────────
    // Invoice creation + KSeF polling
    // ──────────────────────────────────────────────────────────

    public function testInvoiceCreationSchedulesKsefPolling(): void
    {
        $this->setSettings(['ksef_auto_send' => 'yes']);
        $integration = $this->createIntegration();

        $this->mockApiResponse(['id' => 777, 'number' => 'FV 1/2025']);

        $order = $this->createOrder();

        $integration->processAction($order, [
            'trigger'        => 'order_paid_done',
            'is_revoke_hook' => 'no',
        ]);

        $events = $GLOBALS['_fchub_test_scheduled_events'];
        $this->assertNotEmpty($events);
        $this->assertSame('fchub_fakturownia_check_ksef_status', $events[0]['hook']);
        $this->assertSame(42, $events[0]['args'][0]);
        $this->assertSame(777, $events[0]['args'][1]);
    }

    // ──────────────────────────────────────────────────────────
    // BUG 9: Partial refund routing
    // ──────────────────────────────────────────────────────────

    public function testPartialRefundLogsWarningInsteadOfCorrection(): void
    {
        $integration = $this->createIntegration();

        // No API mock needed — partial refund shouldn't hit API
        $order = $this->createOrder([
            'meta' => ['_fakturownia_invoice_id' => 100],
        ]);

        $integration->processAction($order, [
            'trigger'        => 'order_partially_refunded',
            'is_revoke_hook' => 'no',
        ]);

        $logs = $order->getTestLogs();
        $this->assertNotEmpty($logs);
        $this->assertSame('warning', $logs[0]['level']);
        $this->assertStringContainsString('Partial refund', $logs[0]['title']);

        // Should NOT create a correction invoice
        $this->assertNull($order->getMeta('_fakturownia_correction_id'));
    }

    // ──────────────────────────────────────────────────────────
    // Revoke hook triggers refund
    // ──────────────────────────────────────────────────────────

    public function testRevokeHookTriggersRefund(): void
    {
        $integration = $this->createIntegration();
        $this->mockSuccessfulApi();

        $order = $this->createOrder([
            'meta' => ['_fakturownia_invoice_id' => 100],
        ]);

        $integration->processAction($order, [
            'trigger'        => 'whatever',
            'is_revoke_hook' => 'yes',
        ]);

        // Should have created correction
        $this->assertNotNull($order->getMeta('_fakturownia_correction_id'));
    }
}

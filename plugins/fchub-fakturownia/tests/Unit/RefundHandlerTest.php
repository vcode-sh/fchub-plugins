<?php

declare(strict_types=1);

namespace FChubFakturownia\Tests\Unit;

use FChubFakturownia\API\FakturowniaAPI;
use FChubFakturownia\Handler\RefundHandler;
use FChubFakturownia\Tests\PluginTestCase;

/**
 * Tests for RefundHandler — covers BUG 9 (partial refund warning)
 * and correction invoice creation.
 */
final class RefundHandlerTest extends PluginTestCase
{
    private function createHandler(): RefundHandler
    {
        $this->setSettings();
        return new RefundHandler(new FakturowniaAPI('testfirma', 'test-token'));
    }

    // ──────────────────────────────────────────────────────────
    // BUG 9: Partial refund warning
    // ──────────────────────────────────────────────────────────

    public function testPartialRefundLogsWarning(): void
    {
        $handler = $this->createHandler();
        $order = $this->createOrder();

        $handler->handlePartialRefund($order);

        $logs = $order->getTestLogs();
        $this->assertCount(1, $logs);
        $this->assertSame('warning', $logs[0]['level']);
        $this->assertStringContainsString('Partial refund', $logs[0]['title']);
        $this->assertStringContainsString('manually', $logs[0]['content']);
    }

    // ──────────────────────────────────────────────────────────
    // Full refund — correction invoice
    // ──────────────────────────────────────────────────────────

    public function testCorrectionCreatedForFullRefund(): void
    {
        $handler = $this->createHandler();

        $requestCount = 0;
        $this->mockApiHandler(function ($method, $url) use (&$requestCount) {
            $requestCount++;
            if (strpos($url, '/invoices/100.json') !== false && $method === 'GET') {
                return [
                    'response' => ['code' => 200],
                    'body'     => json_encode([
                        'id'        => 100,
                        'number'    => 'FV 1/2025',
                        'positions' => [
                            ['name' => 'Widget', 'quantity' => 2, 'total_price_gross' => '246.00', 'tax' => 23, 'quantity_unit' => 'szt'],
                        ],
                    ]),
                    'headers' => [],
                ];
            }
            // POST correction
            return [
                'response' => ['code' => 200],
                'body'     => json_encode([
                    'id'     => 200,
                    'number' => 'FV-K 1/2025',
                ]),
                'headers' => [],
            ];
        });

        $order = $this->createOrder([
            'meta' => [
                '_fakturownia_invoice_id'     => 100,
                '_fakturownia_invoice_number' => 'FV 1/2025',
            ],
        ]);

        $result = $handler->createCorrectionInvoice($order);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(200, $result['id']);
        $this->assertSame('FV-K 1/2025', $order->getMeta('_fakturownia_correction_number'));
    }

    public function testCorrectionRefusedWithoutOriginalInvoice(): void
    {
        $handler = $this->createHandler();
        $order = $this->createOrder(); // No invoice meta

        $result = $handler->createCorrectionInvoice($order);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('No original invoice', $result['error']);
    }

    public function testDuplicateCorrectionPrevented(): void
    {
        $handler = $this->createHandler();

        $order = $this->createOrder([
            'meta' => [
                '_fakturownia_invoice_id'  => 100,
                '_fakturownia_correction_id' => 200,
            ],
        ]);

        $result = $handler->createCorrectionInvoice($order);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('already exists', $result['error']);
    }

    public function testCorrectionPositionsZeroOutOriginal(): void
    {
        $handler = $this->createHandler();
        $capturedBody = null;

        $this->mockApiHandler(function ($method, $url, $args) use (&$capturedBody) {
            if ($method === 'POST') {
                $capturedBody = json_decode($args['body'], true);
            }
            if (strpos($url, '/invoices/100.json') !== false && $method === 'GET') {
                return [
                    'response' => ['code' => 200],
                    'body'     => json_encode([
                        'id'        => 100,
                        'positions' => [
                            ['name' => 'Item A', 'quantity' => 3, 'total_price_gross' => '369.00', 'tax' => 23, 'quantity_unit' => 'szt'],
                            ['name' => 'Item B', 'quantity' => 1, 'total_price_gross' => '50.00', 'tax' => 8, 'quantity_unit' => 'szt'],
                        ],
                    ]),
                    'headers' => [],
                ];
            }
            return [
                'response' => ['code' => 200],
                'body'     => json_encode(['id' => 200, 'number' => 'K-1']),
                'headers'  => [],
            ];
        });

        $order = $this->createOrder([
            'meta' => ['_fakturownia_invoice_id' => 100],
        ]);

        $handler->createCorrectionInvoice($order);

        $this->assertNotNull($capturedBody);
        $positions = $capturedBody['invoice']['positions'] ?? [];
        $this->assertCount(2, $positions);

        // All corrections should zero out the quantities and totals
        foreach ($positions as $pos) {
            $this->assertSame(0, $pos['quantity']);
            $this->assertSame(0, $pos['total_price_gross']);
            $this->assertArrayHasKey('correction_before_attributes', $pos);
        }

        // Verify original values are preserved in correction_before_attributes
        $this->assertSame(3, $positions[0]['correction_before_attributes']['quantity']);
        $this->assertSame('369.00', $positions[0]['correction_before_attributes']['total_price_gross']);
    }

    // ──────────────────────────────────────────────────────────
    // API error during correction
    // ──────────────────────────────────────────────────────────

    public function testApiErrorDuringCorrectionIsLogged(): void
    {
        $handler = $this->createHandler();

        $this->mockApiHandler(function ($method, $url) {
            if ($method === 'GET') {
                return [
                    'response' => ['code' => 200],
                    'body'     => json_encode(['id' => 100, 'positions' => []]),
                    'headers'  => [],
                ];
            }
            return [
                'response' => ['code' => 500],
                'body'     => json_encode(['error' => 'Internal server error']),
                'headers'  => [],
            ];
        });

        $order = $this->createOrder([
            'meta' => ['_fakturownia_invoice_id' => 100],
        ]);

        $result = $handler->createCorrectionInvoice($order);

        $this->assertArrayHasKey('error', $result);
        $logs = $order->getTestLogs();
        $this->assertNotEmpty($logs);
        $this->assertSame('error', $logs[0]['level']);
    }
}

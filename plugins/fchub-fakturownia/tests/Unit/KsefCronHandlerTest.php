<?php

declare(strict_types=1);

namespace FChubFakturownia\Tests\Unit;

use FChubFakturownia\Tests\PluginTestCase;

/**
 * Tests for the KSeF cron handler — covers BUG 6 (correction invoice KSeF polling).
 *
 * The cron handler is defined inline in fchub-fakturownia.php, so we test the logic
 * by simulating what the handler does with our Order mock.
 */
final class KsefCronHandlerTest extends PluginTestCase
{
    /**
     * Simulate the cron handler logic from fchub-fakturownia.php.
     * We extract the handler into a callable for testability.
     */
    private function runCronHandler(int $orderId, int $fakturowniaInvoiceId, array $invoiceResponse): void
    {
        $order = $GLOBALS['_fchub_test_orders'][$orderId] ?? null;
        if (!$order) {
            return;
        }

        $this->setSettings();

        // Simulate the API response
        $this->mockApiResponse($invoiceResponse);

        $api = new \FChubFakturownia\API\FakturowniaAPI('testfirma', 'test-token');
        $invoice = $api->getInvoice($fakturowniaInvoiceId);

        if (isset($invoice['error'])) {
            return;
        }

        // Replicate the cron handler logic
        $isCorrection = ($fakturowniaInvoiceId == $order->getMeta('_fakturownia_correction_id'));
        $metaPrefix = $isCorrection ? '_fakturownia_correction_ksef' : '_fakturownia_ksef';

        $govStatus = $invoice['gov_status'] ?? null;
        if ($govStatus) {
            $order->updateMeta($metaPrefix . '_status', $govStatus);
        }

        $govId = $invoice['gov_id'] ?? null;
        if ($govId) {
            $order->updateMeta($metaPrefix . '_id', $govId);
        }

        $govLink = $invoice['gov_verification_link'] ?? null;
        if ($govLink) {
            $order->updateMeta($metaPrefix . '_link', $govLink);
        }

        $retryKey = $metaPrefix . '_retry_count';

        if ($govStatus === 'processing') {
            $retryCount = (int) $order->getMeta($retryKey, 0);
            if ($retryCount >= 30) {
                $order->addLog('KSeF timeout', 'Gave up', 'warning', 'Fakturownia');
                return;
            }
            $order->updateMeta($retryKey, $retryCount + 1);
            wp_schedule_single_event(
                time() + 120,
                'fchub_fakturownia_check_ksef_status',
                [$orderId, $fakturowniaInvoiceId]
            );
        }

        if ($govStatus === 'ok' && $govId) {
            $order->deleteMeta($retryKey);
            $order->addLog('KSeF success', "KSeF: $govId", 'info', 'Fakturownia');
        } elseif ($govStatus === 'send_error') {
            $order->deleteMeta($retryKey);
            $errors = $invoice['gov_error_messages'] ?? [];
            $errorText = is_array($errors) ? implode('; ', $errors) : (string) $errors;
            $order->addLog('KSeF failed', $errorText, 'error', 'Fakturownia');
        }
    }

    // ──────────────────────────────────────────────────────────
    // Regular invoice KSeF polling
    // ──────────────────────────────────────────────────────────

    public function testRegularInvoiceKsefSuccess(): void
    {
        $order = $this->createOrder([
            'id'   => 42,
            'meta' => ['_fakturownia_invoice_id' => 100],
        ]);
        $GLOBALS['_fchub_test_orders'][42] = $order;

        $this->runCronHandler(42, 100, [
            'id'                    => 100,
            'gov_status'            => 'ok',
            'gov_id'                => 'KSeF-2025-001',
            'gov_verification_link' => 'https://ksef.gov.pl/verify/001',
        ]);

        $this->assertSame('ok', $order->getMeta('_fakturownia_ksef_status'));
        $this->assertSame('KSeF-2025-001', $order->getMeta('_fakturownia_ksef_id'));
        $this->assertSame('https://ksef.gov.pl/verify/001', $order->getMeta('_fakturownia_ksef_link'));

        $logs = $order->getTestLogs();
        $this->assertSame('info', $logs[0]['level']);
    }

    public function testRegularInvoiceKsefProcessingSchedulesRetry(): void
    {
        $order = $this->createOrder([
            'id'   => 42,
            'meta' => ['_fakturownia_invoice_id' => 100],
        ]);
        $GLOBALS['_fchub_test_orders'][42] = $order;

        $this->runCronHandler(42, 100, [
            'id'         => 100,
            'gov_status' => 'processing',
        ]);

        $this->assertSame('processing', $order->getMeta('_fakturownia_ksef_status'));
        $this->assertSame(1, $order->getMeta('_fakturownia_ksef_retry_count'));

        $events = $GLOBALS['_fchub_test_scheduled_events'];
        $this->assertCount(1, $events);
        $this->assertSame('fchub_fakturownia_check_ksef_status', $events[0]['hook']);
    }

    public function testRegularInvoiceKsefSendError(): void
    {
        $order = $this->createOrder([
            'id'   => 42,
            'meta' => ['_fakturownia_invoice_id' => 100],
        ]);
        $GLOBALS['_fchub_test_orders'][42] = $order;

        $this->runCronHandler(42, 100, [
            'id'                 => 100,
            'gov_status'         => 'send_error',
            'gov_error_messages' => ['Invalid buyer NIP', 'Missing seller data'],
        ]);

        $this->assertSame('send_error', $order->getMeta('_fakturownia_ksef_status'));

        $logs = $order->getTestLogs();
        $this->assertSame('error', $logs[0]['level']);
        $this->assertStringContainsString('Invalid buyer NIP', $logs[0]['content']);
    }

    // ──────────────────────────────────────────────────────────
    // BUG 6: Correction invoice KSeF polling uses separate meta keys
    // ──────────────────────────────────────────────────────────

    public function testCorrectionInvoiceKsefUsesCorrectionMetaKeys(): void
    {
        $order = $this->createOrder([
            'id'   => 42,
            'meta' => [
                '_fakturownia_invoice_id'    => 100,
                '_fakturownia_correction_id' => 200,
            ],
        ]);
        $GLOBALS['_fchub_test_orders'][42] = $order;

        $this->runCronHandler(42, 200, [
            'id'                    => 200,
            'gov_status'            => 'ok',
            'gov_id'                => 'KSeF-CORR-001',
            'gov_verification_link' => 'https://ksef.gov.pl/verify/corr001',
        ]);

        // Should use correction prefix, NOT regular prefix
        $this->assertSame('ok', $order->getMeta('_fakturownia_correction_ksef_status'));
        $this->assertSame('KSeF-CORR-001', $order->getMeta('_fakturownia_correction_ksef_id'));
        $this->assertSame('https://ksef.gov.pl/verify/corr001', $order->getMeta('_fakturownia_correction_ksef_link'));

        // Regular invoice meta should be untouched
        $this->assertNull($order->getMeta('_fakturownia_ksef_status'));
        $this->assertNull($order->getMeta('_fakturownia_ksef_id'));
    }

    public function testCorrectionInvoiceRetryUsesOwnCounter(): void
    {
        $order = $this->createOrder([
            'id'   => 42,
            'meta' => [
                '_fakturownia_invoice_id'      => 100,
                '_fakturownia_correction_id'   => 200,
                '_fakturownia_ksef_status'      => 'ok', // Original was already OK
                '_fakturownia_ksef_id'          => 'KSeF-ORIG-001',
            ],
        ]);
        $GLOBALS['_fchub_test_orders'][42] = $order;

        $this->runCronHandler(42, 200, [
            'id'         => 200,
            'gov_status' => 'processing',
        ]);

        // Correction retry count should be separate from original
        $this->assertSame(1, $order->getMeta('_fakturownia_correction_ksef_retry_count'));
        $this->assertNull($order->getMeta('_fakturownia_ksef_retry_count'));

        // Original KSeF data untouched
        $this->assertSame('ok', $order->getMeta('_fakturownia_ksef_status'));
        $this->assertSame('KSeF-ORIG-001', $order->getMeta('_fakturownia_ksef_id'));
    }

    // ──────────────────────────────────────────────────────────
    // Retry limit
    // ──────────────────────────────────────────────────────────

    public function testRetryLimitStopsPolling(): void
    {
        $order = $this->createOrder([
            'id'   => 42,
            'meta' => [
                '_fakturownia_invoice_id'         => 100,
                '_fakturownia_ksef_retry_count'   => 30, // Already at limit
            ],
        ]);
        $GLOBALS['_fchub_test_orders'][42] = $order;

        $this->runCronHandler(42, 100, [
            'id'         => 100,
            'gov_status' => 'processing',
        ]);

        // Should NOT schedule another retry
        $events = $GLOBALS['_fchub_test_scheduled_events'];
        $this->assertEmpty($events);

        // Should log warning
        $logs = $order->getTestLogs();
        $this->assertSame('warning', $logs[0]['level']);
    }

    public function testRetryCountIncrements(): void
    {
        $order = $this->createOrder([
            'id'   => 42,
            'meta' => [
                '_fakturownia_invoice_id'        => 100,
                '_fakturownia_ksef_retry_count'  => 15,
            ],
        ]);
        $GLOBALS['_fchub_test_orders'][42] = $order;

        $this->runCronHandler(42, 100, [
            'id'         => 100,
            'gov_status' => 'processing',
        ]);

        $this->assertSame(16, $order->getMeta('_fakturownia_ksef_retry_count'));
    }

    public function testSuccessfulStatusCleansRetryCount(): void
    {
        $order = $this->createOrder([
            'id'   => 42,
            'meta' => [
                '_fakturownia_invoice_id'        => 100,
                '_fakturownia_ksef_retry_count'  => 5,
            ],
        ]);
        $GLOBALS['_fchub_test_orders'][42] = $order;

        $this->runCronHandler(42, 100, [
            'id'         => 100,
            'gov_status' => 'ok',
            'gov_id'     => 'KSeF-123',
        ]);

        $this->assertNull($order->getMeta('_fakturownia_ksef_retry_count'));
    }

    public function testErrorStatusCleansRetryCount(): void
    {
        $order = $this->createOrder([
            'id'   => 42,
            'meta' => [
                '_fakturownia_invoice_id'        => 100,
                '_fakturownia_ksef_retry_count'  => 10,
            ],
        ]);
        $GLOBALS['_fchub_test_orders'][42] = $order;

        $this->runCronHandler(42, 100, [
            'id'                 => 100,
            'gov_status'         => 'send_error',
            'gov_error_messages' => ['Bad data'],
        ]);

        $this->assertNull($order->getMeta('_fakturownia_ksef_retry_count'));
    }
}

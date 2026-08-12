<?php

declare(strict_types=1);

namespace CartShift\Tests\Integration\FluentCart;

use CartShift\Tests\Integration\Contract\InstalledContractTestCase;

require_once dirname(__DIR__) . '/Contract/InstalledContractTestCase.php';

final class HistoricalOrderReadModelTest extends InstalledContractTestCase
{
    public function testAtomicHistoricalGraphIsSilentAndReadableAcrossInstalledSurfaces(): void
    {
        $result = $this->runRuntimeContract('historical-order-read-model');

        self::assertTrue($result['same_database_session']);
        self::assertSame(3, $result['start_transaction_count']);
        self::assertSame(3, $result['commit_count']);
        self::assertSame(0, $result['rollback_count']);
        self::assertTrue($result['retry_reused']);
        self::assertTrue($result['retry_byte_stable']);
        self::assertTrue($result['aggregate_retry_reused']);
        self::assertTrue($result['aggregate_retry_byte_stable']);
        self::assertSame(['EUR' => 3000, 'PLN' => 12145], $result['purchase_value']);
        self::assertSame(2, $result['purchase_count']);
        self::assertSame(24145, $result['ltv']);
        self::assertSame(12072.5, $result['aov']);
        self::assertSame('2026-07-01 10:00:00', $result['first_purchase_date']);
        self::assertSame('2026-08-01 10:00:00', $result['last_purchase_date']);
        self::assertSame(1, $result['customer_stage_receipts']);
        self::assertSame(1, $result['customer_aggregate_receipts']);
        self::assertTrue($result['customer_model_aggregate_readable']);
        self::assertTrue($result['customer_mcp_aggregate_readable']);
        self::assertTrue($result['customer_mcp_list_readable']);
        self::assertTrue($result['customer_report_readable']);
        self::assertTrue($result['typed_parent_readable']);
        self::assertTrue($result['typed_renewal_readable']);
        self::assertTrue($result['parent_renewal_history_readable']);
        self::assertNull($result['receipt_number']);
        self::assertSame(0, $result['receipt_allocator_calls']);
        self::assertSame(0, $result['invoice_callbacks']);
        self::assertSame(0, $result['lifecycle_side_effects']);
        self::assertSame(2, $result['transaction_count']);
        self::assertTrue($result['refund_parent_matches']);
        self::assertSame(0, $result['max_refundable']);
        self::assertTrue($result['admin_detail_readable']);
        self::assertTrue($result['customer_list_readable']);
        self::assertTrue($result['customer_detail_readable']);
        self::assertTrue($result['receipt_rendered']);
        self::assertTrue($result['mcp_readable']);
        self::assertTrue($result['revenue_report_readable']);
        self::assertTrue($result['order_report_readable']);
        self::assertTrue($result['refund_report_readable']);
        self::assertTrue($result['product_report_readable']);
        self::assertTrue($result['download_permission_granted']);
        self::assertSame('Customer-visible canonical note', $result['visible_note']);
        self::assertTrue($result['private_note_reconciled']);
        self::assertFalse($result['display_identity_leaks_source_order_number']);
    }
}

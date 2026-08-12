<?php

declare(strict_types=1);

namespace CartShift\Tests\Integration\Contract;

final class HistoricalGatewayIsolationTest extends InstalledContractTestCase
{
    public function testEveryInstalledRefundEntryPointRejectsHistoricalPaymentsWithoutSideEffects(): void
    {
        $result = $this->runRuntimeContract('historical-payment-isolation');

        self::assertSame(0, $result['max_refundable']);
        self::assertSame('invalid_refund_amount', $result['direct_error']);
        self::assertSame(422, $result['admin_status']);
        self::assertSame('invalid_refund_amount', $result['mcp_execute_error']);
        self::assertTrue($result['mcp_preview_was_inert']);
        self::assertTrue($result['transaction_rows_unchanged']);
        self::assertTrue($result['parent_meta_unchanged']);
        self::assertSame(0, $result['gateway_calls']);
        self::assertSame(0, $result['manual_refund_filter_calls']);
        self::assertSame(0, $result['refund_event_calls']);
        self::assertFalse($result['order_detail_contains_full_reference']);
        self::assertTrue($result['order_detail_contains_redacted_reference']);
        self::assertSame('wc_migrated', $result['executable_payment_method']);
        self::assertSame('stripe', $result['nested_source_gateway']);
        self::assertSame('', $result['vendor_charge_id']);
    }
}

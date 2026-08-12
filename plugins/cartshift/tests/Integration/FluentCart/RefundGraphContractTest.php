<?php

declare(strict_types=1);

namespace CartShift\Tests\Integration\FluentCart;

use CartShift\Tests\Integration\Contract\InstalledContractTestCase;

require_once dirname(__DIR__) . '/Contract/InstalledContractTestCase.php';

final class RefundGraphContractTest extends InstalledContractTestCase
{
    public function testDirectHistoricalGraphMatchesInstalledRefundReadsWithoutAnExecutableGateway(): void
    {
        $result = $this->runRuntimeContract('refund-graph-contract');

        self::assertSame(2, $result['transaction_count']);
        self::assertSame('succeeded', $result['charge_status']);
        self::assertSame('refunded', $result['refund_status']);
        self::assertSame(2500, $result['parent_refunded_total']);
        self::assertTrue($result['refund_parent_matches']);
        self::assertSame('partially_refunded', $result['order_payment_status']);
        self::assertSame(25, $result['reported_refund_amount']);
        self::assertSame(1, $result['reported_refund_orders']);
        self::assertTrue($result['dedup_returned_existing']);
        self::assertTrue($result['dedup_was_read_only']);
        self::assertSame(0, $result['max_refundable']);
        self::assertSame('invalid_refund_amount', $result['direct_refund_error']);
        self::assertSame(0, $result['gateway_calls']);
        self::assertSame(0, $result['refund_event_calls']);
        self::assertTrue($result['detail_has_redacted_charge_reference']);
        self::assertTrue($result['detail_has_redacted_refund_reference']);
        self::assertFalse($result['detail_has_full_reference']);
    }
}

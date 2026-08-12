<?php

declare(strict_types=1);

namespace CartShift\Tests\Integration\Contract;

final class OrderMoneyContractTest extends InstalledContractTestCase
{
    public function testProjectionMatchesInstalledCheckoutAndTaxPersistenceContracts(): void
    {
        $result = $this->runRuntimeContract('order-money-contract');

        self::assertTrue($result['exclusive_header_matches']);
        self::assertTrue($result['exclusive_product_item_matches']);
        self::assertTrue($result['exclusive_fee_item_matches']);
        self::assertTrue($result['exclusive_coupon_matches']);
        self::assertTrue($result['exclusive_tax_row_matches']);
        self::assertTrue($result['inclusive_header_matches']);
        self::assertTrue($result['inclusive_product_item_matches']);
        self::assertTrue($result['inclusive_fee_item_matches']);
        self::assertTrue($result['zero_tax_sentinel_matches']);
        self::assertSame([0, 1], $result['shipping_remainder_by_rate']);
        self::assertSame(2, $result['compound_rate_count']);
        self::assertTrue($result['projection_reconciles']);
        self::assertSame('target_schema_unrepresentable', $result['negative_fee_reason']);
    }
}

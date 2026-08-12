<?php

declare(strict_types=1);

namespace CartShift\Tests\Integration\Contract;

require_once __DIR__ . '/InstalledContractTestCase.php';

final class OrderSemanticInvariantTest extends InstalledContractTestCase
{
    public function testIndependentNegativeVariantsBreakTheLedgerContract(): void
    {
        $result = $this->runRuntimeContract('woo-order-ledger');

        self::assertSame('order_money_mismatch', $result['total_drift_reason']);
        self::assertSame('order_tax_mismatch', $result['tax_drift_reason']);
        self::assertSame('order_census_duplicate', $result['duplicate_selection_reason']);
        self::assertSame('selection_identity_missing', $result['missing_selection_reason']);
    }
}

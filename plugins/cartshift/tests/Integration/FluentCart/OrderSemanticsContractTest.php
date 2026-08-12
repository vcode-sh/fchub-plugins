<?php

declare(strict_types=1);

namespace CartShift\Tests\Integration\FluentCart;

use CartShift\Tests\Integration\Contract\InstalledContractTestCase;

require_once dirname(__DIR__) . '/Contract/InstalledContractTestCase.php';

final class OrderSemanticsContractTest extends InstalledContractTestCase
{
    public function testCanonicalBusinessAndNonphysicalFulfilmentSurviveInstalledReadModels(): void
    {
        $result = $this->runRuntimeContract('order-semantics-contract');

        self::assertSame('5291831115', $result['native_vat_number']);
        self::assertSame('5291831115', $result['fakturownia_nip_alias']);
        self::assertSame('5291831115', $result['business_tax_number']);
        self::assertTrue($result['business_tax_validated']);
        self::assertTrue($result['fakturownia_buyer_company']);
        self::assertSame('5291831115', $result['fakturownia_buyer_tax_no']);
        self::assertSame('Example Sp. z o.o.', $result['fakturownia_buyer_name']);
        self::assertSame(2, $result['mcp_fulfilled_quantity']);
        self::assertSame('none', $result['mcp_shipping_status']);
        self::assertSame('Courier', $result['shipping_method_title']);
        self::assertSame(0, $result['http_calls']);
    }
}

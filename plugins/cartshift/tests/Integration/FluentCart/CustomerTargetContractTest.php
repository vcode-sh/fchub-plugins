<?php

declare(strict_types=1);

namespace CartShift\Tests\Integration\FluentCart;

use CartShift\Tests\Integration\Contract\InstalledContractTestCase;

require_once dirname(__DIR__) . '/Contract/InstalledContractTestCase.php';

final class CustomerTargetContractTest extends InstalledContractTestCase
{
    public function testCanonicalCustomerWritesAtomicallyWithoutAccountsOrLifecycleSideEffects(): void
    {
        $result = $this->runRuntimeContract('customer-target-contract');

        self::assertSame(1, $result['user_id']);
        self::assertSame(['billing', 'shipping'], $result['address_types']);
        self::assertSame(['Billing City', 'Shipping City'], $result['address_cities']);
        self::assertSame(0, $result['new_wordpress_users']);
        self::assertSame(0, $result['mail_calls']);
        self::assertSame(0, $result['customer_lifecycle_hooks']);
        self::assertTrue($result['retry_reused']);
        self::assertTrue($result['retry_byte_stable']);
        self::assertTrue($result['fluentcart_model_readable']);
        self::assertTrue($result['duplicate_guest_target_ids_distinct']);
        self::assertSame(2, $result['duplicate_guest_rows']);
    }
}

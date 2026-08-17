<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Integration;

use FChubMultiCurrency\Integration\OrderSnapshotHooks;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class OrderSnapshotHooksTest extends TestCase
{
    #[Test]
    public function testRegisterAddsCheckoutAndOrderHooks(): void
    {
        OrderSnapshotHooks::register();

        $registered = array_column($GLOBALS['wp_actions_registered'], 'tag');

        $this->assertContains('fluent_cart/before_payment_methods', $registered);
        $this->assertContains('fluent_cart/checkout/prepare_other_data', $registered);
        $this->assertContains('fluent_cart/order_paid_done', $registered);
    }
}

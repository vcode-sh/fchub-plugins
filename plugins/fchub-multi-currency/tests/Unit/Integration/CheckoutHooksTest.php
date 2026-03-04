<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Integration;

use FChubMultiCurrency\Integration\CheckoutHooks;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class CheckoutHooksTest extends TestCase
{
    #[Test]
    public function testRegisterAddsExpectedHooks(): void
    {
        CheckoutHooks::register();

        $registered = array_column($GLOBALS['wp_actions_registered'] ?? [], 'tag');
        $filters = array_column($GLOBALS['wp_filters_registered'], 'tag');

        $this->assertNotContains('fluent_cart/checkout/before_patch_checkout_data', $registered);
        $this->assertContains('fluent_cart/checkout/after_patch_checkout_data_fragments', $filters);
    }
}

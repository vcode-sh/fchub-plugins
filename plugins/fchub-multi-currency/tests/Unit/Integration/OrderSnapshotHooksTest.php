<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Integration;

use FChubMultiCurrency\Integration\OrderSnapshotHooks;
use FChubMultiCurrency\Support\Constants;
use FChubMultiCurrency\Tests\Support\TestCase;
use FluentCart\Api\CurrencySettings;
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

    /**
     * A manual or API order never runs checkout capture, so the snapshot falls
     * back to the customer's saved preference. The order is shaped like
     * FluentCart 1.6.1 ships it: no user_id column, the WP user on the
     * customer relation. order_paid_done fires from Action Scheduler, so no
     * request cookie or logged-in user is available — only the relation.
     */
    #[Test]
    public function testSnapshotFallbackReadsTheUserThroughTheCustomerRelation(): void
    {
        CurrencySettings::setMock(['currency' => 'USD']);
        $this->setOption('fchub_mc_settings', [
            'enabled'            => 'yes',
            'base_currency'      => 'USD',
            'display_currencies' => [
                ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimals' => 2, 'position' => 'left'],
            ],
        ]);
        $this->setWpdbMockRow([
            'base_currency'  => 'USD',
            'quote_currency' => 'EUR',
            'rate'           => '0.92000000',
            'provider'       => 'manual',
            'fetched_at'     => gmdate('Y-m-d H:i:s'),
        ]);
        $this->setUserMeta(7, Constants::USER_META_KEY, 'EUR');

        $order = new class {
            public ?object $customer;
            public array $savedMeta = [];
            public function __construct() { $this->customer = (object) ['user_id' => 7]; }
            public function getMeta(string $key, mixed $default = null): mixed { return $default; }
            public function updateMeta(string $key, mixed $value): void { $this->savedMeta[$key] = $value; }
        };

        OrderSnapshotHooks::saveSnapshot(['order' => $order]);

        $this->assertSame('EUR', $order->savedMeta['_fchub_mc_display_currency'] ?? null);
        $this->assertSame('0.92000000', $order->savedMeta['_fchub_mc_rate'] ?? null);
    }
}

<?php

declare(strict_types=1);

namespace FChubWishlist\Tests\Unit\Domain;

use FChubWishlist\Domain\PurchaseWatcher;
use FChubWishlist\Tests\Support\MockBuilder;
use FChubWishlist\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

class PurchaseWatcherTest extends TestCase
{
    #[Test]
    public function testRegisterHooksOrderPaidDone(): void
    {
        PurchaseWatcher::register();

        $hooks = $GLOBALS['wp_actions_registered'];
        $hookTags = array_column($hooks, 'tag');

        $this->assertContains('fluent_cart/order_paid_done', $hookTags);
    }

    #[Test]
    public function testOnOrderPaidExitsEarlyWhenSettingDisabled(): void
    {
        $this->setOption('fchub_wishlist_settings', ['auto_remove_purchased' => 'no']);

        $order = MockBuilder::order(['user_id' => 1, 'order_items' => [
            MockBuilder::orderItem(['post_id' => 100, 'object_id' => 200]),
        ]]);

        PurchaseWatcher::onOrderPaid(['order' => $order]);

        // No wishlist query should happen since setting is disabled
        $this->assertHookNotFired('fchub_wishlist/items_auto_removed');
    }

    #[Test]
    public function testOnOrderPaidExitsEarlyWhenNoOrder(): void
    {
        PurchaseWatcher::onOrderPaid([]);
        $this->assertHookNotFired('fchub_wishlist/items_auto_removed');
    }

    #[Test]
    public function testOnOrderPaidExitsEarlyForGuestUser(): void
    {
        $order = MockBuilder::order(['user_id' => 0, 'order_items' => [
            MockBuilder::orderItem(['post_id' => 100, 'object_id' => 200]),
        ]]);

        PurchaseWatcher::onOrderPaid(['order' => $order]);
        $this->assertHookNotFired('fchub_wishlist/items_auto_removed');
    }

    #[Test]
    public function testOnOrderPaidExtractsProductAndVariantIds(): void
    {
        // User has no wishlist, so auto-remove returns 0
        $this->setWpdbMockRow(null);

        $order = MockBuilder::order([
            'user_id'     => 42,
            'order_items' => [
                MockBuilder::orderItem(['post_id' => 100, 'object_id' => 200]),
                MockBuilder::orderItem(['post_id' => 101, 'object_id' => 201]),
            ],
        ]);

        PurchaseWatcher::onOrderPaid(['order' => $order]);

        // Since no wishlist found, items_auto_removed should not fire
        $this->assertHookNotFired('fchub_wishlist/items_auto_removed');
    }

    #[Test]
    public function testOnOrderPaidExitsWhenNoOrderItems(): void
    {
        $order = MockBuilder::order([
            'user_id'     => 42,
            'order_items' => [],
        ]);

        PurchaseWatcher::onOrderPaid(['order' => $order]);
        $this->assertHookNotFired('fchub_wishlist/items_auto_removed');
    }

    #[Test]
    public function testOnOrderPaidDefaultsToEnabledSetting(): void
    {
        // Default setting is 'yes', so auto-remove should proceed
        $this->setWpdbMockRow(null); // No wishlist found

        $order = MockBuilder::order([
            'user_id'     => 42,
            'order_items' => [
                MockBuilder::orderItem(['post_id' => 100, 'object_id' => 200]),
            ],
        ]);

        PurchaseWatcher::onOrderPaid(['order' => $order]);

        // Query for user wishlist was attempted
        $this->assertQueryContains('user_id');
    }
}

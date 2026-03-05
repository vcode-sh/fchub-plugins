<?php

declare(strict_types=1);

namespace FChubWishlist\Tests\Unit\Actions;

use FChubWishlist\Domain\Actions\AutoRemovePurchasedAction;
use FChubWishlist\Storage\WishlistItemRepository;
use FChubWishlist\Storage\WishlistRepository;
use FChubWishlist\Tests\Support\MockBuilder;
use FChubWishlist\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

class AutoRemovePurchasedActionTest extends TestCase
{
    #[Test]
    public function testReturnsZeroForEmptyPurchasedItems(): void
    {
        $items = $this->createStub(WishlistItemRepository::class);
        $wishlists = $this->createStub(WishlistRepository::class);

        $action = new AutoRemovePurchasedAction($items, $wishlists);
        $result = $action->execute(42, [], 1000);

        $this->assertSame(0, $result);
    }

    #[Test]
    public function testReturnsZeroWhenUserHasNoWishlist(): void
    {
        $items = $this->createStub(WishlistItemRepository::class);
        $wishlists = $this->createStub(WishlistRepository::class);
        $wishlists->method('findByUserId')
            ->willReturn(null);

        $action = new AutoRemovePurchasedAction($items, $wishlists);
        $result = $action->execute(42, [
            ['product_id' => 100, 'variant_id' => 200],
        ], 1000);

        $this->assertSame(0, $result);
    }

    #[Test]
    public function testMatchingItemsRemoved(): void
    {
        $wishlist = MockBuilder::wishlist(['id' => 1, 'user_id' => 42, 'item_count' => 3]);

        $wishlists = $this->createStub(WishlistRepository::class);
        $wishlists->method('findByUserId')
            ->willReturn($wishlist);

        $items = $this->createMock(WishlistItemRepository::class);
        $items->method('findByWishlistId')
            ->willReturn([
                MockBuilder::wishlistItem(['id' => 10, 'product_id' => 100, 'variant_id' => 200]),
                MockBuilder::wishlistItem(['id' => 11, 'product_id' => 101, 'variant_id' => 201]),
            ]);

        $items->expects($this->once())
            ->method('deleteByIds')
            ->with([10])
            ->willReturn(1);

        $action = new AutoRemovePurchasedAction($items, $wishlists);
        $result = $action->execute(42, [
            ['product_id' => 100, 'variant_id' => 200],
            ['product_id' => 999, 'variant_id' => 888],
        ], 1000);

        $this->assertSame(1, $result);
    }

    #[Test]
    public function testNonMatchingItemsKept(): void
    {
        $wishlist = MockBuilder::wishlist(['id' => 1, 'user_id' => 42]);

        $wishlists = $this->createStub(WishlistRepository::class);
        $wishlists->method('findByUserId')
            ->willReturn($wishlist);

        $items = $this->createMock(WishlistItemRepository::class);
        $items->method('findByWishlistId')
            ->willReturn([
                MockBuilder::wishlistItem(['id' => 10, 'product_id' => 100, 'variant_id' => 200]),
            ]);

        $items->expects($this->never())
            ->method('deleteByIds');

        $action = new AutoRemovePurchasedAction($items, $wishlists);
        $result = $action->execute(42, [
            ['product_id' => 999, 'variant_id' => 888],
        ], 1000);

        $this->assertSame(0, $result);
    }

    #[Test]
    public function testCountRecalculatedAfterRemoval(): void
    {
        $wishlist = MockBuilder::wishlist(['id' => 1, 'user_id' => 42, 'item_count' => 5]);

        $wishlists = $this->createMock(WishlistRepository::class);
        $wishlists->method('findByUserId')
            ->willReturn($wishlist);

        $wishlists->expects($this->once())
            ->method('recalculateItemCount')
            ->with(1);

        $items = $this->createMock(WishlistItemRepository::class);
        $items->method('findByWishlistId')
            ->willReturn([
                MockBuilder::wishlistItem(['id' => 10, 'product_id' => 100, 'variant_id' => 200]),
            ]);
        $items->expects($this->once())
            ->method('deleteByIds')
            ->with([10])
            ->willReturn(1);

        $action = new AutoRemovePurchasedAction($items, $wishlists);
        $action->execute(42, [
            ['product_id' => 100, 'variant_id' => 200],
        ], 1000);
    }

    #[Test]
    public function testHookFiredAfterRemoval(): void
    {
        $wishlist = MockBuilder::wishlist(['id' => 1, 'user_id' => 42]);

        $wishlists = $this->createStub(WishlistRepository::class);
        $wishlists->method('findByUserId')
            ->willReturn($wishlist);

        $items = $this->createMock(WishlistItemRepository::class);
        $items->method('findByWishlistId')
            ->willReturn([
                MockBuilder::wishlistItem(['id' => 10, 'product_id' => 100, 'variant_id' => 200]),
            ]);
        $items->method('deleteByIds')
            ->willReturn(1);

        $action = new AutoRemovePurchasedAction($items, $wishlists);
        $action->execute(42, [
            ['product_id' => 100, 'variant_id' => 200],
        ], 1000);

        $fired = $this->getActionsFired('fchub_wishlist/items_auto_removed');
        $this->assertCount(1, $fired);
        $this->assertSame(42, $fired[0]['args'][0]);        // userId
        $this->assertSame([100], $fired[0]['args'][1]);     // removedProductIds
        $this->assertSame(1, $fired[0]['args'][2]);         // wishlistId
        $this->assertSame(1000, $fired[0]['args'][3]);      // orderId
    }

    #[Test]
    public function testNoHookFiredWhenNothingRemoved(): void
    {
        $wishlist = MockBuilder::wishlist(['id' => 1, 'user_id' => 42]);

        $wishlists = $this->createStub(WishlistRepository::class);
        $wishlists->method('findByUserId')
            ->willReturn($wishlist);

        $items = $this->createMock(WishlistItemRepository::class);
        $items->method('findByWishlistId')
            ->willReturn([
                MockBuilder::wishlistItem(['id' => 10, 'product_id' => 100, 'variant_id' => 200]),
            ]);
        $items->expects($this->never())->method('deleteByIds');

        $action = new AutoRemovePurchasedAction($items, $wishlists);
        $action->execute(42, [
            ['product_id' => 999, 'variant_id' => 888],
        ], 1000);

        $this->assertHookNotFired('fchub_wishlist/items_auto_removed');
    }

    #[Test]
    public function testMultipleMatchingItemsAllRemoved(): void
    {
        $wishlist = MockBuilder::wishlist(['id' => 1, 'user_id' => 42]);

        $wishlists = $this->createStub(WishlistRepository::class);
        $wishlists->method('findByUserId')
            ->willReturn($wishlist);

        $items = $this->createMock(WishlistItemRepository::class);
        $items->method('findByWishlistId')
            ->willReturn([
                MockBuilder::wishlistItem(['id' => 10, 'product_id' => 100, 'variant_id' => 200]),
                MockBuilder::wishlistItem(['id' => 11, 'product_id' => 101, 'variant_id' => 201]),
                MockBuilder::wishlistItem(['id' => 12, 'product_id' => 102, 'variant_id' => 202]),
            ]);
        $items->expects($this->once())
            ->method('deleteByIds')
            ->with([10, 11, 12])
            ->willReturn(3);

        $action = new AutoRemovePurchasedAction($items, $wishlists);
        $result = $action->execute(42, [
            ['product_id' => 100, 'variant_id' => 200],
            ['product_id' => 101, 'variant_id' => 201],
            ['product_id' => 102, 'variant_id' => 202],
        ], 1000);

        $this->assertSame(3, $result);
    }
}

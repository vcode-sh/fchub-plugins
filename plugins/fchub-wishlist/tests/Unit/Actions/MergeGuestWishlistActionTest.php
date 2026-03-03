<?php

declare(strict_types=1);

namespace FChubWishlist\Tests\Unit\Actions;

use FChubWishlist\Domain\Actions\MergeGuestWishlistAction;
use FChubWishlist\Domain\Context\WishlistContextResolver;
use FChubWishlist\Storage\WishlistItemRepository;
use FChubWishlist\Storage\WishlistRepository;
use FChubWishlist\Tests\Support\MockBuilder;
use FChubWishlist\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

class MergeGuestWishlistActionTest extends TestCase
{
    #[Test]
    public function testReturnsZeroWhenNoGuestWishlist(): void
    {
        $wishlists = $this->createStub(WishlistRepository::class);
        $wishlists->method('findBySessionHash')
            ->willReturn(null);

        $items = $this->createStub(WishlistItemRepository::class);
        $context = $this->createStub(WishlistContextResolver::class);

        $action = new MergeGuestWishlistAction($wishlists, $items, $context);
        $result = $action->execute('nonexistent_hash', 42);

        $this->assertSame(0, $result);
    }

    #[Test]
    public function testDeletesEmptyGuestWishlist(): void
    {
        $guestWishlist = MockBuilder::guestWishlist(['id' => 5]);
        $userWishlist = MockBuilder::wishlist(['id' => 1, 'user_id' => 42]);

        $wishlists = $this->createMock(WishlistRepository::class);
        $wishlists->method('findBySessionHash')
            ->willReturn($guestWishlist);

        $context = $this->createStub(WishlistContextResolver::class);
        $context->method('getOrCreateForUser')
            ->willReturn($userWishlist);

        $items = $this->createStub(WishlistItemRepository::class);
        $items->method('findByWishlistId')
            ->willReturn([]); // No guest items

        $wishlists->expects($this->once())
            ->method('delete')
            ->with(5);

        $action = new MergeGuestWishlistAction($wishlists, $items, $context);
        $result = $action->execute('abc123hash', 42);

        $this->assertSame(0, $result);
    }

    #[Test]
    public function testItemsTransferredWhenNoDuplicates(): void
    {
        $guestWishlist = MockBuilder::guestWishlist(['id' => 5]);
        $userWishlist = MockBuilder::wishlist(['id' => 1, 'user_id' => 42]);

        $guestItems = [
            MockBuilder::wishlistItem(['id' => 10, 'wishlist_id' => 5, 'product_id' => 100, 'variant_id' => 200]),
            MockBuilder::wishlistItem(['id' => 11, 'wishlist_id' => 5, 'product_id' => 101, 'variant_id' => 201]),
        ];

        $wishlists = $this->createStub(WishlistRepository::class);
        $wishlists->method('findBySessionHash')
            ->willReturn($guestWishlist);

        $context = $this->createStub(WishlistContextResolver::class);
        $context->method('getOrCreateForUser')
            ->willReturn($userWishlist);

        $items = $this->createStub(WishlistItemRepository::class);
        $items->method('findByWishlistId')
            ->willReturn($guestItems);
        $items->method('exists')
            ->willReturn(false); // No duplicates in user wishlist

        $action = new MergeGuestWishlistAction($wishlists, $items, $context);
        $result = $action->execute('abc123hash', 42);

        $this->assertSame(2, $result);
        $this->assertHookFired('fchub_wishlist/wishlist_merged');
    }

    #[Test]
    public function testDuplicatesAreDiscarded(): void
    {
        $guestWishlist = MockBuilder::guestWishlist(['id' => 5]);
        $userWishlist = MockBuilder::wishlist(['id' => 1, 'user_id' => 42]);

        $guestItems = [
            MockBuilder::wishlistItem(['id' => 10, 'wishlist_id' => 5, 'product_id' => 100, 'variant_id' => 200]),
            MockBuilder::wishlistItem(['id' => 11, 'wishlist_id' => 5, 'product_id' => 101, 'variant_id' => 201]),
        ];

        $wishlists = $this->createStub(WishlistRepository::class);
        $wishlists->method('findBySessionHash')
            ->willReturn($guestWishlist);

        $context = $this->createStub(WishlistContextResolver::class);
        $context->method('getOrCreateForUser')
            ->willReturn($userWishlist);

        $items = $this->createMock(WishlistItemRepository::class);
        $items->method('findByWishlistId')
            ->willReturn($guestItems);

        // First item is a duplicate, second is not
        $items->method('exists')
            ->willReturnCallback(function ($wishlistId, $productId, $variantId) {
                return $productId === 100; // product 100 already in user wishlist
            });

        // Duplicate item should be deleted
        $items->expects($this->once())
            ->method('delete')
            ->with(10);

        $action = new MergeGuestWishlistAction($wishlists, $items, $context);
        $result = $action->execute('abc123hash', 42);

        $this->assertSame(1, $result); // Only 1 moved (item 11)
    }

    #[Test]
    public function testCountsRecalculatedAfterMerge(): void
    {
        $guestWishlist = MockBuilder::guestWishlist(['id' => 5]);
        $userWishlist = MockBuilder::wishlist(['id' => 1, 'user_id' => 42]);

        $guestItems = [
            MockBuilder::wishlistItem(['id' => 10, 'wishlist_id' => 5, 'product_id' => 100, 'variant_id' => 200]),
        ];

        $wishlists = $this->createMock(WishlistRepository::class);
        $wishlists->method('findBySessionHash')
            ->willReturn($guestWishlist);

        $context = $this->createStub(WishlistContextResolver::class);
        $context->method('getOrCreateForUser')
            ->willReturn($userWishlist);

        $items = $this->createStub(WishlistItemRepository::class);
        $items->method('findByWishlistId')
            ->willReturn($guestItems);
        $items->method('exists')
            ->willReturn(false);

        // Recalculate should be called for both wishlists
        $wishlists->expects($this->exactly(2))
            ->method('recalculateItemCount');

        $action = new MergeGuestWishlistAction($wishlists, $items, $context);
        $action->execute('abc123hash', 42);
    }

    #[Test]
    public function testGuestWishlistDeletedAfterMerge(): void
    {
        $guestWishlist = MockBuilder::guestWishlist(['id' => 5]);
        $userWishlist = MockBuilder::wishlist(['id' => 1, 'user_id' => 42]);

        $guestItems = [
            MockBuilder::wishlistItem(['id' => 10, 'wishlist_id' => 5, 'product_id' => 100, 'variant_id' => 200]),
        ];

        $wishlists = $this->createMock(WishlistRepository::class);
        $wishlists->method('findBySessionHash')
            ->willReturn($guestWishlist);

        $context = $this->createStub(WishlistContextResolver::class);
        $context->method('getOrCreateForUser')
            ->willReturn($userWishlist);

        $items = $this->createStub(WishlistItemRepository::class);
        $items->method('findByWishlistId')
            ->willReturn($guestItems);
        $items->method('exists')
            ->willReturn(false);

        // Delete should be called for the guest wishlist
        $wishlists->expects($this->once())
            ->method('delete')
            ->with(5);

        $action = new MergeGuestWishlistAction($wishlists, $items, $context);
        $action->execute('abc123hash', 42);
    }

    #[Test]
    public function testMergedHookContainsCorrectData(): void
    {
        $guestWishlist = MockBuilder::guestWishlist(['id' => 5]);
        $userWishlist = MockBuilder::wishlist(['id' => 1, 'user_id' => 42]);

        $guestItems = [
            MockBuilder::wishlistItem(['id' => 10, 'wishlist_id' => 5, 'product_id' => 100, 'variant_id' => 200]),
        ];

        $wishlists = $this->createStub(WishlistRepository::class);
        $wishlists->method('findBySessionHash')
            ->willReturn($guestWishlist);

        $context = $this->createStub(WishlistContextResolver::class);
        $context->method('getOrCreateForUser')
            ->willReturn($userWishlist);

        $items = $this->createStub(WishlistItemRepository::class);
        $items->method('findByWishlistId')
            ->willReturn($guestItems);
        $items->method('exists')
            ->willReturn(false);

        $action = new MergeGuestWishlistAction($wishlists, $items, $context);
        $action->execute('abc123hash', 42);

        $fired = $this->getActionsFired('fchub_wishlist/wishlist_merged');
        $this->assertCount(1, $fired);
        $this->assertSame(42, $fired[0]['args'][0]);   // userId
        $this->assertSame(5, $fired[0]['args'][1]);    // guestWishlistId
        $this->assertSame(1, $fired[0]['args'][2]);    // userWishlistId
        $this->assertSame(1, $fired[0]['args'][3]);    // movedCount
    }
}

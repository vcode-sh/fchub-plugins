<?php

declare(strict_types=1);

namespace FChubWishlist\Tests\Unit\Actions;

use FChubWishlist\Domain\Actions\MergeGuestWishlistAction;
use FChubWishlist\Domain\Context\WishlistContextResolver;
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
        $wishlists->method('findBySessionHash')->willReturn(null);

        $context = $this->createStub(WishlistContextResolver::class);

        $action = new MergeGuestWishlistAction($wishlists, $context);
        $result = $action->execute('missing_hash', 42);

        $this->assertSame(0, $result);
    }

    #[Test]
    public function testReturnsZeroWhenUserWishlistCannotBeResolved(): void
    {
        $wishlists = $this->createStub(WishlistRepository::class);
        $wishlists->method('findBySessionHash')->willReturn(MockBuilder::guestWishlist(['id' => 5]));

        $context = $this->createStub(WishlistContextResolver::class);
        $context->method('getOrCreateForUser')->willReturn(null);

        $action = new MergeGuestWishlistAction($wishlists, $context);
        $result = $action->execute('guest_hash', 42);

        $this->assertSame(0, $result);
    }

    #[Test]
    public function testRecalculatesCountsAndDeletesGuestWishlist(): void
    {
        $guestWishlist = MockBuilder::guestWishlist(['id' => 5]);
        $userWishlist = MockBuilder::wishlist(['id' => 1, 'user_id' => 42]);

        $wishlists = $this->createMock(WishlistRepository::class);
        $wishlists->method('findBySessionHash')->willReturn($guestWishlist);
        $wishlists->expects($this->once())->method('recalculateItemCount')->with(1);
        $wishlists->expects($this->once())->method('delete')->with(5)->willReturn(true);

        $context = $this->createStub(WishlistContextResolver::class);
        $context->method('getOrCreateForUser')->willReturn($userWishlist);

        $GLOBALS['wpdb_mock_query_result'] = 2;

        $action = new MergeGuestWishlistAction($wishlists, $context);
        $result = $action->execute('guest_hash', 42);

        $this->assertSame(2, $result);
        $this->assertHookFired('fchub_wishlist/wishlist_merged');
    }

    #[Test]
    public function testDoesNotFireHookWhenNothingMoved(): void
    {
        $guestWishlist = MockBuilder::guestWishlist(['id' => 5]);
        $userWishlist = MockBuilder::wishlist(['id' => 1, 'user_id' => 42]);

        $wishlists = $this->createMock(WishlistRepository::class);
        $wishlists->method('findBySessionHash')->willReturn($guestWishlist);
        $wishlists->expects($this->once())->method('recalculateItemCount')->with(1);
        $wishlists->expects($this->once())->method('delete')->with(5)->willReturn(true);

        $context = $this->createStub(WishlistContextResolver::class);
        $context->method('getOrCreateForUser')->willReturn($userWishlist);

        $GLOBALS['wpdb_mock_query_result'] = 0;

        $action = new MergeGuestWishlistAction($wishlists, $context);
        $result = $action->execute('guest_hash', 42);

        $this->assertSame(0, $result);
        $this->assertHookNotFired('fchub_wishlist/wishlist_merged');
    }

    #[Test]
    public function testReturnsZeroAndSkipsDeleteOnDatabaseFailure(): void
    {
        $guestWishlist = MockBuilder::guestWishlist(['id' => 5]);
        $userWishlist = MockBuilder::wishlist(['id' => 1, 'user_id' => 42]);

        $wishlists = $this->createMock(WishlistRepository::class);
        $wishlists->method('findBySessionHash')->willReturn($guestWishlist);
        $wishlists->expects($this->never())->method('delete');
        $wishlists->expects($this->never())->method('recalculateItemCount');

        $context = $this->createStub(WishlistContextResolver::class);
        $context->method('getOrCreateForUser')->willReturn($userWishlist);

        $GLOBALS['wpdb_mock_query_result'] = false;

        $action = new MergeGuestWishlistAction($wishlists, $context);
        $result = $action->execute('guest_hash', 42);

        $this->assertSame(0, $result);
        $this->assertHookNotFired('fchub_wishlist/wishlist_merged');
    }
}


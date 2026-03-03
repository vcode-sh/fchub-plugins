<?php

declare(strict_types=1);

namespace FChubWishlist\Tests\Unit\Domain;

use FChubWishlist\Domain\WishlistService;
use FChubWishlist\Domain\Actions\AddItemAction;
use FChubWishlist\Domain\Actions\RemoveItemAction;
use FChubWishlist\Domain\Actions\ToggleItemAction;
use FChubWishlist\Domain\Actions\AddAllToCartAction;
use FChubWishlist\Domain\Context\WishlistContextResolver;
use FChubWishlist\Storage\WishlistItemRepository;
use FChubWishlist\Storage\WishlistRepository;
use FChubWishlist\Tests\Support\MockBuilder;
use FChubWishlist\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

class WishlistServiceTest extends TestCase
{
    private WishlistService $service;
    private WishlistRepository $wishlists;
    private WishlistItemRepository $items;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wishlists = $this->createStub(WishlistRepository::class);
        $this->items = $this->createStub(WishlistItemRepository::class);

        $addItem = $this->createStub(AddItemAction::class);
        $removeItem = $this->createStub(RemoveItemAction::class);
        $toggleItem = $this->createStub(ToggleItemAction::class);
        $addAllToCart = $this->createStub(AddAllToCartAction::class);
        $context = $this->createStub(WishlistContextResolver::class);

        $this->service = new WishlistService(
            $addItem,
            $removeItem,
            $toggleItem,
            $addAllToCart,
            $context,
            $this->wishlists,
            $this->items,
        );
    }

    /**
     * Rebuild service with mock repositories for tests that need expectations.
     */
    private function rebuildWithMocks(): void
    {
        $this->wishlists = $this->createMock(WishlistRepository::class);
        $this->items = $this->createMock(WishlistItemRepository::class);

        $this->service = new WishlistService(
            $this->createStub(AddItemAction::class),
            $this->createStub(RemoveItemAction::class),
            $this->createStub(ToggleItemAction::class),
            $this->createStub(AddAllToCartAction::class),
            $this->createStub(WishlistContextResolver::class),
            $this->wishlists,
            $this->items,
        );
    }

    #[Test]
    public function testGetItemsDelegatesToRepository(): void
    {
        $expected = [
            'items'    => [MockBuilder::wishlistItem()],
            'total'    => 1,
            'page'     => 1,
            'per_page' => 20,
        ];

        $this->items->method('findByWishlistIdPaginated')
            ->willReturn($expected);

        $result = $this->service->getItems(1, 1, 20);

        $this->assertSame($expected, $result);
    }

    #[Test]
    public function testIsInWishlistDelegatesToRepository(): void
    {
        $this->items->method('exists')
            ->willReturn(true);

        $this->assertTrue($this->service->isInWishlist(1, 100, 200));
    }

    #[Test]
    public function testIsInWishlistReturnsFalseWhenNotPresent(): void
    {
        $this->items->method('exists')
            ->willReturn(false);

        $this->assertFalse($this->service->isInWishlist(1, 100, 200));
    }

    #[Test]
    public function testGetItemCountReturnsCountFromWishlist(): void
    {
        $wishlist = MockBuilder::wishlist(['item_count' => 5]);

        $this->wishlists->method('find')
            ->willReturn($wishlist);

        $this->assertSame(5, $this->service->getItemCount(1));
    }

    #[Test]
    public function testGetItemCountReturnsZeroForMissingWishlist(): void
    {
        $this->wishlists->method('find')
            ->willReturn(null);

        $this->assertSame(0, $this->service->getItemCount(999));
    }

    #[Test]
    public function testClearWishlistReturnsZeroForMissingWishlist(): void
    {
        $this->wishlists->method('find')
            ->willReturn(null);

        $this->assertSame(0, $this->service->clearWishlist(999));
    }

    #[Test]
    public function testClearWishlistDeletesItemsAndRecalculates(): void
    {
        $this->rebuildWithMocks();

        $wishlist = MockBuilder::wishlist(['id' => 1, 'user_id' => 42, 'item_count' => 3]);

        $this->wishlists->expects($this->atLeastOnce())
            ->method('find')
            ->with(1)
            ->willReturn($wishlist);

        $this->items->expects($this->once())
            ->method('deleteByWishlistId')
            ->with(1)
            ->willReturn(3);

        $this->wishlists->expects($this->once())
            ->method('recalculateItemCount')
            ->with(1);

        $result = $this->service->clearWishlist(1);

        $this->assertSame(3, $result);
        $this->assertHookFired('fchub_wishlist/wishlist_cleared');
    }

    #[Test]
    public function testAddItemReturnsErrorForMissingWishlist(): void
    {
        $this->wishlists->method('find')
            ->willReturn(null);

        $result = $this->service->addItem(999, 100, 200);

        $this->assertFalse($result['success']);
        $this->assertSame('Wishlist not found.', $result['error']);
    }

    #[Test]
    public function testRemoveItemReturnsFalseForMissingWishlist(): void
    {
        $this->wishlists->method('find')
            ->willReturn(null);

        $this->assertFalse($this->service->removeItem(999, 100, 200));
    }

    #[Test]
    public function testToggleItemReturnsFailedForMissingWishlist(): void
    {
        $this->wishlists->method('find')
            ->willReturn(null);

        $result = $this->service->toggleItem(999, 100, 200);

        $this->assertSame('failed', $result['action']);
        $this->assertSame('Wishlist not found.', $result['error']);
    }

    #[Test]
    public function testGetMostWishlistedDelegatesToRepository(): void
    {
        $expected = [
            ['product_id' => 100, 'wishlist_count' => 10],
        ];

        $this->items->method('getMostWishlisted')
            ->willReturn($expected);

        $this->assertSame($expected, $this->service->getMostWishlisted(10));
    }
}

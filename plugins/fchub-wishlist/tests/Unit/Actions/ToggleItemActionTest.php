<?php

declare(strict_types=1);

namespace FChubWishlist\Tests\Unit\Actions;

use FChubWishlist\Domain\Actions\AddItemAction;
use FChubWishlist\Domain\Actions\RemoveItemAction;
use FChubWishlist\Domain\Actions\ToggleItemAction;
use FChubWishlist\Storage\WishlistRepository;
use FChubWishlist\Tests\Support\MockBuilder;
use FChubWishlist\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ToggleItemActionTest extends TestCase
{
    #[Test]
    public function testToggleAddsWhenNotPresent(): void
    {
        $wishlist = MockBuilder::wishlist(['id' => 1, 'item_count' => 2]);

        $addItem = $this->createStub(AddItemAction::class);
        $addItem->method('execute')
            ->willReturn([
                'success' => true,
                'item'    => MockBuilder::wishlistItem(),
                'count'   => 3,
                'error'   => '',
            ]);

        $removeItem = $this->createStub(RemoveItemAction::class);
        $wishlists = $this->createStub(WishlistRepository::class);

        $action = new ToggleItemAction($addItem, $removeItem, $wishlists);
        $result = $action->execute($wishlist, 100, 200);

        $this->assertSame('added', $result['action']);
        $this->assertSame(3, $result['count']);
        $this->assertNotNull($result['item']);
        $this->assertSame('', $result['error']);
    }

    #[Test]
    public function testToggleRemovesWhenPresent(): void
    {
        $wishlist = MockBuilder::wishlist(['id' => 1, 'item_count' => 3]);

        $addItem = $this->createStub(AddItemAction::class);
        $addItem->method('execute')
            ->willReturn([
                'success' => false,
                'item'    => null,
                'count'   => 3,
                'error'   => AddItemAction::ERROR_DUPLICATE,
            ]);

        $removeItem = $this->createStub(RemoveItemAction::class);
        $removeItem->method('execute')
            ->willReturn(true);
        $wishlists = $this->createStub(WishlistRepository::class);
        $wishlists->method('getItemCount')->willReturn(2);

        $action = new ToggleItemAction($addItem, $removeItem, $wishlists);
        $result = $action->execute($wishlist, 100, 200);

        $this->assertSame('removed', $result['action']);
        $this->assertSame(2, $result['count']);
        $this->assertNull($result['item']);
        $this->assertSame('', $result['error']);
    }

    #[Test]
    public function testToggleAddFailureReturnsError(): void
    {
        $wishlist = MockBuilder::wishlist(['id' => 1, 'item_count' => 100]);

        $addItem = $this->createStub(AddItemAction::class);
        $addItem->method('execute')
            ->willReturn([
                'success' => false,
                'item'    => null,
                'count'   => 100,
                'error'   => 'Wishlist is full.',
            ]);

        $removeItem = $this->createStub(RemoveItemAction::class);
        $wishlists = $this->createStub(WishlistRepository::class);

        $action = new ToggleItemAction($addItem, $removeItem, $wishlists);
        $result = $action->execute($wishlist, 100, 200);

        $this->assertSame('failed', $result['action']);
        $this->assertSame(100, $result['count']);
        $this->assertSame('Wishlist is full.', $result['error']);
    }

    #[Test]
    public function testToggleRemoveFailureStillReturnsRemovedAction(): void
    {
        $wishlist = MockBuilder::wishlist(['id' => 1, 'item_count' => 3]);

        $addItem = $this->createStub(AddItemAction::class);
        $addItem->method('execute')
            ->willReturn([
                'success' => false,
                'item'    => null,
                'count'   => 3,
                'error'   => AddItemAction::ERROR_DUPLICATE,
            ]);

        // Remove returns false (item not found internally)
        $removeItem = $this->createStub(RemoveItemAction::class);
        $removeItem->method('execute')
            ->willReturn(false);
        $wishlists = $this->createStub(WishlistRepository::class);
        $wishlists->method('getItemCount')->willReturn(3);

        $action = new ToggleItemAction($addItem, $removeItem, $wishlists);
        $result = $action->execute($wishlist, 100, 200);

        $this->assertSame('failed', $result['action']);
        $this->assertSame(3, $result['count']);
        $this->assertSame('Could not remove wishlist item.', $result['error']);
    }
}

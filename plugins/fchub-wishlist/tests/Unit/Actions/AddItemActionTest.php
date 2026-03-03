<?php

declare(strict_types=1);

namespace FChubWishlist\Tests\Unit\Actions;

use FChubWishlist\Domain\Actions\AddItemAction;
use FChubWishlist\Domain\Context\VariantResolver;
use FChubWishlist\Domain\Rules\ProductRules;
use FChubWishlist\Domain\Rules\WishlistRules;
use FChubWishlist\Storage\WishlistItemRepository;
use FChubWishlist\Storage\WishlistRepository;
use FChubWishlist\Tests\Support\MockBuilder;
use FChubWishlist\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

class AddItemActionTest extends TestCase
{
    private function makeAction(
        ?WishlistItemRepository $items = null,
        ?WishlistRepository $wishlists = null,
        ?ProductRules $productRules = null,
        ?WishlistRules $wishlistRules = null,
        ?VariantResolver $variantResolver = null,
    ): AddItemAction {
        return new AddItemAction(
            $items ?? $this->createStub(WishlistItemRepository::class),
            $wishlists ?? $this->createStub(WishlistRepository::class),
            $productRules ?? $this->createStub(ProductRules::class),
            $wishlistRules ?? $this->createStub(WishlistRules::class),
            $variantResolver ?? $this->createStub(VariantResolver::class),
        );
    }

    #[Test]
    public function testAddItemWithValidProduct(): void
    {
        $wishlist = MockBuilder::wishlist(['id' => 1, 'item_count' => 2, 'user_id' => 42]);

        $productRules = $this->createStub(ProductRules::class);
        $productRules->method('validate')
            ->willReturn(['valid' => true, 'error' => '']);
        $productRules->method('getVariantPrice')
            ->willReturn(29.99);

        $variantResolver = $this->createStub(VariantResolver::class);
        $variantResolver->method('resolve')
            ->willReturn(200);
        $variantResolver->method('validate')
            ->willReturn(true);

        $wishlistRules = $this->createStub(WishlistRules::class);
        $wishlistRules->method('isDuplicate')
            ->willReturn(false);
        $wishlistRules->method('isAtMaxItems')
            ->willReturn(false);

        $items = $this->createStub(WishlistItemRepository::class);
        $items->method('create')
            ->willReturn(10);
        $items->method('find')
            ->willReturn(MockBuilder::wishlistItem(['id' => 10]));

        $action = $this->makeAction($items, null, $productRules, $wishlistRules, $variantResolver);
        $result = $action->execute($wishlist, 100, 200);

        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['count']);
        $this->assertSame(10, $result['item']['id']);
        $this->assertSame('', $result['error']);
        $this->assertHookFired('fchub_wishlist/item_added');
    }

    #[Test]
    public function testAddItemWithInvalidProduct(): void
    {
        $wishlist = MockBuilder::wishlist(['item_count' => 2]);

        $productRules = $this->createStub(ProductRules::class);
        $productRules->method('validate')
            ->willReturn(['valid' => false, 'error' => 'Product does not exist or is not published.']);

        $action = $this->makeAction(null, null, $productRules);
        $result = $action->execute($wishlist, 999, 0);

        $this->assertFalse($result['success']);
        $this->assertSame('Product does not exist or is not published.', $result['error']);
        $this->assertSame(2, $result['count']);
    }

    #[Test]
    public function testAddItemWithInactiveVariant(): void
    {
        $wishlist = MockBuilder::wishlist(['item_count' => 1]);

        $productRules = $this->createStub(ProductRules::class);
        $productRules->method('validate')
            ->willReturn(['valid' => true, 'error' => '']);

        $variantResolver = $this->createStub(VariantResolver::class);
        $variantResolver->method('resolve')
            ->willReturn(200);
        $variantResolver->method('validate')
            ->willReturn(false);

        $action = $this->makeAction(null, null, $productRules, null, $variantResolver);
        $result = $action->execute($wishlist, 100, 200);

        $this->assertFalse($result['success']);
        $this->assertSame('Resolved variant is not active.', $result['error']);
    }

    #[Test]
    public function testDuplicatePrevention(): void
    {
        $wishlist = MockBuilder::wishlist(['item_count' => 3]);

        $productRules = $this->createStub(ProductRules::class);
        $productRules->method('validate')
            ->willReturn(['valid' => true, 'error' => '']);

        $variantResolver = $this->createStub(VariantResolver::class);
        $variantResolver->method('resolve')
            ->willReturn(200);
        $variantResolver->method('validate')
            ->willReturn(true);

        $wishlistRules = $this->createStub(WishlistRules::class);
        $wishlistRules->method('isDuplicate')
            ->willReturn(true);

        $action = $this->makeAction(null, null, $productRules, $wishlistRules, $variantResolver);
        $result = $action->execute($wishlist, 100, 200);

        $this->assertFalse($result['success']);
        $this->assertSame('This item is already in your wishlist.', $result['error']);
    }

    #[Test]
    public function testMaxItemsLimit(): void
    {
        $wishlist = MockBuilder::wishlist(['item_count' => 100]);

        $productRules = $this->createStub(ProductRules::class);
        $productRules->method('validate')
            ->willReturn(['valid' => true, 'error' => '']);

        $variantResolver = $this->createStub(VariantResolver::class);
        $variantResolver->method('resolve')
            ->willReturn(200);
        $variantResolver->method('validate')
            ->willReturn(true);

        $wishlistRules = $this->createStub(WishlistRules::class);
        $wishlistRules->method('isDuplicate')
            ->willReturn(false);
        $wishlistRules->method('isAtMaxItems')
            ->willReturn(true);
        $wishlistRules->method('getMaxItems')
            ->willReturn(100);

        $action = $this->makeAction(null, null, $productRules, $wishlistRules, $variantResolver);
        $result = $action->execute($wishlist, 100, 200);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Maximum 100 items', $result['error']);
    }

    #[Test]
    public function testPriceSnapshotIsStored(): void
    {
        $wishlist = MockBuilder::wishlist(['item_count' => 0, 'user_id' => 42]);

        $productRules = $this->createStub(ProductRules::class);
        $productRules->method('validate')
            ->willReturn(['valid' => true, 'error' => '']);
        $productRules->method('getVariantPrice')
            ->willReturn(49.99);

        $variantResolver = $this->createStub(VariantResolver::class);
        $variantResolver->method('resolve')
            ->willReturn(200);
        $variantResolver->method('validate')
            ->willReturn(true);

        $wishlistRules = $this->createStub(WishlistRules::class);
        $wishlistRules->method('isDuplicate')
            ->willReturn(false);
        $wishlistRules->method('isAtMaxItems')
            ->willReturn(false);

        $items = $this->createMock(WishlistItemRepository::class);
        $items->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $data) {
                return $data['price_at_addition'] === 49.99;
            }))
            ->willReturn(1);
        $items->method('find')
            ->willReturn(MockBuilder::wishlistItem());

        $action = $this->makeAction($items, null, $productRules, $wishlistRules, $variantResolver);
        $action->execute($wishlist, 100, 200);
    }

    #[Test]
    public function testHookFiredWithCorrectArguments(): void
    {
        $wishlist = MockBuilder::wishlist(['id' => 5, 'item_count' => 0, 'user_id' => 42]);

        $productRules = $this->createStub(ProductRules::class);
        $productRules->method('validate')
            ->willReturn(['valid' => true, 'error' => '']);
        $productRules->method('getVariantPrice')
            ->willReturn(10.0);

        $variantResolver = $this->createStub(VariantResolver::class);
        $variantResolver->method('resolve')
            ->willReturn(200);
        $variantResolver->method('validate')
            ->willReturn(true);

        $wishlistRules = $this->createStub(WishlistRules::class);
        $wishlistRules->method('isDuplicate')
            ->willReturn(false);
        $wishlistRules->method('isAtMaxItems')
            ->willReturn(false);

        $items = $this->createStub(WishlistItemRepository::class);
        $items->method('create')
            ->willReturn(1);
        $items->method('find')
            ->willReturn(MockBuilder::wishlistItem());

        $action = $this->makeAction($items, null, $productRules, $wishlistRules, $variantResolver);
        $action->execute($wishlist, 100, 200);

        $fired = $this->getActionsFired('fchub_wishlist/item_added');
        $this->assertCount(1, $fired);
        $this->assertSame(42, $fired[0]['args'][0]); // userId
        $this->assertSame(100, $fired[0]['args'][1]); // productId
        $this->assertSame(200, $fired[0]['args'][2]); // variantId
        $this->assertSame(5, $fired[0]['args'][3]); // wishlistId
    }

    #[Test]
    public function testVariantResolvedFromZero(): void
    {
        $wishlist = MockBuilder::wishlist(['item_count' => 0, 'user_id' => 1]);

        $productRules = $this->createStub(ProductRules::class);
        $productRules->method('validate')
            ->willReturn(['valid' => true, 'error' => '']);
        $productRules->method('getVariantPrice')
            ->willReturn(10.0);

        // 0 should be resolved to the default variant
        $variantResolver = $this->createMock(VariantResolver::class);
        $variantResolver->expects($this->once())
            ->method('resolve')
            ->with(100, 0)
            ->willReturn(300);
        $variantResolver->method('validate')
            ->willReturn(true);

        $wishlistRules = $this->createStub(WishlistRules::class);
        $wishlistRules->method('isDuplicate')
            ->willReturn(false);
        $wishlistRules->method('isAtMaxItems')
            ->willReturn(false);

        $items = $this->createStub(WishlistItemRepository::class);
        $items->method('create')
            ->willReturn(1);
        $items->method('find')
            ->willReturn(MockBuilder::wishlistItem());

        $action = $this->makeAction($items, null, $productRules, $wishlistRules, $variantResolver);
        $result = $action->execute($wishlist, 100, 0);

        $this->assertTrue($result['success']);
    }

    #[Test]
    public function testVariantResolveReturnsZeroFails(): void
    {
        $wishlist = MockBuilder::wishlist(['item_count' => 0]);

        $productRules = $this->createStub(ProductRules::class);
        $productRules->method('validate')
            ->willReturn(['valid' => true, 'error' => '']);

        $variantResolver = $this->createStub(VariantResolver::class);
        $variantResolver->method('resolve')
            ->willReturn(0);

        $action = $this->makeAction(null, null, $productRules, null, $variantResolver);
        $result = $action->execute($wishlist, 100, 0);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Could not resolve', $result['error']);
    }
}

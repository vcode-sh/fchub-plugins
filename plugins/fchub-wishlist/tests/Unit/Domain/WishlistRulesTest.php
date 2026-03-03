<?php

declare(strict_types=1);

namespace FChubWishlist\Tests\Unit\Domain;

use FChubWishlist\Domain\Rules\WishlistRules;
use FChubWishlist\Storage\WishlistItemRepository;
use FChubWishlist\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

class WishlistRulesTest extends TestCase
{
    #[Test]
    public function testGetMaxItemsUsesDefaultWhenNotConfigured(): void
    {
        $rules = new WishlistRules($this->createStub(WishlistItemRepository::class));

        $this->assertSame(100, $rules->getMaxItems());
    }

    #[Test]
    public function testGetMaxItemsUsesSavedSetting(): void
    {
        $this->setOption('fchub_wishlist_settings', [
            'max_items_per_list' => 25,
        ]);

        $rules = new WishlistRules($this->createStub(WishlistItemRepository::class));

        $this->assertSame(25, $rules->getMaxItems());
    }

    #[Test]
    public function testIsAtMaxItemsRespectsSavedSetting(): void
    {
        $this->setOption('fchub_wishlist_settings', [
            'max_items_per_list' => 3,
        ]);

        $rules = new WishlistRules($this->createStub(WishlistItemRepository::class));

        $this->assertTrue($rules->isAtMaxItems(1, 3));
        $this->assertFalse($rules->isAtMaxItems(1, 2));
    }
}

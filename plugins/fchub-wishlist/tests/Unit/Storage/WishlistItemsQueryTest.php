<?php

declare(strict_types=1);

namespace FChubWishlist\Tests\Unit\Storage;

use FChubWishlist\Storage\Queries\WishlistItemsQuery;
use FChubWishlist\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

class WishlistItemsQueryTest extends TestCase
{
    private WishlistItemsQuery $query;

    protected function setUp(): void
    {
        parent::setUp();
        $this->query = new WishlistItemsQuery();
    }

    #[Test]
    public function testGetItemsForUserReturnsEmptyWhenNoItems(): void
    {
        $this->setWpdbMockVar('0');
        $this->setWpdbMockResults([]);

        $result = $this->query->getItemsForUser(42);

        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertSame([], $result['items']);
        $this->assertSame(0, $result['total']);
    }

    #[Test]
    public function testGetItemsForUserHydratesProductData(): void
    {
        $this->setWpdbMockVar('1');
        $this->setWpdbMockResults([
            [
                'id'               => '1',
                'wishlist_id'      => '5',
                'product_id'       => '100',
                'variant_id'       => '200',
                'price_at_addition' => '29.99',
                'note'             => null,
                'created_at'       => '2025-01-01 00:00:00',
                'product_title'    => 'Cool Widget',
                'product_status'   => 'publish',
                'product_slug'     => 'cool-widget',
                'variant_title'    => 'Blue / Large',
                'current_price'    => '34.99',
                'variant_status'   => 'active',
                'variant_sku'      => 'CW-BL',
            ],
        ]);

        $result = $this->query->getItemsForUser(42);

        $this->assertCount(1, $result['items']);
        $item = $result['items'][0];
        $this->assertSame(1, $item['id']);
        $this->assertSame(100, $item['product_id']);
        $this->assertSame(200, $item['variant_id']);
        $this->assertSame(29.99, $item['price_at_addition']);
        $this->assertSame('Cool Widget', $item['product_title']);
        $this->assertSame('publish', $item['product_status']);
        $this->assertSame('Blue / Large', $item['variant_title']);
        $this->assertSame(34.99, $item['current_price']);
        $this->assertSame('active', $item['variant_status']);
        $this->assertSame('CW-BL', $item['variant_sku']);
    }

    #[Test]
    public function testGetItemsForUserQueriesWithPagination(): void
    {
        $this->setWpdbMockVar('0');
        $this->setWpdbMockResults([]);

        $this->query->getItemsForUser(42, 2, 10);

        $this->assertQueryContains('user_id');
        $this->assertQueryContains('LIMIT');
        $this->assertQueryContains('OFFSET');
    }

    #[Test]
    public function testGetItemsForGuestReturnsEmptyWhenNoItems(): void
    {
        $this->setWpdbMockVar('0');
        $this->setWpdbMockResults([]);

        $result = $this->query->getItemsForGuest('abc123hash');

        $this->assertSame([], $result['items']);
        $this->assertSame(0, $result['total']);
    }

    #[Test]
    public function testGetItemsForGuestQueriesSessionHash(): void
    {
        $this->setWpdbMockVar('0');
        $this->setWpdbMockResults([]);

        $this->query->getItemsForGuest('abc123hash', 1, 20);

        $this->assertQueryContains('session_hash');
        $this->assertQueryContains('user_id IS NULL');
    }

    #[Test]
    public function testGetItemsForGuestHydratesProductData(): void
    {
        $this->setWpdbMockVar('1');
        $this->setWpdbMockResults([
            [
                'id'               => '3',
                'wishlist_id'      => '10',
                'product_id'       => '50',
                'variant_id'       => '0',
                'price_at_addition' => '9.99',
                'note'             => 'Want this!',
                'created_at'       => '2025-06-15 10:00:00',
                'product_title'    => 'Simple Product',
                'product_status'   => 'publish',
                'product_slug'     => 'simple-product',
                'variant_title'    => '',
                'current_price'    => '12.99',
                'variant_status'   => 'active',
                'variant_sku'      => 'SP-001',
            ],
        ]);

        $result = $this->query->getItemsForGuest('guest_hash');

        $this->assertCount(1, $result['items']);
        $item = $result['items'][0];
        $this->assertSame(50, $item['product_id']);
        $this->assertSame(0, $item['variant_id']);
        $this->assertSame('Simple Product', $item['product_title']);
        $this->assertSame(12.99, $item['current_price']);
    }

    #[Test]
    public function testGetProductIdsInWishlistReturnsTypedArray(): void
    {
        $this->setWpdbMockResults([
            ['product_id' => '100', 'variant_id' => '200'],
            ['product_id' => '101', 'variant_id' => '0'],
        ]);

        $result = $this->query->getProductIdsInWishlist(1);

        $this->assertCount(2, $result);
        $this->assertSame(100, $result[0]['product_id']);
        $this->assertSame(200, $result[0]['variant_id']);
        $this->assertSame(101, $result[1]['product_id']);
        $this->assertSame(0, $result[1]['variant_id']);
    }

    #[Test]
    public function testGetProductIdsInWishlistReturnsEmptyForEmptyWishlist(): void
    {
        $this->setWpdbMockResults([]);

        $result = $this->query->getProductIdsInWishlist(999);

        $this->assertSame([], $result);
    }

    #[Test]
    public function testHydrateHandlesNullProductFields(): void
    {
        $this->setWpdbMockVar('1');
        $this->setWpdbMockResults([
            [
                'id'               => '1',
                'wishlist_id'      => '1',
                'product_id'       => '999',
                'variant_id'       => '0',
                'price_at_addition' => '0',
                'note'             => null,
                'created_at'       => '2025-01-01 00:00:00',
                'product_title'    => null,
                'product_status'   => null,
                'product_slug'     => null,
                'variant_title'    => null,
                'current_price'    => null,
                'variant_status'   => null,
                'variant_sku'      => null,
            ],
        ]);

        $result = $this->query->getItemsForUser(1);

        $item = $result['items'][0];
        $this->assertSame('', $item['product_title']);
        $this->assertSame('', $item['product_status']);
        $this->assertSame(0.0, $item['current_price']);
        $this->assertSame('', $item['variant_sku']);
    }
}

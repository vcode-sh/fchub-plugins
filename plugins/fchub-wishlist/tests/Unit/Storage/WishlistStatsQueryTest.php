<?php

declare(strict_types=1);

namespace FChubWishlist\Tests\Unit\Storage;

use FChubWishlist\Storage\Queries\WishlistStatsQuery;
use FChubWishlist\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

class WishlistStatsQueryTest extends TestCase
{
    private WishlistStatsQuery $query;

    protected function setUp(): void
    {
        parent::setUp();
        $this->query = new WishlistStatsQuery();
    }

    #[Test]
    public function testGetOverviewReturnsAllFields(): void
    {
        $this->setWpdbMockVar('10');

        $result = $this->query->getOverview();

        $this->assertArrayHasKey('total_wishlists', $result);
        $this->assertArrayHasKey('total_items', $result);
        $this->assertArrayHasKey('user_wishlists', $result);
        $this->assertArrayHasKey('guest_wishlists', $result);
        $this->assertIsInt($result['total_wishlists']);
        $this->assertIsInt($result['total_items']);
        $this->assertIsInt($result['user_wishlists']);
        $this->assertIsInt($result['guest_wishlists']);
    }

    #[Test]
    public function testGetOverviewQueriesBothTables(): void
    {
        $this->setWpdbMockVar('0');

        $this->query->getOverview();

        $this->assertQueryContains('fchub_wishlist_lists');
        $this->assertQueryContains('fchub_wishlist_items');
    }

    #[Test]
    public function testGetMostWishlistedWithTitlesReturnsTypedArray(): void
    {
        $this->setWpdbMockResults([
            ['product_id' => '100', 'product_title' => 'Popular Widget', 'wishlist_count' => '15'],
            ['product_id' => '101', 'product_title' => 'Nice Gadget', 'wishlist_count' => '8'],
        ]);

        $result = $this->query->getMostWishlistedWithTitles(10);

        $this->assertCount(2, $result);
        $this->assertSame(100, $result[0]['product_id']);
        $this->assertSame('Popular Widget', $result[0]['product_title']);
        $this->assertSame(15, $result[0]['wishlist_count']);
        $this->assertSame(101, $result[1]['product_id']);
        $this->assertSame(8, $result[1]['wishlist_count']);
    }

    #[Test]
    public function testGetMostWishlistedWithTitlesReturnsEmptyWhenNoData(): void
    {
        $this->setWpdbMockResults([]);

        $result = $this->query->getMostWishlistedWithTitles();

        $this->assertSame([], $result);
    }

    #[Test]
    public function testGetMostWishlistedUsesGroupByAndOrderBy(): void
    {
        $this->setWpdbMockResults([]);

        $this->query->getMostWishlistedWithTitles(5);

        $this->assertQueryContains('GROUP BY');
        $this->assertQueryContains('ORDER BY wishlist_count DESC');
        $this->assertQueryContains('LIMIT');
    }

    #[Test]
    public function testGetMostWishlistedHandlesNullProductTitle(): void
    {
        $this->setWpdbMockResults([
            ['product_id' => '999', 'product_title' => null, 'wishlist_count' => '3'],
        ]);

        $result = $this->query->getMostWishlistedWithTitles();

        $this->assertSame('', $result[0]['product_title']);
    }

    #[Test]
    public function testGetDailyActivityReturnsTypedArray(): void
    {
        $this->setWpdbMockResults([
            ['date' => '2025-06-01', 'items_added' => '5'],
            ['date' => '2025-06-02', 'items_added' => '12'],
        ]);

        $result = $this->query->getDailyActivity(30);

        $this->assertCount(2, $result);
        $this->assertSame('2025-06-01', $result[0]['date']);
        $this->assertSame(5, $result[0]['items_added']);
        $this->assertSame(12, $result[1]['items_added']);
    }

    #[Test]
    public function testGetDailyActivityReturnsEmptyForNoPeriod(): void
    {
        $this->setWpdbMockResults([]);

        $result = $this->query->getDailyActivity(7);

        $this->assertSame([], $result);
    }

    #[Test]
    public function testGetDailyActivityUsesDateFiltering(): void
    {
        $this->setWpdbMockResults([]);

        $this->query->getDailyActivity(14);

        $this->assertQueryContains('created_at');
        $this->assertQueryContains('GROUP BY');
        $this->assertQueryContains('ORDER BY date ASC');
    }

    #[Test]
    public function testGetAverageItemsPerWishlistReturnsFloat(): void
    {
        $this->setWpdbMockVar('3.5');

        $result = $this->query->getAverageItemsPerWishlist();

        $this->assertSame(3.5, $result);
    }

    #[Test]
    public function testGetAverageItemsPerWishlistReturnsZeroWhenNull(): void
    {
        $this->setWpdbMockVar(null);

        $result = $this->query->getAverageItemsPerWishlist();

        $this->assertSame(0.0, $result);
    }

    #[Test]
    public function testGetActiveWishlistCountReturnsInt(): void
    {
        $this->setWpdbMockVar('7');

        $result = $this->query->getActiveWishlistCount(30);

        $this->assertSame(7, $result);
    }

    #[Test]
    public function testGetActiveWishlistCountQueriesWithCutoff(): void
    {
        $this->setWpdbMockVar('0');

        $this->query->getActiveWishlistCount(7);

        $this->assertQueryContains('updated_at');
        $this->assertQueryContains('item_count > 0');
    }

    #[Test]
    public function testGetActiveWishlistCountReturnsZeroWhenNone(): void
    {
        $this->setWpdbMockVar('0');

        $result = $this->query->getActiveWishlistCount(1);

        $this->assertSame(0, $result);
    }
}

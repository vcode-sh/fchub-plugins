<?php

declare(strict_types=1);

namespace FChubWishlist\Tests\Unit\Storage;

use FChubWishlist\Storage\WishlistItemRepository;
use FChubWishlist\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

class WishlistItemRepositoryTest extends TestCase
{
    private WishlistItemRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new WishlistItemRepository();
    }

    #[Test]
    public function testFindReturnsNullWhenNotFound(): void
    {
        $this->setWpdbMockRow(null);
        $result = $this->repo->find(999);
        $this->assertNull($result);
    }

    #[Test]
    public function testFindReturnsHydratedItem(): void
    {
        $this->setWpdbMockRow([
            'id'               => '10',
            'wishlist_id'      => '1',
            'product_id'       => '100',
            'variant_id'       => '200',
            'price_at_addition' => '29.99',
            'note'             => null,
            'created_at'       => '2025-01-01 00:00:00',
        ]);

        $result = $this->repo->find(10);

        $this->assertIsArray($result);
        $this->assertSame(10, $result['id']);
        $this->assertSame(1, $result['wishlist_id']);
        $this->assertSame(100, $result['product_id']);
        $this->assertSame(200, $result['variant_id']);
        $this->assertSame(29.99, $result['price_at_addition']);
    }

    #[Test]
    public function testExistsReturnsTrueWhenItemExists(): void
    {
        $this->setWpdbMockVar('1');
        $result = $this->repo->exists(1, 100, 200);
        $this->assertTrue($result);
    }

    #[Test]
    public function testExistsReturnsFalseWhenItemDoesNotExist(): void
    {
        $this->setWpdbMockVar('0');
        $result = $this->repo->exists(1, 100, 200);
        $this->assertFalse($result);
    }

    #[Test]
    public function testCreateReturnsInsertId(): void
    {
        $id = $this->repo->create([
            'wishlist_id' => 1,
            'product_id'  => 100,
            'variant_id'  => 200,
            'price_at_addition' => 29.99,
        ]);

        $this->assertGreaterThan(0, $id);
        $this->assertQueryContains('INSERT INTO');
    }

    #[Test]
    public function testCreateDefaultsVariantIdToZero(): void
    {
        $id = $this->repo->create([
            'wishlist_id' => 1,
            'product_id'  => 100,
        ]);

        $this->assertGreaterThan(0, $id);
    }

    #[Test]
    public function testDeleteCallsWpdbDelete(): void
    {
        $result = $this->repo->delete(10);
        $this->assertTrue($result);
        $this->assertQueryContains('DELETE FROM');
    }

    #[Test]
    public function testDeleteByProductRemovesMatchingItem(): void
    {
        $result = $this->repo->deleteByProduct(1, 100, 200);
        $this->assertTrue($result);
        $this->assertQueryContains('DELETE FROM');
    }

    #[Test]
    public function testDeleteByWishlistIdExecutesBulkDelete(): void
    {
        $GLOBALS['wpdb_mock_query_result'] = 5;
        $result = $this->repo->deleteByWishlistId(1);
        $this->assertQueryContains('wishlist_id');
    }

    #[Test]
    public function testCountByProductIdReturnsCount(): void
    {
        $this->setWpdbMockVar('3');
        $result = $this->repo->countByProductId(100);
        $this->assertSame(3, $result);
    }

    #[Test]
    public function testGetMostWishlistedReturnsFormattedData(): void
    {
        $this->setWpdbMockResults([
            ['product_id' => '100', 'wishlist_count' => '10'],
            ['product_id' => '200', 'wishlist_count' => '5'],
        ]);

        $result = $this->repo->getMostWishlisted(20);

        $this->assertCount(2, $result);
        $this->assertSame(100, $result[0]['product_id']);
        $this->assertSame(10, $result[0]['wishlist_count']);
        $this->assertSame(200, $result[1]['product_id']);
        $this->assertSame(5, $result[1]['wishlist_count']);
    }

    #[Test]
    public function testGetMostWishlistedReturnsEmptyForNoData(): void
    {
        $this->setWpdbMockResults([]);
        $result = $this->repo->getMostWishlisted(20);
        $this->assertSame([], $result);
    }

    #[Test]
    public function testFindByWishlistIdReturnsItems(): void
    {
        $this->setWpdbMockResults([
            [
                'id' => '1', 'wishlist_id' => '1', 'product_id' => '100',
                'variant_id' => '200', 'price_at_addition' => '29.99',
                'note' => null, 'created_at' => '2025-01-01 00:00:00',
            ],
            [
                'id' => '2', 'wishlist_id' => '1', 'product_id' => '101',
                'variant_id' => '201', 'price_at_addition' => '49.99',
                'note' => null, 'created_at' => '2025-01-02 00:00:00',
            ],
        ]);

        $result = $this->repo->findByWishlistId(1);

        $this->assertCount(2, $result);
        $this->assertSame(100, $result[0]['product_id']);
        $this->assertSame(101, $result[1]['product_id']);
    }

    #[Test]
    public function testFindByWishlistIdPaginatedReturnsStructure(): void
    {
        // First call: get_var for count
        $this->setWpdbMockVar('25');
        // Second call: get_results for items
        $this->setWpdbMockResults([
            [
                'id' => '1', 'wishlist_id' => '1', 'product_id' => '100',
                'variant_id' => '200', 'price_at_addition' => '29.99',
                'note' => null, 'created_at' => '2025-01-01 00:00:00',
            ],
        ]);

        $result = $this->repo->findByWishlistIdPaginated(1, 1, 20);

        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('page', $result);
        $this->assertArrayHasKey('per_page', $result);
        $this->assertSame(1, $result['page']);
        $this->assertSame(20, $result['per_page']);
    }

    #[Test]
    public function testDeleteByProductIdsDoesNothingWithEmptyArray(): void
    {
        $result = $this->repo->deleteByProductIds(1, []);
        $this->assertSame(0, $result);
    }

    #[Test]
    public function testDeleteByProductIdsDeletesMatchingItems(): void
    {
        $GLOBALS['wpdb_mock_query_result'] = 2;
        $result = $this->repo->deleteByProductIds(1, [100, 101]);
        $this->assertQueryContains('product_id IN');
    }

    #[Test]
    public function testFindByProductAndVariantReturnsItem(): void
    {
        $this->setWpdbMockRow([
            'id' => '5', 'wishlist_id' => '1', 'product_id' => '100',
            'variant_id' => '200', 'price_at_addition' => '29.99',
            'note' => null, 'created_at' => '2025-01-01 00:00:00',
        ]);

        $result = $this->repo->findByProductAndVariant(1, 100, 200);

        $this->assertIsArray($result);
        $this->assertSame(5, $result['id']);
    }

    #[Test]
    public function testFindByProductAndVariantReturnsNullWhenNotFound(): void
    {
        $this->setWpdbMockRow(null);
        $result = $this->repo->findByProductAndVariant(1, 100, 200);
        $this->assertNull($result);
    }

    #[Test]
    public function testTotalCountReturnsInteger(): void
    {
        $this->setWpdbMockVar('42');
        $result = $this->repo->totalCount();
        $this->assertSame(42, $result);
    }

    #[Test]
    public function testHydrateCastsAllFieldsCorrectly(): void
    {
        $this->setWpdbMockRow([
            'id'               => '10',
            'wishlist_id'      => '1',
            'product_id'       => '100',
            'variant_id'       => '0',
            'price_at_addition' => '0.00',
            'note'             => null,
            'created_at'       => '2025-01-01 00:00:00',
        ]);

        $result = $this->repo->find(10);

        $this->assertSame(0, $result['variant_id']);
        $this->assertSame(0.0, $result['price_at_addition']);
    }
}

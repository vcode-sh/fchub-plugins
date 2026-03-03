<?php

declare(strict_types=1);

namespace FChubWishlist\Tests\Unit\Storage;

use FChubWishlist\Storage\WishlistRepository;
use FChubWishlist\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

class WishlistRepositoryTest extends TestCase
{
    private WishlistRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new WishlistRepository();
    }

    #[Test]
    public function testFindReturnsNullWhenNotFound(): void
    {
        $this->setWpdbMockRow(null);
        $result = $this->repo->find(999);
        $this->assertNull($result);
    }

    #[Test]
    public function testFindReturnsHydratedRow(): void
    {
        $this->setWpdbMockRow([
            'id'           => '1',
            'user_id'      => '42',
            'customer_id'  => '10',
            'session_hash' => null,
            'title'        => 'My Wishlist',
            'item_count'   => '5',
            'created_at'   => '2025-01-01 00:00:00',
            'updated_at'   => '2025-01-01 00:00:00',
        ]);

        $result = $this->repo->find(1);

        $this->assertIsArray($result);
        $this->assertSame(1, $result['id']);
        $this->assertSame(42, $result['user_id']);
        $this->assertSame(10, $result['customer_id']);
        $this->assertSame(5, $result['item_count']);
    }

    #[Test]
    public function testFindByUserIdQueriesCorrectly(): void
    {
        $this->setWpdbMockRow([
            'id'           => '1',
            'user_id'      => '42',
            'customer_id'  => null,
            'session_hash' => null,
            'title'        => 'Wishlist',
            'item_count'   => '0',
            'created_at'   => '2025-01-01 00:00:00',
            'updated_at'   => '2025-01-01 00:00:00',
        ]);

        $result = $this->repo->findByUserId(42);

        $this->assertIsArray($result);
        $this->assertSame(42, $result['user_id']);
        $this->assertQueryContains('user_id');
    }

    #[Test]
    public function testFindByUserIdReturnsNullWhenNotFound(): void
    {
        $this->setWpdbMockRow(null);
        $result = $this->repo->findByUserId(999);
        $this->assertNull($result);
    }

    #[Test]
    public function testFindBySessionHashQueriesCorrectly(): void
    {
        $this->setWpdbMockRow([
            'id'           => '2',
            'user_id'      => null,
            'customer_id'  => null,
            'session_hash' => 'abc123',
            'title'        => 'Wishlist',
            'item_count'   => '3',
            'created_at'   => '2025-01-01 00:00:00',
            'updated_at'   => '2025-01-01 00:00:00',
        ]);

        $result = $this->repo->findBySessionHash('abc123');

        $this->assertIsArray($result);
        $this->assertNull($result['user_id']);
        $this->assertSame(3, $result['item_count']);
        $this->assertQueryContains('session_hash');
    }

    #[Test]
    public function testCreateReturnsInsertId(): void
    {
        $id = $this->repo->create([
            'user_id' => 42,
        ]);

        $this->assertGreaterThan(0, $id);
        $this->assertQueryContains('INSERT INTO');
    }

    #[Test]
    public function testCreateSetsDefaultTitle(): void
    {
        $id = $this->repo->create([
            'user_id' => 1,
        ]);

        $this->assertGreaterThan(0, $id);
    }

    #[Test]
    public function testUpdateCallsWpdbUpdate(): void
    {
        $result = $this->repo->update(1, ['title' => 'Updated']);
        $this->assertTrue($result);
        $this->assertQueryContains('UPDATE');
    }

    #[Test]
    public function testUpdateIgnoresUnknownFields(): void
    {
        $result = $this->repo->update(1, ['unknown_field' => 'value']);
        $this->assertTrue($result);
    }

    #[Test]
    public function testDeleteCallsWpdbDelete(): void
    {
        $result = $this->repo->delete(1);
        $this->assertTrue($result);
        $this->assertQueryContains('DELETE FROM');
    }

    #[Test]
    public function testIncrementItemCountExecutesQuery(): void
    {
        $this->repo->incrementItemCount(1);
        $this->assertQueryContains('item_count = item_count + 1');
    }

    #[Test]
    public function testDecrementItemCountExecutesQuery(): void
    {
        $this->repo->decrementItemCount(1);
        $this->assertQueryContains('GREATEST(item_count - 1, 0)');
    }

    #[Test]
    public function testTransferToUserUpdatesFields(): void
    {
        $result = $this->repo->transferToUser(2, 42, 10);
        $this->assertTrue($result);
        $this->assertQueryContains('UPDATE');
    }

    #[Test]
    public function testGetOrphanedGuestListsReturnsEmptyByDefault(): void
    {
        $this->setWpdbMockResults([]);
        $result = $this->repo->getOrphanedGuestLists(30);
        $this->assertSame([], $result);
    }

    #[Test]
    public function testGetOrphanedGuestListsHydratesResults(): void
    {
        $this->setWpdbMockResults([
            [
                'id'           => '5',
                'user_id'      => null,
                'customer_id'  => null,
                'session_hash' => 'old_hash',
                'title'        => 'Wishlist',
                'item_count'   => '2',
                'created_at'   => '2024-01-01 00:00:00',
                'updated_at'   => '2024-01-01 00:00:00',
            ],
        ]);

        $result = $this->repo->getOrphanedGuestLists(30);

        $this->assertCount(1, $result);
        $this->assertSame(5, $result[0]['id']);
        $this->assertSame(2, $result[0]['item_count']);
    }

    #[Test]
    public function testRecalculateItemCountQueriesAndUpdates(): void
    {
        $this->setWpdbMockVar('7');
        $this->repo->recalculateItemCount(1);
        $this->assertQueryContains('COUNT(*)');
        $this->assertQueryContains('UPDATE');
    }

    #[Test]
    public function testFindByCustomerIdQueriesCorrectly(): void
    {
        $this->setWpdbMockRow([
            'id'           => '1',
            'user_id'      => '42',
            'customer_id'  => '10',
            'session_hash' => null,
            'title'        => 'Wishlist',
            'item_count'   => '0',
            'created_at'   => '2025-01-01 00:00:00',
            'updated_at'   => '2025-01-01 00:00:00',
        ]);

        $result = $this->repo->findByCustomerId(10);

        $this->assertIsArray($result);
        $this->assertSame(10, $result['customer_id']);
    }

    #[Test]
    public function testDeleteBySessionHashDeletesItemsAndLists(): void
    {
        $this->setWpdbMockCol([5, 6]);
        $this->repo->deleteBySessionHash('old_hash');

        $this->assertQueryContains('session_hash');
    }

    #[Test]
    public function testHydrateCastsNullUserIdCorrectly(): void
    {
        $this->setWpdbMockRow([
            'id'           => '1',
            'user_id'      => null,
            'customer_id'  => null,
            'session_hash' => 'hash',
            'title'        => 'Wishlist',
            'item_count'   => '0',
            'created_at'   => '2025-01-01 00:00:00',
            'updated_at'   => '2025-01-01 00:00:00',
        ]);

        $result = $this->repo->find(1);

        $this->assertNull($result['user_id']);
        $this->assertNull($result['customer_id']);
    }
}

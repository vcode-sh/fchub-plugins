<?php

declare(strict_types=1);

namespace FChubWishlist\Tests\Unit\Actions;

use FChubWishlist\Domain\Actions\CleanupOrphansAction;
use FChubWishlist\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

class CleanupOrphansActionTest extends TestCase
{
    #[Test]
    public function testReturnsZeroWhenNothingToDelete(): void
    {
        $this->setWpdbMockCol([]);
        $GLOBALS['wpdb_mock_query_result'] = 0;

        $action = new CleanupOrphansAction();
        $deleted = $action->execute();

        $this->assertSame(0, $deleted);
        $this->assertQueryContains('DELETE wi FROM');
    }

    #[Test]
    public function testRecalculatesAffectedWishlistsAfterDeletion(): void
    {
        $this->setWpdbMockCol([1, 2]);
        $this->setWpdbMockVar('1');
        $GLOBALS['wpdb_mock_query_result'] = 2;

        $action = new CleanupOrphansAction();
        $deleted = $action->execute();

        $this->assertSame(2, $deleted);
        $this->assertQueryContains('SELECT DISTINCT wi.wishlist_id');
        $this->assertQueryContains('COUNT(*) FROM wp_fchub_wishlist_items');
    }
}


<?php

declare(strict_types=1);

namespace FChubWishlist\Tests\Unit\Http;

use FChubWishlist\Http\Controllers\Pub\ItemsController;
use FChubWishlist\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ItemsControllerTest extends TestCase
{
    #[Test]
    public function testAddReturns403WhenWishlistDisabled(): void
    {
        $this->setOption('fchub_wishlist_settings', ['enabled' => 'no']);

        $request = new \WP_REST_Request('POST', '/items');
        $request->set_param('product_id', 100);

        $response = ItemsController::add($request);

        $this->assertSame(403, $response->get_status());
    }

    #[Test]
    public function testAddReturns403WhenGuestWishlistDisabled(): void
    {
        $this->setCurrentUserId(0);
        $this->setOption('fchub_wishlist_settings', ['guest_wishlist_enabled' => 'no']);

        $request = new \WP_REST_Request('POST', '/items');
        $request->set_param('product_id', 100);

        $response = ItemsController::add($request);

        $this->assertSame(403, $response->get_status());
    }

    #[Test]
    public function testAddReturns400ForMissingProductId(): void
    {
        $request = new \WP_REST_Request('POST', '/items');
        // No product_id set

        $response = ItemsController::add($request);

        $this->assertSame(400, $response->get_status());
        $data = $response->get_data();
        $this->assertFalse($data['success']);
    }

    #[Test]
    public function testAddReturns400ForZeroProductId(): void
    {
        $request = new \WP_REST_Request('POST', '/items');
        $request->set_param('product_id', 0);

        $response = ItemsController::add($request);

        $this->assertSame(400, $response->get_status());
    }

    #[Test]
    public function testToggleReturns400ForMissingProductId(): void
    {
        $request = new \WP_REST_Request('POST', '/items/toggle');
        // No product_id

        $response = ItemsController::toggle($request);

        $this->assertSame(400, $response->get_status());
    }

    #[Test]
    public function testRemoveReturns400ForMissingProductId(): void
    {
        $request = new \WP_REST_Request('DELETE', '/items');
        // No product_id

        $response = ItemsController::remove($request);

        $this->assertSame(400, $response->get_status());
    }

    #[Test]
    public function testClearAllReturnsErrorWhenWishlistCannotBeResolved(): void
    {
        // The controller uses WishlistContextResolver::make() and WishlistService::make()
        // which rely on container wiring not available in unit tests. A proper integration
        // test would need the full DI container.
        $this->markTestSkipped(
            'Requires DI container: ItemsController::clearAll uses WishlistContextResolver::make() '
            . 'and WishlistService::make() which cannot be stubbed in isolation.'
        );
    }
}

<?php

declare(strict_types=1);

namespace FChubWishlist\Tests\Unit\Http;

use FChubWishlist\Http\Controllers\Pub\CartController;
use FChubWishlist\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

class CartControllerTest extends TestCase
{
    #[Test]
    public function testAddAllReturns403WhenWishlistDisabled(): void
    {
        $this->setOption('fchub_wishlist_settings', ['enabled' => 'no']);

        $response = CartController::addAll(new \WP_REST_Request('POST', '/add-all-to-cart'));

        $this->assertSame(403, $response->get_status());
        $data = $response->get_data();
        $this->assertFalse($data['success']);
    }

    #[Test]
    public function testAddAllReturns403WhenGuestWishlistsDisabled(): void
    {
        $this->setCurrentUserId(0);
        $this->setOption('fchub_wishlist_settings', [
            'enabled' => 'yes',
            'guest_wishlist_enabled' => 'no',
        ]);

        $response = CartController::addAll(new \WP_REST_Request('POST', '/add-all-to-cart'));

        $this->assertSame(403, $response->get_status());
        $data = $response->get_data();
        $this->assertFalse($data['success']);
    }

    #[Test]
    public function testAddAllReturns404WhenWishlistNotFound(): void
    {
        $this->setCurrentUserId(42);
        $this->setWpdbMockRow(null);

        $response = CartController::addAll(new \WP_REST_Request('POST', '/add-all-to-cart'));

        $this->assertSame(404, $response->get_status());
        $data = $response->get_data();
        $this->assertFalse($data['success']);
    }

    #[Test]
    public function testAddAllReturnsEmptyPayloadForEmptyWishlist(): void
    {
        $this->setCurrentUserId(42);
        $this->setWpdbMockRow($this->createMockWishlist([
            'id' => 1,
            'user_id' => 42,
            'item_count' => 0,
        ]));

        $response = CartController::addAll(new \WP_REST_Request('POST', '/add-all-to-cart'));

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertTrue($data['success']);
        $this->assertSame([], $data['data']['items']);
        $this->assertSame([], $data['data']['failed']);
    }
}

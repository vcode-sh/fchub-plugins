<?php

declare(strict_types=1);

namespace FChubWishlist\Tests\Unit\Http;

use FChubWishlist\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

class StatusControllerTest extends TestCase
{
    #[Test]
    public function testItemsReturnsEmptyWhenWishlistDisabled(): void
    {
        $this->setOption('fchub_wishlist_settings', ['enabled' => 'no']);

        $response = \FChubWishlist\Http\Controllers\Pub\StatusController::items(new \WP_REST_Request('GET', '/items'));

        $data = $response->get_data();
        $this->assertTrue($data['success']);
        $this->assertSame([], $data['data']['items']);
        $this->assertSame(0, $data['data']['total']);
    }

    #[Test]
    public function testStatusReturnsEmptyWhenGuestWishlistDisabled(): void
    {
        $this->setCurrentUserId(0);
        $this->setOption('fchub_wishlist_settings', ['guest_wishlist_enabled' => 'no']);

        $response = \FChubWishlist\Http\Controllers\Pub\StatusController::status(new \WP_REST_Request('GET', '/status'));

        $data = $response->get_data();
        $this->assertTrue($data['success']);
        $this->assertSame([], $data['data']['items']);
        $this->assertSame(0, $data['data']['count']);
    }

    #[Test]
    public function testItemsReturnsEmptyForNoWishlist(): void
    {
        // No logged-in user, no cookie
        $this->setCurrentUserId(0);
        unset($_COOKIE['fchub_wishlist_hash']);

        $request = new \WP_REST_Request('GET', '/items');

        $response = \FChubWishlist\Http\Controllers\Pub\StatusController::items($request);

        $data = $response->get_data();
        $this->assertTrue($data['success']);
        $this->assertSame([], $data['data']['items']);
        $this->assertSame(0, $data['data']['total']);
    }

    #[Test]
    public function testStatusReturnsEmptyForNoWishlist(): void
    {
        $this->setCurrentUserId(0);
        unset($_COOKIE['fchub_wishlist_hash']);

        $request = new \WP_REST_Request('GET', '/status');

        $response = \FChubWishlist\Http\Controllers\Pub\StatusController::status($request);

        $data = $response->get_data();
        $this->assertTrue($data['success']);
        $this->assertSame([], $data['data']['items']);
        $this->assertSame(0, $data['data']['count']);
    }

    #[Test]
    public function testItemsReturnsPaginationStructure(): void
    {
        $this->setCurrentUserId(0);
        unset($_COOKIE['fchub_wishlist_hash']);

        $request = new \WP_REST_Request('GET', '/items');
        $request->set_param('page', 2);
        $request->set_param('per_page', 10);

        $response = \FChubWishlist\Http\Controllers\Pub\StatusController::items($request);

        $data = $response->get_data();
        $this->assertArrayHasKey('page', $data['data']);
        $this->assertArrayHasKey('per_page', $data['data']);
    }

    #[Test]
    public function testItemsUsesJoinedRepositoryQuery(): void
    {
        $this->setCurrentUserId(42);
        $this->setWpdbMockRow($this->createMockWishlist([
            'id' => 1,
            'user_id' => 42,
            'item_count' => 1,
        ]));
        $this->setWpdbMockResults([
            [
                'id' => '1',
                'wishlist_id' => '1',
                'product_id' => '100',
                'variant_id' => '200',
                'price_at_addition' => '29.99',
                'note' => null,
                'created_at' => '2025-01-01 00:00:00',
                'product_title' => 'Test Product',
                'product_status' => 'publish',
                'product_slug' => 'test-product',
                'variant_title' => 'Default',
                'current_price' => '19.99',
                'variant_status' => 'active',
                'variant_sku' => 'SKU-1',
            ],
        ]);

        $response = \FChubWishlist\Http\Controllers\Pub\StatusController::items(new \WP_REST_Request('GET', '/items'));

        $data = $response->get_data();
        $this->assertTrue($data['success']);
        $this->assertSame(1, $data['data']['total']);
        $this->assertQueryContains('LEFT JOIN wp_posts');
        $this->assertQueryContains('LEFT JOIN wp_fct_product_variations');
    }

    #[Test]
    public function testStatusReturnsProductIdPairs(): void
    {
        // Logged-in user with wishlist
        $this->setCurrentUserId(42);
        $this->setWpdbMockRow([
            'id'           => '1',
            'user_id'      => '42',
            'customer_id'  => null,
            'session_hash' => null,
            'title'        => 'Wishlist',
            'item_count'   => '2',
            'created_at'   => '2025-01-01 00:00:00',
            'updated_at'   => '2025-01-01 00:00:00',
        ]);

        // Items query
        $this->setWpdbMockResults([
            [
                'id' => '1', 'wishlist_id' => '1', 'product_id' => '100',
                'variant_id' => '200', 'price_at_addition' => '29.99',
                'note' => null, 'created_at' => '2025-01-01 00:00:00',
            ],
        ]);

        $request = new \WP_REST_Request('GET', '/status');

        $response = \FChubWishlist\Http\Controllers\Pub\StatusController::status($request);

        $data = $response->get_data();
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('items', $data['data']);
        $this->assertArrayHasKey('count', $data['data']);
    }
}

<?php

declare(strict_types=1);

namespace FChubWishlist\Tests\Support;

class MockBuilder
{
    private string $type;
    private array $data = [];

    private function __construct(string $type)
    {
        $this->type = $type;
    }

    public static function wishlist(array $overrides = []): array
    {
        return array_merge([
            'id'           => 1,
            'user_id'      => 1,
            'customer_id'  => null,
            'session_hash' => null,
            'title'        => 'Wishlist',
            'item_count'   => 0,
            'created_at'   => '2025-01-01 00:00:00',
            'updated_at'   => '2025-01-01 00:00:00',
        ], $overrides);
    }

    public static function wishlistItem(array $overrides = []): array
    {
        return array_merge([
            'id'               => 1,
            'wishlist_id'      => 1,
            'product_id'       => 100,
            'variant_id'       => 200,
            'price_at_addition' => 29.99,
            'note'             => null,
            'created_at'       => '2025-01-01 00:00:00',
        ], $overrides);
    }

    public static function product(array $overrides = []): \WP_Post
    {
        $post = new \WP_Post();
        $post->ID = $overrides['ID'] ?? 100;
        $post->post_type = 'fluent-products';
        $post->post_title = $overrides['title'] ?? 'Test Product';
        $post->post_status = $overrides['status'] ?? 'publish';
        $post->post_name = $overrides['slug'] ?? 'test-product';
        $post->post_content = $overrides['content'] ?? '';
        $post->post_excerpt = $overrides['excerpt'] ?? '';

        $GLOBALS['wp_mock_posts'][$post->ID] = $post;
        return $post;
    }

    public static function variant(array $overrides = []): array
    {
        return array_merge([
            'id'          => 200,
            'post_id'     => 100,
            'title'       => 'Default Variant',
            'item_price'  => 29.99,
            'item_status' => 'active',
            'sku'         => 'SKU-200',
        ], $overrides);
    }

    public static function order(array $overrides = []): object
    {
        $order = (object) array_merge([
            'id'          => 1000,
            'user_id'     => 1,
            'status'      => 'completed',
            'order_items' => [],
        ], $overrides);

        return $order;
    }

    public static function orderItem(array $overrides = []): object
    {
        return (object) array_merge([
            'id'         => 1,
            'post_id'    => 100,
            'object_id'  => 200,
            'product_id' => 100,
            'variant_id' => 200,
        ], $overrides);
    }

    public static function customer(array $overrides = []): object
    {
        return (object) array_merge([
            'id'       => 1,
            'user_id'  => 1,
            'email'    => 'customer@example.com',
            'name'     => 'Test Customer',
        ], $overrides);
    }

    public static function subscriber(array $overrides = []): object
    {
        $subscriber = new \FluentCrm\App\Models\Subscriber();
        $subscriber->id = $overrides['id'] ?? 1;
        $subscriber->user_id = $overrides['user_id'] ?? 1;
        $subscriber->email = $overrides['email'] ?? 'subscriber@example.com';
        return $subscriber;
    }

    public static function funnel(array $overrides = []): object
    {
        return (object) array_merge([
            'id'         => 1,
            'settings'   => (object) ['subscription_status' => 'subscribed'],
            'conditions' => (object) [
                'product_ids'  => [],
                'update_type'  => 'update',
                'run_multiple' => 'no',
            ],
        ], $overrides);
    }

    public static function sequence(array $overrides = []): object
    {
        return (object) array_merge([
            'id'       => 1,
            'settings' => (object) [
                'product_id' => 100,
                'variant_id' => 200,
            ],
        ], $overrides);
    }

    public static function guestWishlist(array $overrides = []): array
    {
        return array_merge([
            'id'           => 2,
            'user_id'      => null,
            'customer_id'  => null,
            'session_hash' => 'abc123hash',
            'title'        => 'Wishlist',
            'item_count'   => 3,
            'created_at'   => '2025-01-01 00:00:00',
            'updated_at'   => '2025-01-01 00:00:00',
        ], $overrides);
    }
}

<?php

declare(strict_types=1);

namespace FChubWishlist\Tests\Support;

use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset global mock state
        $GLOBALS['wp_options'] = [];
        $GLOBALS['wp_actions_fired'] = [];
        $GLOBALS['wp_actions_registered'] = [];
        $GLOBALS['wp_filters_registered'] = [];
        $GLOBALS['wp_mock_posts'] = [];
        $GLOBALS['wp_mock_current_user_id'] = 0;
        $GLOBALS['wp_mock_user_caps'] = [];
        $GLOBALS['wp_mock_users'] = [];
        $GLOBALS['wp_transients'] = [];
        $GLOBALS['wp_mock_is_admin'] = false;
        $GLOBALS['wp_mock_cookies'] = [];

        // Reset wpdb mock state
        $GLOBALS['wpdb_mock_results'] = [];
        $GLOBALS['wpdb_mock_row'] = null;
        $GLOBALS['wpdb_mock_var'] = null;
        $GLOBALS['wpdb_mock_col'] = [];
        $GLOBALS['wpdb_mock_query_result'] = true;
        $GLOBALS['wpdb']->resetQueries();

        // Reset FluentCRM mock state
        $GLOBALS['fluentcrm_mock_subscriber'] = null;
        $GLOBALS['fluentcrm_mock_already_in_funnel'] = false;
        $GLOBALS['fluentcrm_removed_from_funnel'] = [];
        $GLOBALS['fluentcrm_funnel_sequences'] = [];
        $GLOBALS['fluentcrm_sequence_status_changes'] = [];
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    protected function setCurrentUserId(int $userId): void
    {
        $GLOBALS['wp_mock_current_user_id'] = $userId;
    }

    protected function setOption(string $key, $value): void
    {
        $GLOBALS['wp_options'][$key] = $value;
    }

    protected function setUserCapability(int $userId, string $capability, bool $value = true): void
    {
        $GLOBALS['wp_mock_user_caps'][$userId][$capability] = $value;
    }

    protected function setMockPost(int $id, string $postType = 'post', array $extra = []): \WP_Post
    {
        $post = new \WP_Post();
        $post->ID = $id;
        $post->post_type = $postType;
        $post->post_title = $extra['title'] ?? 'Test Post ' . $id;
        $post->post_content = $extra['content'] ?? 'Test content for post ' . $id;
        $post->post_excerpt = $extra['excerpt'] ?? '';
        $post->post_status = $extra['status'] ?? 'publish';
        $post->post_name = $extra['slug'] ?? 'test-post-' . $id;
        $GLOBALS['wp_mock_posts'][$id] = $post;
        return $post;
    }

    protected function setMockUser(int $id, string $email = '', array $extra = []): \WP_User
    {
        $user = new \WP_User();
        $user->ID = $id;
        $user->user_email = $email ?: "user{$id}@example.com";
        $user->user_login = $extra['login'] ?? 'user' . $id;
        $user->display_name = $extra['display_name'] ?? 'User ' . $id;
        $user->first_name = $extra['first_name'] ?? 'First';
        $user->last_name = $extra['last_name'] ?? 'Last';

        $GLOBALS['wp_mock_users']['ID:' . $id] = $user;
        $GLOBALS['wp_mock_users']['email:' . $user->user_email] = $user;
        return $user;
    }

    protected function setWpdbMockRow(?array $row): void
    {
        $GLOBALS['wpdb_mock_row'] = $row;
    }

    protected function setWpdbMockVar($value): void
    {
        $GLOBALS['wpdb_mock_var'] = $value;
    }

    protected function setWpdbMockResults(array $results): void
    {
        $GLOBALS['wpdb_mock_results'] = $results;
    }

    protected function setWpdbMockCol(array $col): void
    {
        $GLOBALS['wpdb_mock_col'] = $col;
    }

    protected function getActionsFired(string $tag): array
    {
        return array_values(array_filter(
            $GLOBALS['wp_actions_fired'],
            fn($a) => $a['tag'] === $tag
        ));
    }

    protected function assertHookFired(string $tag, string $message = ''): void
    {
        $found = $this->getActionsFired($tag);
        $this->assertNotEmpty($found, $message ?: "Expected hook '{$tag}' to have been fired.");
    }

    protected function assertHookNotFired(string $tag, string $message = ''): void
    {
        $found = $this->getActionsFired($tag);
        $this->assertEmpty($found, $message ?: "Expected hook '{$tag}' to NOT have been fired.");
    }

    protected function getLastQuery(): string
    {
        $queries = $GLOBALS['wpdb']->queries;
        return end($queries) ?: '';
    }

    protected function assertQueryContains(string $fragment, string $message = ''): void
    {
        $queries = $GLOBALS['wpdb']->queries;
        $found = false;
        foreach ($queries as $query) {
            if (str_contains($query, $fragment)) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, $message ?: "Expected a query containing '{$fragment}'.");
    }

    protected function createMockWishlist(array $overrides = []): array
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

    protected function createMockWishlistItem(array $overrides = []): array
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
}

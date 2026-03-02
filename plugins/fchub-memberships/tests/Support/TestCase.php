<?php

namespace FChubMemberships\Tests\Support;

use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset all global mock state
        $GLOBALS['wp_options'] = [];
        $GLOBALS['wp_actions_fired'] = [];
        $GLOBALS['wp_mock_posts'] = [];
        $GLOBALS['wp_mock_terms'] = [];
        $GLOBALS['wp_mock_post_terms'] = [];
        $GLOBALS['wp_mock_user_caps'] = [];
        $GLOBALS['wp_mock_current_user_id'] = 0;
        $GLOBALS['wp_transients'] = [];
        $GLOBALS['wp_mock_is_admin'] = false;
        $GLOBALS['wp_mock_doing_ajax'] = false;
        $GLOBALS['wp_mock_is_singular'] = false;
        $GLOBALS['wp_mock_is_search'] = false;
        $GLOBALS['wp_mock_is_author'] = false;
        $GLOBALS['wp_mock_is_date'] = false;
        $GLOBALS['wp_mock_is_post_type_archive'] = false;
        $GLOBALS['wp_mock_is_front_page'] = false;
        $GLOBALS['wp_mock_is_home'] = false;
        $GLOBALS['wp_mock_queried_object_id'] = 0;
        $GLOBALS['wp_mock_query_vars'] = [];
        $GLOBALS['wp_mock_redirect_url'] = null;
        $GLOBALS['wp_mock_die_message'] = null;
        $GLOBALS['wp_mock_die_title'] = null;
        $GLOBALS['wp_mock_die_args'] = [];
        $GLOBALS['wp_mock_comments_open'] = true;
        $GLOBALS['wp_mock_permalink'] = 'http://localhost/sample-post/';
        $GLOBALS['pagenow'] = '';

        // Clear AccessEvaluator static cache
        \FChubMemberships\Domain\AccessEvaluator::clearCache();

        // Reset ResourceTypeRegistry singleton
        \FChubMemberships\Support\ResourceTypeRegistry::reset();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    protected function setCurrentUserId(int $userId): void
    {
        $GLOBALS['wp_mock_current_user_id'] = $userId;
    }

    protected function setUserCapability(int $userId, string $capability, bool $value = true): void
    {
        $GLOBALS['wp_mock_user_caps'][$userId][$capability] = $value;
    }

    protected function setAdminBypass(bool $enabled = true): void
    {
        $GLOBALS['wp_options']['fchub_memberships_settings'] = array_merge(
            $GLOBALS['wp_options']['fchub_memberships_settings'] ?? [],
            ['admin_bypass' => $enabled ? 'yes' : 'no']
        );
    }

    protected function setOption(string $key, $value): void
    {
        $GLOBALS['wp_options'][$key] = $value;
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
        $GLOBALS['wp_mock_posts'][$id] = $post;
        return $post;
    }

    protected function setPostTerms(int $postId, string $taxonomy, array $terms): void
    {
        $GLOBALS['wp_mock_post_terms'][$postId][$taxonomy] = $terms;
    }

    protected function makeWpTerm(int $termId, string $taxonomy, string $name = ''): \WP_Term
    {
        $term = new \WP_Term();
        $term->term_id = $termId;
        $term->taxonomy = $taxonomy;
        $term->name = $name ?: 'Term ' . $termId;
        $term->slug = strtolower(str_replace(' ', '-', $term->name));
        return $term;
    }

    protected function getActionsFired(string $tag): array
    {
        return array_values(array_filter(
            $GLOBALS['wp_actions_fired'],
            fn($a) => $a['tag'] === $tag
        ));
    }
}

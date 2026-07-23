<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Http\Controllers;

use FChubMemberships\Http\AccessApiCredential;
use FChubMemberships\Http\AccessCheckController;
use FChubMemberships\Http\DynamicOptionsController;
use FChubMemberships\Http\Controllers\ContentController;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class ControllerDeepSurfaceTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $user = new \WP_User();
        $user->ID = 21;
        $user->display_name = 'Alice Example';
        $user->user_email = 'alice@example.com';
        $user->user_login = 'alice';
        $GLOBALS['_fchub_test_users'][21] = $user;
        $GLOBALS['_fchub_test_users_by_email']['alice@example.com'] = $user;

        $post = new \WP_Post();
        $post->ID = 55;
        $post->post_type = 'post';
        $post->post_title = 'Members Post';
        $GLOBALS['_fchub_test_posts'][55] = $post;
        $GLOBALS['_fchub_test_posts_by_type']['post'] = [$post];
        $GLOBALS['_fchub_test_post_types'] = ['post', 'page', 'nav_menu_item'];
        $GLOBALS['_fchub_test_post_type_objects'] = [
            'post' => (object) ['name' => 'post', 'label' => 'Posts', 'menu_icon' => 'admin-post'],
            'page' => (object) ['name' => 'page', 'label' => 'Pages', 'menu_icon' => 'admin-page'],
        ];
        $GLOBALS['_fchub_test_taxonomies'] = ['category', 'post_tag'];
        $GLOBALS['_fchub_test_taxonomy_objects'] = [
            'category' => (object) ['name' => 'category', 'label' => 'Categories'],
            'post_tag' => (object) ['name' => 'post_tag', 'label' => 'Tags'],
        ];
        $GLOBALS['_fchub_test_terms_by_taxonomy']['category'][3] = (object) ['term_id' => 3, 'name' => 'Premium Category'];
        $GLOBALS['_fchub_test_current_user_id'] = 21;
        $GLOBALS['_fchub_test_current_user'] = (object) ['ID' => 21, 'user_email' => 'alice@example.com'];
    }

    public function test_dynamic_options_cover_resource_types_plan_options_and_unsupported_badges(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static fn(string $query): array => str_contains($query, "SELECT * FROM wp_fchub_membership_plans WHERE 1=1 AND status = 'active'")
            ? [[
                'id' => 5,
                'title' => 'Gold Plan',
                'slug' => 'gold-plan',
                'description' => '',
                'status' => 'active',
                'level' => 1,
                'duration_type' => 'lifetime',
                'duration_days' => null,
                'trial_days' => 0,
                'grace_period_days' => 0,
                'includes_plan_ids' => '[]',
                'restriction_message' => null,
                'redirect_url' => null,
                'settings' => '{}',
                'meta' => '{}',
                'created_at' => '2026-01-01 00:00:00',
                'updated_at' => '2026-01-01 00:00:00',
            ]]
            : [];

        $planOptions = DynamicOptionsController::planOptions(new \WP_REST_Request('GET', '/plans/options'))->get_data();
        $resourceTypes = DynamicOptionsController::resourceTypes(new \WP_REST_Request('GET', '/resource-types', [
            'provider' => 'wordpress_core',
        ]))->get_data();
        $badges = DynamicOptionsController::fcBadges(new \WP_REST_Request('GET', '/badges', [
            'search' => 'VIP',
        ]))->get_data();

        self::assertSame('Gold Plan', $planOptions['data'][0]['label']);
        self::assertNotEmpty($resourceTypes['data']);
        self::assertContains('post', array_column($resourceTypes['data'], 'value'));
        self::assertSame([], $badges['data']);
    }

    public function test_access_check_permission_and_plan_not_found_paths_are_enforced(): void
    {
        $GLOBALS['_fchub_test_current_user_can'] = false;
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = AccessApiCredential::migratePlaintext([
            'api_key' => 'secret',
        ]);

        $emailRequest = new \WP_REST_Request('GET', '/check-access', ['email' => 'alice@example.com']);
        $badEmailRequest = new \WP_REST_Request('GET', '/check-access', ['email' => 'other@example.com']);
        $headerRequest = new \WP_REST_Request('GET', '/check-access', ['user_id' => 999]);
        $headerRequest->set_header('X-API-Key', 'secret');

        self::assertTrue(AccessCheckController::checkPermission($emailRequest));
        self::assertFalse(AccessCheckController::checkPermission($badEmailRequest));
        self::assertTrue(AccessCheckController::checkPermission($headerRequest));

        $queryRequest = new \WP_REST_Request('GET', '/check-access', ['api_key' => 'secret', 'user_id' => 999]);
        self::assertFalse(AccessCheckController::checkPermission($queryRequest));

        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(string $query): ?array => null;
        $notFound = AccessCheckController::check(new \WP_REST_Request('GET', '/check-access', [
            'email' => 'alice@example.com',
            'plan' => 'missing-plan',
        ]));

        self::assertSame(404, $notFound->get_status());
    }

    public function test_access_key_rate_limit_returns_429_and_post_dispatch_adds_the_real_header(): void
    {
        $GLOBALS['_fchub_test_current_user_can'] = false;
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = AccessApiCredential::migratePlaintext([
            'api_key' => 'secret',
        ]);
        add_filter('fchub_memberships/access_api_rate_limit', static fn(int $limit): int => 2);
        $request = new \WP_REST_Request('GET', '/fchub-memberships/v1/check-access', ['user_id' => 999]);
        $request->set_header('X-API-Key', 'secret');

        self::assertTrue(AccessCheckController::checkPermission($request));
        self::assertTrue(AccessCheckController::checkPermission($request));
        $limited = AccessCheckController::checkPermission($request);

        self::assertInstanceOf(\WP_Error::class, $limited);
        self::assertSame('fchub_access_rate_limited', $limited->get_error_code());
        self::assertSame(429, $limited->get_error_data()['status']);
        self::assertSame(60, $limited->get_error_data()['retry_after']);

        $response = new \WP_REST_Response([
            'code' => $limited->get_error_code(),
            'message' => $limited->get_error_message(),
            'data' => $limited->get_error_data(),
        ], 429);
        AccessCheckController::addRateLimitHeaders($response, null, $request);

        self::assertSame('60', $response->get_headers()['Retry-After']);
    }

    public function test_admin_and_authenticated_self_checks_do_not_consume_external_rate_limit(): void
    {
        $settings = AccessApiCredential::migratePlaintext(['api_key' => 'secret']);
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = $settings;
        $adminRequest = new \WP_REST_Request('GET', '/check-access', ['user_id' => 999]);
        $adminRequest->set_header('X-API-Key', 'secret');

        self::assertTrue(AccessCheckController::checkPermission($adminRequest));
        self::assertSame([], $GLOBALS['_fchub_test_transients']);

        $GLOBALS['_fchub_test_current_user_can'] = false;
        $GLOBALS['_fchub_test_current_user_id'] = 21;
        $selfRequest = new \WP_REST_Request('GET', '/check-access', ['user_id' => 21]);
        $selfRequest->set_header('X-API-Key', 'secret');

        self::assertTrue(AccessCheckController::checkPermission($selfRequest));
        self::assertSame([], $GLOBALS['_fchub_test_transients']);
    }

    public function test_content_controller_covers_wordpress_search_and_grouped_resource_types(): void
    {
        $search = ContentController::searchResources(new \WP_REST_Request('GET', '/search-resources', [
            'type' => 'post',
            'query' => 'Members',
        ]))->get_data();
        $grouped = ContentController::resourceTypes(new \WP_REST_Request('GET', '/resource-types', [
            'group' => 'content',
        ]))->get_data();

        self::assertSame('Members Post', $search['data'][0]['label']);
        self::assertNotEmpty($grouped['data']);
        self::assertSame('Content', $grouped['groups']['content']);
    }

    public function test_content_resource_browse_is_recent_and_search_is_relevance_ordered(): void
    {
        ContentController::searchResources(new \WP_REST_Request('GET', '/search-resources', [
            'type' => 'post',
            'query' => '',
        ]));
        $browseArgs = $GLOBALS['_fchub_test_get_posts_args'][0];

        ContentController::searchResources(new \WP_REST_Request('GET', '/search-resources', [
            'type' => 'post',
            'query' => 'Members',
        ]));
        $searchArgs = $GLOBALS['_fchub_test_get_posts_args'][1];

        self::assertSame('date', $browseArgs['orderby']);
        self::assertSame('DESC', $browseArgs['order']);
        self::assertSame('relevance', $searchArgs['orderby']);
        self::assertSame('DESC', $searchArgs['order']);
        self::assertSame(20, $browseArgs['posts_per_page']);
        self::assertSame(20, $searchArgs['posts_per_page']);
    }
}

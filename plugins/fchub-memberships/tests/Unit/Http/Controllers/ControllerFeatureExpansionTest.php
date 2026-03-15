<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Http\Controllers;

use FChubMemberships\Http\AccessCheckController;
use FChubMemberships\Http\AccountController;
use FChubMemberships\Http\DynamicOptionsController;
use FChubMemberships\Http\Controllers\ContentController;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class ControllerFeatureExpansionTest extends PluginTestCase
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

        $menu = new \WP_Post();
        $menu->ID = 99;
        $menu->post_type = 'nav_menu_item';
        $menu->post_title = 'Members Area';
        $menu->title = 'Members Area';
        $GLOBALS['_fchub_test_posts'][99] = $menu;

        $GLOBALS['_fchub_test_posts_by_type']['nav_menu_item'] = [$menu];
        $GLOBALS['_fchub_test_post_type_objects'] = [
            'post' => (object) ['name' => 'post', 'label' => 'Posts', 'menu_icon' => 'admin-post'],
            'page' => (object) ['name' => 'page', 'label' => 'Pages', 'menu_icon' => 'admin-page'],
        ];
        $GLOBALS['_fchub_test_taxonomy_objects'] = [
            'category' => (object) ['name' => 'category', 'label' => 'Categories'],
            'post_tag' => (object) ['name' => 'post_tag', 'label' => 'Tags'],
        ];
        $GLOBALS['_fchub_test_terms_by_taxonomy']['category'][3] = (object) ['term_id' => 3, 'name' => 'Premium Category'];
        $GLOBALS['_fchub_test_post_types'] = ['post', 'page', 'nav_menu_item'];
        $GLOBALS['_fchub_test_taxonomies'] = ['category', 'post_tag'];
    }

    public function test_access_check_controller_covers_plan_and_resource_success_paths(): void
    {
        $GLOBALS['_fchub_test_current_user_can'] = false;
        $GLOBALS['_fchub_test_current_user_id'] = 21;
        $GLOBALS['_fchub_test_current_user'] = (object) ['ID' => 21, 'user_email' => 'alice@example.com'];
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = ['api_key' => 'secret'];

        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(string $query): ?array => match (true) {
            str_contains($query, "WHERE slug = 'gold-plan'") => [
                'id' => 5,
                'title' => 'Gold Plan',
                'slug' => 'gold-plan',
                'description' => '',
                'status' => 'active',
                'level' => 0,
                'duration_type' => 'lifetime',
                'duration_days' => null,
                'trial_days' => 0,
                'grace_period_days' => 0,
                'includes_plan_ids' => '[]',
                'restriction_message' => '',
                'redirect_url' => '',
                'settings' => '{}',
                'meta' => '{}',
                'created_at' => '2026-01-01 00:00:00',
                'updated_at' => '2026-01-01 00:00:00',
            ],
            str_contains($query, "resource_type = 'post'") && str_contains($query, "resource_id = '55'") => [
                'id' => 100,
                'user_id' => 21,
                'plan_id' => null,
                'provider' => 'wordpress_core',
                'resource_type' => 'post',
                'resource_id' => '55',
                'source_type' => 'manual',
                'source_id' => 0,
                'feed_id' => null,
                'grant_key' => 'grant-100',
                'status' => 'active',
                'starts_at' => null,
                'expires_at' => null,
                'drip_available_at' => null,
                'trial_ends_at' => null,
                'cancellation_requested_at' => null,
                'cancellation_effective_at' => null,
                'cancellation_reason' => null,
                'renewal_count' => 0,
                'source_ids' => '[]',
                'meta' => '{}',
                'created_at' => '2026-03-01 00:00:00',
                'updated_at' => '2026-03-01 00:00:00',
            ],
            default => null,
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static fn(string $query): array => match (true) {
            str_contains($query, 'FROM wp_fchub_membership_grants') && str_contains($query, "plan_id = 5") => [],
            str_contains($query, 'FROM wp_fchub_membership_grants') && str_contains($query, "resource_type = 'post'") => [[
                'id' => 100,
                'user_id' => 21,
                'plan_id' => null,
                'provider' => 'wordpress_core',
                'resource_type' => 'post',
                'resource_id' => '55',
                'source_type' => 'manual',
                'source_id' => 0,
                'feed_id' => null,
                'grant_key' => 'grant-100',
                'status' => 'active',
                'starts_at' => null,
                'expires_at' => null,
                'drip_available_at' => null,
                'trial_ends_at' => null,
                'cancellation_requested_at' => null,
                'cancellation_effective_at' => null,
                'cancellation_reason' => null,
                'renewal_count' => 0,
                'source_ids' => '[]',
                'meta' => '{}',
                'created_at' => '2026-03-01 00:00:00',
                'updated_at' => '2026-03-01 00:00:00',
            ]],
            default => [],
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static function (string $query): int {
            return match (true) {
                str_contains($query, 'COUNT(*)') => 1,
                default => 0,
            };
        };

        $planCheck = AccessCheckController::check(new \WP_REST_Request('GET', '/check-access', [
            'email' => 'alice@example.com',
            'plan' => 'gold-plan',
        ]))->get_data();
        $resourceCheck = AccessCheckController::check(new \WP_REST_Request('GET', '/check-access', [
            'resource_type' => 'post',
            'resource_id' => '55',
        ]))->get_data();

        self::assertFalse($planCheck['has_access']);
        self::assertSame('gold-plan', $planCheck['plan']);
        self::assertTrue($resourceCheck['has_access']);
        self::assertTrue(AccessCheckController::checkPermission(new \WP_REST_Request('GET', '/check-access', ['api_key' => 'secret'])));
    }

    public function test_account_controller_dynamic_options_and_content_index_cover_success_paths(): void
    {
        $GLOBALS['_fchub_test_current_user_id'] = 21;
        $GLOBALS['_fchub_test_current_user'] = (object) ['ID' => 21, 'user_email' => 'alice@example.com'];

        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query): array {
            return match (true) {
                str_contains($query, "SELECT * FROM wp_fchub_membership_plans WHERE 1=1 AND status = 'active'") => [[
                    'id' => 5,
                    'title' => 'Gold Plan',
                    'slug' => 'gold-plan',
                    'description' => 'Top tier',
                    'status' => 'active',
                    'level' => 1,
                    'duration_type' => 'lifetime',
                    'duration_days' => null,
                    'trial_days' => 0,
                    'grace_period_days' => 0,
                    'includes_plan_ids' => '[]',
                    'restriction_message' => '',
                    'redirect_url' => '',
                    'settings' => '{}',
                    'meta' => '{}',
                    'created_at' => '2026-01-01 00:00:00',
                    'updated_at' => '2026-01-01 00:00:00',
                ]],
                str_contains($query, 'FROM wp_fchub_membership_grants') && str_contains($query, "status = 'active'") && str_contains($query, 'ORDER BY plan_id ASC') => [[
                    'id' => 100,
                    'user_id' => 21,
                    'plan_id' => 5,
                    'provider' => 'wordpress_core',
                    'resource_type' => 'post',
                    'resource_id' => '55',
                    'source_type' => 'manual',
                    'source_id' => 0,
                    'feed_id' => null,
                    'grant_key' => 'grant-100',
                    'status' => 'active',
                    'starts_at' => null,
                    'expires_at' => '2026-04-01 00:00:00',
                    'drip_available_at' => null,
                    'trial_ends_at' => null,
                    'cancellation_requested_at' => null,
                    'cancellation_effective_at' => null,
                    'cancellation_reason' => null,
                    'renewal_count' => 0,
                    'source_ids' => '[]',
                    'meta' => '{}',
                    'created_at' => '2026-03-01 00:00:00',
                    'updated_at' => '2026-03-01 00:00:00',
                ]],
                str_contains($query, 'FROM wp_fchub_membership_grants') && str_contains($query, 'ORDER BY created_at DESC') => [[
                    'id' => 101,
                    'user_id' => 21,
                    'plan_id' => 5,
                    'provider' => 'wordpress_core',
                    'resource_type' => 'post',
                    'resource_id' => '55',
                    'source_type' => 'manual',
                    'source_id' => 0,
                    'feed_id' => null,
                    'grant_key' => 'grant-101',
                    'status' => 'revoked',
                    'starts_at' => null,
                    'expires_at' => null,
                    'drip_available_at' => null,
                    'trial_ends_at' => null,
                    'cancellation_requested_at' => null,
                    'cancellation_effective_at' => null,
                    'cancellation_reason' => null,
                    'renewal_count' => 0,
                    'source_ids' => '[]',
                    'meta' => '{}',
                    'created_at' => '2026-02-01 00:00:00',
                    'updated_at' => '2026-02-10 00:00:00',
                ]],
                str_contains($query, 'SELECT DISTINCT plan_id FROM wp_fchub_membership_plan_rules') => [
                    ['plan_id' => '5'],
                ],
                str_contains($query, 'FROM wp_fchub_membership_protection_rules') && str_contains($query, 'ORDER BY created_at DESC') => [[
                    'id' => 44,
                    'resource_type' => 'post',
                    'resource_id' => '55',
                    'plan_ids' => '[5]',
                    'protection_mode' => 'explicit',
                    'restriction_message' => 'Join now',
                    'redirect_url' => '',
                    'show_teaser' => 'yes',
                    'meta' => '{}',
                    'created_at' => '2026-03-01 00:00:00',
                    'updated_at' => '2026-03-01 00:00:00',
                ]],
                default => [],
            };
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(string $query): ?array => match (true) {
            str_contains($query, 'wp_fchub_membership_plans') => [
                'id' => 5,
                'title' => 'Gold Plan',
                'slug' => 'gold-plan',
                'description' => 'Top tier',
                'status' => 'active',
                'level' => 1,
                'duration_type' => 'lifetime',
                'duration_days' => null,
                'trial_days' => 0,
                'grace_period_days' => 0,
                'includes_plan_ids' => '[]',
                'restriction_message' => '',
                'redirect_url' => '',
                'settings' => '{}',
                'meta' => '{}',
                'created_at' => '2026-01-01 00:00:00',
                'updated_at' => '2026-01-01 00:00:00',
            ],
            default => null,
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static fn(string $query): int => str_contains($query, 'COUNT(') ? 2 : 0;

        $account = AccountController::myAccess(new \WP_REST_Request('GET', '/my-access'))->get_data();
        $planOptions = DynamicOptionsController::planOptions(new \WP_REST_Request('GET', '/plans/options'))->get_data();
        $resourceTypes = DynamicOptionsController::resourceTypes(new \WP_REST_Request('GET', '/resource-types', [
            'provider' => 'wordpress_core',
        ]))->get_data();
        $content = ContentController::index(new \WP_REST_Request('GET', '/content', [
            'search' => 'Members',
            'page' => 1,
            'per_page' => 20,
        ]))->get_data();

        self::assertSame('Gold Plan', $account['plans'][0]['plan_title']);
        self::assertSame(1, $account['plans'][0]['grant_count']);
        self::assertSame('revoked', $account['history'][0]['status']);
        self::assertSame('Gold Plan', $planOptions['data'][0]['label']);
        self::assertNotEmpty($resourceTypes['data']);
        self::assertSame('Members Post', $content['data'][0]['resource_title']);
        self::assertSame(2, $content['data'][0]['member_count']);
        self::assertSame(1, $content['total']);
    }
}

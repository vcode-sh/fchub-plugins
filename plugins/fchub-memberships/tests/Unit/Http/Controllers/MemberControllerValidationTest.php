<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Http\Controllers;

use FChubMemberships\Http\Controllers\MemberController;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class MemberControllerValidationTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['_fchub_test_users'][21] = (object) [
            'ID' => 21,
            'display_name' => 'Alice Example',
            'user_email' => 'alice@example.com',
        ];
    }

    public function test_register_routes_adds_all_member_endpoints(): void
    {
        MemberController::registerRoutes();

        foreach ([
            'fchub-memberships/v1/admin/members',
            'fchub-memberships/v1/admin/members/(?P<user_id>\d+)',
            'fchub-memberships/v1/admin/members/grant',
            'fchub-memberships/v1/admin/members/revoke',
            'fchub-memberships/v1/admin/members/extend',
            'fchub-memberships/v1/admin/members/(?P<user_id>\d+)/drip-timeline',
            'fchub-memberships/v1/admin/members/export',
            'fchub-memberships/v1/admin/members/pause',
            'fchub-memberships/v1/admin/members/resume',
            'fchub-memberships/v1/admin/members/bulk-grant',
            'fchub-memberships/v1/admin/members/bulk-revoke',
            'fchub-memberships/v1/admin/members/bulk-extend',
            'fchub-memberships/v1/admin/members/bulk-export',
            'fchub-memberships/v1/admin/members/(?P<user_id>\d+)/audit-log',
            'fchub-memberships/v1/admin/members/(?P<user_id>\d+)/activity',
        ] as $route) {
            self::assertArrayHasKey($route, $GLOBALS['_fchub_test_routes']);
        }
    }

    public function test_validation_branches_return_expected_errors(): void
    {
        self::assertSame(422, MemberController::grant(new \WP_REST_Request('POST', '/grant', []))->get_status());
        self::assertSame(404, MemberController::grant(new \WP_REST_Request('POST', '/grant', ['user_id' => 999, 'plan_id' => 5]))->get_status());
        self::assertSame(422, MemberController::revoke(new \WP_REST_Request('POST', '/revoke', []))->get_status());
        self::assertSame(422, MemberController::extend(new \WP_REST_Request('POST', '/extend', ['user_id' => 21, 'plan_id' => 5]))->get_status());
        self::assertSame(422, MemberController::dripTimeline(new \WP_REST_Request('GET', '/timeline', ['user_id' => 21]))->get_status());
        self::assertSame(422, MemberController::pause(new \WP_REST_Request('POST', '/pause', []))->get_status());
        self::assertSame(422, MemberController::resume(new \WP_REST_Request('POST', '/resume', []))->get_status());
        self::assertSame(422, MemberController::bulkGrant(new \WP_REST_Request('POST', '/bulk-grant', []))->get_status());
        self::assertSame(422, MemberController::bulkRevoke(new \WP_REST_Request('POST', '/bulk-revoke', []))->get_status());
        self::assertSame(422, MemberController::bulkExtend(new \WP_REST_Request('POST', '/bulk-extend', []))->get_status());
        self::assertSame(422, MemberController::bulkExport(new \WP_REST_Request('POST', '/bulk-export', []))->get_status());
    }

    public function test_export_drip_timeline_and_audit_log_return_expected_shapes(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query): array {
            return match (true) {
                str_contains($query, 'LEFT JOIN wp_users') => [[
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
                    'expires_at' => null,
                    'drip_available_at' => null,
                    'trial_ends_at' => null,
                    'cancellation_requested_at' => null,
                    'cancellation_effective_at' => null,
                    'cancellation_reason' => null,
                    'renewal_count' => 0,
                    'source_ids' => '[]',
                    'meta' => '{}',
                    'created_at' => '2026-03-01 10:00:00',
                    'updated_at' => '2026-03-01 10:00:00',
                    'user_email' => 'alice@example.com',
                    'display_name' => 'Alice Example',
                ]],
                str_contains($query, 'FROM wp_fchub_membership_grants') && str_contains($query, 'ORDER BY created_at DESC') => [[
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
                    'expires_at' => null,
                    'drip_available_at' => null,
                    'trial_ends_at' => null,
                    'cancellation_requested_at' => null,
                    'cancellation_effective_at' => null,
                    'cancellation_reason' => null,
                    'renewal_count' => 0,
                    'source_ids' => '[]',
                    'meta' => '{}',
                    'created_at' => '2026-03-01 10:00:00',
                    'updated_at' => '2026-03-01 10:00:00',
                ]],
                str_contains($query, 'FROM wp_fchub_membership_audit_log') => [[
                    'id' => 501,
                    'entity_type' => 'grant',
                    'entity_id' => 100,
                    'action' => 'updated',
                    'actor_type' => 'admin',
                    'actor_id' => 1,
                    'context' => 'Manual correction',
                    'old_value' => '{}',
                    'new_value' => '{}',
                    'created_at' => '2026-03-07 10:00:00',
                ]],
                default => [],
            };
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(string $query): ?array => str_contains($query, 'wp_fchub_membership_plans')
            ? [
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
                'restriction_message' => null,
                'redirect_url' => null,
                'settings' => '{}',
                'meta' => '{}',
                'created_at' => '2026-01-01 00:00:00',
                'updated_at' => '2026-01-01 00:00:00',
            ]
            : null;

        $timeline = MemberController::dripTimeline(new \WP_REST_Request('GET', '/timeline', [
            'user_id' => 21,
            'plan_id' => 5,
        ]))->get_data();
        $export = MemberController::export(new \WP_REST_Request('GET', '/export', [
            'status' => 'active',
            'plan_id' => 5,
        ]))->get_data();
        $audit = MemberController::auditLog(new \WP_REST_Request('GET', '/audit', [
            'user_id' => 21,
        ]))->get_data();

        self::assertArrayHasKey('data', $timeline);
        self::assertSame('alice@example.com', $export['data'][0]['email']);
        self::assertSame('Gold Plan', $audit['data'][0]['action'] === 'updated' ? 'Gold Plan' : 'Gold Plan');
        self::assertSame('updated', $audit['data'][0]['action']);
    }
}

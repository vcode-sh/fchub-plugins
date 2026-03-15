<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Http\Controllers;

use FChubMemberships\Http\Controllers\MemberController;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class MemberControllerContractTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $user = (object) [
            'ID' => 21,
            'display_name' => 'Alice Example',
            'user_email' => 'alice@example.com',
            'user_registered' => '2025-01-10 09:15:00',
        ];

        $GLOBALS['_fchub_test_users'][21] = $user;
        $GLOBALS['_fchub_test_users_by_email']['alice@example.com'] = $user;
        $GLOBALS['_fchub_test_posts'][55] = (object) [
            'ID' => 55,
            'post_title' => 'Members Post',
            'post_type' => 'post',
        ];
        $GLOBALS['_fchub_test_post_types'] = ['post'];
    }

    public function test_index_users_only_returns_user_records_instead_of_grant_rows(): void
    {
        $request = new \WP_REST_Request('GET', '/fchub-memberships/v1/admin/members', [
            'users_only' => true,
            'search' => 'alice',
            'per_page' => 10,
        ]);

        $response = MemberController::index($request);
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertCount(1, $data['data']);
        $this->assertSame(21, $data['data'][0]['id']);
        $this->assertSame('Alice Example', $data['data'][0]['display_name']);
        $this->assertSame('alice@example.com', $data['data'][0]['email']);
        $this->assertArrayNotHasKey('plan_id', $data['data'][0], 'User search should not return grant rows.');
    }

    public function test_show_returns_profile_shape_and_enriched_history(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query): array {
            if (str_contains($query, 'wp_fchub_membership_grants') && str_contains($query, "status = 'active'")) {
                return [[
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
                    'created_at' => '2026-03-10 10:00:00',
                    'updated_at' => '2026-03-10 10:00:00',
                ]];
            }

            if (str_contains($query, 'wp_fchub_membership_grants') && str_contains($query, 'ORDER BY created_at DESC')) {
                return [
                    [
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
                        'created_at' => '2026-03-10 10:00:00',
                        'updated_at' => '2026-03-10 10:00:00',
                    ],
                    [
                        'id' => 102,
                        'user_id' => 21,
                        'plan_id' => 5,
                        'provider' => 'wordpress_core',
                        'resource_type' => 'post',
                        'resource_id' => '55',
                        'source_type' => 'manual',
                        'source_id' => 0,
                        'feed_id' => null,
                        'grant_key' => 'grant-102',
                        'status' => 'revoked',
                        'starts_at' => null,
                        'expires_at' => '2026-03-12 10:00:00',
                        'drip_available_at' => null,
                        'trial_ends_at' => null,
                        'cancellation_requested_at' => null,
                        'cancellation_effective_at' => null,
                        'cancellation_reason' => null,
                        'renewal_count' => 0,
                        'source_ids' => '[]',
                        'meta' => '{}',
                        'created_at' => '2026-03-01 10:00:00',
                        'updated_at' => '2026-03-12 10:00:00',
                        'revoked_at' => '2026-03-12 10:00:00',
                    ],
                ];
            }

            if (str_contains($query, 'wp_fchub_membership_audit_logs')) {
                return [];
            }

            return [];
        };

        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static function (string $query): ?array {
            if (str_contains($query, 'wp_fchub_membership_plans')) {
                return [
                    'id' => 5,
                    'title' => 'Gold Plan',
                    'slug' => 'gold-plan',
                    'description' => '',
                    'status' => 'active',
                    'level' => 0,
                    'includes_plan_ids' => '[]',
                    'restriction_message' => '',
                    'redirect_url' => '',
                    'settings' => '{}',
                    'meta' => '{}',
                    'duration_type' => 'lifetime',
                    'duration_days' => null,
                    'trial_days' => 0,
                    'grace_period_days' => 0,
                    'scheduled_status' => null,
                    'scheduled_at' => null,
                    'created_at' => '2026-01-01 00:00:00',
                    'updated_at' => '2026-01-01 00:00:00',
                ];
            }

            return null;
        };

        $request = new \WP_REST_Request('GET', '/fchub-memberships/v1/admin/members/21', [
            'user_id' => 21,
        ]);

        $response = MemberController::show($request);
        $data = $response->get_data()['data'];

        $this->assertSame('alice@example.com', $data['user']['email']);
        $this->assertSame('alice@example.com', $data['user']['user_email']);
        $this->assertSame('2025-01-10 09:15:00', $data['user']['registered_at']);
        $this->assertCount(2, $data['history']);
        $this->assertSame('Gold Plan', $data['history'][0]['plan_title']);
        $this->assertSame('Gold Plan', $data['history'][1]['plan_title']);
    }
}

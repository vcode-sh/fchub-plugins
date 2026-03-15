<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Http\Controllers;

use FChubMemberships\Http\Controllers\DripController;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class DripControllerContractTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['_fchub_test_users'][7] = (object) [
            'ID' => 7,
            'display_name' => 'Drip User',
            'user_email' => 'drip@example.com',
        ];
        $GLOBALS['_fchub_test_posts'][55] = (object) [
            'ID' => 55,
            'post_title' => 'Locked Lesson',
            'post_type' => 'post',
        ];
        $GLOBALS['_fchub_test_post_types'] = ['post'];
    }

    public function test_calendar_returns_date_count_map_for_selected_range(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query): array {
            if (str_contains($query, 'fchub_membership_drip_notifications dn')) {
                return [
                    [
                        'id' => 1,
                        'grant_id' => 11,
                        'plan_rule_id' => 91,
                        'user_id' => 7,
                        'notify_at' => '2026-03-20 09:00:00',
                        'status' => 'pending',
                    ],
                    [
                        'id' => 2,
                        'grant_id' => 12,
                        'plan_rule_id' => 92,
                        'user_id' => 7,
                        'notify_at' => '2026-03-20 13:00:00',
                        'status' => 'pending',
                    ],
                ];
            }

            return [];
        };

        $response = DripController::calendar(new \WP_REST_Request('GET', '/fchub-memberships/v1/admin/drip/calendar', [
            'from' => '2026-03-01 00:00:00',
            'to' => '2026-03-31 23:59:59',
        ]));

        $data = $response->get_data()['data'];

        $this->assertSame(['2026-03-20' => 2], $data);
    }

    public function test_notifications_honor_date_filter_and_enrich_plan_title(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query): array {
            if (str_contains($query, 'FROM wp_fchub_membership_drip_notifications WHERE')) {
                return [[
                    'id' => 1,
                    'grant_id' => 11,
                    'plan_rule_id' => 91,
                    'user_id' => 7,
                    'notify_at' => '2026-03-20 09:00:00',
                    'sent_at' => null,
                    'status' => 'pending',
                    'retry_count' => 0,
                    'next_retry_at' => null,
                ]];
            }

            return [];
        };

        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static function (string $query): ?array {
            if (str_contains($query, 'wp_fchub_membership_plan_rules')) {
                return [
                    'id' => 91,
                    'plan_id' => 5,
                    'provider' => 'wordpress_core',
                    'resource_type' => 'post',
                    'resource_id' => '55',
                    'drip_type' => 'delayed',
                    'drip_delay_days' => 3,
                    'drip_date' => null,
                    'sort_order' => 1,
                ];
            }

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

        $request = new \WP_REST_Request('GET', '/fchub-memberships/v1/admin/drip/notifications', [
            'date' => '2026-03-20',
            'per_page' => 20,
            'page' => 1,
        ]);
        $response = DripController::notifications($request);
        $data = $response->get_data();

        $this->assertStringContainsString("notify_at >= '2026-03-20 00:00:00'", implode("\n", array_column($GLOBALS['_fchub_test_queries'], 1)));
        $this->assertSame('Gold Plan', $data['data'][0]['plan_title']);
        $this->assertSame('Locked Lesson', $data['data'][0]['content_title']);
        $this->assertSame('drip@example.com', $data['data'][0]['user_email']);
    }

    public function test_register_routes_and_stats_endpoints_are_wired(): void
    {
        DripController::registerRoutes();

        self::assertArrayHasKey('fchub-memberships/v1/admin/drip/overview', $GLOBALS['_fchub_test_routes']);
        self::assertArrayHasKey('fchub-memberships/v1/admin/drip/calendar', $GLOBALS['_fchub_test_routes']);
        self::assertArrayHasKey('fchub-memberships/v1/admin/drip/notifications', $GLOBALS['_fchub_test_routes']);
        self::assertArrayHasKey('fchub-memberships/v1/admin/drip/notifications/(?P<id>\d+)/retry', $GLOBALS['_fchub_test_routes']);
        self::assertArrayHasKey('fchub-memberships/v1/admin/drip/stats', $GLOBALS['_fchub_test_routes']);

        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static fn(string $query): array => str_contains($query, 'SELECT * FROM wp_fchub_membership_plans')
            ? [[
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
            ]]
            : [[
                'id' => 90,
                'plan_id' => 5,
                'provider' => 'wordpress_core',
                'resource_type' => 'post',
                'resource_id' => '55',
                'drip_type' => 'delayed',
                'drip_delay_days' => 3,
                'drip_date' => null,
                'sort_order' => 1,
            ]];

        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static function (string $query): int {
            return match (true) {
                str_contains($query, "status = 'pending'") => 3,
                str_contains($query, "status = 'sent'") => 9,
                str_contains($query, "status = 'failed'") => 1,
                default => 0,
            };
        };

        $overview = DripController::overview(new \WP_REST_Request('GET', '/fchub-memberships/v1/admin/drip/overview'));
        $stats = DripController::stats(new \WP_REST_Request('GET', '/fchub-memberships/v1/admin/drip/stats'));
        $retry = DripController::retry(new \WP_REST_Request('POST', '/fchub-memberships/v1/admin/drip/notifications/999/retry', ['id' => 999]));

        self::assertSame(1, $overview->get_data()['data']['total_rules']);
        self::assertSame(3, $overview->get_data()['data']['pending']);
        self::assertSame(['pending' => 3, 'sent' => 9], $stats->get_data()['data']);
        self::assertSame(422, $retry->get_status());
    }
}

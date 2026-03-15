<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Http\Controllers;

use FChubMemberships\Http\Controllers\ReportController;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class ReportControllerFeatureTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['_fchub_test_users'][21] = (object) [
            'ID' => 21,
            'display_name' => 'Alice Example',
            'user_email' => 'alice@example.com',
        ];
        $GLOBALS['_fchub_test_posts'][55] = (object) [
            'ID' => 55,
            'post_title' => 'Members Post',
            'post_type' => 'post',
        ];
        $GLOBALS['_fchub_test_terms_by_taxonomy']['category'][3] = (object) [
            'term_id' => 3,
            'name' => 'Premium Category',
        ];
    }

    public function test_register_routes_registers_all_report_endpoints(): void
    {
        ReportController::registerRoutes();

        foreach ([
            'fchub-memberships/v1/admin/reports/overview',
            'fchub-memberships/v1/admin/reports/members-over-time',
            'fchub-memberships/v1/admin/reports/plan-distribution',
            'fchub-memberships/v1/admin/reports/churn',
            'fchub-memberships/v1/admin/reports/retention-cohort',
            'fchub-memberships/v1/admin/reports/revenue',
            'fchub-memberships/v1/admin/reports/content-popularity',
            'fchub-memberships/v1/admin/reports/expiring-soon',
            'fchub-memberships/v1/admin/reports/renewal-rate',
            'fchub-memberships/v1/admin/reports/trial-conversion',
        ] as $route) {
            self::assertArrayHasKey($route, $GLOBALS['_fchub_test_routes']);
        }
    }

    public function test_controller_methods_return_expected_shapes_and_value_transforms(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static function (string $query): int|float {
            return match (true) {
                str_contains($query, 'COUNT(DISTINCT user_id)') && str_contains($query, "status = 'active'") => 12,
                str_contains($query, 'COUNT(*) FROM wp_fchub_membership_plans WHERE status = \'active\'') => 4,
                str_contains($query, 'COUNT(*) FROM wp_fchub_membership_protection_rules') => 7,
                str_contains($query, 'COUNT(*) FROM wp_fchub_membership_grants WHERE created_at >=') => 9,
                str_contains($query, 'CASE s.billing_interval') => 1200.0,
                str_contains($query, 'SUM(o.total_amount)') && str_contains($query, 'g.status = \'active\'') => 6000,
                str_contains($query, 'COUNT(*) FROM (') => 2,
                str_contains($query, 'COUNT(DISTINCT user_id)') && str_contains($query, 'status IN (\'active\', \'expired\', \'revoked\')') => 10,
                str_contains($query, 'COUNT(DISTINCT user_id)') && str_contains($query, 'renewal_count > 0') => 5,
                str_contains($query, 'AVG(renewal_count)') => 2.2,
                default => 2,
            };
        };

        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query): array {
            return match (true) {
                str_contains($query, 'GROUP BY stat_date') => [['date' => '2026-01-01', 'count' => '5']],
                str_contains($query, 'ORDER BY count DESC') => [['plan_id' => '5', 'plan_title' => 'Gold Plan', 'count' => '8']],
                str_contains($query, 'GROUP BY DATE_FORMAT(stat_date') => [['month' => '2026-01', 'peak_active' => '10', 'total_churned' => '2']],
                str_contains($query, 'GROUP BY month, g.plan_id, p.title') => [['month' => '2026-01', 'plan_id' => '5', 'plan_title' => 'Gold Plan', 'revenue' => '4500']],
                str_contains($query, 'GROUP BY g.plan_id, p.title') && str_contains($query, 'member_count') => [['plan_id' => '5', 'plan_title' => 'Gold Plan', 'member_count' => '3', 'total_revenue' => '9000']],
                str_contains($query, 'GROUP BY g.provider, g.resource_type, g.resource_id') && str_contains($query, 'ORDER BY member_count DESC') => [['provider' => 'wordpress_core', 'resource_type' => 'post', 'resource_id' => '55', 'member_count' => '7']],
                str_contains($query, 'ORDER BY member_count ASC') => [['provider' => 'wordpress_core', 'resource_type' => 'category', 'resource_id' => '3', 'member_count' => '1']],
                str_contains($query, 'WHERE r.drip_type != \'immediate\'') => [['plan_id' => '5', 'plan_title' => 'Gold Plan', 'drip_count' => '2']],
                str_contains($query, 'GROUP_CONCAT(DISTINCT p.title') => [['provider' => 'wordpress_core', 'resource_type' => 'post', 'resource_id' => '55', 'plan_count' => '2', 'plan_titles' => 'Gold Plan||Silver Plan']],
                str_contains($query, 'GROUP BY g.plan_id, p.title') && str_contains($query, 'renewed_members') => [['plan_id' => '5', 'plan_title' => 'Gold Plan', 'total_members' => '4', 'renewed_members' => '3', 'avg_renewals' => '1.5']],
                str_contains($query, 'GROUP BY DATE_FORMAT(updated_at') => [['month' => '2026-01', 'total_renewals' => '3']],
                str_contains($query, 'WHERE g.trial_ends_at IS NOT NULL') => [['plan_id' => '5', 'plan_title' => 'Gold Plan', 'total_trials' => '4', 'converted' => '3', 'dropped' => '1']],
                str_contains($query, 'ORDER BY expires_at ASC') => [[
                    'id' => 99,
                    'user_id' => 21,
                    'plan_id' => 5,
                    'provider' => 'wordpress_core',
                    'resource_type' => 'post',
                    'resource_id' => '55',
                    'source_type' => 'manual',
                    'source_id' => 0,
                    'feed_id' => null,
                    'grant_key' => 'grant-99',
                    'status' => 'active',
                    'starts_at' => null,
                    'expires_at' => '2026-01-15 10:00:00',
                    'drip_available_at' => null,
                    'trial_ends_at' => null,
                    'cancellation_requested_at' => null,
                    'cancellation_effective_at' => null,
                    'cancellation_reason' => null,
                    'renewal_count' => 0,
                    'source_ids' => '[]',
                    'meta' => '{}',
                    'created_at' => '2026-01-01 10:00:00',
                    'updated_at' => '2026-01-01 10:00:00',
                ]],
                default => [],
            };
        };

        $GLOBALS['_fchub_test_wpdb_overrides']['get_col'] = static fn(): array => [21, 22];

        $rangeRequest = new \WP_REST_Request('GET', '/fchub-memberships/v1/admin/reports/all', [
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
        ]);

        $overview = ReportController::overview($rangeRequest)->get_data()['data'];
        $members = ReportController::membersOverTime(new \WP_REST_Request('GET', '/r', ['period' => '30d']))->get_data()['data'];
        $plans = ReportController::planDistribution($rangeRequest)->get_data()['data'];
        $churn = ReportController::churn(new \WP_REST_Request('GET', '/r', ['period' => '12m']))->get_data()['data'];
        $retention = ReportController::retentionCohort(new \WP_REST_Request('GET', '/r', ['months' => 2]))->get_data()['data'];
        $revenue = ReportController::revenue($rangeRequest)->get_data()['data'];
        $content = ReportController::contentPopularity($rangeRequest)->get_data()['data'];
        $expiring = ReportController::expiringSoon(new \WP_REST_Request('GET', '/r', ['days' => 7, 'limit' => 10]))->get_data()['data'];
        $renewal = ReportController::renewalRate($rangeRequest)->get_data()['data'];
        $trial = ReportController::trialConversion($rangeRequest)->get_data()['data'];

        self::assertSame(12, $overview['active_members']);
        self::assertSame(5, $members[0]['count']);
        self::assertSame('Gold Plan', $plans[0]['plan_title']);
        self::assertSame(16.67, $churn['current_rate']);
        self::assertSame('2026-02', $retention[0]['cohort']);
        self::assertSame(45.0, $revenue['per_plan'][0]['revenue']);
        self::assertSame(12.0, $revenue['mrr']);
        self::assertSame(5.0, $revenue['arpm']);
        self::assertSame(30.0, $revenue['ltv'][0]['ltv']);
        self::assertSame('Members Post', $content['most_accessed'][0]['title']);
        self::assertSame('Premium Category', $content['least_accessed'][0]['title']);
        self::assertSame('alice@example.com', $expiring[0]['user_email']);
        self::assertSame(50.0, $renewal['overall_rate']);
        self::assertSame(75.0, $trial['overall_rate']);
    }
}

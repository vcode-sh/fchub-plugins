<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Reports;

use FChubMemberships\Domain\Reports\ReportInsightsService;
use FChubMemberships\Reports\ChurnReport;
use FChubMemberships\Reports\ContentPopularityReport;
use FChubMemberships\Reports\MemberStatsReport;
use FChubMemberships\Reports\RevenueReport;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class ReportServicesTest extends PluginTestCase
{
    private function planRow(int $id, string $title, int $level = 0): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'slug' => strtolower(str_replace(' ', '-', $title)),
            'description' => '',
            'status' => 'active',
            'level' => $level,
            'duration_type' => 'lifetime',
            'duration_days' => null,
            'trial_days' => 0,
            'grace_period_days' => 0,
            'includes_plan_ids' => '[]',
            'restriction_message' => null,
            'redirect_url' => null,
            'settings' => '{}',
            'meta' => '{}',
            'scheduled_status' => null,
            'scheduled_at' => null,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['_fchub_test_posts'][55] = (object) ['ID' => 55, 'post_title' => 'Members Post', 'post_type' => 'post'];
        $GLOBALS['_fchub_test_terms_by_taxonomy']['category'][3] = (object) ['term_id' => 3, 'name' => 'Premium Category'];
        $GLOBALS['_fchub_test_users'][21] = (object) ['ID' => 21, 'display_name' => 'Alice Example', 'user_email' => 'alice@example.com'];
    }

    public function test_member_stats_and_churn_reports_transform_rows_and_date_ranges(): void
    {
        $queries = [];

        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static function (string $query) use (&$queries): int|string {
            $queries[] = $query;

            return match (true) {
                str_contains($query, 'user_id IN') && str_contains($query, "status = 'active'") => 2,
                str_contains($query, 'COUNT(DISTINCT user_id)') && str_contains($query, "status = 'active'") => 12,
                str_contains($query, 'status = \'active\'') && str_contains($query, 'fchub_membership_plans') => 4,
                str_contains($query, 'COUNT(*) FROM wp_fchub_membership_protection_rules') => 7,
                str_contains($query, 'COUNT(*) FROM wp_fchub_membership_grants WHERE created_at >=') => 9,
                default => 2,
            };
        };

        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query) use (&$queries): array {
            $queries[] = $query;

            return match (true) {
                str_contains($query, 'GROUP BY stat_date') => [
                    ['date' => '2026-01-01', 'count' => '5'],
                    ['date' => '2026-01-02', 'count' => '6'],
                ],
                str_contains($query, 'ORDER BY count DESC') => [
                    ['plan_id' => '5', 'plan_title' => 'Gold Plan', 'count' => '8'],
                ],
                str_contains($query, 'GROUP BY DATE_FORMAT(stat_date') => [
                    ['month' => '2026-01', 'peak_active' => '10', 'total_churned' => '2'],
                ],
                default => [],
            };
        };

        $GLOBALS['_fchub_test_wpdb_overrides']['get_col'] = static function (): array {
            return [21, 22];
        };

        $memberStats = new MemberStatsReport();
        $overview = $memberStats->getOverview('2026-01-01', '2026-01-31');
        $membersOverTime = $memberStats->getMembersOverTime('12m', '2026-01-01', '2026-01-31');
        $planDistribution = $memberStats->getPlanDistribution('2026-01-01', '2026-01-31');

        $churn = new ChurnReport();
        $churnRate = $churn->getChurnRate('30d', '2026-01-01', '2026-01-31');
        $churnOverTime = $churn->getChurnOverTime('12m', '2026-01-01', '2026-01-31');
        $cohort = $churn->getRetentionCohort(2);

        self::assertSame(12, $overview['active_members']);
        self::assertSame(4, $overview['active_plans']);
        self::assertSame(7, $overview['content_protected']);
        self::assertSame(9, $overview['grants_this_month']);
        self::assertSame([
            ['date' => '2026-01-01', 'count' => 5],
            ['date' => '2026-01-02', 'count' => 6],
        ], $membersOverTime);
        self::assertSame('Gold Plan', $planDistribution[0]['plan_title']);
        self::assertSame(16.67, $churnRate);
        self::assertSame(20.0, $churnOverTime[0]['churn_rate']);
        self::assertSame('2026-02', $cohort[0]['cohort']);
        self::assertSame(2, $cohort[0]['size']);
        self::assertSame(100.0, $cohort[0]['retention'][0]);

        $queryDump = implode("\n", $queries);
        self::assertStringContainsString("'2026-01-01 00:00:00'", $queryDump);
        self::assertStringContainsString("'2026-01-31 23:59:59'", $queryDump);
        self::assertStringContainsString("'2026-01-01'", $queryDump);
        self::assertStringContainsString("'2026-01-31'", $queryDump);
    }

    public function test_member_stats_aggregate_daily_updates_existing_rows_and_inserts_new_totals(): void
    {
        $updates = [];
        $inserts = [];

        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = fn(string $query): array => str_contains($query, 'SELECT * FROM wp_fchub_membership_plans')
            ? [
                $this->planRow(5, 'Gold Plan', 10),
                $this->planRow(6, 'Silver Plan', 5),
            ]
            : [];

        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static function (string $query): int|string {
            return match (true) {
                str_contains($query, 'SELECT id FROM wp_fchub_membership_stats_daily') && str_contains($query, 'plan_id = 5') => 300,
                str_contains($query, 'SELECT id FROM wp_fchub_membership_stats_daily') && str_contains($query, 'plan_id = 6') => 0,
                str_contains($query, 'SELECT id FROM wp_fchub_membership_stats_daily') && str_contains($query, 'plan_id = 0') => 0,
                str_contains($query, 'COUNT(DISTINCT user_id)') && str_contains($query, 'plan_id = 5') => 9,
                str_contains($query, 'COUNT(DISTINCT user_id)') && str_contains($query, 'plan_id = 6') => 4,
                str_contains($query, 'COUNT(DISTINCT user_id)') => 13,
                str_contains($query, 'SELECT COALESCE(SUM(o.total_amount), 0)') && str_contains($query, 'plan_id = 5') => 5000,
                str_contains($query, 'SELECT COALESCE(SUM(o.total_amount), 0)') && str_contains($query, 'plan_id = 6') => 2000,
                str_contains($query, 'SELECT COALESCE(SUM(o.total_amount), 0)') => 7000,
                default => 1,
            };
        };

        $GLOBALS['_fchub_test_wpdb_overrides']['update'] = static function (string $table, array $data, array $where) use (&$updates): int {
            $updates[] = [$table, $data, $where];
            return 1;
        };

        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static function (string $table, array $data) use (&$inserts): int {
            $inserts[] = [$table, $data];
            return 1;
        };

        (new MemberStatsReport())->aggregateDaily();

        self::assertCount(1, $updates);
        self::assertSame(['id' => 300], $updates[0][2]);
        self::assertSame(5000, $updates[0][1]['revenue']);
        self::assertCount(2, $inserts);
        self::assertSame(6, $inserts[0][1]['plan_id']);
        self::assertSame(2000, $inserts[0][1]['revenue']);
        self::assertSame(0, $inserts[1][1]['plan_id']);
        self::assertSame(7000, $inserts[1][1]['revenue']);
    }

    public function test_revenue_content_and_insight_reports_transform_rows(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static function (string $query): int|float {
            return match (true) {
                str_contains($query, 'CASE s.billing_interval') => 1200.0,
                str_contains($query, 'SUM(o.total_amount)') && str_contains($query, 'g.status = \'active\'') => 6000,
                str_contains($query, 'COUNT(DISTINCT user_id)') && str_contains($query, 'status IN (\'active\', \'expired\', \'revoked\')') => 10,
                str_contains($query, 'COUNT(DISTINCT user_id)') && str_contains($query, 'renewal_count > 0') => 5,
                str_contains($query, 'AVG(renewal_count)') => 2.2,
                str_contains($query, 'COUNT(*) FROM (') => 2,
                default => 4,
            };
        };

        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query): array {
            return match (true) {
                str_contains($query, 'GROUP BY month, g.plan_id, p.title') => [
                    ['month' => '2026-01', 'plan_id' => '5', 'plan_title' => 'Gold Plan', 'revenue' => '4500'],
                ],
                str_contains($query, 'GROUP BY g.plan_id, p.title') && str_contains($query, 'member_count') => [
                    ['plan_id' => '5', 'plan_title' => 'Gold Plan', 'member_count' => '3', 'total_revenue' => '9000'],
                ],
                str_contains($query, 'GROUP BY g.provider, g.resource_type, g.resource_id') && str_contains($query, 'ORDER BY member_count DESC') => [
                    ['provider' => 'wordpress_core', 'resource_type' => 'post', 'resource_id' => '55', 'member_count' => '7'],
                ],
                str_contains($query, 'ORDER BY member_count ASC') => [
                    ['provider' => 'wordpress_core', 'resource_type' => 'category', 'resource_id' => '3', 'member_count' => '1'],
                ],
                str_contains($query, 'WHERE r.drip_type != \'immediate\'') => [
                    ['plan_id' => '5', 'plan_title' => 'Gold Plan', 'drip_count' => '2'],
                ],
                str_contains($query, 'GROUP_CONCAT(DISTINCT p.title') => [
                    ['provider' => 'wordpress_core', 'resource_type' => 'post', 'resource_id' => '55', 'plan_count' => '2', 'plan_titles' => 'Gold Plan||Silver Plan'],
                ],
                str_contains($query, 'GROUP BY g.plan_id, p.title') && str_contains($query, 'renewed_members') => [
                    ['plan_id' => '5', 'plan_title' => 'Gold Plan', 'total_members' => '4', 'renewed_members' => '3', 'avg_renewals' => '1.5'],
                ],
                str_contains($query, 'GROUP BY DATE_FORMAT(updated_at') => [
                    ['month' => '2026-01', 'total_renewals' => '3'],
                ],
                str_contains($query, 'WHERE g.trial_ends_at IS NOT NULL') => [
                    ['plan_id' => '5', 'plan_title' => 'Gold Plan', 'total_trials' => '4', 'converted' => '3', 'dropped' => '1'],
                ],
                default => [],
            };
        };

        $revenue = new RevenueReport();
        $perPlan = $revenue->getRevenuePerPlan('12m', '2026-01-01', '2026-01-31');
        $mrr = $revenue->getMRR();
        $arpm = $revenue->getAverageRevenuePerMember('2026-01-01', '2026-01-31');
        $ltv = $revenue->getLifetimeValuePerPlan('2026-01-01', '2026-01-31');

        $content = new ContentPopularityReport();
        $most = $content->getMostAccessedContent(20, '2026-01-01', '2026-01-31');
        $least = $content->getLeastAccessedContent();
        $completion = $content->getDripCompletionRates();
        $overlap = $content->getContentByPlanOverlap();

        $insights = new ReportInsightsService();
        $renewal = $insights->renewalRate('2026-01-01', '2026-01-31');
        $trial = $insights->trialConversion('2026-01-01', '2026-01-31');

        self::assertSame('Gold Plan', $perPlan[0]['plan_title']);
        self::assertSame(1200, $mrr);
        self::assertSame(1500, $arpm);
        self::assertSame(3000, $ltv[0]['ltv']);

        self::assertSame('Members Post', $most[0]['title']);
        self::assertSame('Premium Category', $least[0]['title']);
        self::assertSame(50.0, $completion[0]['completion_rate']);
        self::assertSame(['Gold Plan', 'Silver Plan'], $overlap[0]['plan_titles']);

        self::assertSame(50.0, $renewal['overall_rate']);
        self::assertSame(2.2, $renewal['avg_renewals_per_member']);
        self::assertSame(75.0, $trial['overall_rate']);
    }
}

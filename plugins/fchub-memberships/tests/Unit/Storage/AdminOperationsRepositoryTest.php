<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Storage;

use FChubMemberships\Storage\AdminMemberQuery;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class AdminOperationsRepositoryTest extends PluginTestCase
{
    private function planRow(): array
    {
        return [
            'id' => 5,
            'title' => 'Gold Plan',
            'slug' => 'gold-plan',
            'description' => '',
            'status' => 'active',
            'level' => 10,
            'duration_type' => 'lifetime',
            'duration_days' => null,
            'trial_days' => 0,
            'grace_period_days' => 0,
            'includes_plan_ids' => '[]',
            'restriction_message' => '',
            'redirect_url' => '',
            'settings' => '{}',
            'meta' => '{}',
            'scheduled_status' => null,
            'scheduled_at' => null,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
            'members_count' => '8',
            'rules_count' => '3',
            'drip_count' => '1',
            'history_count' => '12',
        ];
    }

    private function memberRow(): array
    {
        return [
            'id' => 15,
            'user_id' => 21,
            'plan_id' => 5,
            'source_type' => 'order',
            'source_id' => 77,
            'feed_id' => null,
            'status' => 'active',
            'created_at' => '2026-03-01 10:00:00',
            'updated_at' => '2026-03-02 10:00:00',
            'expires_at' => '2026-03-18 10:00:00',
            'starts_at' => null,
            'grant_count' => '4',
            'user_email' => 'alice@example.com',
            'display_name' => 'Alice Example',
            'plan_title' => 'Gold Plan',
        ];
    }

    public function test_admin_plan_list_searches_title_and_slug_and_returns_aggregate_health_data(): void
    {
        $query = '';
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = function (string $sql) use (&$query): array {
            $query = $sql;
            return [$this->planRow()];
        };

        $plans = (new PlanRepository())->allForAdmin([
            'search' => 'gold',
            'status' => 'active',
            'per_page' => 20,
            'page' => 1,
        ]);

        self::assertSame(8, $plans[0]['members_count']);
        self::assertSame(3, $plans[0]['rules_count']);
        self::assertSame(1, $plans[0]['drip_count']);
        self::assertSame(12, $plans[0]['history_count']);
        self::assertStringContainsString("(p.title LIKE '%gold%' OR p.slug LIKE '%gold%')", $query);
        self::assertStringContainsString("g.status = 'active'", $query);
        self::assertStringContainsString('g.starts_at IS NULL', $query);
        self::assertStringContainsString('g.expires_at IS NULL', $query);
        self::assertStringContainsString('AS members_count', $query);
        self::assertStringContainsString('AS history_count', $query);
    }

    public function test_admin_plan_summary_and_history_count_are_bounded_database_aggregates(): void
    {
        $summaryQuery = '';
        $historyQuery = '';
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static function (string $sql) use (&$summaryQuery): array {
            $summaryQuery = $sql;
            return ['total' => '6', 'active' => '3', 'needs_content' => '1', 'scheduled' => '2'];
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static function (string $sql) use (&$historyQuery): int {
            $historyQuery = $sql;
            return 4;
        };

        $repo = new PlanRepository();
        $summary = $repo->getAdminSummary();
        $historyCount = $repo->countGrantHistory(5);

        self::assertSame(['total' => 6, 'active' => 3, 'needs_content' => 1, 'scheduled' => 2], $summary);
        self::assertStringContainsString('COUNT(*) AS total', $summaryQuery);
        self::assertStringContainsString('NOT EXISTS', $summaryQuery);
        self::assertStringContainsString('fchub_membership_plan_rules', $summaryQuery);
        self::assertSame(4, $historyCount);
        self::assertStringContainsString('WHERE plan_id = 5', $historyQuery);
    }

    public function test_admin_member_list_joins_plan_labels_supports_user_id_search_and_expiry_window(): void
    {
        $queries = [];
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = function (string $sql) use (&$queries): array {
            $queries[] = $sql;
            return [$this->memberRow()];
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static function (string $sql) use (&$queries): int {
            $queries[] = $sql;
            return 1;
        };

        $repo = new AdminMemberQuery();
        $members = $repo->getMembers([
            'search' => '21',
            'expires_within' => 7,
            'source_type' => 'order',
            'per_page' => 20,
            'page' => 1,
        ]);
        $total = $repo->countMembers([
            'search' => '21',
            'expires_within' => 7,
            'source_type' => 'order',
        ]);

        self::assertSame('Gold Plan', $members[0]['plan_title']);
        self::assertSame(4, $members[0]['grant_count']);
        self::assertSame(1, $total);

        $queryDump = implode("\n", $queries);
        self::assertStringContainsString('LEFT JOIN wp_fchub_membership_plans p ON g.plan_id = p.id', $queryDump);
        self::assertStringContainsString('MAX(g.feed_id) AS feed_id', $queryDump);
        self::assertStringContainsString('u.ID = 21', $queryDump);
        self::assertStringContainsString("g.source_type = 'order'", $queryDump);
        self::assertStringContainsString('g.expires_at IS NOT NULL', $queryDump);
        self::assertStringContainsString("g.expires_at > '2026-03-13 22:00:00'", $queryDump);
        self::assertStringContainsString("g.expires_at <= '2026-03-20 22:00:00'", $queryDump);
    }

    public function test_admin_member_summary_returns_access_assignment_health_counts(): void
    {
        $query = '';
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static function (string $sql) use (&$query): array {
            $query = $sql;
            return ['active' => '9', 'expiring_soon' => '2', 'scheduled' => '3', 'paused' => '1', 'ended' => '4'];
        };

        $summary = (new AdminMemberQuery())->getAdminSummary(7);

        self::assertSame(
            ['active' => 9, 'expiring_soon' => 2, 'scheduled' => 3, 'paused' => 1, 'ended' => 4],
            $summary
        );
        self::assertStringContainsString('GROUP BY g.user_id, g.plan_id', $query);
        self::assertStringContainsString('AS expiring_soon', $query);
        self::assertStringContainsString("IN ('expired', 'revoked')", $query);
    }
}

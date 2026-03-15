<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Storage;

use FChubMemberships\Storage\EventLockRepository;
use FChubMemberships\Storage\GrantSourceRepository;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Storage\PlanRuleRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class RepositoryCoverageTest extends PluginTestCase
{
    private function planRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 5,
            'title' => 'Gold Plan',
            'slug' => 'gold-plan',
            'description' => 'Top tier',
            'status' => 'draft',
            'level' => 10,
            'duration_type' => 'fixed_days',
            'duration_days' => 30,
            'trial_days' => 7,
            'grace_period_days' => 3,
            'includes_plan_ids' => '[1,2]',
            'restriction_message' => 'Restricted',
            'redirect_url' => 'https://example.com/join',
            'settings' => '{"color":"gold"}',
            'meta' => '{"billing_anchor_day":15}',
            'scheduled_status' => 'archived',
            'scheduled_at' => '2026-03-20 10:00:00',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-02 00:00:00',
        ], $overrides);
    }

    private function ruleRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 11,
            'plan_id' => 5,
            'provider' => 'wordpress_core',
            'resource_type' => 'post',
            'resource_id' => '55',
            'drip_delay_days' => 3,
            'drip_type' => 'delayed',
            'drip_date' => null,
            'sort_order' => 1,
            'meta' => '{"teaser":"yes"}',
            'created_at' => '2026-03-01 00:00:00',
            'updated_at' => '2026-03-02 00:00:00',
        ], $overrides);
    }

    public function test_plan_repository_covers_crud_schedule_and_slug_helpers(): void
    {
        $inserted = [];
        $updated = [];
        $queries = [];

        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static function (string $table, array $data, \wpdb $wpdb) use (&$inserted): int {
            $inserted[] = [$table, $data];
            $wpdb->insert_id = 55;
            return 1;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['update'] = static function (string $table, array $data, array $where) use (&$updated): int {
            $updated[] = [$table, $data, $where];
            return 1;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['delete'] = static function (): int {
            return 1;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = fn(string $query): ?array => match (true) {
            str_contains($query, 'WHERE id = 5') => $this->planRow(),
            str_contains($query, "WHERE slug = 'gold-plan'") => $this->planRow(),
            default => null,
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query) use (&$queries): array {
            $queries[] = $query;
            return [[
                'id' => 5,
                'title' => 'Gold Plan',
                'slug' => 'gold-plan',
                'description' => 'Top tier',
                'status' => 'active',
                'level' => 10,
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
            ]];
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static function (string $query) use (&$queries): int {
            $queries[] = $query;
            return match (true) {
                str_contains($query, 'COUNT(DISTINCT user_id)') => 8,
                str_contains($query, 'COUNT(*) FROM wp_fchub_membership_plan_rules') => 4,
                str_contains($query, "slug = 'gold-plan' AND id != 5") => 1,
                str_contains($query, "slug = 'gold-plan'") => 1,
                str_contains($query, "slug = 'gold-plan-1'") => 0,
                default => 2,
            };
        };

        $repo = new PlanRepository();
        $found = $repo->find(5);
        $bySlug = $repo->findBySlug('gold-plan');
        $all = $repo->all(['status' => 'active', 'search' => 'Gold', 'order_by' => 'unknown', 'per_page' => 10, 'page' => 2]);
        $count = $repo->count(['status' => 'active', 'search' => 'Gold']);
        $created = $repo->create(['title' => 'Silver Plan', 'slug' => 'silver-plan', 'status' => 'draft', 'includes_plan_ids' => [1], 'settings' => ['x' => 1], 'meta' => ['y' => 2]]);
        $repo->update(5, ['status' => 'draft', 'includes_plan_ids' => [3], 'settings' => ['theme' => 'dark'], 'meta' => ['flag' => true]]);
        $repo->delete(5);
        $activePlans = $repo->getActivePlans();
        $memberCount = $repo->getMemberCount(5);
        $ruleCount = $repo->getRuleCount(5);
        $slugExists = $repo->slugExists('gold-plan');
        $uniqueSlug = $repo->generateUniqueSlug('Gold Plan', 5);
        $repo->updateSchedule(5, 'archived', '2026-03-20 10:00:00');
        $dueScheduled = $repo->getDueScheduledPlans();

        self::assertSame('inactive', $found['status']);
        self::assertSame([1, 2], $bySlug['includes_plan_ids']);
        self::assertCount(1, $all);
        self::assertSame(2, $count);
        self::assertSame(55, $created);
        self::assertSame('wp_fchub_membership_plans', $inserted[0][0]);
        self::assertSame('[1]', $inserted[0][1]['includes_plan_ids']);
        self::assertSame('{"x":1}', $inserted[0][1]['settings']);
        self::assertSame('{"y":2}', $inserted[0][1]['meta']);
        self::assertSame('inactive', $updated[0][1]['status']);
        self::assertSame('[3]', $updated[0][1]['includes_plan_ids']);
        self::assertSame([5], array_column($activePlans, 'id'));
        self::assertSame(8, $memberCount);
        self::assertSame(4, $ruleCount);
        self::assertTrue($slugExists);
        self::assertSame('gold-plan-1', $uniqueSlug);
        self::assertCount(1, $dueScheduled);
    }

    public function test_plan_rule_repository_covers_crud_bulk_and_lookup_helpers(): void
    {
        $inserted = [];
        $deleted = [];
        $updates = [];

        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static function (string $table, array $data, \wpdb $wpdb) use (&$inserted): int {
            $inserted[] = [$table, $data];
            $wpdb->insert_id = 70 + count($inserted);
            return 1;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['update'] = static function (string $table, array $data, array $where) use (&$updates): int {
            $updates[] = [$table, $data, $where];
            return 1;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['delete'] = static function (string $table, array $where) use (&$deleted): int {
            $deleted[] = [$table, $where];
            return 1;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = fn(string $query): ?array => str_contains($query, 'WHERE id = 11')
            ? $this->ruleRow()
            : null;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query): array {
            return match (true) {
                str_contains($query, 'WHERE plan_id = 5 ORDER BY sort_order ASC, id ASC') => [[
                    'id' => 11,
                    'plan_id' => 5,
                    'provider' => 'wordpress_core',
                    'resource_type' => 'post',
                    'resource_id' => '55',
                    'drip_delay_days' => 3,
                    'drip_type' => 'delayed',
                    'drip_date' => null,
                    'sort_order' => 1,
                    'meta' => '{"teaser":"yes"}',
                ]],
                str_contains($query, 'WHERE plan_id IN (5,6)') => [[
                    'id' => 12,
                    'plan_id' => 6,
                    'provider' => 'wordpress_core',
                    'resource_type' => 'page',
                    'resource_id' => '99',
                    'drip_delay_days' => 0,
                    'drip_type' => 'immediate',
                    'drip_date' => null,
                    'sort_order' => 2,
                    'meta' => '{}',
                ]],
                str_contains($query, 'SELECT DISTINCT plan_id') => [['plan_id' => '5'], ['plan_id' => '6']],
                str_contains($query, "drip_type != 'immediate'") => [[
                    'id' => 13,
                    'plan_id' => 5,
                    'provider' => 'wordpress_core',
                    'resource_type' => 'post',
                    'resource_id' => '55',
                    'drip_delay_days' => 3,
                    'drip_type' => 'delayed',
                    'drip_date' => null,
                    'sort_order' => 1,
                    'meta' => '{}',
                ]],
                default => [],
            };
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static fn(string $query): int => str_contains($query, 'COUNT(*)') ? 4 : 0;

        $repo = new PlanRuleRepository();
        $found = $repo->find(11);
        $byPlan = $repo->getByPlanId(5);
        $byPlans = $repo->getByPlanIds([5, 6]);
        $created = $repo->create(['plan_id' => 5, 'resource_type' => 'post', 'resource_id' => '55', 'meta' => ['x' => 1]]);
        $repo->update(11, ['meta' => ['z' => 2], 'sort_order' => 9]);
        $repo->delete(11);
        $repo->deleteByPlanId(5);
        $bulk = $repo->bulkCreate(5, [['resource_type' => 'post', 'resource_id' => '10'], ['resource_type' => 'page', 'resource_id' => '20']]);
        $repo->syncRules(6, [['resource_type' => 'post', 'resource_id' => '99']]);
        $plans = $repo->findPlansWithResource('wordpress_core', 'post', '55');
        $dripRules = $repo->getDripRules(5);
        $count = $repo->countByPlanId(5);

        self::assertSame(['teaser' => 'yes'], $found['meta']);
        self::assertCount(1, $byPlan);
        self::assertCount(1, $byPlans);
        self::assertSame(71, $created);
        self::assertSame('{"x":1}', $inserted[0][1]['meta']);
        self::assertSame('{"z":2}', $updates[0][1]['meta']);
        self::assertSame(['id' => 11], $updates[0][2]);
        self::assertSame([72, 73], $bulk);
        self::assertSame(['5', '6'], $plans);
        self::assertCount(1, $dripRules);
        self::assertSame(4, $count);
        self::assertCount(3, $deleted);
    }

    public function test_event_lock_and_grant_source_repositories_cover_lookup_and_mutation_paths(): void
    {
        $updates = [];
        $deletes = [];
        $queries = [];

        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static function (string $query) use (&$queries): int {
            $queries[] = $query;
            return match (true) {
                str_contains($query, "result = 'success'") => 1,
                str_contains($query, 'grant_id = 10 AND source_type = \'order\' AND source_id = 77') => 0,
                str_contains($query, 'grant_id = 10') => 2,
                default => 0,
            };
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $query, \wpdb $wpdb) use (&$queries): int {
            $queries[] = $query;
            $wpdb->rows_affected = 1;
            return 1;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['update'] = static function (string $table, array $data, array $where) use (&$updates): int {
            $updates[] = [$table, $data, $where];
            return 1;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static fn(string $table, array $data): int => 1;
        $GLOBALS['_fchub_test_wpdb_overrides']['delete'] = static function (string $table, array $where) use (&$deletes): int {
            $deletes[] = [$table, $where];
            return 1;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query): array {
            return match (true) {
                str_contains($query, 'WHERE order_id = 99') => [['event_hash' => 'hash', 'order_id' => 99]],
                str_contains($query, 'WHERE grant_id = 10 ORDER BY created_at ASC') => [['grant_id' => 10, 'source_type' => 'order', 'source_id' => 77]],
                str_contains($query, 'SELECT DISTINCT grant_id') => [['grant_id' => '10'], ['grant_id' => '11']],
                str_contains($query, 'WHERE source_id = 77 AND source_type = \'order\'') => [['grant_id' => 10, 'source_type' => 'order', 'source_id' => 77]],
                default => [],
            };
        };

        $eventLocks = new EventLockRepository();
        $sources = new GrantSourceRepository();

        self::assertSame(md5('99|7|created|0'), EventLockRepository::makeEventHash(99, 7, 'created'));
        self::assertTrue($eventLocks->isProcessed('hash'));
        self::assertTrue($eventLocks->acquire([
            'event_hash' => 'hash',
            'order_id' => 99,
            'feed_id' => 7,
            'trigger' => 'created',
            'subscription_id' => 123,
        ]));
        $eventLocks->recordFailure('hash', 'Broken');
        self::assertSame([['event_hash' => 'hash', 'order_id' => 99]], $eventLocks->getByOrderId(99));
        self::assertSame(1, $eventLocks->purgeOlderThan(30));

        self::assertTrue($sources->addSource(10, 'order', 77));
        self::assertTrue($sources->removeSource(10, 'order', 77));
        self::assertSame([['grant_id' => 10, 'source_type' => 'order', 'source_id' => 77]], $sources->getSourcesByGrant(10));
        self::assertSame([['grant_id' => 10, 'source_type' => 'order', 'source_id' => 77]], $sources->getGrantsBySource(77));
        self::assertFalse($sources->hasSource(10, 'order', 77));
        self::assertTrue($sources->removeAllByGrant(10));
        self::assertSame(['10', '11'], $sources->getGrantIdsBySource(77));
        self::assertSame(2, $sources->countSourcesByGrant(10));

        self::assertSame('failed', $updates[0][1]['result']);
        self::assertSame([['wp_fchub_membership_grant_sources', ['grant_id' => 10, 'source_type' => 'order', 'source_id' => 77]], ['wp_fchub_membership_grant_sources', ['grant_id' => 10]]], $deletes);
        self::assertStringContainsString('INSERT IGNORE INTO wp_fchub_membership_event_locks', implode("\n", $queries));
    }
}

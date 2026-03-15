<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Storage;

use FChubMemberships\Storage\DripScheduleRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class DripScheduleRepositoryTest extends PluginTestCase
{
    private function row(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'grant_id' => 10,
            'plan_rule_id' => 20,
            'user_id' => 30,
            'notify_at' => '2026-03-20 10:00:00',
            'sent_at' => null,
            'status' => 'pending',
            'retry_count' => 0,
            'next_retry_at' => null,
        ], $overrides);
    }

    public function test_schedule_mark_sent_and_mark_failed_manage_retry_state(): void
    {
        $inserted = null;
        $updates = [];

        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static function (string $table, array $data, \wpdb $wpdb) use (&$inserted): int {
            $inserted = [$table, $data];
            $wpdb->insert_id = 51;
            return 1;
        };

        $GLOBALS['_fchub_test_wpdb_overrides']['update'] = static function (string $table, array $data, array $where) use (&$updates): int {
            $updates[] = [$table, $data, $where];
            return 1;
        };

        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = fn(string $query): ?array => str_contains($query, 'WHERE id = 51')
            ? $this->row(['id' => 51, 'status' => 'failed', 'retry_count' => 1])
            : null;

        $repo = new DripScheduleRepository();
        $id = $repo->schedule([
            'grant_id' => 10,
            'plan_rule_id' => 20,
            'user_id' => 30,
            'notify_at' => '2026-03-20 10:00:00',
        ]);

        $sent = $repo->markSent(51);
        $failed = $repo->markFailed(51);

        self::assertSame(51, $id);
        self::assertSame('wp_fchub_membership_drip_notifications', $inserted[0]);
        self::assertSame('pending', $inserted[1]['status']);
        self::assertTrue($sent);
        self::assertTrue($failed);
        self::assertSame('sent', $updates[0][1]['status']);
        self::assertSame('failed', $updates[1][1]['status']);
        self::assertSame(2, $updates[1][1]['retry_count']);
        self::assertSame(['id' => 51], $updates[1][2]);
    }

    public function test_collection_queries_apply_filters_and_hydrate_results(): void
    {
        $queries = [];

        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query) use (&$queries): array {
            $queries[] = $query;
            return [[
                'id' => 5,
                'grant_id' => 11,
                'plan_rule_id' => 21,
                'user_id' => 31,
                'notify_at' => '2026-03-21 11:00:00',
                'sent_at' => null,
                'status' => 'pending',
                'retry_count' => 1,
                'next_retry_at' => '2026-03-21 11:30:00',
                'resource_type' => 'post',
                'resource_id' => '55',
                'plan_id' => '5',
            ]];
        };

        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static function (string $query): int {
            return match (true) {
                str_contains($query, "status = 'pending'") => 4,
                str_contains($query, "status = 'sent'") => 7,
                default => 0,
            };
        };

        $GLOBALS['_fchub_test_wpdb_overrides']['delete'] = static function (string $table, array $where): int {
            return $table === 'wp_fchub_membership_drip_notifications' && $where === ['grant_id' => 11] ? 2 : 0;
        };

        $repo = new DripScheduleRepository();

        $pending = $repo->getPendingNotifications(50);
        $byGrant = $repo->getByGrantId(11);
        $byUser = $repo->getByUserId(31, ['status' => 'pending', 'per_page' => 10, 'page' => 2]);
        $all = $repo->all(['status' => 'pending', 'user_id' => 31, 'date' => '2026-03-21', 'per_page' => 20, 'page' => 1]);
        $calendar = $repo->getUpcomingUnlocks('2026-03-01 00:00:00', '2026-03-31 23:59:59');
        $deleted = $repo->deleteByGrantId(11);

        self::assertSame(5, $pending[0]['id']);
        self::assertSame(31, $byGrant[0]['user_id']);
        self::assertSame(21, $byUser[0]['plan_rule_id']);
        self::assertSame('55', $calendar[0]['resource_id']);
        self::assertSame(2, $deleted);
        self::assertSame(4, $repo->countPending());
        self::assertSame(7, $repo->countSent());

        $queryDump = implode("\n", $queries);
        self::assertStringContainsString("retry_count < 3", $queryDump);
        self::assertStringContainsString("user_id = 31", $queryDump);
        self::assertStringContainsString("status = 'pending'", $queryDump);
        self::assertStringContainsString("notify_at >= '2026-03-21 00:00:00'", $queryDump);
        self::assertStringContainsString("notify_at <= '2026-03-21 23:59:59'", $queryDump);
        self::assertStringContainsString("dn.notify_at >= '2026-03-01 00:00:00'", $queryDump);
        self::assertStringContainsString("dn.notify_at <= '2026-03-31 23:59:59'", $queryDump);
        self::assertCount(1, $all);
    }
}

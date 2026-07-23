<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Storage;

use FChubMemberships\Storage\EventLockRepository;
use FChubMemberships\Support\Clock;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class EventLockClockTest extends PluginTestCase
{
    public function test_claim_and_retention_use_site_local_clock(): void
    {
        $queries = [];
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $query, \wpdb $wpdb) use (&$queries): int {
            $queries[] = $query;
            $wpdb->rows_affected = 1;
            return 1;
        };
        $timezone = new \DateTimeZone('Asia/Kolkata');
        $clock = new Clock(new \DateTimeImmutable('2026-03-14 12:30:00', $timezone), $timezone);
        $repository = new EventLockRepository($clock);

        self::assertTrue(method_exists(EventLockRepository::class, 'claim'));
        $repository->claim('hash', ['order_id' => 9], 'owner-a', 300);
        $repository->purgeOlderThan(2);

        $sql = implode("\n", $queries);
        self::assertStringContainsString("'2026-03-14 12:30:00'", $sql);
        self::assertStringContainsString("'2026-03-14 12:35:00'", $sql);
        self::assertStringContainsString("completed_at < '2026-03-12 12:30:00'", $sql);
    }

    public function test_completion_and_failure_use_site_local_clock(): void
    {
        $queries = [];
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $query, \wpdb $wpdb) use (&$queries): int {
            $queries[] = $query;
            $wpdb->rows_affected = 1;
            return 1;
        };
        $timezone = new \DateTimeZone('Asia/Kolkata');
        $clock = new Clock(new \DateTimeImmutable('2026-03-14 12:30:00', $timezone), $timezone);
        $repository = new EventLockRepository($clock);

        self::assertTrue(method_exists(EventLockRepository::class, 'succeed'));
        self::assertTrue(method_exists(EventLockRepository::class, 'fail'));
        $repository->succeed('hash-success', 'owner-a');
        $repository->fail('hash-retry', 'owner-b', 'Transient', true);
        $repository->fail('hash-terminal', 'owner-c', 'Permanent', false);

        $sql = implode("\n", $queries);
        self::assertSame(9, substr_count($sql, '2026-03-14 12:30:00'));
        self::assertStringContainsString("next_retry_at = '2026-03-14 12:30:00'", $sql);
        self::assertStringContainsString("completed_at = '2026-03-14 12:30:00'", $sql);
    }
}

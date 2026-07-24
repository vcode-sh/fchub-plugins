<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Storage;

use FChubMemberships\Storage\WebhookDeliveryRepository;
use FChubMemberships\Support\Clock;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class WebhookDeliveryRepositoryTest extends PluginTestCase
{
    private const EVENT_ID = '00000000-0000-4000-8000-000000000123';

    public function test_create_many_deduplicates_destinations_and_replays_unique_rows(): void
    {
        $rows = [];
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static fn(string $query): ?string =>
            str_contains($query, 'webhook_events') ? self::EVENT_ID : null;
        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static function (
            string $table,
            array $data,
            \wpdb $wpdb
        ) use (&$rows): int|false {
            self::assertSame('wp_fchub_membership_webhook_deliveries', $table);
            $key = $data['event_id'] . ':' . $data['destination_hash'];
            if (isset($rows[$key])) {
                return false;
            }
            $rows[$key] = ['id' => $wpdb->insert_id] + $data;
            return 1;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static function (string $query) use (&$rows): ?array {
            foreach ($rows as $key => $row) {
                if (str_contains($query, "destination_hash = '" . $row['destination_hash'] . "'")) {
                    return $row;
                }
            }
            return null;
        };

        $repository = new WebhookDeliveryRepository();
        $first = $repository->createMany(self::EVENT_ID, [
            'https://example.com/hook',
            'https://example.com/hook',
            'https://example.com/other',
        ]);
        $replay = $repository->createMany(self::EVENT_ID, [
            'https://example.com/hook',
            'https://example.com/other',
        ]);

        self::assertCount(2, $first);
        self::assertSame(array_column($first, 'id'), array_column($replay, 'id'));
        self::assertSame(hash('sha256', 'https://example.com/hook'), $first[0]['destination_hash']);
        self::assertSame('pending', $first[0]['status']);
        self::assertSame(0, $first[0]['attempt_count']);
    }

    public function test_create_many_stops_when_the_event_is_missing(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static fn(): null => null;

        $this->expectException(\RuntimeException::class);
        (new WebhookDeliveryRepository())->createMany(self::EVENT_ID, ['https://example.com/hook']);
    }

    public function test_create_many_does_not_disguise_a_database_failure_as_duplicate_replay(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static fn(): string => self::EVENT_ID;
        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static fn(): false => false;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(): null => null;

        $this->expectException(\RuntimeException::class);
        (new WebhookDeliveryRepository())->createMany(self::EVENT_ID, ['https://example.com/hook']);
    }

    public function test_acquire_is_single_owner_and_new_attempts_increment_once(): void
    {
        $row = [
            'id' => 9,
            'event_id' => self::EVENT_ID,
            'destination_url' => 'https://example.com/hook',
            'destination_hash' => hash('sha256', 'https://example.com/hook'),
            'status' => 'pending',
            'attempt_count' => 0,
            'lease_owner' => null,
            'lease_expires_at' => null,
        ];
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $query) use (&$row): int {
            if ($row['status'] !== 'pending') {
                return 0;
            }
            preg_match("/lease_owner = '([^']+)'/", $query, $owner);
            preg_match("/lease_expires_at = '([^']+)'/", $query, $lease);
            $row['status'] = 'processing';
            $row['attempt_count']++;
            $row['lease_owner'] = $owner[1] ?? null;
            $row['lease_expires_at'] = $lease[1] ?? null;
            return 1;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static function (string $query) use (&$row): ?array {
            return str_contains($query, 'WHERE id = 9') ? $row : null;
        };

        $repository = new WebhookDeliveryRepository();
        $first = $repository->acquire(9, 'owner-a', '2026-07-23 10:00:00', '2026-07-23 10:05:00');
        $second = $repository->acquire(9, 'owner-b', '2026-07-23 10:00:00', '2026-07-23 10:05:00');

        self::assertSame('owner-a', $first['lease_owner']);
        self::assertSame(1, $first['attempt_count']);
        self::assertNull($second);
    }

    public function test_stale_processing_reclaim_keeps_the_interrupted_attempt(): void
    {
        $captured = '';
        $row = [
            'id' => 9,
            'event_id' => self::EVENT_ID,
            'destination_url' => 'https://example.com/hook',
            'destination_hash' => hash('sha256', 'https://example.com/hook'),
            'status' => 'processing',
            'attempt_count' => 3,
            'lease_owner' => 'old-owner',
            'lease_expires_at' => '2026-07-23 09:59:00',
        ];
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $query) use (&$captured, &$row): int {
            $captured = $query;
            $row['lease_owner'] = 'new-owner';
            $row['lease_expires_at'] = '2026-07-23 10:05:00';
            return 1;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static function () use (&$row): array {
            return $row;
        };

        $claimed = (new WebhookDeliveryRepository())->acquire(
            9,
            'new-owner',
            '2026-07-23 10:00:00',
            '2026-07-23 10:05:00'
        );

        self::assertSame(3, $claimed['attempt_count']);
        self::assertSame('new-owner', $claimed['lease_owner']);
        self::assertStringContainsString("WHEN status = 'processing' THEN attempt_count", $captured);
        self::assertStringContainsString("lease_expires_at <= '2026-07-23 10:00:00'", $captured);
    }

    public function test_terminal_updates_require_the_live_owner_and_attempt_then_clear_the_lease(): void
    {
        $queries = [];
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $query) use (&$queries): int {
            $queries[] = $query;
            return str_contains($query, "lease_owner = 'owner-a'") ? 1 : 0;
        };

        $repository = new WebhookDeliveryRepository(new Clock(
            new \DateTimeImmutable('2026-07-23 10:00:10', new \DateTimeZone('UTC')),
            new \DateTimeZone('UTC')
        ));
        self::assertTrue($repository->markSucceeded(
            9,
            'owner-a',
            2,
            204,
            'ok',
            '2026-07-23 10:00:10'
        ));
        self::assertTrue($repository->markRetrying(
            9,
            'owner-a',
            2,
            503,
            'busy',
            'http_503',
            '2026-07-23 10:05:10'
        ));
        self::assertTrue($repository->markFailed(
            9,
            'owner-a',
            7,
            null,
            '',
            'retry_exhausted',
            '2026-07-23 10:00:10'
        ));
        self::assertFalse($repository->markSucceeded(
            9,
            'old-owner',
            2,
            200,
            'late',
            '2026-07-23 10:00:11'
        ));

        $sql = implode("\n", $queries);
        self::assertStringContainsString("status = 'succeeded'", $sql);
        self::assertStringContainsString("status = 'retrying'", $sql);
        self::assertStringContainsString("status = 'failed'", $sql);
        self::assertStringContainsString('lease_owner = NULL', $sql);
        self::assertStringContainsString('lease_expires_at = NULL', $sql);
        self::assertStringContainsString('attempt_count = 2', $sql);
        self::assertStringContainsString("lease_expires_at > '2026-07-23 10:00:10'", $sql);
    }

    public function test_recent_summary_and_recovery_queries_are_bounded_and_project_typed_rows(): void
    {
        $captured = [];
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query) use (&$captured): array {
            $captured[] = $query;
            return [[
                'id' => '9',
                'attempt_count' => '2',
                'response_code' => '503',
                'status' => 'retrying',
                'event_type' => 'grant_created',
            ]];
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static function (string $query) use (&$captured): array {
            $captured[] = $query;
            return [
                'pending' => '1',
                'processing' => '2',
                'retrying' => '3',
                'succeeded' => '4',
                'failed' => '5',
                'last_success_at' => '2026-07-23 09:00:00',
            ];
        };

        $repository = new WebhookDeliveryRepository();
        $recent = $repository->recent(['status' => 'retrying', 'event_type' => 'grant_created', 'page' => 2, 'per_page' => 25]);
        $summary = $repository->summary();
        $due = $repository->retryableDue('2026-07-23 10:00:00', 500);

        self::assertSame(9, $recent[0]['id']);
        self::assertSame(2, $recent[0]['attempt_count']);
        self::assertSame(503, $recent[0]['response_code']);
        self::assertSame(5, $summary['failed']);
        self::assertSame('2026-07-23 09:00:00', $summary['last_success_at']);
        self::assertSame(9, $due[0]['id']);
        $sql = implode("\n", $captured);
        self::assertStringContainsString("delivery.status = 'retrying'", $sql);
        self::assertStringContainsString("event.event_type = 'grant_created'", $sql);
        self::assertStringContainsString('LIMIT 25 OFFSET 25', $sql);
        self::assertStringContainsString("status IN ('pending', 'retrying')", $sql);
        self::assertStringContainsString("status = 'processing'", $sql);
        self::assertStringContainsString('LIMIT 100', $sql);
    }

    public function test_recent_rejects_an_unknown_status_filter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new WebhookDeliveryRepository())->recent(['status' => 'anything_goes']);
    }

    public function test_manual_retry_and_retention_touch_only_terminal_rows(): void
    {
        $queries = [];
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $query) use (&$queries): int {
            $queries[] = $query;
            return 1;
        };

        $repository = new WebhookDeliveryRepository();
        self::assertTrue($repository->resetForManualRetry(9));
        self::assertSame(1, $repository->purge('2026-06-01 00:00:00', '2026-05-01 00:00:00'));

        $sql = implode("\n", $queries);
        self::assertStringContainsString("WHERE id = 9 AND status = 'failed'", $sql);
        self::assertStringContainsString("status = 'pending'", $sql);
        self::assertStringContainsString("status = 'succeeded'", $sql);
        self::assertStringContainsString("status = 'failed'", $sql);
        self::assertStringNotContainsString("status = 'processing' AND", $sql);
        self::assertStringNotContainsString("status = 'retrying' AND", $sql);
    }

    public function test_cancel_stops_only_pending_or_retrying_deliveries(): void
    {
        $queries = [];
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $query) use (&$queries): int {
            $queries[] = $query;
            return 1;
        };

        $repository = new WebhookDeliveryRepository();

        self::assertTrue($repository->cancel(9));
        self::assertTrue($repository->markCancelled(
            10,
            'owner-a',
            2,
            '2026-07-24 08:00:00'
        ));

        $sql = implode("\n", $queries);
        self::assertStringContainsString("status = 'cancelled'", $sql);
        self::assertStringContainsString("status IN ('pending', 'retrying')", $sql);
        self::assertStringContainsString("status = 'processing'", $sql);
        self::assertStringContainsString("lease_owner = 'owner-a'", $sql);
    }
}

<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Storage;

use FChubMemberships\Storage\WebhookEventRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class WebhookEventRepositoryTest extends PluginTestCase
{
    public function test_create_and_find_preserve_the_exact_event_body(): void
    {
        $inserted = null;
        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static function (
            string $table,
            array $data
        ) use (&$inserted): int {
            self::assertSame('wp_fchub_membership_webhook_events', $table);
            $inserted = $data;
            return 1;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static function (string $query) use (&$inserted): ?array {
            if (!str_contains($query, "event_id = '00000000-0000-4000-8000-000000000123'")) {
                return null;
            }

            return ['id' => '7'] + ($inserted ?? []);
        };

        $body = '{"id":"00000000-0000-4000-8000-000000000123","data":{"name":"Jos\\u00e9"}}';
        $event = [
            'event_id' => '00000000-0000-4000-8000-000000000123',
            'event_type' => 'grant_created',
            'schema_version' => '1.0',
            'body' => $body,
            'occurred_at' => '2026-07-23 10:00:00',
            'created_at' => '2026-07-23 10:00:01',
        ];

        $repository = new WebhookEventRepository();
        self::assertTrue($repository->create($event));
        $found = $repository->findByEventId($event['event_id']);

        self::assertSame(7, $found['id']);
        self::assertSame($body, $found['body']);
        self::assertSame($event['event_id'], $found['event_id']);
    }

    public function test_create_refuses_invalid_identity_and_reports_insert_failure(): void
    {
        $repository = new WebhookEventRepository();

        $this->expectException(\InvalidArgumentException::class);
        $repository->create([
            'event_id' => '',
            'event_type' => 'grant_created',
            'schema_version' => '1.0',
            'body' => '{}',
            'occurred_at' => '2026-07-23 10:00:00',
            'created_at' => '2026-07-23 10:00:00',
        ]);
    }

    public function test_duplicate_event_accepts_only_the_same_immutable_record(): void
    {
        $existing = [
            'id' => '7',
            'event_id' => '00000000-0000-4000-8000-000000000123',
            'event_type' => 'grant_created',
            'schema_version' => '1.0',
            'body' => '{"grant":7}',
            'occurred_at' => '2026-07-23 10:00:00',
            'created_at' => '2026-07-23 10:00:01',
        ];
        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static fn(): false => false;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(): array => $existing;

        $repository = new WebhookEventRepository();
        self::assertTrue($repository->create(array_diff_key($existing, ['id' => true])));

        $this->expectException(\RuntimeException::class);
        $repository->create(array_merge(array_diff_key($existing, ['id' => true]), ['body' => '{"grant":8}']));
    }

    #[DataProvider('priorSuppressionStateProvider')]
    public function test_duplicate_replay_suppresses_expected_insert_errors_and_restores_prior_state(
        bool $priorSuppression,
        array $expectedCalls
    ): void {
        $originalWpdb = $GLOBALS['wpdb'];
        $trackingWpdb = new class extends \wpdb {
            /** @var list<bool> */
            public array $suppressionCalls = [];

            /** @var list<string> */
            public array $duplicateLogs = [];

            public function suppress_errors(bool $suppress = true): bool
            {
                $this->suppressionCalls[] = $suppress;

                return parent::suppress_errors($suppress);
            }

            public function insert(string $table, array $data, ?array $format = null): int|false
            {
                if (!(bool) ($GLOBALS['_fchub_test_wpdb_suppress_errors'] ?? false)) {
                    $this->duplicateLogs[] = "Duplicate SQL for {$table}: " . (string) ($data['body'] ?? '');
                }

                return false;
            }
        };
        $GLOBALS['wpdb'] = $trackingWpdb;
        $GLOBALS['_fchub_test_wpdb_suppress_errors'] = $priorSuppression;
        $existing = [
            'id' => '7',
            'event_id' => '00000000-0000-4000-8000-000000000123',
            'event_type' => 'grant_created',
            'schema_version' => '1.0',
            'body' => '{"sensitive":"duplicate-body-sentinel"}',
            'occurred_at' => '2026-07-23 10:00:00',
            'created_at' => '2026-07-23 10:00:01',
        ];
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(): array => $existing;

        try {
            $replayed = (new WebhookEventRepository())->create(array_diff_key($existing, ['id' => true]));

            self::assertTrue($replayed);
            self::assertSame($expectedCalls, $trackingWpdb->suppressionCalls);
            self::assertSame([], $trackingWpdb->duplicateLogs);
            self::assertSame($priorSuppression, $GLOBALS['_fchub_test_wpdb_suppress_errors']);
        } finally {
            $GLOBALS['wpdb'] = $originalWpdb;
            $GLOBALS['_fchub_test_wpdb_suppress_errors'] = false;
        }
    }

    public static function priorSuppressionStateProvider(): array
    {
        return [
            'errors initially visible' => [false, [true, false]],
            'errors already suppressed' => [true, [true, true]],
        ];
    }

    public function test_insert_failure_without_an_existing_event_is_not_reported_as_replay(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static fn(): false => false;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(): null => null;

        $this->expectException(\RuntimeException::class);
        (new WebhookEventRepository())->create([
            'event_id' => '00000000-0000-4000-8000-000000000123',
            'event_type' => 'grant_created',
            'schema_version' => '1.0',
            'body' => '{}',
            'occurred_at' => '2026-07-23 10:00:00',
            'created_at' => '2026-07-23 10:00:00',
        ]);
    }

    public function test_orphan_cleanup_deletes_only_old_events_without_deliveries(): void
    {
        $captured = '';
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $query) use (&$captured): int {
            $captured = $query;
            return 3;
        };

        $deleted = (new WebhookEventRepository())->deleteOrphansBefore('2026-06-01 00:00:00');

        self::assertSame(3, $deleted);
        self::assertStringContainsString('DELETE event FROM wp_fchub_membership_webhook_events event', $captured);
        self::assertStringContainsString("event.created_at < '2026-06-01 00:00:00'", $captured);
        self::assertStringContainsString('NOT EXISTS', $captured);
        self::assertStringContainsString('wp_fchub_membership_webhook_deliveries delivery', $captured);
    }
}

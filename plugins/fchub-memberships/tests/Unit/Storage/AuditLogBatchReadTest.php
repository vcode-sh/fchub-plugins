<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Storage;

use FChubMemberships\Storage\AuditLogRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class AuditLogBatchReadTest extends PluginTestCase
{
    public function test_it_reads_every_grants_audit_trail_in_one_query(): void
    {
        $query = '';
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $sql) use (&$query): array {
            $query = $sql;

            return [
                ['id' => '1', 'entity_type' => 'grant', 'entity_id' => '3', 'action' => 'created', 'actor_id' => '9'],
                ['id' => '2', 'entity_type' => 'grant', 'entity_id' => '4', 'action' => 'revoked', 'actor_id' => '9'],
            ];
        };

        $entries = (new AuditLogRepository())->getByEntityIds('grant', [3, 4]);

        self::assertSame([3, 4], array_column($entries, 'entity_id'));
        self::assertStringContainsString('entity_id IN (3,4)', $query);
        self::assertStringContainsString("entity_type = 'grant'", $query);
        self::assertSame(1, count(array_filter(
            $GLOBALS['_fchub_test_queries'],
            static fn(array $entry): bool => $entry[0] === 'get_results'
        )));
    }

    public function test_it_asks_for_nothing_when_the_member_has_no_grants(): void
    {
        $entries = (new AuditLogRepository())->getByEntityIds('grant', []);

        self::assertSame([], $entries);
        self::assertSame([], array_filter(
            $GLOBALS['_fchub_test_queries'],
            static fn(array $entry): bool => $entry[0] === 'get_results'
        ));
    }

    public function test_it_never_returns_another_entity_type_stored_under_the_same_id(): void
    {
        $query = '';
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $sql) use (&$query): array {
            $query = $sql;

            return [];
        };

        (new AuditLogRepository())->getByEntityIds('grant', [3]);

        self::assertStringContainsString("entity_type = 'grant'", $query);
        self::assertStringNotContainsString("entity_type = 'plan'", $query);
    }

    public function test_it_hydrates_the_stored_json_columns_rather_than_handing_back_strings(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static fn(): array => [[
            'id' => '1',
            'entity_type' => 'grant',
            'entity_id' => '3',
            'action' => 'extended',
            'actor_id' => '9',
            'old_value' => '{"expires_at":"2026-09-12 00:00:00"}',
            'new_value' => '{"expires_at":"2026-12-31 00:00:00"}',
        ]];

        $entry = (new AuditLogRepository())->getByEntityIds('grant', [3])[0];

        self::assertSame(['expires_at' => '2026-12-31 00:00:00'], $entry['new_value']);
        self::assertSame(3, $entry['entity_id']);
        self::assertSame(9, $entry['actor_id']);
    }

    public function test_it_bounds_the_read_so_one_member_cannot_pull_the_whole_table(): void
    {
        $query = '';
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $sql) use (&$query): array {
            $query = $sql;

            return [];
        };

        (new AuditLogRepository())->getByEntityIds('grant', [3], 25);

        self::assertStringContainsString('LIMIT 25', $query);
    }

    public function test_it_collapses_repeated_grant_ids_into_one_placeholder_each(): void
    {
        $query = '';
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $sql) use (&$query): array {
            $query = $sql;

            return [];
        };

        (new AuditLogRepository())->getByEntityIds('grant', [3, 3, 4, 0]);

        self::assertStringContainsString('entity_id IN (3,4)', $query);
    }
}

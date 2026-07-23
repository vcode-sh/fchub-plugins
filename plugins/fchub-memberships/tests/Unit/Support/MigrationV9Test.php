<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Support;

use FChubMemberships\Support\MigrationV9;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class MigrationV9Test extends PluginTestCase
{
    public function test_v9_adds_and_backfills_the_lineage_access_status_contract(): void
    {
        self::assertTrue(
            class_exists(MigrationV9::class),
            'Migration V9 must add durable per-lineage access status.'
        );

        $failures = MigrationV9::run();
        $queries = implode("\n", array_map(
            static fn(array $entry): string => (string) ($entry[1] ?? ''),
            $GLOBALS['_fchub_test_queries']
        ));

        self::assertSame([], $failures);
        self::assertStringContainsString(
            "ADD COLUMN access_status VARCHAR(20) NOT NULL DEFAULT 'active' AFTER lifecycle",
            $queries
        );
        self::assertStringContainsString(
            "SET edge.access_status = 'paused'",
            $queries
        );
        self::assertStringContainsString("edge.lifecycle = 'active'", $queries);
        self::assertStringContainsString("aggregate.status = 'paused'", $queries);
        self::assertStringContainsString(
            'ADD INDEX plan_access_lifecycle_user (plan_id, access_status, lifecycle, user_id)',
            $queries
        );
        self::assertSame('1.9.0', FCHUB_MEMBERSHIPS_DB_VERSION);
    }

    public function test_v9_replay_does_not_repeat_column_or_index_ddl(): void
    {
        self::assertTrue(class_exists(MigrationV9::class));

        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query): array {
            if (str_contains($query, "SHOW COLUMNS") && str_contains($query, "access_status")) {
                return [['Field' => 'access_status']];
            }
            if (str_contains($query, 'SHOW INDEX') && str_contains($query, 'plan_access_lifecycle_user')) {
                return [['Key_name' => 'plan_access_lifecycle_user']];
            }

            return [];
        };

        self::assertSame([], MigrationV9::run());
        $queries = implode("\n", array_map(
            static fn(array $entry): string => (string) ($entry[1] ?? ''),
            $GLOBALS['_fchub_test_queries']
        ));

        self::assertStringNotContainsString('ADD COLUMN access_status', $queries);
        self::assertStringNotContainsString('ADD INDEX plan_access_lifecycle_user', $queries);
        self::assertStringContainsString("SET edge.access_status = 'paused'", $queries);
    }
}

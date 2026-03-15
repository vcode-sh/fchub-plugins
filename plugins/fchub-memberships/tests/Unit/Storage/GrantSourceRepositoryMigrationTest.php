<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Storage;

use FChubMemberships\Storage\GrantSourceRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class GrantSourceRepositoryMigrationTest extends PluginTestCase
{
    public function test_create_table_runs_dbdelta_only_when_table_missing(): void
    {
        $calls = 0;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static function (string $query) use (&$calls): int|string {
            if (str_contains($query, "SHOW TABLES LIKE 'wp_fchub_membership_grant_sources'")) {
                $calls++;
                return $calls === 1 ? '' : 'wp_fchub_membership_grant_sources';
            }

            return 0;
        };

        GrantSourceRepository::createTable();
        GrantSourceRepository::createTable();

        self::assertCount(1, $GLOBALS['_fchub_test_dbdelta']);
        self::assertStringContainsString('CREATE TABLE wp_fchub_membership_grant_sources', $GLOBALS['_fchub_test_dbdelta'][0]);
    }

    public function test_migrate_from_json_skips_and_inserts_expected_rows(): void
    {
        $inserted = [];

        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static function (string $query): int|string {
            return match (true) {
                str_contains($query, "SHOW TABLES LIKE 'wp_fchub_membership_grant_sources'") => 'wp_fchub_membership_grant_sources',
                str_contains($query, 'SELECT COUNT(*) FROM wp_fchub_membership_grant_sources') => 0,
                default => 0,
            };
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static fn(string $query): array => [[
            'id' => 10,
            'source_type' => 'order',
            'source_id' => 77,
            'source_ids' => '[77,88,0]',
        ]];
        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static function (string $table, array $data) use (&$inserted): int {
            $inserted[] = [$table, $data];
            return 1;
        };

        GrantSourceRepository::migrateFromJson();

        self::assertCount(2, $inserted);
        self::assertSame(77, $inserted[0][1]['source_id']);
        self::assertSame(88, $inserted[1][1]['source_id']);
        self::assertCount(1, $GLOBALS['_fchub_test_fc_logs']);
    }
}

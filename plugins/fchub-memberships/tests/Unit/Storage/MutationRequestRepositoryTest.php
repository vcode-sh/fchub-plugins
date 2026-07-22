<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Storage;

use FChubMemberships\Storage\MutationRequestRepository;
use FChubMemberships\Support\Migrations;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class MutationRequestRepositoryTest extends PluginTestCase
{
    public function test_mutation_request_migration_is_additive_and_replayable(): void
    {
        Migrations::run();
        Migrations::run();

        $schemaCalls = array_values(array_filter($GLOBALS['_fchub_test_dbdelta'], static fn(string $schema): bool => str_contains($schema, 'CREATE TABLE wp_fchub_membership_mutation_requests')));

        self::assertCount(2, $schemaCalls);
        self::assertStringContainsString('UNIQUE KEY request_key (request_key)', $schemaCalls[0]);
        self::assertStringContainsString('response_body LONGTEXT NULL', $schemaCalls[0]);
    }

    public function test_reserves_once_and_persists_a_completed_response(): void
    {
        $rows = [];

        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static function (string $query) use (&$rows): ?array {
            preg_match("/request_key = '([^']+)'/", $query, $matches);
            return $rows[$matches[1] ?? ''] ?? null;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static function (string $table, array $data) use (&$rows): int|false {
            if (isset($rows[$data['request_key']])) {
                return false;
            }

            $rows[$data['request_key']] = $data;
            return 1;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['update'] = static function (string $table, array $data, array $where) use (&$rows): int {
            $key = $where['request_key'];
            $rows[$key] = array_merge($rows[$key], $data);
            return 1;
        };

        $repository = new MutationRequestRepository();

        self::assertTrue($repository->reserve('request-1', str_repeat('a', 64), 9));
        self::assertFalse($repository->reserve('request-1', str_repeat('a', 64), 9));
        self::assertTrue($repository->complete('request-1', 207, ['data' => ['completed' => 1]]));

        self::assertSame([
            'request_key' => 'request-1',
            'fingerprint' => str_repeat('a', 64),
            'user_id' => 9,
            'state' => 'complete',
            'response_status' => 207,
            'response_body' => ['data' => ['completed' => 1]],
        ], array_intersect_key($repository->find('request-1') ?? [], array_flip([
            'request_key', 'fingerprint', 'user_id', 'state', 'response_status', 'response_body',
        ])));
    }

    public function test_persists_failed_state_and_preserves_scalar_and_null_bodies(): void
    {
        $rows = [];

        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static function (string $query) use (&$rows): ?array {
            preg_match("/request_key = '([^']+)'/", $query, $matches);
            return $rows[$matches[1] ?? ''] ?? null;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static function (string $table, array $data) use (&$rows): int {
            $rows[$data['request_key']] = $data;
            return 1;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['update'] = static function (string $table, array $data, array $where) use (&$rows): int {
            $rows[$where['request_key']] = array_merge($rows[$where['request_key']], $data);
            return 1;
        };

        $repository = new MutationRequestRepository();
        $repository->reserve('scalar', str_repeat('b', 64), 9);
        $repository->reserve('null', str_repeat('c', 64), 9);

        self::assertTrue($repository->complete('scalar', 200, 'created'));
        self::assertTrue($repository->fail('null', 500, null));
        self::assertSame('created', $repository->find('scalar')['response_body']);
        self::assertSame('failed', $repository->find('null')['state']);
        self::assertNull($repository->find('null')['response_body']);
    }

    public function test_complete_and_fail_require_exactly_one_affected_row(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['update'] = static fn(): int => 0;
        $repository = new MutationRequestRepository();

        self::assertFalse($repository->complete('vanished', 200, ['ok' => true]));
        self::assertFalse($repository->fail('vanished', 500, ['ok' => false]));
    }
}

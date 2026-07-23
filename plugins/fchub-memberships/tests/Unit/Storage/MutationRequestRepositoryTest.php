<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Storage;

use FChubMemberships\Storage\MutationRequestRepository;
use FChubMemberships\Support\Clock;
use FChubMemberships\Support\Migrations;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class MutationRequestRepositoryTest extends PluginTestCase
{
    public function test_mutation_request_migration_is_additive_and_replayable(): void
    {
        Migrations::run();
        Migrations::run();

        $schemaCalls = array_values(array_filter($GLOBALS['_fchub_test_dbdelta'], static fn(string $schema): bool => str_contains($schema, 'CREATE TABLE wp_fchub_membership_mutation_requests')));

        self::assertCount(4, $schemaCalls);
        self::assertStringContainsString('UNIQUE KEY request_key (request_key)', $schemaCalls[0]);
        self::assertStringContainsString('response_body LONGTEXT NULL', $schemaCalls[0]);
        self::assertStringContainsString('lease_token VARCHAR(64) NULL', $schemaCalls[0]);
        self::assertStringContainsString('KEY state_lease (state, lease_expires_at)', $schemaCalls[0]);
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
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $query) use (&$rows): int {
            preg_match("/request_key = '([^']+)'/", $query, $keyMatch);
            preg_match("/SET state = '([^']+)'/", $query, $stateMatch);
            preg_match('/response_status = ([0-9]+)/', $query, $statusMatch);
            preg_match("/response_body = '([^']*)'/", $query, $bodyMatch);
            $key = $keyMatch[1] ?? '';
            $rows[$key] = array_merge($rows[$key], [
                'state' => $stateMatch[1],
                'response_status' => (int) $statusMatch[1],
                'response_body' => $bodyMatch[1],
            ]);
            return 1;
        };

        $repository = new MutationRequestRepository();

        $token = $repository->reserve('request-1', str_repeat('a', 64), 9);
        self::assertIsString($token);
        self::assertNull($repository->reserve('request-1', str_repeat('a', 64), 9));
        self::assertTrue($repository->complete('request-1', $token, 207, ['data' => ['completed' => 1]]));

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
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $query) use (&$rows): int {
            preg_match("/request_key = '([^']+)'/", $query, $keyMatch);
            preg_match("/SET state = '([^']+)'/", $query, $stateMatch);
            preg_match('/response_status = ([0-9]+)/', $query, $statusMatch);
            preg_match("/response_body = '([^']*)'/", $query, $bodyMatch);
            $key = $keyMatch[1] ?? '';
            $rows[$key] = array_merge($rows[$key], [
                'state' => $stateMatch[1],
                'response_status' => (int) $statusMatch[1],
                'response_body' => $bodyMatch[1],
            ]);
            return 1;
        };

        $repository = new MutationRequestRepository();
        $scalarToken = $repository->reserve('scalar', str_repeat('b', 64), 9);
        $nullToken = $repository->reserve('null', str_repeat('c', 64), 9);

        self::assertIsString($scalarToken);
        self::assertIsString($nullToken);
        self::assertTrue($repository->complete('scalar', $scalarToken, 200, 'created'));
        self::assertTrue($repository->fail('null', $nullToken, 500, null));
        self::assertSame('created', $repository->find('scalar')['response_body']);
        self::assertSame('failed', $repository->find('null')['state']);
        self::assertNull($repository->find('null')['response_body']);
    }

    public function test_complete_and_fail_require_exactly_one_affected_row(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static fn(): int => 0;
        $repository = new MutationRequestRepository();

        self::assertFalse($repository->complete('vanished', 'active-token', 200, ['ok' => true]));
        self::assertFalse($repository->fail('vanished', 'active-token', 500, ['ok' => false]));
    }

    public function test_new_reservation_owns_an_exact_five_minute_utc_lease(): void
    {
        $insert = null;
        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static function (string $table, array $data) use (&$insert): int {
            $insert = $data;
            return 1;
        };
        $clock = new Clock(
            new \DateTimeImmutable('2026-03-13 22:00:00', new \DateTimeZone('UTC')),
            new \DateTimeZone('UTC')
        );

        $token = (new MutationRequestRepository($clock))->reserve('utc-lease', str_repeat('a', 64), 9);

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        self::assertSame('2026-03-13 22:00:00', $insert['created_at']);
        self::assertSame('2026-03-13 22:00:00', $insert['updated_at']);
        self::assertSame('2026-03-13 22:05:00', $insert['lease_expires_at']);
        self::assertSame($token, $insert['lease_token']);
        self::assertSame(1, $insert['attempt_count']);
    }

    public function test_reservation_rejects_an_unauthenticated_user_identity(): void
    {
        $writes = 0;
        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static function () use (&$writes): int {
            $writes++;
            return 1;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function () use (&$writes): int {
            $writes++;
            return 1;
        };

        try {
            (new MutationRequestRepository())->reserve('anonymous', str_repeat('a', 64), 0);
            self::fail('Unauthenticated user identity was accepted.');
        } catch (\InvalidArgumentException) {
            self::assertSame(0, $writes);
        }
    }

    public function test_expired_matching_reservation_is_reclaimed_with_an_exact_compare_and_swap(): void
    {
        $row = [
            'request_key' => 'reclaim',
            'fingerprint' => str_repeat('b', 64),
            'user_id' => 9,
            'state' => 'reserved',
            'lease_token' => str_repeat('1', 64),
            'lease_expires_at' => '2026-03-13 21:59:59',
            'attempt_count' => 1,
        ];
        $query = '';
        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static fn(): false => false;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(): array => $row;
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $sql, \wpdb $wpdb) use (&$query): int {
            $query = $sql;
            $wpdb->rows_affected = 1;
            return 1;
        };
        $clock = new Clock(
            new \DateTimeImmutable('2026-03-13 22:00:00', new \DateTimeZone('UTC')),
            new \DateTimeZone('UTC')
        );

        $token = (new MutationRequestRepository($clock))->reserve('reclaim', str_repeat('b', 64), 9);

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        self::assertStringContainsString("state = 'reserved'", $query);
        self::assertStringContainsString("lease_token = '" . str_repeat('1', 64) . "'", $query);
        self::assertStringContainsString("lease_expires_at = '2026-03-13 21:59:59'", $query);
        self::assertStringContainsString('attempt_count = 1', $query);
        self::assertStringContainsString("fingerprint = '" . str_repeat('b', 64) . "'", $query);
        self::assertStringContainsString('user_id = 9', $query);
        self::assertStringContainsString("lease_expires_at <= '2026-03-13 22:00:00'", $query);
    }

    public function test_live_or_identity_mismatched_reservation_is_never_reclaimed(): void
    {
        $queries = 0;
        $row = [
            'request_key' => 'live',
            'fingerprint' => str_repeat('c', 64),
            'user_id' => 9,
            'state' => 'reserved',
            'lease_token' => str_repeat('2', 64),
            'lease_expires_at' => '2026-03-13 22:00:01',
            'attempt_count' => 1,
        ];
        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static fn(): false => false;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(): array => $row;
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function () use (&$queries): int {
            $queries++;
            return 1;
        };
        $clock = new Clock(
            new \DateTimeImmutable('2026-03-13 22:00:00', new \DateTimeZone('UTC')),
            new \DateTimeZone('UTC')
        );
        $repository = new MutationRequestRepository($clock);

        self::assertNull($repository->reserve('live', str_repeat('c', 64), 9));
        self::assertNull($repository->reserve('live', str_repeat('d', 64), 9));
        self::assertNull($repository->reserve('live', str_repeat('c', 64), 10));
        self::assertSame(0, $queries);
    }

    public function test_legacy_null_lease_is_reclaimed_with_exact_null_compare_and_swap_guards(): void
    {
        $row = [
            'request_key' => 'legacy-null',
            'fingerprint' => str_repeat('e', 64),
            'user_id' => 9,
            'state' => 'reserved',
            'lease_token' => null,
            'lease_expires_at' => null,
            'attempt_count' => 1,
        ];
        $query = '';
        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static fn(): false => false;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(): array => $row;
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $sql, \wpdb $wpdb) use (&$query): int {
            $query = $sql;
            $wpdb->rows_affected = 1;
            return 1;
        };
        $clock = new Clock(
            new \DateTimeImmutable('2026-03-13 22:00:00', new \DateTimeZone('UTC')),
            new \DateTimeZone('UTC')
        );

        $token = (new MutationRequestRepository($clock))->reserve('legacy-null', str_repeat('e', 64), 9);

        self::assertIsString($token);
        self::assertStringContainsString('attempt_count = attempt_count + 1', $query);
        self::assertStringContainsString('attempt_count = 1', $query);
        self::assertStringContainsString('lease_token IS NULL', $query);
        self::assertStringContainsString('lease_expires_at IS NULL', $query);
    }

    public function test_expired_reservation_with_wrong_fingerprint_or_user_issues_no_takeover_update(): void
    {
        $queries = 0;
        $row = [
            'request_key' => 'expired-mismatch',
            'fingerprint' => str_repeat('f', 64),
            'user_id' => 9,
            'state' => 'reserved',
            'lease_token' => str_repeat('3', 64),
            'lease_expires_at' => '2026-03-13 21:59:59',
            'attempt_count' => 1,
        ];
        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static fn(): false => false;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(): array => $row;
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function () use (&$queries): int {
            $queries++;
            return 1;
        };
        $clock = new Clock(
            new \DateTimeImmutable('2026-03-13 22:00:00', new \DateTimeZone('UTC')),
            new \DateTimeZone('UTC')
        );
        $repository = new MutationRequestRepository($clock);

        self::assertNull($repository->reserve('expired-mismatch', str_repeat('a', 64), 9));
        self::assertNull($repository->reserve('expired-mismatch', str_repeat('f', 64), 10));
        self::assertSame(0, $queries);
    }

    public function test_lost_reclaim_compare_and_swap_returns_no_token(): void
    {
        $row = [
            'request_key' => 'lost-race',
            'fingerprint' => str_repeat('a', 64),
            'user_id' => 9,
            'state' => 'reserved',
            'lease_token' => str_repeat('4', 64),
            'lease_expires_at' => '2026-03-13 21:59:59',
            'attempt_count' => 1,
        ];
        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static fn(): false => false;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(): array => $row;
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $query, \wpdb $wpdb): int {
            $wpdb->rows_affected = 0;
            return 0;
        };
        $clock = new Clock(
            new \DateTimeImmutable('2026-03-13 22:00:00', new \DateTimeZone('UTC')),
            new \DateTimeZone('UTC')
        );

        self::assertNull(
            (new MutationRequestRepository($clock))->reserve('lost-race', str_repeat('a', 64), 9)
        );
    }

    public function test_terminal_write_requires_the_live_lease_token_and_clears_lease_metadata(): void
    {
        $queries = [];
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $query, \wpdb $wpdb) use (&$queries): int {
            $queries[] = $query;
            $wpdb->rows_affected = str_contains($query, "lease_token = 'active-token'") ? 1 : 0;
            return $wpdb->rows_affected;
        };
        $clock = new Clock(
            new \DateTimeImmutable('2026-03-13 22:00:00', new \DateTimeZone('UTC')),
            new \DateTimeZone('UTC')
        );
        $repository = new MutationRequestRepository($clock);

        self::assertTrue($repository->complete('request', 'active-token', 200, ['ok' => true]));
        self::assertFalse($repository->fail('request', 'stale-token', 500, ['ok' => false]));
        self::assertStringContainsString('lease_token = NULL', $queries[0]);
        self::assertStringContainsString('lease_expires_at = NULL', $queries[0]);
        self::assertStringContainsString("state = 'reserved'", $queries[0]);
        self::assertStringContainsString("lease_expires_at > '2026-03-13 22:00:00'", $queries[0]);
    }

    public function test_terminal_retention_is_bounded_and_never_selects_reserved_rows(): void
    {
        $query = '';
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $sql) use (&$query): int {
            $query = $sql;
            return 37;
        };
        $clock = new Clock(
            new \DateTimeImmutable('2026-03-31 22:00:00', new \DateTimeZone('UTC')),
            new \DateTimeZone('UTC')
        );

        $deleted = (new MutationRequestRepository($clock))->purgeTerminalOlderThan(30, 100);

        self::assertSame(37, $deleted);
        self::assertStringContainsString("state IN ('complete', 'failed')", $query);
        self::assertStringContainsString("completed_at < '2026-03-01 22:00:00'", $query);
        self::assertStringContainsString('ORDER BY completed_at ASC, id ASC', $query);
        self::assertStringContainsString('LIMIT 100', $query);
        self::assertStringNotContainsString("state = 'reserved'", $query);
    }
}

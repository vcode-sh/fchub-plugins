<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Storage;

use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class GrantRepositoryTest extends PluginTestCase
{
    private function sampleGrantRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 15,
            'user_id' => 21,
            'plan_id' => 5,
            'provider' => 'wordpress_core',
            'resource_type' => 'post',
            'resource_id' => '55',
            'source_type' => 'order',
            'source_id' => 77,
            'feed_id' => 9,
            'grant_key' => 'grant-key',
            'status' => 'active',
            'starts_at' => null,
            'expires_at' => '2026-04-01 00:00:00',
            'drip_available_at' => null,
            'trial_ends_at' => null,
            'cancellation_requested_at' => null,
            'cancellation_effective_at' => null,
            'cancellation_reason' => null,
            'renewal_count' => 2,
            'source_ids' => '[77,88]',
            'meta' => '{"membership_term_ends_at":"2026-03-01 00:00:00","flag":"yes"}',
            'created_at' => '2026-03-01 10:00:00',
            'updated_at' => '2026-03-02 10:00:00',
            'user_email' => 'alice@example.com',
            'display_name' => 'Alice Example',
        ], $overrides);
    }

    public function test_create_update_and_find_hydrate_grant_records(): void
    {
        $inserted = null;
        $updated = null;

        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static function (string $table, array $data, \wpdb $wpdb) use (&$inserted): int {
            $inserted = [$table, $data];
            $wpdb->insert_id = 44;
            return 1;
        };

        $GLOBALS['_fchub_test_wpdb_overrides']['update'] = static function (string $table, array $data, array $where) use (&$updated): int {
            $updated = [$table, $data, $where];
            return 1;
        };

        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = fn(string $query): ?array => str_contains($query, 'WHERE id = 44')
            ? $this->sampleGrantRow(['id' => 44, 'source_ids' => '[1,2]', 'meta' => '{"hello":"world"}'])
            : null;

        $repo = new GrantRepository();
        $id = $repo->create([
            'user_id' => 21,
            'plan_id' => 5,
            'resource_type' => 'post',
            'resource_id' => '55',
            'grant_key' => 'created-key',
            'source_ids' => [1, 2],
            'meta' => ['hello' => 'world'],
        ]);
        $repo->update(44, [
            'status' => 'paused',
            'plan_id' => 6,
            'source_ids' => [3],
            'meta' => ['paused_at' => '2026-03-10 12:00:00'],
        ]);
        $grant = $repo->find(44);

        self::assertSame(44, $id);
        self::assertSame('wp_fchub_membership_grants', $inserted[0]);
        self::assertSame('[1,2]', $inserted[1]['source_ids']);
        self::assertSame('{"hello":"world"}', $inserted[1]['meta']);
        self::assertSame('paused', $updated[1]['status']);
        self::assertSame(6, $updated[1]['plan_id']);
        self::assertSame('{"paused_at":"2026-03-10 12:00:00"}', $updated[1]['meta']);
        self::assertSame(44, $grant['id']);
        self::assertSame([1, 2], $grant['source_ids']);
        self::assertSame(['hello' => 'world'], $grant['meta']);
        self::assertSame('alice@example.com', $grant['user_email']);
    }

    public function test_query_builders_cover_access_count_and_member_filters(): void
    {
        $queries = [];
        $globals = &$queries;

        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static function (string $query) use (&$globals): int {
            $globals[] = $query;

            if (str_contains($query, 'COUNT(*)')) {
                return 1;
            }

            if (str_contains($query, 'COUNT(DISTINCT user_id)')) {
                return 3;
            }

            return 0;
        };

        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = fn(string $query): array => str_contains($query, 'LEFT JOIN wp_users')
            ? [$this->sampleGrantRow()]
            : [$this->sampleGrantRow(['id' => 20, 'resource_type' => 'page', 'resource_id' => '77'])];

        $repo = new GrantRepository();

        self::assertTrue($repo->hasActiveGrant(21, 'wordpress_core', 'post', '55'));
        self::assertTrue($repo->hasAccessibleGrant(21, 'wordpress_core', 'post', '55'));
        self::assertSame(3, $repo->countActiveMembers(5, '2026-03-15 00:00:00'));
        self::assertSame(3, $repo->countNewMembers('2026-03-01 00:00:00', '2026-03-31 23:59:59', 5));
        self::assertSame(3, $repo->countChurnedMembers('2026-03-01 00:00:00', '2026-03-31 23:59:59', 5));

        $members = $repo->getMembers([
            'status' => 'active',
            'plan_id' => 5,
            'search' => 'alice',
            'source_type' => 'order',
            'per_page' => 10,
            'page' => 2,
        ]);
        $count = $repo->countMembers([
            'status' => 'paused',
            'plan_id' => 5,
            'search' => 'alice',
        ]);

        self::assertCount(1, $members);
        self::assertSame('Alice Example', $members[0]['display_name']);
        self::assertSame(1, $count);

        $queryDump = implode("\n", array_merge($queries, array_map(static fn(array $entry): string => $entry[1], array_filter($GLOBALS['_fchub_test_queries'], static fn(array $entry): bool => isset($entry[1]) && is_string($entry[1])))));
        self::assertStringContainsString("status = 'active'", $queryDump);
        self::assertStringContainsString("drip_available_at IS NULL OR drip_available_at <= '2026-03-13 22:00:00'", $queryDump);
        self::assertStringContainsString("AND plan_id = 5", $queryDump);
        self::assertStringContainsString("u.user_email LIKE '%alice%'", $queryDump);
        self::assertStringContainsString("g.source_type = 'order'", $queryDump);
        self::assertStringContainsString('LIMIT 10 OFFSET 10', $queryDump);
        self::assertStringContainsString("g.status = 'paused'", $queryDump);
    }

    public function test_source_term_and_summary_helpers_cover_remaining_repository_branches(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static function (string $query): int|string {
            if (str_contains($query, 'SHOW TABLES LIKE')) {
                return 'wp_fchub_membership_grant_sources';
            }

            if (str_contains($query, 'MAX(') || str_contains($query, 'COUNT(')) {
                return 0;
            }

            return 0;
        };

        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = fn(string $query): array => match (true) {
            str_contains($query, 'INNER JOIN wp_fchub_membership_grant_sources') => [
                $this->sampleGrantRow(['id' => 90, 'source_ids' => '[77]', 'meta' => '{}']),
            ],
            str_contains($query, "source_ids LIKE '%\\\"77\\\"%'") => [
                $this->sampleGrantRow(['id' => 91, 'source_ids' => '[77,88]', 'meta' => '{}']),
                $this->sampleGrantRow(['id' => 92, 'source_ids' => '[12]', 'meta' => '{}']),
            ],
            str_contains($query, "status IN ('active', 'paused')") => [
                $this->sampleGrantRow(['id' => 93]),
                $this->sampleGrantRow(['id' => 94, 'meta' => '{"membership_term_ends_at":"2026-04-01 00:00:00"}']),
            ],
            str_contains($query, 'SELECT DISTINCT resource_type, resource_id') => [
                ['resource_type' => 'post', 'resource_id' => '55'],
                ['resource_type' => 'page', 'resource_id' => '77'],
            ],
            str_contains($query, 'SELECT DISTINCT plan_id') => [
                ['plan_id' => '5'],
                ['plan_id' => '9'],
            ],
            str_contains($query, 'GROUP BY status') => [
                ['status' => 'active', 'count' => '4'],
                ['status' => 'revoked', 'count' => '2'],
            ],
            str_contains($query, 'source_type = \'subscription\'') => [
                ['source_id' => '300'],
                ['source_id' => '301'],
            ],
            default => [],
        };

        $repo = new GrantRepository();

        $junction = $repo->getBySourceId(77);
        $expiredTerms = $repo->getTermExpiredGrants('2026-03-13 22:00:00');
        $resources = $repo->getAllUserResourceIds(21);
        $planIds = $repo->getUserActivePlanIds(21);
        $statusCounts = $repo->countByStatus();
        $subscriptionIds = $repo->getActiveSubscriptionSourceIds();

        self::assertSame(90, $junction[0]['id']);
        self::assertCount(1, $expiredTerms);
        self::assertSame(['55'], $resources['post']);
        self::assertSame([5, 9], $planIds);
        self::assertSame(['active' => 4, 'revoked' => 2], $statusCounts);
        self::assertSame([300, 301], $subscriptionIds);
    }
}

<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Storage;

use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

/**
 * `getByUserId()` is the read behind the member profile, its history, the
 * timeline, bulk export, and the sibling lookup that pauses a whole membership.
 * If it ever narrowed silently, every one of those would quietly lose rows, so
 * the scope it asks for is pinned here rather than assumed.
 */
final class GrantReadScopeTest extends PluginTestCase
{
    public function test_reading_a_member_without_filters_constrains_only_the_member(): void
    {
        $where = $this->whereClauseOf($this->captureQuery(17));

        self::assertStringContainsString('user_id = 17', $where);
        self::assertStringNotContainsString('status', $where);
        self::assertStringNotContainsString('plan_id', $where);
        self::assertStringNotContainsString('provider', $where);
    }

    public function test_it_returns_ended_grants_alongside_current_ones(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static fn(): array => [
            self::row(3, 'active'),
            self::row(4, 'revoked'),
            self::row(5, 'expired'),
        ];

        $grants = (new GrantRepository())->getByUserId(17);

        self::assertSame(['active', 'revoked', 'expired'], array_column($grants, 'status'));
    }

    public function test_each_filter_it_offers_reaches_the_query(): void
    {
        $where = $this->whereClauseOf($this->captureQuery(17, [
            'status' => 'paused',
            'plan_id' => 5,
            'provider' => 'fluent_community',
        ]));

        self::assertStringContainsString("status = 'paused'", $where);
        self::assertStringContainsString('plan_id = 5', $where);
        self::assertStringContainsString("provider = 'fluent_community'", $where);
    }

    public function test_it_orders_newest_first_so_the_profile_reads_in_that_order(): void
    {
        self::assertStringContainsString('ORDER BY created_at DESC', $this->captureQuery(17));
    }

    public function test_two_different_filter_sets_do_not_share_a_cached_result(): void
    {
        $queries = [];
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $sql) use (&$queries): array {
            $queries[] = $sql;

            return [];
        };

        $repository = new GrantRepository();
        $repository->getByUserId(17);
        $repository->getByUserId(17, ['status' => 'paused']);

        self::assertCount(2, $queries);
        self::assertStringNotContainsString('paused', $queries[0]);
        self::assertStringContainsString('paused', $queries[1]);
    }

    public function test_repeating_the_same_read_uses_the_request_cache(): void
    {
        $queries = 0;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function () use (&$queries): array {
            $queries++;

            return [];
        };

        $repository = new GrantRepository();
        $repository->getByUserId(17);
        $repository->getByUserId(17);

        self::assertSame(1, $queries);
    }

    /** @param array<string, mixed> $filters */
    private function captureQuery(int $userId, array $filters = []): string
    {
        GrantRepository::clearRequestCache();
        $query = '';
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $sql) use (&$query): array {
            $query = $sql;

            return [];
        };

        (new GrantRepository())->getByUserId($userId, $filters);

        return $query;
    }

    private function whereClauseOf(string $query): string
    {
        $start = strpos($query, 'WHERE ');
        self::assertNotFalse($start, 'The grant read has no WHERE clause.');
        $end = strpos($query, 'ORDER BY', (int) $start);

        return substr($query, (int) $start, $end === false ? null : $end - (int) $start);
    }

    /** @return array<string, mixed> */
    private static function row(int $id, string $status): array
    {
        return [
            'id' => $id,
            'user_id' => 17,
            'plan_id' => 5,
            'provider' => 'wordpress_core',
            'resource_type' => 'post',
            'resource_id' => '1',
            'source_type' => 'order',
            'source_id' => 4,
            'feed_id' => null,
            'status' => $status,
            'source_ids' => '[]',
            'meta' => '{}',
            'created_at' => '2026-03-01 10:00:00',
            'updated_at' => '2026-03-01 10:00:00',
        ];
    }
}

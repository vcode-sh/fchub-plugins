<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Storage;

use FChubMemberships\Storage\EntitlementEdgeRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class EntitlementEdgeMemberContextTest extends PluginTestCase
{
    public function test_it_reads_all_active_community_edges_for_one_user_in_one_query(): void
    {
        $query = '';
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $sql) use (&$query): array {
            $query = $sql;

            return [
                self::edge(41, 'fc_space', '2', 5),
                self::edge(42, 'fc_course', '5', 8),
            ];
        };

        $edges = (new EntitlementEdgeRepository())->getActiveByUserProvider(17, 'fluent_community');

        self::assertSame([41, 42], array_column($edges, 'id'));
        self::assertSame([5, 8], array_column($edges, 'plan_id'));
        self::assertStringContainsString('user_id = 17', $query);
        self::assertStringContainsString("provider = 'fluent_community'", $query);
        self::assertStringContainsString("lifecycle = 'active'", $query);
        self::assertSame(1, count(array_filter(
            $GLOBALS['_fchub_test_queries'],
            static fn(array $entry): bool => $entry[0] === 'get_results'
        )));
    }

    private static function edge(int $id, string $resourceType, string $resourceId, int $planId): array
    {
        return [
            'id' => $id,
            'user_id' => 17,
            'provider' => 'fluent_community',
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'plan_id' => $planId,
            'feed_id' => 0,
            'feed_scope' => 'global',
            'source_type' => 'manual',
            'source_id' => 0,
            'owner' => 'fchub',
            'assignment_provenance' => 'fchub_created',
            'lifecycle' => 'active',
            'access_status' => 'active',
            'policy' => '{}',
        ];
    }
}

<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Storage;

use FChubMemberships\Storage\AuditLogRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class AuditLogRepositoryTest extends PluginTestCase
{
    public function test_audit_log_repository_covers_queries_and_cleanup(): void
    {
        $queries = [];

        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query) use (&$queries): array {
            $queries[] = $query;
            return [[
                'id' => 10,
                'entity_type' => 'grant',
                'entity_id' => 5,
                'action' => 'updated',
                'old_value' => '{"old":1}',
                'new_value' => '{"new":2}',
                'actor_id' => 3,
                'actor_type' => 'admin',
                'created_at' => '2026-03-01 00:00:00',
            ]];
        };

        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $query) use (&$queries): int {
            $queries[] = $query;
            return 4;
        };

        $repo = new AuditLogRepository();

        $entity = $repo->getByEntity('grant', 5, 20);
        $actor = $repo->getByActor(3, 'admin', 20);
        $recent = $repo->getRecent(20);
        $deleted = $repo->cleanup(30);

        self::assertSame(['old' => 1], $entity[0]['old_value']);
        self::assertSame(['new' => 2], $actor[0]['new_value']);
        self::assertSame(10, $recent[0]['id']);
        self::assertSame(4, $deleted);
        self::assertStringContainsString('entity_type = \'grant\'', implode("\n", $queries));
        self::assertStringContainsString('created_at <', implode("\n", $queries));
    }
}

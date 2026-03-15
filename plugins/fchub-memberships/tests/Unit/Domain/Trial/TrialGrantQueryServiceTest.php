<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Trial;

use FChubMemberships\Domain\Trial\TrialGrantQueryService;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class TrialGrantQueryServiceTest extends PluginTestCase
{
    public function test_trial_grant_query_service_covers_all_public_queries(): void
    {
        $updates = [];
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static fn(string $query): array => match (true) {
            str_contains($query, 'trial_ends_at >') => [['id' => 2, 'user_id' => 21, 'plan_id' => 5, 'trial_ends_at' => '2026-03-15 00:00:00', 'meta' => '{}']],
            str_contains($query, 'trial_ends_at <=') => [['id' => 1, 'user_id' => 21, 'plan_id' => 5, 'trial_ends_at' => '2026-03-13 00:00:00', 'source_id' => 0, 'source_ids' => '[]', 'meta' => '{}']],
            default => [],
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(string $query): ?object => str_contains($query, 'SELECT title, slug')
            ? (object) ['title' => 'Gold Plan', 'slug' => 'gold-plan']
            : null;
        $GLOBALS['_fchub_test_wpdb_overrides']['update'] = static function (string $table, array $data, array $where) use (&$updates): int {
            $updates[] = [$table, $data, $where];
            return 1;
        };

        $service = new TrialGrantQueryService();

        $due = $service->getDueTrialExpirations('2026-03-13 22:00:00');
        $soon = $service->getTrialExpiringSoon('2026-03-13 22:00:00', '2026-03-16 22:00:00');
        $plan = $service->findPlanSummary(5);
        $service->markTrialExpiryNotified(2, ['trial_expiry_notified' => '2026-03-13 22:00:00']);

        self::assertSame(1, $due[0]['id']);
        self::assertSame(2, $soon[0]['id']);
        self::assertSame('Gold Plan', $plan->title);
        self::assertSame('{"trial_expiry_notified":"2026-03-13 22:00:00"}', $updates[0][1]['meta']);
    }
}

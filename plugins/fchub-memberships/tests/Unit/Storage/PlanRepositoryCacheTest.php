<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Storage;

use FChubMemberships\Domain\AccessEvaluator;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class PlanRepositoryCacheTest extends PluginTestCase
{
    public function test_find_many_queries_only_missing_ids_and_returns_stable_id_keys(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = fn(string $query): ?array => str_contains($query, 'WHERE id = 2')
            ? $this->row(2, 'Two')
            : null;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = fn(string $query): array => str_contains($query, 'WHERE id IN (3, 1)')
            ? [$this->row(3, 'Three'), $this->row(1, 'One')]
            : [];

        $first = new PlanRepository();
        self::assertSame(2, $first->find(2)['id']);

        $plans = (new PlanRepository())->findMany([3, 2, 1, 3, 0]);

        self::assertSame([3, 2, 1], array_keys($plans));
        self::assertSame(['Three', 'Two', 'One'], array_column($plans, 'title'));

        $planReads = array_values(array_filter(
            $GLOBALS['_fchub_test_queries'],
            static fn(array $query): bool => in_array($query[0], ['get_row', 'get_results'], true)
                && str_contains((string) $query[1], 'fchub_membership_plans')
        ));
        self::assertCount(2, $planReads);
        self::assertStringContainsString('WHERE id IN (3, 1)', (string) $planReads[1][1]);
    }

    public function test_identity_cache_spans_repository_instances_and_successful_mutation_invalidates_it(): void
    {
        $reads = 0;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = function () use (&$reads): array {
            $reads++;
            return $this->row(5, $reads === 1 ? 'Before' : 'After');
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['update'] = static fn(): int => 1;

        self::assertSame('Before', (new PlanRepository())->find(5)['title']);
        self::assertSame('Before', (new PlanRepository())->find(5)['title']);
        self::assertSame(1, $reads);

        self::assertTrue((new PlanRepository())->update(5, ['title' => 'After']));
        self::assertSame('After', (new PlanRepository())->find(5)['title']);
        self::assertSame(2, $reads);
    }

    public function test_every_plan_write_invalidates_the_active_plan_cache(): void
    {
        $reads = 0;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = function () use (&$reads): array {
            $reads++;
            return [$this->row(5, 'Gold')];
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static function (
            string $table,
            array $data,
            \wpdb $wpdb
        ): int {
            $wpdb->insert_id = 6;
            return 1;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['update'] = static fn(): int => 1;
        $GLOBALS['_fchub_test_wpdb_overrides']['delete'] = static fn(): int => 1;

        $repo = new PlanRepository();
        $repo->getActivePlans();
        $repo->getActivePlans();
        self::assertSame(1, $reads);

        $repo->create(['title' => 'Silver', 'slug' => 'silver']);
        $repo->getActivePlans();
        $repo->update(5, ['title' => 'Updated']);
        $repo->getActivePlans();
        $repo->delete(5);
        $repo->getActivePlans();
        $repo->updateSchedule(5, 'inactive', '2026-07-24 10:00:00');
        $repo->getActivePlans();

        self::assertSame(5, $reads);
    }

    public function test_find_by_slug_does_not_negative_cache_database_errors(): void
    {
        $reads = 0;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = function (
            string $query,
            string $output,
            \wpdb $wpdb
        ) use (&$reads): ?array {
            $reads++;
            if ($reads === 1) {
                $wpdb->last_error = 'read failed';
                return null;
            }
            $wpdb->last_error = '';
            return $this->row(5, 'Gold');
        };
        $repo = new PlanRepository();

        try {
            $repo->findBySlug('gold');
            self::fail('Expected the failed database read to throw.');
        } catch (\RuntimeException) {
            self::assertSame('Gold', $repo->findBySlug('gold')['title']);
        }

        self::assertSame(2, $reads);
    }

    public function test_every_successful_plan_write_invalidates_shared_policy_and_count_caches(): void
    {
        $countReads = 0;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query) use (&$countReads): array {
            if (!str_contains($query, 'GROUP BY access_rows._resource_key')) {
                return [];
            }

            $countReads++;
            return [['_resource_key' => 'resource', 'member_count' => (string) $countReads]];
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static fn(): int => 1;
        $GLOBALS['_fchub_test_wpdb_overrides']['update'] = static fn(): int => 1;
        $GLOBALS['_fchub_test_wpdb_overrides']['delete'] = static fn(): int => 1;

        $resources = [
            'resource' => [
                'provider' => 'wordpress_core',
                'resource_type' => 'post',
                'resource_id' => '55',
            ],
        ];
        $evaluator = new AccessEvaluator();
        $repo = new PlanRepository();

        self::assertSame(1, $evaluator->countDistinctUsersWithResourceAccessBatch($resources)['resource']);
        $generation = (int) wp_cache_get('access_policy_generation', 'fchub_memberships');

        foreach ([
            static fn(): int => $repo->create(['title' => 'Silver', 'slug' => 'silver']),
            static fn(): bool => $repo->update(5, ['includes_plan_ids' => [6]]),
            static fn(): bool => $repo->updateSchedule(5, 'inactive', '2026-07-24 10:00:00'),
            static fn(): bool => $repo->delete(5),
        ] as $index => $write) {
            $write();
            self::assertGreaterThan(
                $generation,
                (int) wp_cache_get('access_policy_generation', 'fchub_memberships')
            );
            $generation = (int) wp_cache_get('access_policy_generation', 'fchub_memberships');
            self::assertSame(
                $index + 2,
                $evaluator->countDistinctUsersWithResourceAccessBatch($resources)['resource']
            );
        }

        self::assertSame(5, $countReads);
    }

    #[DataProvider('adminReadFailureProvider')]
    public function test_admin_reads_throw_instead_of_reporting_database_failures_as_empty(
        string $method,
        string $operation
    ): void {
        $GLOBALS['_fchub_test_wpdb_overrides'][$operation] = static function (
            string $query,
            mixed ...$args
        ) use ($operation): array|int {
            $wpdb = $args[array_key_last($args)];
            $wpdb->last_error = 'read failed';
            return $operation === 'get_results' ? [] : 0;
        };

        $this->expectException(\RuntimeException::class);
        (new PlanRepository())->{$method}();
    }

    /** @return array<string, array{string, string}> */
    public static function adminReadFailureProvider(): array
    {
        return [
            'list' => ['all', 'get_results'],
            'admin list' => ['allForAdmin', 'get_results'],
            'count' => ['count', 'get_var'],
        ];
    }

    /** @return array<string, mixed> */
    private function row(int $id, string $title): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'slug' => strtolower($title),
            'description' => '',
            'status' => 'active',
            'level' => $id,
            'duration_type' => 'lifetime',
            'duration_days' => null,
            'trial_days' => 0,
            'grace_period_days' => 0,
            'includes_plan_ids' => '[]',
            'restriction_message' => null,
            'redirect_url' => null,
            'settings' => '{}',
            'meta' => '{}',
            'scheduled_status' => null,
            'scheduled_at' => null,
            'created_at' => '2026-07-23 10:00:00',
            'updated_at' => '2026-07-23 10:00:00',
        ];
    }
}

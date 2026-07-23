<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Storage;

use FChubMemberships\Domain\AccessEvaluator;
use FChubMemberships\Storage\ProtectionRuleRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ProtectionRuleRepositoryCacheTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AccessEvaluator::clearCache();
    }

    public function test_identity_cache_includes_negative_reads_and_spans_repository_instances(): void
    {
        $reads = 0;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = function (string $query) use (&$reads): ?array {
            $reads++;
            return str_contains($query, "resource_id = '55'") ? $this->row() : null;
        };

        self::assertSame(7, (new ProtectionRuleRepository())->findByResource('post', '55')['id']);
        self::assertSame(7, (new ProtectionRuleRepository())->find(7)['id']);
        self::assertNull((new ProtectionRuleRepository())->findByResource('post', '404'));
        self::assertNull((new ProtectionRuleRepository())->findByResource('post', '404'));

        self::assertSame(2, $reads);
    }

    public function test_successful_mutation_invalidates_all_identity_entries(): void
    {
        $reads = 0;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = function () use (&$reads): array {
            $reads++;
            return $this->row(['restriction_message' => $reads === 1 ? 'Before' : 'After']);
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['update'] = static fn(): int => 1;

        $repo = new ProtectionRuleRepository();
        self::assertSame('Before', $repo->find(7)['restriction_message']);
        self::assertTrue($repo->update(7, ['restriction_message' => 'After']));
        self::assertSame('After', $repo->find(7)['restriction_message']);
        self::assertSame(2, $reads);
    }

    public function test_create_and_delete_also_invalidate_identity_entries(): void
    {
        $reads = 0;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = function () use (&$reads): array {
            $reads++;
            return $this->row();
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static function (
            string $table,
            array $data,
            \wpdb $wpdb
        ): int {
            $wpdb->insert_id = 8;
            return 1;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['delete'] = static fn(): int => 1;

        $repo = new ProtectionRuleRepository();
        $repo->find(7);
        $repo->find(7);
        self::assertSame(1, $reads);

        $repo->create([
            'resource_type' => 'page',
            'resource_id' => '99',
            'plan_ids' => [],
        ]);
        $repo->find(7);
        $repo->delete(7);
        $repo->find(7);

        self::assertSame(3, $reads);
    }

    public function test_create_clears_the_shared_effective_access_count_boundary(): void
    {
        $this->assertMutationClearsEffectiveAccessCount(static function (ProtectionRuleRepository $repo): void {
            $repo->create([
                'resource_type' => 'post',
                'resource_id' => '55',
                'plan_ids' => [],
            ]);
        });
    }

    public function test_update_clears_the_shared_effective_access_count_boundary(): void
    {
        $this->assertMutationClearsEffectiveAccessCount(static function (ProtectionRuleRepository $repo): void {
            $repo->update(7, ['plan_ids' => [5]]);
        });
    }

    public function test_delete_clears_the_shared_effective_access_count_boundary(): void
    {
        $this->assertMutationClearsEffectiveAccessCount(static function (ProtectionRuleRepository $repo): void {
            $repo->delete(7);
        });
    }

    public function test_failed_identity_reads_throw_without_negative_caching(): void
    {
        $reads = 0;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = function (
            string $query,
            string $output,
            \wpdb $wpdb
        ) use (&$reads): ?array {
            $reads++;
            if ($reads <= 2) {
                $wpdb->last_error = 'read failed';
                return null;
            }
            $wpdb->last_error = '';
            return $this->row();
        };
        $repo = new ProtectionRuleRepository();

        foreach ([
            static fn(): ?array => $repo->find(7),
            static fn(): ?array => $repo->findByResource('post', '55'),
        ] as $read) {
            try {
                $read();
                self::fail('Expected the failed database read to throw.');
            } catch (\RuntimeException) {
                self::assertTrue(true);
            }
        }

        self::assertSame(7, $repo->find(7)['id']);
        self::assertSame(7, $repo->findByResource('post', '55')['id']);
        self::assertSame(3, $reads);
    }

    public function test_has_any_rules_is_cached_fail_closed_and_invalidated_by_every_write(): void
    {
        $reads = 0;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static function (
            string $query,
            \wpdb $wpdb
        ) use (&$reads): int {
            if (!str_contains($query, 'SELECT EXISTS(SELECT 1 FROM wp_fchub_membership_protection_rules')) {
                return 0;
            }

            $reads++;
            if ($reads === 1) {
                $wpdb->last_error = 'read failed';
                return 0;
            }
            $wpdb->last_error = '';
            return $reads % 2;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static fn(): int => 1;
        $GLOBALS['_fchub_test_wpdb_overrides']['update'] = static fn(): int => 1;
        $GLOBALS['_fchub_test_wpdb_overrides']['delete'] = static fn(): int => 1;
        $repo = new ProtectionRuleRepository();

        try {
            $repo->hasAnyRules();
            self::fail('Expected the failed database read to throw.');
        } catch (\RuntimeException) {
            self::assertTrue(true);
        }

        self::assertFalse($repo->hasAnyRules());
        self::assertFalse($repo->hasAnyRules());
        self::assertSame(2, $reads);

        $repo->create(['resource_type' => 'post', 'resource_id' => '55']);
        self::assertTrue($repo->hasAnyRules());
        $repo->update(7, ['plan_ids' => [5]]);
        self::assertFalse($repo->hasAnyRules());
        $repo->delete(7);
        self::assertTrue($repo->hasAnyRules());

        self::assertSame(5, $reads);
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
        (new ProtectionRuleRepository())->{$method}();
    }

    /** @return array<string, array{string, string}> */
    public static function adminReadFailureProvider(): array
    {
        return [
            'list' => ['all', 'get_results'],
            'count' => ['count', 'get_var'],
            'summary' => ['summary', 'get_results'],
        ];
    }

    /** @return array<string, mixed> */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'id' => 7,
            'resource_type' => 'post',
            'resource_id' => '55',
            'plan_ids' => '[5]',
            'protection_mode' => 'explicit',
            'restriction_message' => null,
            'redirect_url' => null,
            'show_teaser' => 'no',
            'meta' => '{}',
            'created_at' => '2026-07-23 10:00:00',
            'updated_at' => '2026-07-23 10:00:00',
        ], $overrides);
    }

    private function assertMutationClearsEffectiveAccessCount(callable $mutate): void
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

        self::assertSame(1, $evaluator->countDistinctUsersWithResourceAccessBatch($resources)['resource']);
        $mutate(new ProtectionRuleRepository());
        self::assertSame(2, $evaluator->countDistinctUsersWithResourceAccessBatch($resources)['resource']);
        self::assertSame(2, $countReads);
    }
}

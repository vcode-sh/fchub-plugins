<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Storage;

use FChubMemberships\Domain\Access\ResourceAccessPolicy;
use FChubMemberships\Domain\Access\ResourceAccessPolicyResolver;
use FChubMemberships\Domain\AccessEvaluator;
use FChubMemberships\Domain\Plan\PlanRuleResolver;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Storage\PlanRuleRepository;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\ProtectionRuleRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class PlanRuleRepositoryCacheTest extends PluginTestCase
{
    public function test_scalar_and_batch_use_the_same_most_permissive_exact_wildcard_drip_rule(): void
    {
        $rules = [
            [
                'id' => 1,
                'plan_id' => 5,
                'provider' => 'wordpress_core',
                'resource_type' => 'post',
                'resource_id' => '42',
                'drip_type' => 'delayed',
                'drip_delay_days' => 30,
                'drip_date' => null,
            ],
            [
                'id' => 2,
                'plan_id' => 5,
                'provider' => 'wordpress_core',
                'resource_type' => 'post',
                'resource_id' => '*',
                'drip_type' => 'immediate',
                'drip_delay_days' => 0,
                'drip_date' => null,
            ],
        ];
        $plans = new class extends PlanRepository {
            public function find(int $id): ?array
            {
                return ['id' => $id, 'includes_plan_ids' => []];
            }

            public function getActivePlans(): array
            {
                return [['id' => 5, 'includes_plan_ids' => []]];
            }
        };
        $ruleRepo = new class($rules) extends PlanRuleRepository {
            public function __construct(private array $rules)
            {
            }

            public function getByPlanIds(array $planIds): array
            {
                return $this->rules;
            }

            public function getAllForAccessResolution(): array
            {
                return $this->rules;
            }
        };
        $resolver = new PlanRuleResolver($plans, $ruleRepo);

        $scalar = $resolver->getDripRule(5, 'wordpress_core', 'post', '42');
        $batch = $resolver->findPathsForResourcesBatch([
            'post-42' => [
                'provider' => 'wordpress_core',
                'resource_type' => 'post',
                'resource_id' => '42',
            ],
        ]);

        self::assertSame('immediate', $scalar['drip_type']);
        self::assertSame($scalar['id'], $batch['post-42'][0]['rule']['id']);
    }
    public function test_resolved_rules_reuse_cross_instance_cache_and_rule_write_invalidates_it(): void
    {
        $ruleReads = 0;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = fn(string $query): ?array => str_contains($query, 'fchub_membership_plans')
            ? $this->planRow()
            : null;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = function (string $query) use (&$ruleReads): array {
            if (!str_contains($query, 'fchub_membership_plan_rules')) {
                return [];
            }
            $ruleReads++;
            return [$this->ruleRow($ruleReads === 1 ? 7 : 3)];
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['update'] = static fn(): int => 1;

        self::assertSame(7, (new PlanRuleResolver())->resolveRules(5)[0]['drip_delay_days']);
        self::assertSame(7, (new PlanRuleResolver())->resolveRules(5)[0]['drip_delay_days']);
        self::assertSame(1, $ruleReads);

        self::assertTrue((new PlanRuleRepository())->update(9, ['drip_delay_days' => 3]));
        self::assertSame(3, (new PlanRuleResolver())->resolveRules(5)[0]['drip_delay_days']);
        self::assertSame(2, $ruleReads);
    }

    public function test_rule_write_clears_the_shared_effective_access_count_boundary(): void
    {
        $countReads = 0;
        $grants = new class($countReads) extends GrantRepository {
            public function __construct(private int &$countReads)
            {
            }

            public function countDistinctUsersWithResourceAccessBatch(array $policies): array
            {
                $this->countReads++;
                return ['resource' => $this->countReads];
            }
        };
        $policyResolver = new class extends ResourceAccessPolicyResolver {
            public function resolveBatch(array $resources): array
            {
                return ['resource' => new ResourceAccessPolicy('wordpress_core', 'post', '42')];
            }
        };
        $evaluator = new AccessEvaluator(
            $grants,
            new PlanRuleResolver(),
            new ProtectionRuleRepository(),
            null,
            $policyResolver
        );
        $resources = [
            'resource' => [
                'provider' => 'wordpress_core',
                'resource_type' => 'post',
                'resource_id' => '42',
            ],
        ];
        $GLOBALS['_fchub_test_wpdb_overrides']['update'] = static fn(): int => 1;

        self::assertSame(1, $evaluator->countDistinctUsersWithResourceAccessBatch($resources)['resource']);
        self::assertTrue((new PlanRuleRepository())->update(9, ['drip_delay_days' => 3]));
        self::assertSame(2, $evaluator->countDistinctUsersWithResourceAccessBatch($resources)['resource']);
        self::assertSame(2, $countReads);
    }

    public function test_any_rule_probe_throws_on_database_failure_instead_of_caching_false(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static function (string $query, \wpdb $wpdb): int {
            $wpdb->last_error = 'plan rule probe failed';
            return 0;
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to determine whether membership plan rules exist.');

        (new PlanRuleRepository())->hasAnyRules();
    }

    public function test_batch_paths_keep_direct_rule_plans_when_plan_is_no_longer_active(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = function (string $query): array {
            if (str_contains($query, 'fchub_membership_plan_rules')) {
                return [$this->ruleRow(0)];
            }
            return [];
        };

        $paths = (new PlanRuleResolver())->findPathsForResourcesBatch([
            'post' => [
                'provider' => 'wordpress_core',
                'resource_type' => 'post',
                'resource_id' => '42',
            ],
        ]);

        self::assertSame(5, $paths['post'][0]['plan_id']);
        self::assertSame('42', $paths['post'][0]['rule']['resource_id']);
    }

    /** @return array<string, mixed> */
    private function planRow(): array
    {
        return [
            'id' => 5,
            'title' => 'Gold',
            'slug' => 'gold',
            'description' => '',
            'status' => 'active',
            'level' => 1,
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

    /** @return array<string, mixed> */
    private function ruleRow(int $delay): array
    {
        return [
            'id' => 9,
            'plan_id' => 5,
            'provider' => 'wordpress_core',
            'resource_type' => 'post',
            'resource_id' => '42',
            'drip_delay_days' => $delay,
            'drip_type' => 'delayed',
            'drip_date' => null,
            'sort_order' => 0,
            'meta' => '{}',
            'created_at' => '2026-07-23 10:00:00',
            'updated_at' => '2026-07-23 10:00:00',
        ];
    }
}

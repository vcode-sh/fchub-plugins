<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Plan;

use FChubMemberships\Domain\Plan\PlanRuleResolver;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Storage\PlanRuleRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

/**
 * Bug hunt tests for PlanRuleResolver.
 *
 * Bug L: isMorePermissive() — delayed vs fixed_date comparison is arbitrary (documented).
 * Bug M: collectPlanIds() max depth of 5 is hardcoded with no warning.
 */
final class PlanRuleResolverBugHuntTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_fchub_test_cache'] = [];
    }

    // --- Bug L: isMorePermissive tests ---

    /**
     * Bug L: immediate always beats delayed.
     */
    public function test_immediate_is_more_permissive_than_delayed(): void
    {
        $resolver = $this->createResolver(
            plans: [1 => ['id' => 1, 'includes_plan_ids' => []]],
            rules: [
                ['plan_id' => 1, 'provider' => 'wp', 'resource_type' => 'post', 'resource_id' => '10', 'drip_type' => 'immediate', 'drip_delay_days' => 0, 'drip_date' => null],
                ['plan_id' => 1, 'provider' => 'wp', 'resource_type' => 'post', 'resource_id' => '10', 'drip_type' => 'delayed', 'drip_delay_days' => 1, 'drip_date' => null],
            ]
        );

        $unique = $resolver->resolveUniqueRules(1);

        self::assertCount(1, $unique);
        self::assertSame('immediate', $unique[0]['drip_type']);
    }

    /**
     * Bug L: immediate always beats fixed_date.
     */
    public function test_immediate_is_more_permissive_than_fixed_date(): void
    {
        $resolver = $this->createResolver(
            plans: [1 => ['id' => 1, 'includes_plan_ids' => []]],
            rules: [
                ['plan_id' => 1, 'provider' => 'wp', 'resource_type' => 'post', 'resource_id' => '10', 'drip_type' => 'fixed_date', 'drip_delay_days' => 0, 'drip_date' => '2020-01-01'],
                ['plan_id' => 1, 'provider' => 'wp', 'resource_type' => 'post', 'resource_id' => '10', 'drip_type' => 'immediate', 'drip_delay_days' => 0, 'drip_date' => null],
            ]
        );

        $unique = $resolver->resolveUniqueRules(1);

        self::assertCount(1, $unique);
        self::assertSame('immediate', $unique[0]['drip_type']);
    }

    /**
     * Bug L: shorter delay beats longer delay (same type).
     */
    public function test_shorter_delay_is_more_permissive(): void
    {
        $resolver = $this->createResolver(
            plans: [1 => ['id' => 1, 'includes_plan_ids' => []]],
            rules: [
                ['plan_id' => 1, 'provider' => 'wp', 'resource_type' => 'post', 'resource_id' => '10', 'drip_type' => 'delayed', 'drip_delay_days' => 30, 'drip_date' => null],
                ['plan_id' => 1, 'provider' => 'wp', 'resource_type' => 'post', 'resource_id' => '10', 'drip_type' => 'delayed', 'drip_delay_days' => 7, 'drip_date' => null],
            ]
        );

        $unique = $resolver->resolveUniqueRules(1);

        self::assertCount(1, $unique);
        self::assertSame(7, $unique[0]['drip_delay_days']);
    }

    /**
     * Bug L: earlier fixed_date beats later fixed_date (same type).
     */
    public function test_earlier_fixed_date_is_more_permissive(): void
    {
        $resolver = $this->createResolver(
            plans: [1 => ['id' => 1, 'includes_plan_ids' => []]],
            rules: [
                ['plan_id' => 1, 'provider' => 'wp', 'resource_type' => 'post', 'resource_id' => '10', 'drip_type' => 'fixed_date', 'drip_delay_days' => 0, 'drip_date' => '2026-12-01'],
                ['plan_id' => 1, 'provider' => 'wp', 'resource_type' => 'post', 'resource_id' => '10', 'drip_type' => 'fixed_date', 'drip_delay_days' => 0, 'drip_date' => '2026-06-01'],
            ]
        );

        $unique = $resolver->resolveUniqueRules(1);

        self::assertCount(1, $unique);
        self::assertSame('2026-06-01', $unique[0]['drip_date']);
    }

    /**
     * Bug L: delayed vs fixed_date — delayed wins (documented intentional simplification).
     * This test documents the current behaviour, not an ideal outcome.
     */
    public function test_delayed_beats_fixed_date_by_design(): void
    {
        $resolver = $this->createResolver(
            plans: [1 => ['id' => 1, 'includes_plan_ids' => []]],
            rules: [
                ['plan_id' => 1, 'provider' => 'wp', 'resource_type' => 'post', 'resource_id' => '10', 'drip_type' => 'fixed_date', 'drip_delay_days' => 0, 'drip_date' => '2026-01-01'],
                ['plan_id' => 1, 'provider' => 'wp', 'resource_type' => 'post', 'resource_id' => '10', 'drip_type' => 'delayed', 'drip_delay_days' => 365, 'drip_date' => null],
            ]
        );

        $unique = $resolver->resolveUniqueRules(1);

        self::assertCount(1, $unique);
        // Intentional: delayed always wins over fixed_date regardless of actual dates
        self::assertSame('delayed', $unique[0]['drip_type']);
    }

    /**
     * Bug L: fixed_date vs delayed — fixed_date loses (reverse insertion order).
     */
    public function test_fixed_date_does_not_beat_delayed(): void
    {
        $resolver = $this->createResolver(
            plans: [1 => ['id' => 1, 'includes_plan_ids' => []]],
            rules: [
                ['plan_id' => 1, 'provider' => 'wp', 'resource_type' => 'post', 'resource_id' => '10', 'drip_type' => 'delayed', 'drip_delay_days' => 365, 'drip_date' => null],
                ['plan_id' => 1, 'provider' => 'wp', 'resource_type' => 'post', 'resource_id' => '10', 'drip_type' => 'fixed_date', 'drip_delay_days' => 0, 'drip_date' => '2026-01-01'],
            ]
        );

        $unique = $resolver->resolveUniqueRules(1);

        self::assertCount(1, $unique);
        // delayed was already first, fixed_date cannot beat it
        self::assertSame('delayed', $unique[0]['drip_type']);
    }

    // --- Bug M: collectPlanIds max depth tests ---

    /**
     * Bug M: plans at depth 5 are included.
     */
    public function test_plan_at_depth_5_is_included(): void
    {
        // Chain: 1 -> 2 -> 3 -> 4 -> 5 -> 6 (depth 0-5)
        $plans = [
            1 => ['id' => 1, 'includes_plan_ids' => [2]],
            2 => ['id' => 2, 'includes_plan_ids' => [3]],
            3 => ['id' => 3, 'includes_plan_ids' => [4]],
            4 => ['id' => 4, 'includes_plan_ids' => [5]],
            5 => ['id' => 5, 'includes_plan_ids' => [6]],
            6 => ['id' => 6, 'includes_plan_ids' => []],
        ];

        $resolver = $this->createResolver(plans: $plans, rules: []);
        $ids = $resolver->resolvePlanIds(1);

        // Depth: 1(0), 2(1), 3(2), 4(3), 5(4), 6(5) — all within MAX_HIERARCHY_DEPTH
        self::assertSame([1, 2, 3, 4, 5, 6], $ids);
    }

    /**
     * Bug M: plans at depth 6 are silently excluded.
     */
    public function test_plan_at_depth_6_is_excluded(): void
    {
        // Chain: 1 -> 2 -> 3 -> 4 -> 5 -> 6 -> 7 (depth 0-6)
        $plans = [
            1 => ['id' => 1, 'includes_plan_ids' => [2]],
            2 => ['id' => 2, 'includes_plan_ids' => [3]],
            3 => ['id' => 3, 'includes_plan_ids' => [4]],
            4 => ['id' => 4, 'includes_plan_ids' => [5]],
            5 => ['id' => 5, 'includes_plan_ids' => [6]],
            6 => ['id' => 6, 'includes_plan_ids' => [7]],
            7 => ['id' => 7, 'includes_plan_ids' => []],
        ];

        $resolver = $this->createResolver(plans: $plans, rules: []);
        $ids = $resolver->resolvePlanIds(1);

        // Plan 7 at depth 6 exceeds MAX_HIERARCHY_DEPTH (5)
        self::assertSame([1, 2, 3, 4, 5, 6], $ids);
        self::assertNotContains(7, $ids);
    }

    /**
     * Bug M: constant is defined and accessible.
     */
    public function test_max_hierarchy_depth_constant_exists(): void
    {
        self::assertSame(5, PlanRuleResolver::MAX_HIERARCHY_DEPTH);
    }

    /**
     * Bug M: circular references don't cause infinite recursion.
     */
    public function test_circular_hierarchy_does_not_infinite_loop(): void
    {
        $plans = [
            1 => ['id' => 1, 'includes_plan_ids' => [2]],
            2 => ['id' => 2, 'includes_plan_ids' => [3]],
            3 => ['id' => 3, 'includes_plan_ids' => [1]], // circular back to 1
        ];

        $resolver = $this->createResolver(plans: $plans, rules: []);
        $ids = $resolver->resolvePlanIds(1);

        self::assertSame([1, 2, 3], $ids);
    }

    /**
     * Bug M: single plan with no includes returns just itself.
     */
    public function test_single_plan_no_includes(): void
    {
        $plans = [
            1 => ['id' => 1, 'includes_plan_ids' => []],
        ];

        $resolver = $this->createResolver(plans: $plans, rules: []);
        $ids = $resolver->resolvePlanIds(1);

        self::assertSame([1], $ids);
    }

    /**
     * Constructor accepts injectable dependencies.
     */
    public function test_constructor_accepts_injectable_repos(): void
    {
        $planRepo = new class() extends PlanRepository {
            public function __construct() {}
        };

        $ruleRepo = new class() extends PlanRuleRepository {
            public function __construct() {}
        };

        $resolver = new PlanRuleResolver($planRepo, $ruleRepo);

        // No error = success
        self::assertInstanceOf(PlanRuleResolver::class, $resolver);
    }

    /**
     * Unique rules deduplication works across inherited plans.
     */
    public function test_unique_rules_dedup_across_hierarchy(): void
    {
        $plans = [
            1 => ['id' => 1, 'includes_plan_ids' => [2]],
            2 => ['id' => 2, 'includes_plan_ids' => []],
        ];

        $rules = [
            // Same resource in both plans, plan 1 has delayed, plan 2 has immediate
            ['plan_id' => 1, 'provider' => 'wp', 'resource_type' => 'post', 'resource_id' => '10', 'drip_type' => 'delayed', 'drip_delay_days' => 7, 'drip_date' => null],
            ['plan_id' => 2, 'provider' => 'wp', 'resource_type' => 'post', 'resource_id' => '10', 'drip_type' => 'immediate', 'drip_delay_days' => 0, 'drip_date' => null],
        ];

        $resolver = $this->createResolver(plans: $plans, rules: $rules);
        $unique = $resolver->resolveUniqueRules(1);

        self::assertCount(1, $unique);
        self::assertSame('immediate', $unique[0]['drip_type'], 'immediate from included plan should win');
    }

    /**
     * Different resources are not deduplicated.
     */
    public function test_different_resources_not_deduped(): void
    {
        $plans = [
            1 => ['id' => 1, 'includes_plan_ids' => []],
        ];

        $rules = [
            ['plan_id' => 1, 'provider' => 'wp', 'resource_type' => 'post', 'resource_id' => '10', 'drip_type' => 'immediate', 'drip_delay_days' => 0, 'drip_date' => null],
            ['plan_id' => 1, 'provider' => 'wp', 'resource_type' => 'post', 'resource_id' => '20', 'drip_type' => 'delayed', 'drip_delay_days' => 7, 'drip_date' => null],
        ];

        $resolver = $this->createResolver(plans: $plans, rules: $rules);
        $unique = $resolver->resolveUniqueRules(1);

        self::assertCount(2, $unique);
    }

    // --- helpers ---

    private function createResolver(array $plans, array $rules): PlanRuleResolver
    {
        $planRepo = new class($plans) extends PlanRepository {
            private array $plans;

            public function __construct(array $plans)
            {
                $this->plans = $plans;
            }

            public function find(int $id): ?array
            {
                return $this->plans[$id] ?? null;
            }

            public function getActivePlans(): array
            {
                return array_values($this->plans);
            }
        };

        $ruleRepo = new class($rules) extends PlanRuleRepository {
            private array $rules;

            public function __construct(array $rules)
            {
                $this->rules = $rules;
            }

            public function getByPlanIds(array $planIds): array
            {
                return array_values(array_filter(
                    $this->rules,
                    static fn(array $rule): bool => in_array($rule['plan_id'], $planIds, true)
                ));
            }

            public function findPlansWithResource(string $provider, string $resourceType, string $resourceId): array
            {
                $ids = [];
                foreach ($this->rules as $rule) {
                    if ($rule['provider'] === $provider && $rule['resource_type'] === $resourceType
                        && ($rule['resource_id'] === $resourceId || $rule['resource_id'] === '*')) {
                        $ids[] = $rule['plan_id'];
                    }
                }
                return array_unique($ids);
            }
        };

        return new PlanRuleResolver($planRepo, $ruleRepo);
    }
}

<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Plan;

use FChubMemberships\Domain\Access\ResourceAccessPolicy;
use FChubMemberships\Domain\Access\ResourceAccessPolicyResolver;
use FChubMemberships\Domain\AccessEvaluator;
use FChubMemberships\Domain\Plan\PlanRuleResolver;
use FChubMemberships\Domain\Plan\PlanService;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Storage\PlanRuleRepository;
use FChubMemberships\Storage\ProtectionRuleRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class PlanServiceCacheInvalidationTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AccessEvaluator::clearCache();
    }

    public function test_plan_update_clears_shared_effective_access_counts(): void
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
        $plan = [
            'id' => 5,
            'title' => 'Gold',
            'slug' => 'gold',
            'meta' => [],
            'includes_plan_ids' => [],
        ];
        $planRepo = new class($plan) extends PlanRepository {
            public function __construct(private array $plan)
            {
            }

            public function find(int $id): ?array
            {
                return $this->plan;
            }

            public function update(int $id, array $data): bool
            {
                $this->plan = array_merge($this->plan, $data);
                return true;
            }

            public function slugExists(string $slug, ?int $excludeId = null): bool
            {
                return false;
            }

            public function getMemberCount(int $planId): int
            {
                return 0;
            }
        };
        $ruleRepo = new class extends PlanRuleRepository {
            public function getByPlanId(int $planId): array
            {
                return [];
            }
        };
        $service = new PlanService();
        foreach (['planRepo' => $planRepo, 'ruleRepo' => $ruleRepo] as $property => $value) {
            $reflection = new \ReflectionProperty(PlanService::class, $property);
            $reflection->setValue($service, $value);
        }

        self::assertSame(1, $evaluator->countDistinctUsersWithResourceAccessBatch($resources)['resource']);
        self::assertSame('Updated', $service->update(5, ['title' => 'Updated'])['title']);
        self::assertSame(2, $evaluator->countDistinctUsersWithResourceAccessBatch($resources)['resource']);
        self::assertSame(2, $countReads);
    }

    public function test_plan_service_invalidation_clears_protection_identity_cache_in_same_request(): void
    {
        $reads = 0;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static function (string $query) use (&$reads): array {
            $reads++;
            return [
                'id' => 7,
                'resource_type' => 'post',
                'resource_id' => '42',
                'plan_ids' => $reads === 1 ? '[5,6]' : '[6]',
                'protection_mode' => 'explicit',
                'restriction_message' => null,
                'redirect_url' => null,
                'show_teaser' => 'no',
                'meta' => '{}',
                'created_at' => '2026-07-23 10:00:00',
                'updated_at' => '2026-07-23 10:00:00',
            ];
        };
        $repo = new ProtectionRuleRepository();
        self::assertSame([5, 6], $repo->find(7)['plan_ids']);
        self::assertSame([5, 6], $repo->find(7)['plan_ids']);
        self::assertSame(1, $reads);

        $method = new \ReflectionMethod(PlanService::class, 'invalidateHierarchyCache');
        $method->invoke(new PlanService());

        self::assertSame([6], (new ProtectionRuleRepository())->find(7)['plan_ids']);
        self::assertSame(2, $reads);
    }
}

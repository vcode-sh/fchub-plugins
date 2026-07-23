<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain;

use FChubMemberships\Domain\Access\ResourceAccessPolicy;
use FChubMemberships\Domain\Access\ResourceAccessPolicyResolver;
use FChubMemberships\Domain\AccessEvaluator;
use FChubMemberships\Domain\Plan\PlanRuleResolver;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Storage\ProtectionRuleRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class AccessEvaluatorBatchAccessTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AccessEvaluator::clearCache();
    }

    public function test_batch_count_preserves_caller_keys_and_resolves_each_resource_once(): void
    {
        $resolved = [];
        $policyResolver = new class($resolved) extends ResourceAccessPolicyResolver {
            public function __construct(private array &$resolved)
            {
            }

            public function resolve(string $provider, string $resourceType, string $resourceId): ResourceAccessPolicy
            {
                $this->resolved[] = [$provider, $resourceType, $resourceId];
                $policy = new ResourceAccessPolicy($provider, $resourceType, $resourceId);
                $policy->addPlanPath((int) $resourceId, null);
                return $policy;
            }

            public function resolveBatch(array $resources): array
            {
                $policies = [];
                foreach ($resources as $key => $resource) {
                    $policies[$key] = $this->resolve(
                        (string) $resource['provider'],
                        (string) $resource['resource_type'],
                        (string) $resource['resource_id']
                    );
                }
                return $policies;
            }
        };
        $received = [];
        $grants = new class($received) extends GrantRepository {
            public function __construct(private array &$received)
            {
            }

            public function countDistinctUsersWithResourceAccessBatch(array $policies): array
            {
                $this->received = $policies;
                return ['rule-8' => 3, 'rule-9' => 0];
            }
        };

        $evaluator = new AccessEvaluator(
            $grants,
            new PlanRuleResolver(),
            new ProtectionRuleRepository(),
            null,
            $policyResolver
        );

        $counts = $evaluator->countDistinctUsersWithResourceAccessBatch([
            'rule-8' => ['provider' => 'wordpress_core', 'resource_type' => 'post', 'resource_id' => '8'],
            'rule-9' => ['provider' => 'wordpress_core', 'resource_type' => 'post', 'resource_id' => '9'],
        ]);

        self::assertSame(['rule-8' => 3, 'rule-9' => 0], $counts);
        self::assertCount(2, $resolved);
        self::assertInstanceOf(ResourceAccessPolicy::class, $received['rule-8']);
        self::assertSame('8', $received['rule-8']->resourceId());
    }

    public function test_scalar_access_uses_the_same_policy_and_ignores_paused_lineages(): void
    {
        $policy = new ResourceAccessPolicy('wordpress_core', 'post', '42');
        $policy->addPlanPath(5, null);
        $policyResolver = new class($policy) extends ResourceAccessPolicyResolver {
            public function __construct(private ResourceAccessPolicy $policy)
            {
            }

            public function resolve(string $provider, string $resourceType, string $resourceId): ResourceAccessPolicy
            {
                return $this->policy;
            }
        };
        $grants = new class extends GrantRepository {
            public function getActiveGrant(int $userId, string $provider, string $resourceType, string $resourceId): ?array
            {
                return null;
            }

            public function getEffectivePlanMembershipsForUser(int $userId): array
            {
                return [[
                    'plan_id' => 5,
                    'created_at' => '2026-01-01 00:00:00',
                    'trial_ends_at' => null,
                    'access_status' => 'active',
                ]];
            }

            public function getByUserId(int $userId, array $filters = []): array
            {
                return [];
            }
        };
        $evaluator = new AccessEvaluator(
            $grants,
            new PlanRuleResolver(),
            new ProtectionRuleRepository(),
            null,
            $policyResolver
        );

        self::assertTrue($evaluator->canAccess(17, 'wordpress_core', 'post', '42'));
    }

    public function test_any_membership_path_ignores_unrelated_future_content_drip(): void
    {
        $policy = new ResourceAccessPolicy('wordpress_core', 'post', '42');
        $policy->allowAnyActivePlan();
        $evaluator = $this->evaluatorForPolicyAndMemberships($policy, [[
            'plan_id' => 5,
            'provider' => 'wordpress_core',
            'resource_type' => 'course',
            'resource_id' => '99',
            'created_at' => '2026-01-01 00:00:00',
            'drip_available_at' => '2030-01-01 00:00:00',
            'trial_ends_at' => null,
            'access_status' => 'active',
        ]]);

        self::assertTrue($evaluator->canAccess(17, 'wordpress_core', 'post', '42'));
    }

    public function test_taxonomy_path_requires_its_qualifying_edge_and_honours_that_edges_drip(): void
    {
        $policy = new ResourceAccessPolicy('wordpress_core', 'post', '42');
        $policy->addPlanPath(5, null, 'resource', [
            'provider' => 'wordpress_core',
            'resource_type' => 'category',
            'resource_id' => '7',
        ]);
        $evaluator = $this->evaluatorForPolicyAndMemberships($policy, [
            [
                'plan_id' => 5,
                'provider' => 'wordpress_core',
                'resource_type' => 'course',
                'resource_id' => '99',
                'created_at' => '2026-01-01 00:00:00',
                'drip_available_at' => null,
                'trial_ends_at' => null,
                'access_status' => 'active',
            ],
            [
                'plan_id' => 5,
                'provider' => 'wordpress_core',
                'resource_type' => 'category',
                'resource_id' => '7',
                'created_at' => '2026-01-01 00:00:00',
                'drip_available_at' => '2030-01-01 00:00:00',
                'trial_ends_at' => null,
                'access_status' => 'active',
            ],
        ]);

        $result = $evaluator->evaluate(17, 'wordpress_core', 'post', '42');

        self::assertFalse($result['allowed']);
        self::assertTrue($result['drip_locked']);
        self::assertSame('2030-01-01 00:00:00', $result['drip_available_at']);
    }

    public function test_resource_path_rejects_legacy_membership_without_its_qualifying_identity(): void
    {
        $policy = new ResourceAccessPolicy('wordpress_core', 'post', '42');
        $policy->addPlanPath(5, null, 'resource', [
            'provider' => 'wordpress_core',
            'resource_type' => 'category',
            'resource_id' => '7',
        ]);
        $evaluator = $this->evaluatorForPolicyAndMemberships($policy, [[
            'plan_id' => 5,
            'created_at' => '2026-01-01 00:00:00',
            'drip_available_at' => null,
            'trial_ends_at' => null,
            'access_status' => 'active',
        ]]);

        self::assertFalse($evaluator->canAccess(17, 'wordpress_core', 'post', '42'));
    }

    public function test_twenty_resource_batch_uses_four_plugin_database_reads(): void
    {
        PlanRepository::clearCache();
        ProtectionRuleRepository::clearCache();
        AccessEvaluator::clearCache();
        $resources = [];
        for ($id = 1; $id <= 20; $id++) {
            $resources['rule-' . $id] = [
                'provider' => 'wordpress_core',
                'resource_type' => 'post',
                'resource_id' => (string) $id,
            ];
        }
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = function (string $query): array {
            if (str_contains($query, 'fchub_membership_protection_rules')) {
                $rows = [];
                for ($id = 1; $id <= 20; $id++) {
                    $rows[] = [
                        'id' => $id,
                        'resource_type' => 'post',
                        'resource_id' => (string) $id,
                        'plan_ids' => '[5]',
                        'protection_mode' => 'explicit',
                        'restriction_message' => null,
                        'redirect_url' => null,
                        'show_teaser' => 'no',
                        'meta' => '{}',
                        'created_at' => '2026-07-23 10:00:00',
                        'updated_at' => '2026-07-23 10:00:00',
                    ];
                }
                return $rows;
            }
            if (str_contains($query, 'fchub_membership_plans')) {
                return [[
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
                ]];
            }
            if (str_contains($query, 'fchub_membership_plan_rules')) {
                return [[
                    'id' => 8,
                    'plan_id' => 5,
                    'provider' => 'wordpress_core',
                    'resource_type' => 'post',
                    'resource_id' => '*',
                    'drip_delay_days' => 0,
                    'drip_type' => 'immediate',
                    'drip_date' => null,
                    'sort_order' => 0,
                    'meta' => '{}',
                    'created_at' => '2026-07-23 10:00:00',
                    'updated_at' => '2026-07-23 10:00:00',
                ]];
            }
            if (str_contains($query, 'GROUP BY access_rows._resource_key')) {
                return [['_resource_key' => 'rule-1', 'member_count' => '2']];
            }
            return [];
        };

        $counts = (new AccessEvaluator())->countDistinctUsersWithResourceAccessBatch($resources);

        self::assertSame(2, $counts['rule-1']);
        self::assertSame(0, $counts['rule-20']);
        $pluginReads = array_values(array_filter(
            $GLOBALS['_fchub_test_queries'],
            static fn(array $query): bool => $query[0] === 'get_results'
                && str_contains((string) $query[1], 'fchub_membership_')
        ));
        self::assertCount(4, $pluginReads);
    }

    private function evaluatorForPolicyAndMemberships(
        ResourceAccessPolicy $policy,
        array $memberships
    ): AccessEvaluator {
        $GLOBALS['_fchub_test_user_can'][17]['manage_options'] = false;
        $policyResolver = new class($policy) extends ResourceAccessPolicyResolver {
            public function __construct(private ResourceAccessPolicy $policy)
            {
            }

            public function resolve(string $provider, string $resourceType, string $resourceId): ResourceAccessPolicy
            {
                return $this->policy;
            }

            public function ensurePlanPath(ResourceAccessPolicy $policy, int $planId): void
            {
            }
        };
        $grants = new class($memberships) extends GrantRepository {
            public function __construct(private array $memberships)
            {
            }

            public function getActiveGrant(int $userId, string $provider, string $resourceType, string $resourceId): ?array
            {
                return null;
            }

            public function getEffectivePlanMembershipsForUser(int $userId): array
            {
                return $this->memberships;
            }

            public function getByUserId(int $userId, array $filters = []): array
            {
                return [];
            }
        };

        return new AccessEvaluator(
            $grants,
            new PlanRuleResolver(),
            new ProtectionRuleRepository(),
            null,
            $policyResolver
        );
    }
}

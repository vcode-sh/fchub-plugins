<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Access;

use FChubMemberships\Domain\Access\ResourceAccessPolicy;
use FChubMemberships\Domain\Access\ResourceAccessPolicyResolver;
use FChubMemberships\Domain\Plan\PlanRuleResolver;
use FChubMemberships\Storage\ProtectionRuleRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class ResourceAccessPolicyTest extends PluginTestCase
{
    public function test_it_keeps_immediate_and_dripped_paths_for_each_eligible_plan(): void
    {
        $policy = new ResourceAccessPolicy('wordpress_core', 'post', '42');
        $policy->addPlanPath(5, [
            'drip_type' => 'delayed',
            'drip_delay_days' => 7,
            'drip_date' => null,
        ]);
        $policy->addPlanPath(5, null);
        $policy->addPlanPath(9, [
            'drip_type' => 'fixed_date',
            'drip_delay_days' => 0,
            'drip_date' => '2026-08-01 00:00:00',
        ]);

        self::assertTrue($policy->allowsPlan(5));
        self::assertTrue($policy->allowsPlan(9));
        self::assertFalse($policy->allowsPlan(10));
        self::assertCount(2, $policy->pathsForPlan(5));
        self::assertSame('immediate', $policy->pathsForPlan(5)[1]['drip_type']);
        self::assertSame([5, 9], $policy->eligiblePlanIds());
    }

    public function test_any_active_membership_path_applies_without_erasing_plan_specific_drip(): void
    {
        $policy = new ResourceAccessPolicy('wordpress_core', 'post', '42');
        $policy->allowAnyActivePlan();
        $policy->addPlanPath(5, [
            'drip_type' => 'delayed',
            'drip_delay_days' => 3,
            'drip_date' => null,
        ]);

        self::assertTrue($policy->allowsAnyActivePlan());
        self::assertTrue($policy->allowsPlan(999));
        self::assertSame('immediate', $policy->pathsForPlan(999)[0]['drip_type']);
        self::assertCount(2, $policy->pathsForPlan(5));
    }

    public function test_resource_path_keeps_its_qualifying_identity_separate_from_membership_paths(): void
    {
        $policy = new ResourceAccessPolicy('wordpress_core', 'post', '42');
        $policy->addPlanPath(5, null, 'resource', [
            'provider' => 'wordpress_core',
            'resource_type' => 'category',
            'resource_id' => '7',
        ]);

        self::assertSame('resource', $policy->pathsForPlan(5)[0]['basis']);
        self::assertSame('category', $policy->pathsForPlan(5)[0]['qualifier']['resource_type']);
        self::assertSame('7', $policy->pathsForPlan(5)[0]['qualifier']['resource_id']);
    }

    public function test_global_rule_shortcut_fails_closed_when_protection_probe_fails(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static function (string $query, \wpdb $wpdb): int {
            $wpdb->last_error = 'protection probe failed';
            return 0;
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to determine whether protection rules exist.');

        (new ResourceAccessPolicyResolver())->hasAnyProtectionOrPlanRules();
    }

    public function test_taxonomy_derived_policy_is_not_reused_from_persistent_cache_in_a_new_request(): void
    {
        $post = new \WP_Post();
        $post->ID = 42;
        $post->post_type = 'post';
        $GLOBALS['_fchub_test_posts'][42] = $post;
        $GLOBALS['_fchub_test_post_types'] = ['post'];
        $GLOBALS['_fchub_test_get_object_taxonomies']['post'] = ['category'];
        $GLOBALS['_fchub_test_post_terms'][42]['category'] = [(object) ['term_id' => 7]];

        $planIds = [5];
        $protection = new class($planIds) extends ProtectionRuleRepository {
            public function __construct(private array &$planIds)
            {
            }

            public function findByResource(string $resourceType, string $resourceId): ?array
            {
                if ($resourceType !== 'category' || $resourceId !== '7') {
                    return null;
                }

                return [
                    'plan_ids' => $this->planIds,
                    'meta' => ['inheritance_mode' => 'all_posts'],
                ];
            }
        };
        $rules = new class extends PlanRuleResolver {
            public function findPlansIncluding(array $planIds): array
            {
                return $planIds;
            }

            public function findPlansWithResource(string $provider, string $resourceType, string $resourceId): array
            {
                return [];
            }
        };

        $first = (new ResourceAccessPolicyResolver($rules, $protection))
            ->resolve('wordpress_core', 'post', '42');
        self::assertSame([5], $first->eligiblePlanIds());

        $planIds = [9];
        $GLOBALS['wpdb'] = new \wpdb();

        $second = (new ResourceAccessPolicyResolver($rules, $protection))
            ->resolve('wordpress_core', 'post', '42');
        self::assertSame([9], $second->eligiblePlanIds());
    }
}

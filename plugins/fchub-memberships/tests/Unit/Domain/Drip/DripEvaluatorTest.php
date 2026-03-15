<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Drip;

use FChubMemberships\Domain\Drip\DripEvaluator;
use FChubMemberships\Domain\Plan\PlanRuleResolver;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class DripEvaluatorTest extends PluginTestCase
{
    private function inject(DripEvaluator $evaluator, GrantRepository $grants, PlanRuleResolver $resolver): void
    {
        $grantReflection = new \ReflectionProperty(DripEvaluator::class, 'grantRepo');
        $grantReflection->setValue($evaluator, $grants);

        $resolverReflection = new \ReflectionProperty(DripEvaluator::class, 'ruleResolver');
        $resolverReflection->setValue($evaluator, $resolver);
    }

    public function test_is_available_covers_no_grant_immediate_unlocked_and_locked_states(): void
    {
        $grants = new class extends GrantRepository {
            public function getActiveGrant(int $userId, string $provider, string $resourceType, string $resourceId): ?array
            {
                return match ($resourceId) {
                    'immediate' => ['drip_available_at' => null],
                    'unlocked' => ['drip_available_at' => '2026-03-10 00:00:00'],
                    'locked' => ['drip_available_at' => '2099-03-20 00:00:00'],
                    default => null,
                };
            }
        };

        $evaluator = new DripEvaluator();
        $this->inject($evaluator, $grants, new class extends PlanRuleResolver {});

        self::assertSame(['available' => false, 'reason' => 'no_grant'], $evaluator->isAvailable(1, 'wordpress_core', 'post', 'missing'));
        self::assertSame(['available' => true, 'reason' => 'immediate'], $evaluator->isAvailable(1, 'wordpress_core', 'post', 'immediate'));
        self::assertSame(['available' => true, 'reason' => 'unlocked'], $evaluator->isAvailable(1, 'wordpress_core', 'post', 'unlocked'));
        $locked = $evaluator->isAvailable(1, 'wordpress_core', 'post', 'locked');
        self::assertFalse($locked['available']);
        self::assertSame('drip_locked', $locked['reason']);
        self::assertArrayHasKey('days_left', $locked);
    }

    public function test_get_timeline_and_plan_schedule_transform_and_sort_rules(): void
    {
        $grants = new class extends GrantRepository {
            public function getByUserId(int $userId, array $filters = []): array
            {
                return [[
                    'provider' => 'wordpress_core',
                    'resource_type' => 'post',
                    'resource_id' => '55',
                    'drip_available_at' => null,
                    'status' => 'active',
                ]];
            }
        };

        $resolver = new class extends PlanRuleResolver {
            public function resolveUniqueRules(int $planId): array
            {
                return [
                    ['id' => 1, 'provider' => 'wordpress_core', 'resource_type' => 'post', 'resource_id' => '55', 'drip_type' => 'immediate', 'drip_delay_days' => 0, 'drip_date' => null, 'sort_order' => 2],
                    ['id' => 2, 'provider' => 'wordpress_core', 'resource_type' => 'page', 'resource_id' => '77', 'drip_type' => 'delayed', 'drip_delay_days' => 3, 'drip_date' => null, 'sort_order' => 1],
                ];
            }
        };

        $GLOBALS['_fchub_test_options']['date_format'] = 'Y-m-d';
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static fn(string $query): array => str_contains($query, 'WHERE plan_id = 5')
            ? [
                ['id' => 1, 'plan_id' => 5, 'provider' => 'wordpress_core', 'resource_type' => 'post', 'resource_id' => '55', 'drip_type' => 'immediate', 'drip_delay_days' => 0, 'drip_date' => null, 'sort_order' => 2, 'meta' => '{}'],
                ['id' => 2, 'plan_id' => 5, 'provider' => 'wordpress_core', 'resource_type' => 'page', 'resource_id' => '77', 'drip_type' => 'fixed_date', 'drip_delay_days' => 0, 'drip_date' => '2026-03-20 00:00:00', 'sort_order' => 1, 'meta' => '{}'],
            ]
            : [];

        $evaluator = new DripEvaluator();
        $this->inject($evaluator, $grants, $resolver);

        $timeline = $evaluator->getTimeline(21, 5);
        $schedule = $evaluator->getPlanDripSchedule(5);

        self::assertSame('unlocked', $timeline[0]['status']);
        self::assertSame('post #55', strtolower($timeline[0]['label']));
        self::assertSame('immediate', $schedule[0]['drip_type']);
        self::assertSame('fixed_date', $schedule[1]['drip_type']);
        self::assertSame('2026-03-20 00:00:00', $schedule[1]['drip_date']);
    }
}

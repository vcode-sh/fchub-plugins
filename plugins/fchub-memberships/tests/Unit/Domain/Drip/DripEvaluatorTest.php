<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Drip;

use FChubMemberships\Domain\Drip\DripEvaluator;
use FChubMemberships\Domain\Plan\PlanRuleResolver;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Support\Clock;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class DripEvaluatorTest extends PluginTestCase
{
    public function test_availability_compares_site_local_storage_to_injected_now(): void
    {
        $timezone = new \DateTimeZone('Europe/Warsaw');
        $clock = new Clock(new \DateTimeImmutable('2026-03-14 01:00:00', $timezone), $timezone);
        $grants = new class extends GrantRepository {
            public function getActiveGrant(int $userId, string $provider, string $resourceType, string $resourceId): ?array
            {
                return ['drip_available_at' => '2026-03-14 01:30:00'];
            }
        };
        $evaluator = new DripEvaluator($grants, new PlanRuleResolver(), $clock);

        $result = $evaluator->isAvailable(1, 'wordpress_core', 'post', '55');

        self::assertFalse($result['available']);
        self::assertSame('drip_locked', $result['reason']);
    }

    public function test_locked_payload_days_left_uses_calendar_days_and_rounds_partial_days(): void
    {
        $timezone = new \DateTimeZone('Europe/Warsaw');
        $clock = new Clock(new \DateTimeImmutable('2026-10-24 12:30:00', $timezone), $timezone);
        $grants = new class extends GrantRepository {
            public function getActiveGrant(int $userId, string $provider, string $resourceType, string $resourceId): ?array
            {
                return ['drip_available_at' => match ($resourceId) {
                    'fall-back' => '2026-10-25 12:30:00',
                    'partial' => '2026-10-24 12:30:01',
                }];
            }
        };
        $evaluator = new DripEvaluator($grants, new PlanRuleResolver(), $clock);

        self::assertSame(1, $evaluator->isAvailable(1, 'wordpress_core', 'post', 'fall-back')['days_left']);
        self::assertSame(1, $evaluator->isAvailable(1, 'wordpress_core', 'post', 'partial')['days_left']);
    }

    public function test_timeline_days_left_counts_fall_back_as_one_calendar_day(): void
    {
        $timezone = new \DateTimeZone('Europe/Warsaw');
        $clock = new Clock(new \DateTimeImmutable('2026-10-24 12:30:00', $timezone), $timezone);
        $grants = new class extends GrantRepository {
            public function getByUserId(int $userId, array $filters = []): array
            {
                return [[
                    'provider' => 'wordpress_core',
                    'resource_type' => 'post',
                    'resource_id' => '55',
                    'drip_available_at' => '2026-10-25 12:30:00',
                    'status' => 'active',
                ]];
            }
        };
        $resolver = new class extends PlanRuleResolver {
            public function resolveUniqueRules(int $planId): array
            {
                return [[
                    'id' => 1,
                    'provider' => 'wordpress_core',
                    'resource_type' => 'post',
                    'resource_id' => '55',
                    'drip_type' => 'delayed',
                    'drip_delay_days' => 1,
                    'drip_date' => null,
                    'sort_order' => 1,
                ]];
            }
        };
        $evaluator = new DripEvaluator($grants, $resolver, $clock);

        $timeline = $evaluator->getTimeline(21, 5);

        self::assertSame('upcoming', $timeline[0]['status']);
        self::assertSame(1, $timeline[0]['days_left']);
    }

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

    public function test_the_timeline_only_credits_grants_that_are_still_active(): void
    {
        // A revoked row must not unlock its rule. The fake filters honestly, so
        // dropping the status filter hands the timeline a revoked grant.
        $grants = new class extends GrantRepository {
            public function getByUserId(int $userId, array $filters = []): array
            {
                $rows = [
                    ['provider' => 'wordpress_core', 'resource_type' => 'post', 'resource_id' => '55', 'drip_available_at' => null, 'status' => 'active'],
                    ['provider' => 'wordpress_core', 'resource_type' => 'page', 'resource_id' => '77', 'drip_available_at' => null, 'status' => 'revoked'],
                ];

                if (($filters['status'] ?? '') === '') {
                    return $rows;
                }

                return array_values(array_filter($rows, static fn(array $r): bool => $r['status'] === $filters['status']));
            }
        };

        $resolver = new class extends PlanRuleResolver {
            public function resolveUniqueRules(int $planId): array
            {
                return [
                    ['id' => 1, 'provider' => 'wordpress_core', 'resource_type' => 'post', 'resource_id' => '55', 'drip_type' => 'immediate', 'drip_delay_days' => 0, 'drip_date' => null, 'sort_order' => 1],
                    ['id' => 2, 'provider' => 'wordpress_core', 'resource_type' => 'page', 'resource_id' => '77', 'drip_type' => 'immediate', 'drip_delay_days' => 0, 'drip_date' => null, 'sort_order' => 2],
                ];
            }
        };

        $evaluator = new DripEvaluator();
        $this->inject($evaluator, $grants, $resolver);

        $statuses = array_column($evaluator->getTimeline(1, 5), 'status', 'resource_id');

        self::assertSame('unlocked', $statuses['55']);
        self::assertSame('locked', $statuses['77']);
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

<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Plan;

use FChubMemberships\Domain\Plan\PlanService;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Storage\PlanRuleRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class PlanServiceAdvancedTest extends PluginTestCase
{
    private function planRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 5,
            'title' => 'Gold Plan',
            'slug' => 'gold-plan',
            'description' => '',
            'status' => 'active',
            'level' => 10,
            'duration_type' => 'lifetime',
            'duration_days' => null,
            'trial_days' => 0,
            'grace_period_days' => 0,
            'includes_plan_ids' => [],
            'restriction_message' => '',
            'redirect_url' => '',
            'settings' => [],
            'meta' => ['keep' => 'yes'],
            'scheduled_status' => 'archived',
            'scheduled_at' => '2026-03-20 10:00:00',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ], $overrides);
    }

    private function serviceWith(object $planRepo, object $ruleRepo): PlanService
    {
        $service = new PlanService();

        $planReflection = new \ReflectionProperty(PlanService::class, 'planRepo');
        $planReflection->setValue($service, $planRepo);

        $ruleReflection = new \ReflectionProperty(PlanService::class, 'ruleRepo');
        $ruleReflection->setValue($service, $ruleRepo);

        return $service;
    }

    public function test_create_duplicate_and_process_scheduled_statuses_cover_plan_service_branches(): void
    {
        $createdPayloads = [];
        $updatedPayloads = [];
        $schedulePayloads = [];
        $deletedRules = [];

        $planRepo = new class($this->planRow(), $createdPayloads, $updatedPayloads, $schedulePayloads) extends PlanRepository {
            public function __construct(
                private array $plan,
                private array &$createdPayloads,
                private array &$updatedPayloads,
                private array &$schedulePayloads
            ) {
            }

            public function find(int $id): ?array
            {
                return match ($id) {
                    5 => $this->plan,
                    22 => ['id' => 22, 'title' => 'New Plan', 'slug' => 'new-plan-copy', 'rules' => [], 'meta' => [], 'status' => 'active', 'level' => 0, 'duration_type' => 'lifetime', 'duration_days' => null, 'trial_days' => 0, 'grace_period_days' => 0, 'settings' => [], 'includes_plan_ids' => [], 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'],
                    9 => ['id' => 9, 'title' => 'Child', 'slug' => 'child', 'includes_plan_ids' => [5], 'meta' => [], 'status' => 'active', 'level' => 0, 'duration_type' => 'lifetime', 'duration_days' => null, 'trial_days' => 0, 'grace_period_days' => 0, 'settings' => []],
                    default => null,
                };
            }

            public function create(array $data): int
            {
                $this->createdPayloads[] = $data;
                return 22;
            }

            public function update(int $id, array $data): bool
            {
                $this->updatedPayloads[] = [$id, $data];
                return true;
            }

            public function updateSchedule(int $id, ?string $scheduledStatus, ?string $scheduledAt): bool
            {
                $this->schedulePayloads[] = [$id, $scheduledStatus, $scheduledAt];
                return true;
            }

            public function slugExists(string $slug, ?int $excludeId = null): bool
            {
                return $slug === 'existing-slug';
            }

            public function generateUniqueSlug(string $title, ?int $excludeId = null): string
            {
                return sanitize_title($title) . '-copy';
            }

            public function getDueScheduledPlans(): array
            {
                return [$this->plan];
            }

            public function count(array $filters = []): int
            {
                return 1;
            }

            public function all(array $filters = []): array
            {
                return [$this->plan];
            }

            public function getMemberCount(int $planId): int
            {
                return 8;
            }

            public function delete(int $id): bool
            {
                return true;
            }
        };

        $ruleRepo = new class($deletedRules) extends PlanRuleRepository {
            public function __construct(private array &$deletedRules)
            {
            }

            public function bulkCreate(int $planId, array $rules): array
            {
                $this->deletedRules[] = ['bulkCreate', $planId, $rules];
                return [91];
            }

            public function getByPlanId(int $planId): array
            {
                return [['id' => 91, 'plan_id' => $planId]];
            }

            public function deleteByPlanId(int $planId): int
            {
                $this->deletedRules[] = ['deleteByPlanId', $planId];
                return 1;
            }

            public function syncRules(int $planId, array $rules): void
            {
                $this->deletedRules[] = ['syncRules', $planId, $rules];
            }

            public function getDripRules(int $planId): array
            {
                return [['id' => 91, 'plan_id' => $planId, 'drip_type' => 'delayed']];
            }
        };

        $service = $this->serviceWith($planRepo, $ruleRepo);

        $created = $service->create([
            'title' => 'New Plan',
            'slug' => '',
            'rules' => [['resource_type' => 'post', 'resource_id' => '55']],
        ]);
        $duplicate = $service->duplicate(5);
        $processed = $service->processScheduledStatuses();

        self::assertSame(22, $created['id']);
        self::assertSame('new-plan-copy', $createdPayloads[0]['slug']);
        self::assertSame('Gold Plan (Copy)', $createdPayloads[1]['title']);
        self::assertSame('inactive', $createdPayloads[1]['status']);
        self::assertSame(1, $processed);
        self::assertSame([5, 'archived'], [$updatedPayloads[0][0], $updatedPayloads[0][1]['status']]);
        self::assertSame([5, null, null], $schedulePayloads[0]);
        self::assertContains('fchub_memberships_plan_hierarchy', $GLOBALS['_fchub_test_deleted_transients']);
        self::assertSame('bulkCreate', $deletedRules[0][0]);
    }

    public function test_update_and_delete_handle_cycle_slug_conflicts_and_cascades(): void
    {
        $queries = [];
        $protectionUpdates = [];
        $deletedRules = [];

        $planRepo = new class($this->planRow(), $protectionUpdates) extends PlanRepository {
            public function __construct(private array $plan, private array &$protectionUpdates)
            {
            }

            public function find(int $id): ?array
            {
                return match ($id) {
                    5 => $this->plan,
                    9 => ['id' => 9, 'title' => 'Child', 'slug' => 'child', 'includes_plan_ids' => [5], 'meta' => [], 'status' => 'active', 'level' => 0, 'duration_type' => 'lifetime', 'duration_days' => null, 'trial_days' => 0, 'grace_period_days' => 0, 'settings' => []],
                    default => null,
                };
            }

            public function update(int $id, array $data): bool
            {
                $this->protectionUpdates[] = [$id, $data];
                return true;
            }

            public function slugExists(string $slug, ?int $excludeId = null): bool
            {
                return $slug === 'existing-slug';
            }

            public function delete(int $id): bool
            {
                return true;
            }
        };

        $ruleRepo = new class($deletedRules) extends PlanRuleRepository {
            public function __construct(private array &$deletedRules)
            {
            }

            public function getByPlanId(int $planId): array
            {
                return [['id' => 91, 'plan_id' => $planId]];
            }

            public function deleteByPlanId(int $planId): int
            {
                $this->deletedRules[] = $planId;
                return 1;
            }
        };

        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query) use (&$queries): array {
            $queries[] = $query;
            return str_contains($query, 'FROM wp_fchub_membership_protection_rules')
                ? [['id' => 4, 'plan_ids' => '[5,9]']]
                : [];
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $query) use (&$queries): int {
            $queries[] = $query;
            return 1;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['update'] = static function (string $table, array $data, array $where) use (&$protectionUpdates): int {
            if ($table === 'wp_fchub_membership_protection_rules') {
                $protectionUpdates[] = [$table, $data, $where];
            }

            return 1;
        };

        $service = $this->serviceWith($planRepo, $ruleRepo);

        $slugConflict = $service->update(5, ['slug' => 'existing-slug']);
        $cycle = $service->update(5, ['includes_plan_ids' => [9]]);
        $updated = $service->update(5, ['meta' => ['new' => 'value']]);
        $deleted = $service->delete(5);

        self::assertArrayHasKey('error', $slugConflict);
        self::assertArrayHasKey('error', $cycle);
        self::assertSame('yes', $protectionUpdates[0][1]['meta']['keep']);
        self::assertSame('value', $protectionUpdates[0][1]['meta']['new']);
        self::assertTrue($deleted);
        self::assertContains(5, $deletedRules);
        self::assertStringContainsString('DELETE FROM wp_fchub_membership_drip_notifications', implode("\n", $queries));
    }
}

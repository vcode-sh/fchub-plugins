<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Reports;

use FChubMemberships\Domain\Reports\DashboardQueryService;
use FChubMemberships\Reports\MemberStatsReport;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class DashboardQueryServiceTest extends PluginTestCase
{
    public function test_dashboard_exposes_the_stable_healthy_operations_contract(): void
    {
        $dashboard = $this->dashboard(
            overview: [
                'active_members' => 12,
                'active_plans' => 3,
                'content_protected' => 7,
                'grants_this_month' => 9,
                'new_this_month' => 4,
            ],
            expiringCount: 0,
            drip: ['failed' => 0],
            activity: []
        );

        self::assertSame([
            'summary' => [
                'active_members' => 12,
                'new_members_30d' => 4,
                'grants_30d' => 9,
                'expiring_7d' => 0,
                'failed_notifications' => 0,
            ],
            'readiness' => [
                'active_plans' => 3,
                'protected_items' => 7,
                'has_active_plan' => true,
                'has_protected_content' => true,
                'has_active_members' => true,
            ],
            'attention' => [],
            'trend' => [
                ['date' => '2026-03-12', 'count' => 11],
                ['date' => '2026-03-13', 'count' => 12],
            ],
            'plan_distribution' => [
                ['plan_id' => 3, 'plan_title' => 'Gold', 'count' => 12],
            ],
            'activity' => [],
        ], $dashboard->get());
    }

    public function test_dashboard_synthesises_every_actionable_warning_with_valid_destinations(): void
    {
        $dashboard = $this->dashboard(
            overview: [
                'active_members' => 0,
                'active_plans' => 0,
                'content_protected' => 0,
                'grants_this_month' => 0,
                'new_this_month' => 0,
            ],
            expiringCount: 2,
            drip: ['failed' => 3],
            activity: []
        );

        self::assertSame([
            ['key' => 'failed_notifications', 'severity' => 'error', 'title' => 'Failed notifications', 'description' => '3 failed drip notifications require attention.', 'count' => 3, 'destination' => '/drip'],
            ['key' => 'expiring_7d', 'severity' => 'warning', 'title' => 'Upcoming expirations', 'description' => '2 memberships expire in the next 7 days.', 'count' => 2, 'destination' => '/members'],
            ['key' => 'no_active_plans', 'severity' => 'error', 'title' => 'No active plans', 'description' => 'Create and activate a membership plan to start granting access.', 'count' => 0, 'destination' => '/plans/new'],
            ['key' => 'no_protected_content', 'severity' => 'warning', 'title' => 'No protected content', 'description' => 'Protect content so active memberships have something to unlock.', 'count' => 0, 'destination' => '/content'],
            ['key' => 'no_active_members', 'severity' => 'info', 'title' => 'No active members', 'description' => 'Grant access or complete a purchase to welcome the first member.', 'count' => 0, 'destination' => '/members'],
        ], $dashboard->get()['attention']);
    }

    public function test_dashboard_limits_and_projects_activity_to_safe_fields(): void
    {
        $dashboard = $this->dashboard(
            overview: [
                'active_members' => 1,
                'active_plans' => 1,
                'content_protected' => 1,
                'grants_this_month' => 1,
                'new_this_month' => 1,
            ],
            expiringCount: 0,
            drip: ['failed' => 0],
            activity: array_map(static fn(int $id): array => [
                'id' => $id,
                'action' => 'grant_updated',
                'entity_type' => 'grant',
                'entity_id' => 50 + $id,
                'actor_type' => 'admin',
                'actor_id' => 9,
                'created_at' => '2026-03-13 10:00:00',
                'old_value' => ['status' => 'pending', 'api_key' => 'do-not-expose'],
                'new_value' => ['status' => 'active', 'password' => 'do-not-expose'],
                'unexpected' => 'do not expose this',
            ], range(1, 10))
        );

        $activity = $dashboard->get()['activity'];

        self::assertCount(8, $activity);
        self::assertSame([
            'id' => 1,
            'action' => 'grant_updated',
            'entity_type' => 'grant',
            'entity_id' => 51,
            'actor_type' => 'admin',
            'actor_id' => 9,
            'occurred_at' => '2026-03-13 10:00:00',
        ], $activity[0]);
        self::assertArrayNotHasKey('old_value', $activity[0]);
        self::assertArrayNotHasKey('new_value', $activity[0]);
        self::assertArrayNotHasKey('unexpected', $activity[0]);
        self::assertStringNotContainsString('do-not-expose', json_encode($activity, JSON_THROW_ON_ERROR));
    }

    public function test_dashboard_does_not_hide_source_errors(): void
    {
        $dashboard = $this->dashboard(
            overview: [
                'active_members' => 1,
                'active_plans' => 1,
                'content_protected' => 1,
                'grants_this_month' => 1,
                'new_this_month' => 1,
            ],
            expiringCount: 0,
            drip: ['failed' => 0],
            activity: [],
            activitySource: static function (): array {
                throw new \RuntimeException('Audit storage is unavailable.');
            }
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Audit storage is unavailable.');

        $dashboard->get();
    }

    /**
     * @param array<string, int> $overview
     * @param array<string, int> $drip
     * @param array<int, array<string, mixed>> $activity
     */
    private function dashboard(
        array $overview,
        int $expiringCount,
        array $drip,
        array $activity,
        ?\Closure $activitySource = null
    ): DashboardQueryService {
        $stats = new class($overview) extends MemberStatsReport {
            /** @param array<string, int> $overview */
            public function __construct(private array $overview)
            {
            }

            public function getOverview(?string $from = null, ?string $to = null): array
            {
                return $this->overview;
            }

            public function getMembersOverTime(string $period = '12m', ?string $from = null, ?string $to = null): array
            {
                return [
                    ['date' => '2026-03-12', 'count' => 11],
                    ['date' => '2026-03-13', 'count' => 12],
                ];
            }

            public function getPlanDistribution(?string $from = null, ?string $to = null): array
            {
                return [['plan_id' => 3, 'plan_title' => 'Gold', 'count' => 12]];
            }
        };

        return new DashboardQueryService(
            $stats,
            static fn(): int => $expiringCount,
            static fn(): array => $drip,
            $activitySource ?? static fn(): array => $activity
        );
    }
}

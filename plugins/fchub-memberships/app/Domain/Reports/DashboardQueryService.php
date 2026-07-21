<?php

declare(strict_types=1);

namespace FChubMemberships\Domain\Reports;

defined('ABSPATH') || exit;

use FChubMemberships\Domain\Drip\DripAdminQueryService;
use FChubMemberships\Reports\MemberStatsReport;
use FChubMemberships\Storage\AuditLogRepository;
use FChubMemberships\Storage\GrantRepository;

final class DashboardQueryService
{
    private MemberStatsReport $memberStats;
    private \Closure $expiringSoonCount;
    private \Closure $dripOverview;
    private \Closure $recentActivity;

    public function __construct(
        ?MemberStatsReport $memberStats = null,
        ?\Closure $expiringSoonCount = null,
        ?\Closure $dripOverview = null,
        ?\Closure $recentActivity = null
    ) {
        $this->memberStats = $memberStats ?? new MemberStatsReport();
        $this->expiringSoonCount = $expiringSoonCount ?? static fn(): int => (new GrantRepository())->countExpiringSoon(7);
        $this->dripOverview = $dripOverview ?? static fn(): array => (new DripAdminQueryService())->overview();
        $this->recentActivity = $recentActivity ?? static fn(): array => (new AuditLogRepository())->getRecent(8);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(): array
    {
        $overview = $this->memberStats->getOverview();
        $expiringCount = (int) ($this->expiringSoonCount)();
        $drip = ($this->dripOverview)();

        $summary = [
            'active_members' => (int) ($overview['active_members'] ?? 0),
            'new_members_30d' => (int) ($overview['new_this_month'] ?? 0),
            'grants_30d' => (int) ($overview['grants_this_month'] ?? 0),
            'expiring_7d' => $expiringCount,
            'failed_notifications' => (int) ($drip['failed'] ?? 0),
        ];

        $readiness = [
            'active_plans' => (int) ($overview['active_plans'] ?? 0),
            'protected_items' => (int) ($overview['content_protected'] ?? 0),
        ];
        $readiness['has_active_plan'] = $readiness['active_plans'] > 0;
        $readiness['has_protected_content'] = $readiness['protected_items'] > 0;
        $readiness['has_active_members'] = $summary['active_members'] > 0;

        return [
            'summary' => $summary,
            'readiness' => $readiness,
            'attention' => $this->attention($summary, $readiness),
            'trend' => $this->memberStats->getMembersOverTime('30d'),
            'plan_distribution' => $this->memberStats->getPlanDistribution(),
            'activity' => $this->activity(($this->recentActivity)()),
        ];
    }

    /**
     * @param array<string, int> $summary
     * @param array<string, int|bool> $readiness
     * @return array<int, array<string, int|string>>
     */
    private function attention(array $summary, array $readiness): array
    {
        $items = [];

        if ($summary['failed_notifications'] > 0) {
            $items[] = [
                'key' => 'failed_notifications',
                'severity' => 'error',
                'title' => __('Failed notifications', 'fchub-memberships'),
                'description' => sprintf(
                    _n(
                        '%d failed drip notification requires attention.',
                        '%d failed drip notifications require attention.',
                        $summary['failed_notifications'],
                        'fchub-memberships'
                    ),
                    $summary['failed_notifications']
                ),
                'count' => $summary['failed_notifications'],
                'destination' => '/drip',
            ];
        }

        if ($summary['expiring_7d'] > 0) {
            $items[] = [
                'key' => 'expiring_7d',
                'severity' => 'warning',
                'title' => __('Upcoming expirations', 'fchub-memberships'),
                'description' => sprintf(
                    _n(
                        '%d membership expires in the next 7 days.',
                        '%d memberships expire in the next 7 days.',
                        $summary['expiring_7d'],
                        'fchub-memberships'
                    ),
                    $summary['expiring_7d']
                ),
                'count' => $summary['expiring_7d'],
                'destination' => '/members',
            ];
        }

        if (!$readiness['has_active_plan']) {
            $items[] = [
                'key' => 'no_active_plans',
                'severity' => 'error',
                'title' => __('No active plans', 'fchub-memberships'),
                'description' => __('Create and activate a membership plan to start granting access.', 'fchub-memberships'),
                'count' => 0,
                'destination' => '/plans/new',
            ];
        }

        if (!$readiness['has_protected_content']) {
            $items[] = [
                'key' => 'no_protected_content',
                'severity' => 'warning',
                'title' => __('No protected content', 'fchub-memberships'),
                'description' => __('Protect content so active memberships have something to unlock.', 'fchub-memberships'),
                'count' => 0,
                'destination' => '/content',
            ];
        }

        if (!$readiness['has_active_members']) {
            $items[] = [
                'key' => 'no_active_members',
                'severity' => 'info',
                'title' => __('No active members', 'fchub-memberships'),
                'description' => __('Grant access or complete a purchase to welcome the first member.', 'fchub-memberships'),
                'count' => 0,
                'destination' => '/members',
            ];
        }

        return $items;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     */
    private function activity(array $entries): array
    {
        return array_map(static function (array $entry): array {
            return [
                'id' => (int) ($entry['id'] ?? 0),
                'action' => (string) ($entry['action'] ?? ''),
                'entity_type' => (string) ($entry['entity_type'] ?? ''),
                'entity_id' => (int) ($entry['entity_id'] ?? 0),
                'actor_type' => (string) ($entry['actor_type'] ?? ''),
                'actor_id' => (int) ($entry['actor_id'] ?? 0),
                'occurred_at' => $entry['created_at'] ?? null,
            ];
        }, array_slice($entries, 0, 8));
    }
}

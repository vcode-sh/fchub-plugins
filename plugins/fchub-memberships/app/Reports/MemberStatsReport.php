<?php

namespace FChubMemberships\Reports;

defined('ABSPATH') || exit;

use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\PlanRepository;

class MemberStatsReport
{
    private GrantRepository $grantRepo;
    private PlanRepository $planRepo;
    private string $statsTable;
    private string $grantsTable;

    public function __construct()
    {
        global $wpdb;
        $this->grantRepo = new GrantRepository();
        $this->planRepo = new PlanRepository();
        $this->statsTable = $wpdb->prefix . 'fchub_membership_stats_daily';
        $this->grantsTable = $wpdb->prefix . 'fchub_membership_grants';
    }

    /**
     * Get overview stats for the dashboard.
     */
    public function getOverview(): array
    {
        global $wpdb;

        $monthStart = gmdate('Y-m-01 00:00:00');
        $now = current_time('mysql');

        $activeMembers = $this->grantRepo->countActiveMembers();
        $newThisMonth = $this->grantRepo->countNewMembers($monthStart, $now);
        $churnedThisMonth = $this->grantRepo->countChurnedMembers($monthStart, $now);

        $churnRate = 0.0;
        if ($activeMembers + $churnedThisMonth > 0) {
            $churnRate = round(($churnedThisMonth / ($activeMembers + $churnedThisMonth)) * 100, 2);
        }

        $plansTable = $wpdb->prefix . 'fchub_membership_plans';
        $protectionTable = $wpdb->prefix . 'fchub_membership_protection_rules';

        $activePlans = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$plansTable} WHERE status = 'active'"
        );
        $contentProtected = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$protectionTable}"
        );
        $grantsThisMonth = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->grantsTable} WHERE created_at >= %s",
            $monthStart
        ));

        return [
            'active_members'      => $activeMembers,
            'active_plans'        => $activePlans,
            'content_protected'   => $contentProtected,
            'grants_this_month'   => $grantsThisMonth,
            'new_this_month'      => $newThisMonth,
            'churned_this_month'  => $churnedThisMonth,
            'churn_rate'          => $churnRate,
        ];
    }

    /**
     * Get member counts over time for charting.
     *
     * @param string $period E.g. '12m', '6m', '30d'.
     * @return array Array of ['date' => string, 'count' => int].
     */
    public function getMembersOverTime(string $period = '12m'): array
    {
        global $wpdb;

        $range = $this->parsePeriod($period);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT stat_date AS date, SUM(active_count) AS count
             FROM {$this->statsTable}
             WHERE stat_date >= %s AND stat_date <= %s
             GROUP BY stat_date
             ORDER BY stat_date ASC",
            $range['from'],
            $range['to']
        ), ARRAY_A);

        return array_map(function ($row) {
            return [
                'date'  => $row['date'],
                'count' => (int) $row['count'],
            ];
        }, $rows ?: []);
    }

    /**
     * Get member distribution across plans.
     *
     * @return array Array of ['plan_id' => int, 'plan_title' => string, 'count' => int].
     */
    public function getPlanDistribution(): array
    {
        global $wpdb;
        $now = current_time('mysql');
        $plansTable = $wpdb->prefix . 'fchub_membership_plans';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT g.plan_id, p.title AS plan_title, COUNT(DISTINCT g.user_id) AS count
             FROM {$this->grantsTable} g
             LEFT JOIN {$plansTable} p ON g.plan_id = p.id
             WHERE g.status = 'active'
               AND g.plan_id IS NOT NULL
               AND (g.starts_at IS NULL OR g.starts_at <= %s)
               AND (g.expires_at IS NULL OR g.expires_at > %s)
             GROUP BY g.plan_id, p.title
             ORDER BY count DESC",
            $now,
            $now
        ), ARRAY_A);

        return array_map(function ($row) {
            return [
                'plan_id'    => (int) $row['plan_id'],
                'plan_title' => $row['plan_title'] ?? __('(Deleted Plan)', 'fchub-memberships'),
                'count'      => (int) $row['count'],
            ];
        }, $rows ?: []);
    }

    /**
     * Aggregate daily stats. Called by daily cron.
     * Inserts/updates rows in wp_fchub_membership_stats_daily.
     */
    public function aggregateDaily(): void
    {
        global $wpdb;

        $today = gmdate('Y-m-d');
        $now = current_time('mysql');
        $dayStart = $today . ' 00:00:00';
        $dayEnd = $today . ' 23:59:59';

        $plans = $this->planRepo->all();

        // Aggregate per plan
        foreach ($plans as $plan) {
            $planId = $plan['id'];

            $activeCount = $this->grantRepo->countActiveMembers($planId);
            $newCount = $this->grantRepo->countNewMembers($dayStart, $dayEnd, $planId);
            $churnedCount = $this->grantRepo->countChurnedMembers($dayStart, $dayEnd, $planId);

            // Get revenue from orders linked to this plan's grants today
            $revenue = $this->getDailyRevenue($planId, $dayStart, $dayEnd);

            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$this->statsTable} WHERE stat_date = %s AND plan_id = %d",
                $today,
                $planId
            ));

            if ($existing) {
                $wpdb->update(
                    $this->statsTable,
                    [
                        'active_count'  => $activeCount,
                        'new_count'     => $newCount,
                        'churned_count' => $churnedCount,
                        'revenue'       => $revenue,
                    ],
                    ['id' => (int) $existing]
                );
            } else {
                $wpdb->insert($this->statsTable, [
                    'stat_date'     => $today,
                    'plan_id'       => $planId,
                    'active_count'  => $activeCount,
                    'new_count'     => $newCount,
                    'churned_count' => $churnedCount,
                    'revenue'       => $revenue,
                ]);
            }
        }

        // Aggregate totals (plan_id = 0)
        $totalActive = $this->grantRepo->countActiveMembers();
        $totalNew = $this->grantRepo->countNewMembers($dayStart, $dayEnd);
        $totalChurned = $this->grantRepo->countChurnedMembers($dayStart, $dayEnd);
        $totalRevenue = $this->getDailyRevenue(null, $dayStart, $dayEnd);

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->statsTable} WHERE stat_date = %s AND plan_id = 0",
            $today
        ));

        if ($existing) {
            $wpdb->update(
                $this->statsTable,
                [
                    'active_count'  => $totalActive,
                    'new_count'     => $totalNew,
                    'churned_count' => $totalChurned,
                    'revenue'       => $totalRevenue,
                ],
                ['id' => (int) $existing]
            );
        } else {
            $wpdb->insert($this->statsTable, [
                'stat_date'     => $today,
                'plan_id'       => 0,
                'active_count'  => $totalActive,
                'new_count'     => $totalNew,
                'churned_count' => $totalChurned,
                'revenue'       => $totalRevenue,
            ]);
        }
    }

    /**
     * Get daily revenue for a plan from FluentCart orders.
     */
    private function getDailyRevenue(?int $planId, string $from, string $to): int
    {
        global $wpdb;

        $ordersTable = $wpdb->prefix . 'fct_orders';

        if ($planId !== null) {
            $revenue = $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(o.total), 0)
                 FROM {$this->grantsTable} g
                 JOIN {$ordersTable} o ON g.source_id = o.id AND g.source_type = 'order'
                 WHERE g.plan_id = %d
                   AND g.created_at >= %s
                   AND g.created_at <= %s",
                $planId,
                $from,
                $to
            ));
        } else {
            $revenue = $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(o.total), 0)
                 FROM {$this->grantsTable} g
                 JOIN {$ordersTable} o ON g.source_id = o.id AND g.source_type = 'order'
                 WHERE g.created_at >= %s
                   AND g.created_at <= %s",
                $from,
                $to
            ));
        }

        return (int) $revenue;
    }

    /**
     * Parse a period string like '12m', '6m', '30d' into from/to dates.
     */
    private function parsePeriod(string $period): array
    {
        $to = gmdate('Y-m-d');
        $amount = (int) substr($period, 0, -1);
        $unit = substr($period, -1);

        if ($unit === 'm') {
            $from = gmdate('Y-m-d', strtotime("-{$amount} months"));
        } else {
            $from = gmdate('Y-m-d', strtotime("-{$amount} days"));
        }

        return ['from' => $from, 'to' => $to];
    }
}

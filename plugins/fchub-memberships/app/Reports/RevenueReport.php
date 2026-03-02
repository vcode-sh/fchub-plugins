<?php

namespace FChubMemberships\Reports;

defined('ABSPATH') || exit;

use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\PlanRepository;

class RevenueReport
{
    private GrantRepository $grantRepo;
    private PlanRepository $planRepo;
    private string $grantsTable;
    private string $plansTable;
    private string $ordersTable;
    private string $subscriptionsTable;

    public function __construct()
    {
        global $wpdb;
        $this->grantRepo = new GrantRepository();
        $this->planRepo = new PlanRepository();
        $this->grantsTable = $wpdb->prefix . 'fchub_membership_grants';
        $this->plansTable = $wpdb->prefix . 'fchub_membership_plans';
        $this->ordersTable = $wpdb->prefix . 'fct_orders';
        $this->subscriptionsTable = $wpdb->prefix . 'fct_subscriptions';
    }

    /**
     * Get revenue grouped by plan per month.
     * Joins grants -> fct_orders to get revenue.
     *
     * @param string $period E.g. '12m', '6m'.
     * @return array Array of ['month' => string, 'plan_id' => int, 'plan_title' => string, 'revenue' => int].
     */
    public function getRevenuePerPlan(string $period = '12m'): array
    {
        global $wpdb;

        $range = $this->parsePeriod($period);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT
                DATE_FORMAT(g.created_at, '%%Y-%%m') AS month,
                g.plan_id,
                p.title AS plan_title,
                COALESCE(SUM(o.total), 0) AS revenue
             FROM {$this->grantsTable} g
             JOIN {$this->ordersTable} o ON g.source_id = o.id AND g.source_type = 'order'
             LEFT JOIN {$this->plansTable} p ON g.plan_id = p.id
             WHERE g.plan_id IS NOT NULL
               AND g.created_at >= %s
               AND g.created_at <= %s
             GROUP BY month, g.plan_id, p.title
             ORDER BY month ASC, revenue DESC",
            $range['from'],
            $range['to']
        ), ARRAY_A);

        return array_map(function ($row) {
            return [
                'month'      => $row['month'],
                'plan_id'    => (int) $row['plan_id'],
                'plan_title' => $row['plan_title'] ?? __('(Deleted Plan)', 'fchub-memberships'),
                'revenue'    => (int) $row['revenue'],
            ];
        }, $rows ?: []);
    }

    /**
     * Calculate Monthly Recurring Revenue from membership-linked subscriptions.
     * Sums the recurring_amount of active subscriptions tied to membership grants.
     *
     * @return int MRR in the smallest currency unit (cents).
     */
    public function getMRR(): int
    {
        global $wpdb;

        $mrr = $wpdb->get_var(
            "SELECT COALESCE(SUM(
                CASE s.billing_interval
                    WHEN 'yearly' THEN s.recurring_amount / 12
                    WHEN 'quarterly' THEN s.recurring_amount / 3
                    ELSE s.recurring_amount
                END
            ), 0)
             FROM {$this->grantsTable} g
             JOIN {$this->subscriptionsTable} s ON g.source_id = s.id AND g.source_type = 'subscription'
             WHERE g.status = 'active'
               AND s.status = 'active'"
        );

        return (int) round((float) $mrr);
    }

    /**
     * Get average revenue per active member.
     * Total revenue from membership orders / active member count.
     *
     * @return int Average revenue in the smallest currency unit.
     */
    public function getAverageRevenuePerMember(): int
    {
        global $wpdb;

        $activeMembers = $this->grantRepo->countActiveMembers();

        if ($activeMembers === 0) {
            return 0;
        }

        $totalRevenue = (int) $wpdb->get_var(
            "SELECT COALESCE(SUM(o.total), 0)
             FROM {$this->grantsTable} g
             JOIN {$this->ordersTable} o ON g.source_id = o.id AND g.source_type = 'order'
             WHERE g.status = 'active'"
        );

        return (int) round($totalRevenue / $activeMembers);
    }

    /**
     * Get lifetime value per plan.
     * Average total revenue per member per plan.
     *
     * @return array Array of ['plan_id' => int, 'plan_title' => string, 'ltv' => int, 'member_count' => int, 'total_revenue' => int].
     */
    public function getLifetimeValuePerPlan(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT
                g.plan_id,
                p.title AS plan_title,
                COUNT(DISTINCT g.user_id) AS member_count,
                COALESCE(SUM(o.total), 0) AS total_revenue
             FROM {$this->grantsTable} g
             JOIN {$this->ordersTable} o ON g.source_id = o.id AND g.source_type = 'order'
             LEFT JOIN {$this->plansTable} p ON g.plan_id = p.id
             WHERE g.plan_id IS NOT NULL
             GROUP BY g.plan_id, p.title
             ORDER BY total_revenue DESC",
            ARRAY_A
        );

        return array_map(function ($row) {
            $memberCount = (int) $row['member_count'];
            $totalRevenue = (int) $row['total_revenue'];
            $ltv = $memberCount > 0 ? (int) round($totalRevenue / $memberCount) : 0;

            return [
                'plan_id'       => (int) $row['plan_id'],
                'plan_title'    => $row['plan_title'] ?? __('(Deleted Plan)', 'fchub-memberships'),
                'ltv'           => $ltv,
                'member_count'  => $memberCount,
                'total_revenue' => $totalRevenue,
            ];
        }, $rows ?: []);
    }

    /**
     * Parse a period string into from/to date strings.
     */
    private function parsePeriod(string $period): array
    {
        $to = current_time('mysql');
        $amount = (int) substr($period, 0, -1);
        $unit = substr($period, -1);

        if ($unit === 'm') {
            $from = gmdate('Y-m-d H:i:s', strtotime("-{$amount} months"));
        } else {
            $from = gmdate('Y-m-d H:i:s', strtotime("-{$amount} days"));
        }

        return ['from' => $from, 'to' => $to];
    }
}

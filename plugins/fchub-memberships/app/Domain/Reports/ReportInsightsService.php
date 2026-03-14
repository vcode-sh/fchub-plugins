<?php

namespace FChubMemberships\Domain\Reports;

use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\PlanRepository;

defined('ABSPATH') || exit;

final class ReportInsightsService
{
    private GrantRepository $grants;
    private PlanRepository $plans;
    private \wpdb $wpdb;
    private string $grantsTable;
    private string $plansTable;

    public function __construct(?GrantRepository $grants = null, ?PlanRepository $plans = null, ?\wpdb $wpdb = null)
    {
        $this->grants = $grants ?? new GrantRepository();
        $this->plans = $plans ?? new PlanRepository();
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $this->grantsTable = $this->wpdb->prefix . 'fchub_membership_grants';
        $this->plansTable = $this->wpdb->prefix . 'fchub_membership_plans';
    }

    public function expiringSoon(int $days = 7, int $limit = 10): array
    {
        $items = [];
        $grants = $this->grants->getExpiringSoon($days, $limit);

        foreach ($grants as $grant) {
            $user = get_userdata($grant['user_id']);
            $planTitle = '';

            if (!empty($grant['plan_id'])) {
                $plan = $this->plans->find((int) $grant['plan_id']);
                $planTitle = $plan ? $plan['title'] : '';
            }

            $items[] = [
                'id' => $grant['id'],
                'user_id' => $grant['user_id'],
                'user_email' => $user ? $user->user_email : '',
                'user_name' => $user ? $user->display_name : '',
                'plan_id' => $grant['plan_id'] ?? null,
                'plan_title' => $planTitle,
                'expires_at' => $grant['expires_at'],
                'status' => $grant['status'],
            ];
        }

        return $items;
    }

    public function renewalRate(): array
    {
        $total = (int) $this->wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM {$this->grantsTable} WHERE status IN ('active', 'expired', 'revoked')");
        $renewed = (int) $this->wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM {$this->grantsTable} WHERE renewal_count > 0");
        $rate = $total > 0 ? round(($renewed / $total) * 100, 1) : 0;

        $avgRenewalsPerMember = 0.0;
        if ($renewed > 0) {
            $avgRenewalsPerMember = (float) $this->wpdb->get_var(
                "SELECT AVG(renewal_count) FROM {$this->grantsTable} WHERE renewal_count > 0"
            );
            $avgRenewalsPerMember = round($avgRenewalsPerMember, 1);
        }

        $byPlan = $this->wpdb->get_results(
            "SELECT g.plan_id, p.title AS plan_title, COUNT(DISTINCT g.user_id) AS total_members,
                    SUM(CASE WHEN g.renewal_count > 0 THEN 1 ELSE 0 END) AS renewed_members,
                    AVG(g.renewal_count) AS avg_renewals
             FROM {$this->grantsTable} g
             LEFT JOIN {$this->plansTable} p ON g.plan_id = p.id
             WHERE g.plan_id IS NOT NULL
             GROUP BY g.plan_id, p.title",
            ARRAY_A
        );

        $overTime = $this->wpdb->get_results(
            "SELECT DATE_FORMAT(updated_at, '%Y-%m') AS month,
                    COUNT(*) AS total_renewals
             FROM {$this->grantsTable}
             WHERE renewal_count > 0
               AND updated_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
             GROUP BY DATE_FORMAT(updated_at, '%Y-%m')
             ORDER BY month ASC",
            ARRAY_A
        );

        return [
            'overall_rate' => $rate,
            'total_members' => $total,
            'renewed_members' => $renewed,
            'avg_renewals_per_member' => $avgRenewalsPerMember,
            'by_plan' => $byPlan,
            'over_time' => $overTime,
        ];
    }

    public function trialConversion(): array
    {
        $trials = $this->wpdb->get_results(
            "SELECT g.plan_id, p.title AS plan_title,
                    COUNT(*) AS total_trials,
                    SUM(CASE WHEN g.status = 'active' AND (g.trial_ends_at IS NULL OR g.trial_ends_at < NOW()) THEN 1 ELSE 0 END) AS converted,
                    SUM(CASE WHEN g.status IN ('expired', 'revoked') THEN 1 ELSE 0 END) AS dropped
             FROM {$this->grantsTable} g
             LEFT JOIN {$this->plansTable} p ON g.plan_id = p.id
             WHERE g.trial_ends_at IS NOT NULL
             GROUP BY g.plan_id, p.title",
            ARRAY_A
        );

        $totalTrials = array_sum(array_column($trials, 'total_trials'));
        $totalConverted = array_sum(array_column($trials, 'converted'));
        $totalDropped = array_sum(array_column($trials, 'dropped'));
        $overallRate = $totalTrials > 0 ? round(($totalConverted / $totalTrials) * 100, 1) : 0;

        return [
            'overall_rate' => $overallRate,
            'total_trials' => $totalTrials,
            'total_converted' => $totalConverted,
            'total_dropped' => $totalDropped,
            'by_plan' => $trials,
        ];
    }
}

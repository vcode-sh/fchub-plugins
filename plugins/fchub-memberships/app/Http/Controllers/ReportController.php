<?php

namespace FChubMemberships\Http\Controllers;

defined('ABSPATH') || exit;

use FChubMemberships\Reports\MemberStatsReport;
use FChubMemberships\Reports\ChurnReport;
use FChubMemberships\Reports\RevenueReport;
use FChubMemberships\Reports\ContentPopularityReport;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\PlanRepository;

class ReportController
{
    public static function registerRoutes(): void
    {
        $ns = 'fchub-memberships/v1';

        register_rest_route($ns, '/admin/reports/overview', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'overview'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/reports/members-over-time', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'membersOverTime'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/reports/plan-distribution', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'planDistribution'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/reports/churn', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'churn'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/reports/retention-cohort', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'retentionCohort'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/reports/revenue', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'revenue'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/reports/content-popularity', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'contentPopularity'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/reports/expiring-soon', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'expiringSoon'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/reports/renewal-rate', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'renewalRate'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/reports/trial-conversion', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'trialConversion'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);
    }

    public static function overview(\WP_REST_Request $request): \WP_REST_Response
    {
        $report = new MemberStatsReport();
        return new \WP_REST_Response(['data' => $report->getOverview()]);
    }

    public static function membersOverTime(\WP_REST_Request $request): \WP_REST_Response
    {
        $period = $request->get_param('period') ?: '12m';
        $report = new MemberStatsReport();
        return new \WP_REST_Response(['data' => $report->getMembersOverTime($period)]);
    }

    public static function planDistribution(\WP_REST_Request $request): \WP_REST_Response
    {
        $report = new MemberStatsReport();
        return new \WP_REST_Response(['data' => $report->getPlanDistribution()]);
    }

    public static function churn(\WP_REST_Request $request): \WP_REST_Response
    {
        $period = $request->get_param('period') ?: '12m';
        $report = new ChurnReport();
        return new \WP_REST_Response([
            'data' => [
                'current_rate' => $report->getChurnRate('30d'),
                'over_time'    => $report->getChurnOverTime($period),
            ],
        ]);
    }

    public static function retentionCohort(\WP_REST_Request $request): \WP_REST_Response
    {
        $months = (int) ($request->get_param('months') ?: 6);
        $report = new ChurnReport();
        return new \WP_REST_Response(['data' => $report->getRetentionCohort($months)]);
    }

    public static function revenue(\WP_REST_Request $request): \WP_REST_Response
    {
        $period = $request->get_param('period') ?: '12m';
        $report = new RevenueReport();
        return new \WP_REST_Response([
            'data' => [
                'per_plan' => $report->getRevenuePerPlan($period),
                'mrr'      => $report->getMRR(),
                'arpm'     => $report->getAverageRevenuePerMember(),
                'ltv'      => $report->getLifetimeValuePerPlan(),
            ],
        ]);
    }

    public static function contentPopularity(\WP_REST_Request $request): \WP_REST_Response
    {
        $report = new ContentPopularityReport();
        return new \WP_REST_Response([
            'data' => [
                'most_accessed'     => $report->getMostAccessedContent(),
                'least_accessed'    => $report->getLeastAccessedContent(),
                'drip_completion'   => $report->getDripCompletionRates(),
                'plan_overlap'      => $report->getContentByPlanOverlap(),
            ],
        ]);
    }

    public static function expiringSoon(\WP_REST_Request $request): \WP_REST_Response
    {
        $days = (int) ($request->get_param('days') ?: 7);
        $limit = (int) ($request->get_param('limit') ?: 10);

        $grantRepo = new GrantRepository();
        $planRepo = new PlanRepository();
        $grants = $grantRepo->getExpiringSoon($days, $limit);

        $items = [];
        foreach ($grants as $grant) {
            $user = get_userdata($grant['user_id']);
            $planTitle = '';
            if (!empty($grant['plan_id'])) {
                $plan = $planRepo->find((int) $grant['plan_id']);
                $planTitle = $plan ? $plan['title'] : '';
            }

            $items[] = [
                'id'         => $grant['id'],
                'user_id'    => $grant['user_id'],
                'user_email' => $user ? $user->user_email : '',
                'user_name'  => $user ? $user->display_name : '',
                'plan_id'    => $grant['plan_id'] ?? null,
                'plan_title' => $planTitle,
                'expires_at' => $grant['expires_at'],
                'status'     => $grant['status'],
            ];
        }

        return new \WP_REST_Response(['data' => $items]);
    }

    public static function renewalRate(\WP_REST_Request $request): \WP_REST_Response
    {
        global $wpdb;
        $grantsTable = $wpdb->prefix . 'fchub_membership_grants';
        $plansTable = $wpdb->prefix . 'fchub_membership_plans';

        $total = (int) $wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM {$grantsTable} WHERE status IN ('active', 'expired', 'revoked')");
        $renewed = (int) $wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM {$grantsTable} WHERE renewal_count > 0");
        $rate = $total > 0 ? round(($renewed / $total) * 100, 1) : 0;

        $avgRenewalsPerMember = 0;
        if ($renewed > 0) {
            $avgRenewalsPerMember = (float) $wpdb->get_var(
                "SELECT AVG(renewal_count) FROM {$grantsTable} WHERE renewal_count > 0"
            );
            $avgRenewalsPerMember = round($avgRenewalsPerMember, 1);
        }

        $byPlan = $wpdb->get_results(
            "SELECT g.plan_id, p.title AS plan_title, COUNT(DISTINCT g.user_id) AS total_members,
                    SUM(CASE WHEN g.renewal_count > 0 THEN 1 ELSE 0 END) AS renewed_members,
                    AVG(g.renewal_count) AS avg_renewals
             FROM {$grantsTable} g
             LEFT JOIN {$plansTable} p ON g.plan_id = p.id
             WHERE g.plan_id IS NOT NULL
             GROUP BY g.plan_id, p.title",
            ARRAY_A
        );

        // Renewal rate over time (monthly)
        $overTime = $wpdb->get_results(
            "SELECT DATE_FORMAT(updated_at, '%Y-%m') AS month,
                    COUNT(*) AS total_renewals
             FROM {$grantsTable}
             WHERE renewal_count > 0
               AND updated_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
             GROUP BY DATE_FORMAT(updated_at, '%Y-%m')
             ORDER BY month ASC",
            ARRAY_A
        );

        return new \WP_REST_Response(['data' => [
            'overall_rate'           => $rate,
            'total_members'          => $total,
            'renewed_members'        => $renewed,
            'avg_renewals_per_member' => $avgRenewalsPerMember,
            'by_plan'                => $byPlan,
            'over_time'              => $overTime,
        ]]);
    }

    public static function trialConversion(\WP_REST_Request $request): \WP_REST_Response
    {
        global $wpdb;
        $grantsTable = $wpdb->prefix . 'fchub_membership_grants';
        $plansTable = $wpdb->prefix . 'fchub_membership_plans';

        $trials = $wpdb->get_results(
            "SELECT g.plan_id, p.title AS plan_title,
                    COUNT(*) AS total_trials,
                    SUM(CASE WHEN g.status = 'active' AND (g.trial_ends_at IS NULL OR g.trial_ends_at < NOW()) THEN 1 ELSE 0 END) AS converted,
                    SUM(CASE WHEN g.status IN ('expired', 'revoked') THEN 1 ELSE 0 END) AS dropped
             FROM {$grantsTable} g
             LEFT JOIN {$plansTable} p ON g.plan_id = p.id
             WHERE g.trial_ends_at IS NOT NULL
             GROUP BY g.plan_id, p.title",
            ARRAY_A
        );

        $totalTrials = array_sum(array_column($trials, 'total_trials'));
        $totalConverted = array_sum(array_column($trials, 'converted'));
        $totalDropped = array_sum(array_column($trials, 'dropped'));
        $overallRate = $totalTrials > 0 ? round(($totalConverted / $totalTrials) * 100, 1) : 0;

        return new \WP_REST_Response(['data' => [
            'overall_rate'    => $overallRate,
            'total_trials'    => $totalTrials,
            'total_converted' => $totalConverted,
            'total_dropped'   => $totalDropped,
            'by_plan'         => $trials,
        ]]);
    }

    public static function adminPermission(): bool
    {
        return current_user_can('manage_options');
    }
}

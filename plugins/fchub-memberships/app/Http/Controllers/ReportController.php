<?php

namespace FChubMemberships\Http\Controllers;

defined('ABSPATH') || exit;

use FChubMemberships\Reports\MemberStatsReport;
use FChubMemberships\Reports\ChurnReport;
use FChubMemberships\Reports\RevenueReport;
use FChubMemberships\Reports\ContentPopularityReport;
use FChubMemberships\Domain\Reports\ReportInsightsService;

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

        // FluentCart stores amounts in cents — convert to whole currency units for display
        $perPlan = array_map(function ($row) {
            $row['revenue'] = round($row['revenue'] / 100, 2);
            return $row;
        }, $report->getRevenuePerPlan($period));

        $ltv = array_map(function ($row) {
            $row['ltv'] = round($row['ltv'] / 100, 2);
            $row['total_revenue'] = round($row['total_revenue'] / 100, 2);
            return $row;
        }, $report->getLifetimeValuePerPlan());

        return new \WP_REST_Response([
            'data' => [
                'per_plan' => $perPlan,
                'mrr'      => round($report->getMRR() / 100, 2),
                'arpm'     => round($report->getAverageRevenuePerMember() / 100, 2),
                'ltv'      => $ltv,
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
        return new \WP_REST_Response(['data' => (new ReportInsightsService())->expiringSoon($days, $limit)]);
    }

    public static function renewalRate(\WP_REST_Request $request): \WP_REST_Response
    {
        return new \WP_REST_Response(['data' => (new ReportInsightsService())->renewalRate()]);
    }

    public static function trialConversion(\WP_REST_Request $request): \WP_REST_Response
    {
        return new \WP_REST_Response(['data' => (new ReportInsightsService())->trialConversion()]);
    }

    public static function adminPermission(): bool
    {
        return current_user_can('manage_options');
    }
}

<?php

namespace FChubMemberships\Http\Controllers;

defined('ABSPATH') || exit;

use FChubMemberships\Domain\Drip\DripScheduleService;
use FChubMemberships\Domain\Drip\DripEvaluator;

class DripController
{
    public static function registerRoutes(): void
    {
        $ns = 'fchub-memberships/v1';

        register_rest_route($ns, '/admin/drip/overview', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'overview'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/drip/calendar', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'calendar'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/drip/notifications', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'notifications'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/drip/notifications/(?P<id>\d+)/retry', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'retry'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/drip/stats', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'stats'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);
    }

    public static function overview(\WP_REST_Request $request): \WP_REST_Response
    {
        $service = new DripScheduleService();
        $ruleRepo = new \FChubMemberships\Storage\PlanRuleRepository();
        $dripRepo = new \FChubMemberships\Storage\DripScheduleRepository();

        global $wpdb;
        $table = $wpdb->prefix . 'fchub_membership_drip_notifications';

        // Count total drip rules across all plans
        $planRepo = new \FChubMemberships\Storage\PlanRepository();
        $plans = $planRepo->getActivePlans();
        $totalRules = 0;
        foreach ($plans as $plan) {
            $totalRules += count($ruleRepo->getDripRules($plan['id']));
        }

        $pending = $dripRepo->countPending();

        $sentToday = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE status = 'sent' AND DATE(sent_at) = %s",
            current_time('Y-m-d')
        ));

        $failed = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} WHERE status = 'failed'"
        );

        return new \WP_REST_Response([
            'data' => [
                'total_rules' => $totalRules,
                'pending'     => $pending,
                'sent_today'  => $sentToday,
                'failed'      => $failed,
            ],
        ]);
    }

    public static function calendar(\WP_REST_Request $request): \WP_REST_Response
    {
        $from = $request->get_param('from') ?: gmdate('Y-m-01');
        $to = $request->get_param('to') ?: gmdate('Y-m-t');

        $service = new DripScheduleService();
        return new \WP_REST_Response(['data' => $service->getCalendar($from, $to)]);
    }

    public static function notifications(\WP_REST_Request $request): \WP_REST_Response
    {
        $service = new DripScheduleService();
        $filters = [
            'status'   => $request->get_param('status'),
            'user_id'  => $request->get_param('user_id'),
            'per_page' => $request->get_param('per_page') ?: 20,
            'page'     => $request->get_param('page') ?: 1,
        ];

        $items = $service->getNotificationQueue($filters);
        $ruleRepo = new \FChubMemberships\Storage\PlanRuleRepository();

        // Enrich with user email and content title
        foreach ($items as &$item) {
            $user = get_userdata($item['user_id']);
            $item['user_email'] = $user ? $user->user_email : "User #{$item['user_id']}";

            $rule = $ruleRepo->find($item['plan_rule_id']);
            if ($rule) {
                $item['content_title'] = self::getResourceTitle($rule['resource_type'], $rule['resource_id']);
            } else {
                $item['content_title'] = "Rule #{$item['plan_rule_id']}";
            }

            $item['scheduled_at'] = $item['notify_at'] ?? null;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'fchub_membership_drip_notifications';

        $where = ['1=1'];
        $params = [];
        if (!empty($filters['status'])) {
            $where[] = 'status = %s';
            $params[] = $filters['status'];
        }
        if (!empty($filters['user_id'])) {
            $where[] = 'user_id = %d';
            $params[] = (int) $filters['user_id'];
        }
        $countSql = "SELECT COUNT(*) FROM {$table} WHERE " . implode(' AND ', $where);
        $total = $params
            ? (int) $wpdb->get_var($wpdb->prepare($countSql, ...$params))
            : (int) $wpdb->get_var($countSql);

        return new \WP_REST_Response([
            'data'  => $items,
            'total' => $total,
        ]);
    }

    private static function getResourceTitle(string $type, string $id): string
    {
        if (in_array($type, ['post', 'page'], true) || post_type_exists($type)) {
            return get_the_title((int) $id) ?: "#{$id}";
        }

        if (taxonomy_exists($type)) {
            $term = get_term((int) $id);
            return ($term && !is_wp_error($term)) ? $term->name : "#{$id}";
        }

        return $type . ' #' . $id;
    }

    public static function retry(\WP_REST_Request $request): \WP_REST_Response
    {
        $service = new DripScheduleService();
        $result = $service->retry((int) $request->get_param('id'));

        if (!$result) {
            return new \WP_REST_Response(['message' => __('Unable to retry notification.', 'fchub-memberships')], 422);
        }

        return new \WP_REST_Response(['message' => __('Notification resent.', 'fchub-memberships')]);
    }

    public static function stats(\WP_REST_Request $request): \WP_REST_Response
    {
        $service = new DripScheduleService();
        return new \WP_REST_Response(['data' => $service->getQueueStats()]);
    }

    public static function adminPermission(): bool
    {
        return current_user_can('manage_options');
    }
}

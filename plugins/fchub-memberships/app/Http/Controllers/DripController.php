<?php

namespace FChubMemberships\Http\Controllers;

defined('ABSPATH') || exit;

use FChubMemberships\Domain\Drip\DripScheduleService;
use FChubMemberships\Domain\Drip\DripEvaluator;
use FChubMemberships\Domain\Drip\DripAdminQueryService;

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
        return new \WP_REST_Response([
            'data' => (new DripAdminQueryService())->overview(),
        ]);
    }

    public static function calendar(\WP_REST_Request $request): \WP_REST_Response
    {
        $from = sanitize_text_field((string) ($request->get_param('from') ?: ''));
        $to = sanitize_text_field((string) ($request->get_param('to') ?: ''));

        if ($from === '' || $to === '') {
            $year = (int) ($request->get_param('year') ?: gmdate('Y'));
            $month = (int) ($request->get_param('month') ?: gmdate('n'));
            $month = max(1, min(12, $month));
            $from = gmdate('Y-m-01 00:00:00', strtotime(sprintf('%04d-%02d-01', $year, $month)));
            $to = gmdate('Y-m-t 23:59:59', strtotime($from));
        }

        $service = new DripScheduleService();
        $items = $service->getCalendar($from, $to);
        $calendar = [];

        foreach ($items as $item) {
            $dateKey = gmdate('Y-m-d', strtotime($item['notify_at']));
            $calendar[$dateKey] = ($calendar[$dateKey] ?? 0) + 1;
        }

        return new \WP_REST_Response(['data' => $calendar]);
    }

    public static function notifications(\WP_REST_Request $request): \WP_REST_Response
    {
        $service = new DripScheduleService();
        $filters = [
            'status'   => $request->get_param('status'),
            'user_id'  => $request->get_param('user_id'),
            'date'     => $request->get_param('date'),
            'per_page' => $request->get_param('per_page') ?: 20,
            'page'     => $request->get_param('page') ?: 1,
        ];

        $items = $service->getNotificationQueue($filters);
        $ruleRepo = new \FChubMemberships\Storage\PlanRuleRepository();
        $planRepo = new \FChubMemberships\Storage\PlanRepository();

        // Enrich with user email and content title
        foreach ($items as &$item) {
            $user = get_userdata($item['user_id']);
            $item['user_email'] = $user ? $user->user_email : "User #{$item['user_id']}";

            $rule = $ruleRepo->find($item['plan_rule_id']);
            if ($rule) {
                $item['content_title'] = self::getResourceTitle($rule['resource_type'], $rule['resource_id']);
                $plan = !empty($rule['plan_id']) ? $planRepo->find((int) $rule['plan_id']) : null;
                $item['plan_title'] = $plan ? $plan['title'] : '';
            } else {
                $item['content_title'] = "Rule #{$item['plan_rule_id']}";
                $item['plan_title'] = '';
            }

            $item['scheduled_at'] = $item['notify_at'] ?? null;
        }

        $total = (new DripAdminQueryService())->notificationsTotal($filters);

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

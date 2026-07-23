<?php

namespace FChubMemberships\Http;

defined('ABSPATH') || exit;

use FChubMemberships\Domain\AccessEvaluator;

class AccessCheckController
{
    public static function registerRoutes(): void
    {
        $ns = 'fchub-memberships/v1';

        register_rest_route($ns, '/check-access', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'check'],
            'permission_callback' => [self::class, 'checkPermission'],
            'args'                => AccessCheckRestArguments::all(),
        ]);

        add_filter('rest_post_dispatch', [self::class, 'addRateLimitHeaders'], 10, 3);
    }

    public static function check(\WP_REST_Request $request): \WP_REST_Response
    {
        $validation = AccessCheckRestArguments::validateRequest($request);
        if ($validation instanceof \WP_Error) {
            return new \WP_REST_Response([
                'code' => $validation->get_error_code(),
                'message' => $validation->get_error_message(),
            ], (int) ($validation->get_error_data()['status'] ?? 422));
        }

        $userId = (int) $request->get_param('user_id');
        $email = $request->get_param('email');
        $resourceType = $request->get_param('resource_type');
        $resourceId = $request->get_param('resource_id');
        $planSlug = $request->get_param('plan');
        $provider = $request->get_param('provider') ?: 'wordpress_core';

        // Resolve user
        if (!$userId && $email) {
            $user = get_user_by('email', $email);
            $userId = $user ? $user->ID : 0;
        }

        // Self-check for authenticated users
        if (!$userId && is_user_logged_in()) {
            $userId = get_current_user_id();
        }

        if (!$userId) {
            return new \WP_REST_Response(['message' => __('User not found.', 'fchub-memberships')], 404);
        }

        $evaluator = new AccessEvaluator();

        // Check by plan slug
        if ($planSlug) {
            $planService = new \FChubMemberships\Domain\Plan\PlanService();
            $plan = $planService->findBySlug(sanitize_text_field($planSlug));

            if (!$plan) {
                return new \WP_REST_Response(['message' => __('Plan not found.', 'fchub-memberships')], 404);
            }

            $grantRepo = new \FChubMemberships\Storage\GrantRepository();
            $grants = $grantRepo->getEffectivePlanMembershipsForUserByPlan($userId, (int) $plan['id']);
            $hasAccess = !empty($grants);

            $progress = $hasAccess ? $evaluator->getDripProgress($userId, $plan['id']) : null;

            return new \WP_REST_Response([
                'has_access'  => $hasAccess,
                'reason'      => $hasAccess ? 'active_grant' : 'no_active_grant',
                'plan'        => $plan['slug'],
                'grants'      => array_map([self::class, 'projectGrant'], $grants),
                'drip_status' => $progress,
            ]);
        }

        // Check by resource
        if ($resourceType && $resourceId) {
            $result = $evaluator->evaluate($userId, $provider, sanitize_text_field($resourceType), sanitize_text_field($resourceId));

            return new \WP_REST_Response([
                'has_access'       => $result['allowed'],
                'reason'           => $result['reason'],
                'drip_locked'      => $result['drip_locked'],
                'drip_available_at' => $result['drip_available_at'],
                'grant'            => self::projectGrant($result['grant'] ?? null),
            ]);
        }

        return new \WP_REST_Response(['message' => __('Specify either plan slug or resource_type + resource_id.', 'fchub-memberships')], 422);
    }

    public static function checkPermission(\WP_REST_Request $request): bool|\WP_Error
    {
        // Admin can check any user
        if (current_user_can('manage_options')) {
            return true;
        }

        // Authenticated user can check themselves
        if (is_user_logged_in()) {
            $userId = (int) $request->get_param('user_id');
            $email = $request->get_param('email');

            if (!$userId && !$email) {
                return true; // Self-check
            }

            if ($userId && $userId === get_current_user_id()) {
                return true;
            }

            if ($email) {
                $currentUser = wp_get_current_user();
                return $currentUser && $currentUser->user_email === $email;
            }
        }

        $provided = trim((string) $request->get_header('X-API-Key'));
        if ($provided === '') {
            return false;
        }

        $settings = get_option('fchub_memberships_settings', []);
        if (!AccessApiCredential::verify($provided, $settings)) {
            return false;
        }

        $prefix = (string) ($settings['access_api_key_prefix'] ?? '');
        if ($prefix === '') {
            $prefix = substr(hash('sha256', $provided), 0, 12);
        }
        $limit = (new AccessApiRateLimiter())->consume($prefix);
        if (!$limit['allowed']) {
            return new \WP_Error(
                'fchub_access_rate_limited',
                __('Access API rate limit exceeded.', 'fchub-memberships'),
                [
                    'status' => 429,
                    'retry_after' => $limit['retry_after'],
                    'limit' => $limit['limit'],
                    'remaining' => 0,
                ]
            );
        }

        return true;

    }

    public static function addRateLimitHeaders(mixed $response, mixed $server, \WP_REST_Request $request): mixed
    {
        if ($request->get_route() !== '/fchub-memberships/v1/check-access'
            || !is_object($response)
            || !method_exists($response, 'get_status')
            || !method_exists($response, 'get_data')
            || !method_exists($response, 'header')
            || $response->get_status() !== 429
        ) {
            return $response;
        }

        $data = $response->get_data();
        if (!is_array($data) || ($data['code'] ?? '') !== 'fchub_access_rate_limited') {
            return $response;
        }

        $retryAfter = (int) ($data['data']['retry_after'] ?? 0);
        if ($retryAfter > 0) {
            $response->header('Retry-After', (string) $retryAfter);
        }

        return $response;
    }

    private static function projectGrant(?array $grant): ?array
    {
        if ($grant === null) {
            return null;
        }

        return [
            'id' => $grant['id'] ?? null,
            'plan_id' => $grant['plan_id'] ?? null,
            'status' => $grant['status'] ?? null,
            'starts_at' => $grant['starts_at'] ?? null,
            'expires_at' => $grant['expires_at'] ?? null,
            'drip_available_at' => $grant['drip_available_at'] ?? null,
            'resource_type' => $grant['resource_type'] ?? null,
            'resource_id' => $grant['resource_id'] ?? null,
        ];

    }
}

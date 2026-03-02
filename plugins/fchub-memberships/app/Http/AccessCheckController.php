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
        ]);
    }

    public static function check(\WP_REST_Request $request): \WP_REST_Response
    {
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
            $grants = $grantRepo->getByUserId($userId, ['plan_id' => $plan['id'], 'status' => 'active']);
            $hasAccess = !empty($grants);

            $progress = $hasAccess ? $evaluator->getDripProgress($userId, $plan['id']) : null;

            return new \WP_REST_Response([
                'has_access'  => $hasAccess,
                'plan'        => $plan['slug'],
                'grants'      => $grants,
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
                'grant'            => $result['grant'],
            ]);
        }

        return new \WP_REST_Response(['message' => __('Specify either plan slug or resource_type + resource_id.', 'fchub-memberships')], 422);
    }

    public static function checkPermission(\WP_REST_Request $request): bool
    {
        // Admin can check any user
        if (current_user_can('manage_options')) {
            return true;
        }

        // API key authentication
        $settings = get_option('fchub_memberships_settings', []);
        $apiKey = $settings['api_key'] ?? '';

        if ($apiKey) {
            $providedKey = $request->get_header('X-API-Key') ?: $request->get_param('api_key');
            if ($providedKey && hash_equals($apiKey, $providedKey)) {
                return true;
            }
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

        return false;
    }
}

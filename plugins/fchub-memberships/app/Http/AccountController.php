<?php

namespace FChubMemberships\Http;

defined('ABSPATH') || exit;

use FChubMemberships\Domain\AccessEvaluator;
use FChubMemberships\Domain\Drip\DripEvaluator;
use FChubMemberships\Storage\GrantRepository;

class AccountController
{
    public static function registerRoutes(): void
    {
        $ns = 'fchub-memberships/v1';

        register_rest_route($ns, '/my-access', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'myAccess'],
            'permission_callback' => 'is_user_logged_in',
        ]);
    }

    public static function myAccess(\WP_REST_Request $request): \WP_REST_Response
    {
        $userId = get_current_user_id();
        $repo = new GrantRepository();
        $evaluator = new AccessEvaluator();
        $dripEvaluator = new DripEvaluator();
        $planRepo = new \FChubMemberships\Storage\PlanRepository();

        $grouped = $repo->getActiveByUserGroupedByPlan($userId);
        $plans = [];

        foreach ($grouped as $planId => $grants) {
            $plan = $planId ? $planRepo->find($planId) : null;
            $progress = $planId ? $evaluator->getDripProgress($userId, $planId) : null;
            $timeline = $planId ? $dripEvaluator->getTimeline($userId, $planId) : [];

            // Determine earliest expiry in grants
            $expiresAt = null;
            foreach ($grants as $grant) {
                if ($grant['expires_at']) {
                    if (!$expiresAt || strtotime($grant['expires_at']) < strtotime($expiresAt)) {
                        $expiresAt = $grant['expires_at'];
                    }
                }
            }

            $plans[] = [
                'plan_id'     => $planId,
                'plan_title'  => $plan ? $plan['title'] : __('Direct Access', 'fchub-memberships'),
                'plan_slug'   => $plan ? $plan['slug'] : null,
                'description' => $plan ? $plan['description'] : '',
                'status'      => 'active',
                'expires_at'  => $expiresAt,
                'is_lifetime' => $expiresAt === null,
                'progress'    => $progress,
                'timeline'    => $timeline,
                'grant_count' => count($grants),
            ];
        }

        // Get history (revoked/expired)
        $allGrants = $repo->getByUserId($userId);
        $history = array_filter($allGrants, function ($g) {
            return $g['status'] !== 'active';
        });

        $history = array_map(function ($entry) use ($planRepo) {
            $plan = $entry['plan_id'] ? $planRepo->find($entry['plan_id']) : null;
            $entry['plan_title'] = $plan ? $plan['title'] : __('Direct Access', 'fchub-memberships');
            return $entry;
        }, $history);

        return new \WP_REST_Response([
            'plans'   => $plans,
            'history' => array_values($history),
        ]);
    }
}

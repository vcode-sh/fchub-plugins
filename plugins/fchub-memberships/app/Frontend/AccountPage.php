<?php

namespace FChubMemberships\Frontend;

defined('ABSPATH') || exit;

use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Storage\PlanRuleRepository;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Adapters\WordPressContentAdapter;

class AccountPage
{
    private static string $shieldSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M3.78307 2.82598L12 1L20.2169 2.82598C20.6745 2.92766 21 3.33347 21 3.80217V13.7889C21 15.795 19.9974 17.6684 18.3282 18.7812L12 23L5.6718 18.7812C4.00261 17.6684 3 15.795 3 13.7889V3.80217C3 3.33347 3.32553 2.92766 3.78307 2.82598ZM5 4.60434V13.7889C5 15.1263 5.6684 16.3752 6.7812 17.1171L12 20.5963L17.2188 17.1171C18.3316 16.3752 19 15.1263 19 13.7889V4.60434L12 3.04879L5 4.60434ZM11 10.5858L8.46447 8.05025L7.05025 9.46447L11 13.4142L16.6569 7.75736L15.2426 6.34315L11 10.5858Z"></path></svg>';

    public static function register(): void
    {
        if (!function_exists('fluent_cart_api')) {
            return;
        }

        fluent_cart_api()->addCustomerDashboardEndpoint('memberships', [
            'title'           => __('Memberships', 'fchub-memberships'),
            'icon_svg'        => self::$shieldSvg,
            'render_callback' => [self::class, 'renderAccountSection'],
        ]);
    }

    /**
     * Render the "My Memberships" section in FluentCart customer portal.
     */
    public static function renderAccountSection(): void
    {
        if (!is_user_logged_in()) {
            return;
        }

        echo Shortcodes::renderMyMemberships([]);
    }

    /**
     * Get all membership data for a user.
     *
     * @return array{plans: array, history: array}
     */
    public static function getAccessData(int $userId): array
    {
        $grantRepo = new GrantRepository();
        $planRepo = new PlanRepository();
        $ruleRepo = new PlanRuleRepository();
        $adapter = new WordPressContentAdapter();
        $now = current_time('mysql');

        // Get active grants grouped by plan
        $grouped = $grantRepo->getActiveByUserGroupedByPlan($userId);
        $plans = [];

        foreach ($grouped as $planId => $grants) {
            $plan = $planId ? $planRepo->find($planId) : null;
            $planTitle = $plan ? $plan['title'] : __('Individual Access', 'fchub-memberships');

            // Determine plan-level expiry (latest expiry among grants)
            $expiresAt = null;
            foreach ($grants as $grant) {
                if (!empty($grant['expires_at'])) {
                    if ($expiresAt === null || $grant['expires_at'] > $expiresAt) {
                        $expiresAt = $grant['expires_at'];
                    }
                }
            }

            // Calculate drip progress
            $totalItems = count($grants);
            $unlockedItems = 0;
            $contentItems = [];

            foreach ($grants as $grant) {
                $isLocked = !empty($grant['drip_available_at']) && $grant['drip_available_at'] > $now;

                if (!$isLocked) {
                    $unlockedItems++;
                }

                $title = $adapter->supports($grant['resource_type'])
                    ? $adapter->getResourceLabel($grant['resource_type'], $grant['resource_id'])
                    : sprintf('%s #%s', $grant['resource_type'], $grant['resource_id']);

                $url = null;
                if (!$isLocked && in_array($grant['resource_type'], ['post', 'page'], true)) {
                    $url = get_permalink((int) $grant['resource_id']);
                } elseif (!$isLocked && strpos($grant['resource_type'], 'custom_post_type:') === 0) {
                    $url = get_permalink((int) $grant['resource_id']);
                }

                $contentItems[] = [
                    'title'        => $title,
                    'resource_type' => $grant['resource_type'],
                    'resource_id'  => $grant['resource_id'],
                    'is_locked'    => $isLocked,
                    'available_at' => $isLocked ? $grant['drip_available_at'] : null,
                    'url'          => $url,
                ];
            }

            $hasDrip = $totalItems > $unlockedItems;

            $planData = [
                'id'         => $planId,
                'title'      => $planTitle,
                'slug'       => $plan ? $plan['slug'] : '',
                'expires_at' => $expiresAt,
                'content_items' => $contentItems,
            ];

            if ($hasDrip || $totalItems > 0) {
                $planData['drip_progress'] = [
                    'unlocked' => $unlockedItems,
                    'total'    => $totalItems,
                ];
            }

            $plans[] = $planData;
        }

        // Build history (expired/revoked grants)
        $allGrants = $grantRepo->getByUserId($userId);
        $history = [];

        foreach ($allGrants as $grant) {
            if (in_array($grant['status'], ['expired', 'revoked'], true)) {
                $plan = $grant['plan_id'] ? $planRepo->find($grant['plan_id']) : null;

                $history[] = [
                    'plan_title' => $plan ? $plan['title'] : __('Individual Access', 'fchub-memberships'),
                    'status'     => $grant['status'],
                    'date'       => $grant['updated_at'] ?? $grant['created_at'],
                ];
            }
        }

        return [
            'plans'   => $plans,
            'history' => $history,
        ];
    }
}

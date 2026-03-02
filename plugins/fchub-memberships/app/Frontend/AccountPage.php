<?php

namespace FChubMemberships\Frontend;

defined('ABSPATH') || exit;

use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Storage\PlanRuleRepository;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Adapters\WordPressContentAdapter;

class AccountPage
{
    public static function register(): void
    {
        // Hook into FluentCart customer portal if available
        if (defined('FLUENTCART_VERSION')) {
            add_filter('fluent_cart/customer_portal/sections', [self::class, 'addPortalSection']);
            add_action('fluent_cart/customer_portal/render_section/memberships', [self::class, 'renderAccountSection']);
        }
    }

    /**
     * Add "Memberships" section to the FluentCart customer portal.
     */
    public static function addPortalSection(array $sections): array
    {
        $sections['memberships'] = [
            'title'    => __('Memberships', 'fchub-memberships'),
            'icon'     => 'shield-check',
            'priority' => 30,
        ];

        return $sections;
    }

    /**
     * Render the "My Memberships" section in FluentCart customer portal.
     */
    public static function renderAccountSection(): void
    {
        wp_enqueue_style('fchub-memberships-frontend');

        if (!is_user_logged_in()) {
            return;
        }

        // Reuse the shortcode output
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

<?php

namespace FChubMemberships\Frontend;

defined('ABSPATH') || exit;

use FChubMemberships\Domain\ContentProtection;
use FChubMemberships\Frontend\FrontendAssets;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Storage\PlanRuleRepository;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\ProtectionRuleRepository;
use FChubMemberships\Adapters\WordPressContentAdapter;

class Shortcodes
{
    public static function register(): void
    {
        add_shortcode('fchub_restrict', [self::class, 'renderRestrict']);
        add_shortcode('fchub_membership_status', [self::class, 'renderMembershipStatus']);
        add_shortcode('fchub_drip_progress', [self::class, 'renderDripProgress']);
        add_shortcode('fchub_my_memberships', [self::class, 'renderMyMemberships']);

        add_action('wp_enqueue_scripts', [self::class, 'enqueueAssets']);
    }

    public static function enqueueAssets(): void
    {
        wp_register_style(
            'fchub-memberships-frontend',
            FCHUB_MEMBERSHIPS_URL . 'assets/css/frontend.css',
            [],
            FCHUB_MEMBERSHIPS_VERSION
        );
    }

    /**
     * [fchub_restrict] - Wrapping shortcode that restricts inner content.
     */
    public static function renderRestrict(array $atts, ?string $content = null): string
    {
        wp_enqueue_style('fchub-memberships-frontend');

        $atts = shortcode_atts([
            'plan'          => '',
            'resource_type' => '',
            'resource_id'   => '',
            'message'       => '',
            'show_login'    => 'yes',
            'drip_message'  => '',
        ], $atts, 'fchub_restrict');

        // Not logged in
        if (!is_user_logged_in()) {
            return self::renderRestrictionMessage(
                $atts['message'] ?: self::getDefaultMessage('logged_out'),
                $atts['show_login'] === 'yes'
            );
        }

        $userId = get_current_user_id();
        $resourceType = $atts['resource_type'];
        $resourceId = $atts['resource_id'];

        // Default to current post if no resource specified
        if (empty($resourceType) || empty($resourceId)) {
            $post = get_post();
            if ($post) {
                $resourceType = $post->post_type;
                $resourceId = (string) $post->ID;
            }
        }

        // Check plan-based restriction
        $planSlugs = array_filter(array_map('trim', explode(',', $atts['plan'])));

        if (!empty($planSlugs)) {
            $result = self::checkPlanAccess($userId, $planSlugs, $resourceType, $resourceId);
        } else {
            $result = self::checkResourceAccess($userId, $resourceType, $resourceId);
        }

        if ($result['status'] === 'granted') {
            return do_shortcode($content ?? '');
        }

        if ($result['status'] === 'drip_locked') {
            $dripMessage = $atts['drip_message'] ?: self::getDefaultMessage('drip_locked');
            $dripMessage = str_replace(
                '{date}',
                wp_date(get_option('date_format'), strtotime($result['drip_available_at'])),
                $dripMessage
            );
            return self::renderRestrictionMessage($dripMessage, false, 'drip');
        }

        // Access denied
        return self::renderRestrictionMessage(
            $atts['message'] ?: self::getDefaultMessage('restricted'),
            false
        );
    }

    /**
     * [fchub_membership_status] - Shows current user's membership status.
     */
    public static function renderMembershipStatus(array $atts): string
    {
        wp_enqueue_style('fchub-memberships-frontend');

        $atts = shortcode_atts([
            'display' => 'compact',
        ], $atts, 'fchub_membership_status');

        if (!is_user_logged_in()) {
            return '';
        }

        $userId = get_current_user_id();
        $data = AccountPage::getAccessData($userId);

        if (empty($data['plans'])) {
            return '<div class="fchub-membership-status fchub-membership-status--empty">'
                . '<p>' . esc_html__('You do not have any active memberships.', 'fchub-memberships') . '</p>'
                . '</div>';
        }

        $html = '<div class="fchub-membership-status fchub-membership-status--' . esc_attr($atts['display']) . '">';

        foreach ($data['plans'] as $plan) {
            $html .= '<div class="fchub-plan-item">';
            $html .= '<span class="fchub-plan-badge">' . esc_html($plan['title']) . '</span>';

            if ($atts['display'] === 'full') {
                if (!empty($plan['expires_at'])) {
                    $html .= '<span class="fchub-plan-expiry">';
                    $html .= sprintf(
                        esc_html__('Expires: %s', 'fchub-memberships'),
                        wp_date(get_option('date_format'), strtotime($plan['expires_at']))
                    );
                    $html .= '</span>';
                } else {
                    $html .= '<span class="fchub-plan-expiry">'
                        . esc_html__('Lifetime access', 'fchub-memberships')
                        . '</span>';
                }

                if (isset($plan['drip_progress'])) {
                    $html .= self::buildProgressBar(
                        $plan['drip_progress']['unlocked'],
                        $plan['drip_progress']['total']
                    );
                }
            }

            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * [fchub_drip_progress] - Shows drip progress bar for a plan.
     */
    public static function renderDripProgress(array $atts): string
    {
        wp_enqueue_style('fchub-memberships-frontend');

        $atts = shortcode_atts([
            'plan' => '',
        ], $atts, 'fchub_drip_progress');

        if (!is_user_logged_in() || empty($atts['plan'])) {
            return '';
        }

        $userId = get_current_user_id();
        $planRepo = new PlanRepository();
        $plan = $planRepo->findBySlug($atts['plan']);

        if (!$plan) {
            return '';
        }

        $grantRepo = new GrantRepository();
        $grants = $grantRepo->getByUserId($userId, ['plan_id' => $plan['id'], 'status' => 'active']);

        if (empty($grants)) {
            return '';
        }

        $now = current_time('mysql');
        $total = count($grants);
        $unlocked = 0;

        foreach ($grants as $grant) {
            if (empty($grant['drip_available_at']) || $grant['drip_available_at'] <= $now) {
                $unlocked++;
            }
        }

        return self::buildProgressBar($unlocked, $total, $plan['title']);
    }

    /**
     * [fchub_my_memberships] - Full membership account page.
     */
    public static function renderMyMemberships(array $atts): string
    {
        if (!is_user_logged_in()) {
            wp_enqueue_style('fchub-memberships-frontend');

            return self::renderRestrictionMessage(
                self::getDefaultMessage('logged_out'),
                true
            );
        }

        FrontendAssets::enqueue();

        return '<div id="fchub-membership-portal"></div>';
    }

    /**
     * Check whether the user has access via specific plan slugs.
     */
    private static function checkPlanAccess(int $userId, array $planSlugs, string $resourceType, string $resourceId): array
    {
        $planRepo = new PlanRepository();
        $grantRepo = new GrantRepository();
        $now = current_time('mysql');

        foreach ($planSlugs as $slug) {
            $plan = $planRepo->findBySlug($slug);
            if (!$plan || $plan['status'] !== 'active') {
                continue;
            }

            $grants = $grantRepo->getByUserId($userId, ['plan_id' => $plan['id'], 'status' => 'active']);

            foreach ($grants as $grant) {
                // Skip if starts_at is in the future
                if (!empty($grant['starts_at']) && $grant['starts_at'] > $now) {
                    continue;
                }
                // Skip if expired
                if (!empty($grant['expires_at']) && $grant['expires_at'] <= $now) {
                    continue;
                }

                // If checking a specific resource, match it
                if (!empty($resourceType) && !empty($resourceId)) {
                    if ($grant['resource_type'] !== $resourceType || $grant['resource_id'] !== $resourceId) {
                        continue;
                    }
                }

                // Check drip availability
                if (!empty($grant['drip_available_at']) && $grant['drip_available_at'] > $now) {
                    return [
                        'status'           => 'drip_locked',
                        'drip_available_at' => $grant['drip_available_at'],
                    ];
                }

                return ['status' => 'granted'];
            }

            // If no specific resource check, having any active grant for this plan is enough
            if ((empty($resourceType) || empty($resourceId)) && !empty($grants)) {
                return ['status' => 'granted'];
            }
        }

        return ['status' => 'denied'];
    }

    /**
     * Check whether the user has access to a specific resource (any plan).
     */
    private static function checkResourceAccess(int $userId, string $resourceType, string $resourceId): array
    {
        if (empty($resourceType) || empty($resourceId)) {
            return ['status' => 'denied'];
        }

        $grantRepo = new GrantRepository();
        $now = current_time('mysql');

        // Check for a direct active grant
        $grant = $grantRepo->getActiveGrant($userId, 'wordpress_core', $resourceType, $resourceId);

        if ($grant) {
            // Check drip
            if (!empty($grant['drip_available_at']) && $grant['drip_available_at'] > $now) {
                return [
                    'status'           => 'drip_locked',
                    'drip_available_at' => $grant['drip_available_at'],
                ];
            }
            return ['status' => 'granted'];
        }

        return ['status' => 'denied'];
    }

    private static function renderRestrictionMessage(string $message, bool $showLogin, string $type = 'restricted'): string
    {
        // Try to use ContentProtection's renderRestrictionBlock for current post context
        $post = get_post();
        if ($post) {
            $protectionRepo = new ProtectionRuleRepository();
            $rule = $protectionRepo->findByResource($post->post_type, (string) $post->ID);
            if ($rule) {
                $context = $showLogin && !is_user_logged_in() ? 'logged_out' : 'no_access';
                $contentProtection = new ContentProtection();
                return $contentProtection->renderRestrictionBlock($rule, $context, $post->post_type, (string) $post->ID);
            }
        }

        // Fallback for shortcodes without a post context
        $html = '<div class="fchub-membership-restricted fchub-membership-restricted--' . esc_attr($type) . '">';
        $html .= '<p>' . wp_kses_post($message) . '</p>';

        if ($showLogin && !is_user_logged_in()) {
            $loginUrl = wp_login_url(get_permalink());
            $html .= '<p class="fchub-login-link">';
            $html .= '<a href="' . esc_url($loginUrl) . '" class="fchub-btn fchub-btn-login">';
            $html .= esc_html__('Log in to access this content', 'fchub-memberships');
            $html .= '</a></p>';
        }

        $html .= '</div>';
        return $html;
    }

    private static function buildProgressBar(int $unlocked, int $total, string $label = ''): string
    {
        if ($total <= 0) {
            return '';
        }

        $percent = round(($unlocked / $total) * 100);

        $html = '<div class="fchub-drip-progress">';

        if ($label) {
            $html .= '<div class="fchub-drip-progress-label">' . esc_html($label) . '</div>';
        }

        $html .= '<div class="fchub-drip-progress-track">';
        $html .= '<div class="fchub-drip-progress-bar" style="width: ' . esc_attr($percent) . '%"></div>';
        $html .= '</div>';
        $html .= '<div class="fchub-drip-progress-text">';
        $html .= sprintf(
            esc_html__('%1$d of %2$d items unlocked (%3$d%%)', 'fchub-memberships'),
            $unlocked,
            $total,
            $percent
        );
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    private static function getDefaultMessage(string $type): string
    {
        $settings = get_option('fchub_memberships_settings', []);

        $defaults = [
            'logged_out'  => __('This content is available to members only. Please log in to access it.', 'fchub-memberships'),
            'restricted'  => __('This content is restricted to members with an active plan.', 'fchub-memberships'),
            'drip_locked' => __('This content will be available on {date}.', 'fchub-memberships'),
        ];

        return $settings['messages'][$type] ?? $defaults[$type] ?? $defaults['restricted'];
    }
}

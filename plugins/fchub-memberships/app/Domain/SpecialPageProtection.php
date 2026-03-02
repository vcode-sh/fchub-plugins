<?php

namespace FChubMemberships\Domain;

defined('ABSPATH') || exit;

use FChubMemberships\Storage\ProtectionRuleRepository;
use FChubMemberships\Support\Constants;

class SpecialPageProtection
{
    private AccessEvaluator $evaluator;
    private ProtectionRuleRepository $ruleRepo;

    public function __construct()
    {
        $this->evaluator = new AccessEvaluator();
        $this->ruleRepo = new ProtectionRuleRepository();
    }

    public function register(): void
    {
        // Full-block protection on template redirect (priority 8, before ContentProtection)
        add_action('template_redirect', [$this, 'checkSpecialPageProtection'], 8);

        // Filter results mode via pre_get_posts
        add_action('pre_get_posts', [$this, 'filterSearchResults'], 5);
    }

    /**
     * Full-block protection: redirect or display message for special pages.
     */
    public function checkSpecialPageProtection(): void
    {
        if (is_admin()) {
            return;
        }

        $resourceId = $this->detectCurrentSpecialPage();
        if ($resourceId === null) {
            return;
        }

        $rule = $this->getSpecialPageRule($resourceId);
        if (!$rule) {
            return;
        }

        $meta = $rule['meta'] ?? [];
        $action = $meta['action'] ?? 'redirect';

        // filter_results is handled separately in filterSearchResults via pre_get_posts
        if ($action === 'filter_results') {
            return;
        }

        $userId = get_current_user_id();

        // Admin bypass
        if ($userId && user_can($userId, 'manage_options')) {
            $settings = get_option('fchub_memberships_settings', []);
            if (($settings['admin_bypass'] ?? 'yes') === 'yes') {
                return;
            }
        }

        // Check user access
        if ($userId && $this->userHasSpecialPageAccess($userId, $resourceId, $rule)) {
            return;
        }

        $this->handleRestriction($rule, $resourceId, $userId);
    }

    /**
     * Detect the current special page type from WordPress conditionals.
     */
    public function detectCurrentSpecialPage(): ?string
    {
        if (is_search()) {
            return 'search';
        }

        if (is_author()) {
            return 'author:' . get_queried_object_id();
        }

        if (is_date()) {
            return 'date';
        }

        if (is_post_type_archive()) {
            $postType = get_query_var('post_type');
            if (is_array($postType)) {
                $postType = $postType[0] ?? '';
            }
            return 'post_type_archive:' . $postType;
        }

        if (is_front_page()) {
            return 'front_page';
        }

        if (is_home()) {
            return 'blog_page';
        }

        return null;
    }

    /**
     * Get the protection rule for a special page resource ID.
     * Falls back to a generic type check (e.g., "author" for "author:5") then wildcard.
     */
    public function getSpecialPageRule(string $resourceId): ?array
    {
        // Check exact match
        $rule = $this->ruleRepo->findByResource('special_page', $resourceId);
        if ($rule) {
            return $rule;
        }

        // For types with specific IDs (author:5), also check generic type (author)
        $parts = explode(':', $resourceId, 2);
        if (count($parts) === 2) {
            $genericRule = $this->ruleRepo->findByResource('special_page', $parts[0]);
            if ($genericRule) {
                return $genericRule;
            }
        }

        // Check wildcard
        return $this->ruleRepo->findByResource('special_page', '*');
    }

    /**
     * Handle the restriction for a protected special page.
     */
    public function handleRestriction(array $rule, string $resourceId = '', int $userId = 0): void
    {
        $meta = $rule['meta'] ?? [];
        $action = $meta['action'] ?? 'redirect';

        if ($action === 'redirect') {
            $redirectUrl = $meta['redirect_url'] ?? '';
            if (!$redirectUrl) {
                $redirectUrl = $rule['redirect_url'] ?? '';
            }
            if (!$redirectUrl) {
                $settings = get_option('fchub_memberships_settings', []);
                $redirectUrl = $settings['default_redirect_url'] ?? home_url('/');
            }
            wp_safe_redirect($redirectUrl);
            if (!defined('FCHUB_TESTING')) {
                exit;
            }
            return;
        }

        if ($action === 'message') {
            $message = $meta['restriction_message'] ?? '';
            if (empty($message)) {
                $message = $this->evaluator->getRestrictionMessage(
                    'special_page',
                    $resourceId,
                    $userId ? 'no_access' : 'logged_out'
                );
            }

            $loginLink = '';
            if (!$userId) {
                $loginLink = sprintf(
                    '<p class="fchub-login-link"><a href="%s">%s</a></p>',
                    esc_url(wp_login_url(home_url($_SERVER['REQUEST_URI'] ?? '/'))),
                    esc_html__('Log in', 'fchub-memberships')
                );
            }

            wp_enqueue_style('fchub-memberships-frontend', FCHUB_MEMBERSHIPS_URL . 'assets/css/frontend.css', [], FCHUB_MEMBERSHIPS_VERSION);

            wp_die(
                '<div class="fchub-membership-restricted fchub-restricted-special-page">'
                    . wp_kses_post(wpautop($message))
                    . $loginLink
                    . '</div>',
                esc_html__('Access Restricted', 'fchub-memberships'),
                ['response' => 403, 'back_link' => true]
            );
        }
    }

    /**
     * Filter search results or archive queries for the filter_results mode.
     * Excludes protected posts from the query rather than blocking the whole page.
     */
    public function filterSearchResults(\WP_Query $query): void
    {
        if (is_admin() || !$query->is_main_query()) {
            return;
        }

        $resourceId = $this->detectSpecialPageTypeFromQuery($query);
        if ($resourceId === null) {
            return;
        }

        $rule = $this->getSpecialPageRule($resourceId);
        if (!$rule) {
            return;
        }

        $meta = $rule['meta'] ?? [];
        if (($meta['action'] ?? 'redirect') !== 'filter_results') {
            return;
        }

        $userId = get_current_user_id();

        // Admin bypass
        if ($userId && user_can($userId, 'manage_options')) {
            $settings = get_option('fchub_memberships_settings', []);
            if (($settings['admin_bypass'] ?? 'yes') === 'yes') {
                return;
            }
        }

        // User has full access, no filtering needed
        if ($userId && $this->userHasSpecialPageAccess($userId, $resourceId, $rule)) {
            return;
        }

        // Get all protected post IDs and exclude them
        $protectionRepo = new ProtectionRuleRepository();
        $postType = $query->get('post_type') ?: 'post';
        if (is_array($postType)) {
            $postType = $postType[0] ?? 'post';
        }

        $protectedIds = $protectionRepo->getProtectedResourceIds($postType);
        if (empty($protectedIds)) {
            return;
        }

        $excludeIds = array_map('intval', $protectedIds);

        // If user is logged in, allow posts they have access to
        if ($userId) {
            $accessibleIds = $this->evaluator->canAccessMultiple($userId, $protectedIds, $postType);
            $accessibleInts = array_map('intval', $accessibleIds);
            $excludeIds = array_diff($excludeIds, $accessibleInts);
        }

        if (!empty($excludeIds)) {
            $existing = $query->get('post__not_in') ?: [];
            $query->set('post__not_in', array_merge($existing, array_values($excludeIds)));
        }
    }

    /**
     * Detect special page type from WP_Query object (for pre_get_posts).
     */
    private function detectSpecialPageTypeFromQuery(\WP_Query $query): ?string
    {
        if ($query->is_search()) {
            return 'search';
        }

        if ($query->is_author()) {
            $authorId = $query->get('author');
            return $authorId ? 'author:' . $authorId : 'author';
        }

        if ($query->is_date()) {
            return 'date';
        }

        if ($query->is_post_type_archive()) {
            $postType = $query->get('post_type');
            if (is_array($postType)) {
                $postType = $postType[0] ?? '';
            }
            return $postType ? 'post_type_archive:' . $postType : null;
        }

        if ($query->is_home()) {
            return 'blog_page';
        }

        return null;
    }

    /**
     * Check if user has access to a special page.
     */
    private function userHasSpecialPageAccess(int $userId, string $resourceId, array $rule): bool
    {
        // Check plan-based access
        $planIds = $rule['plan_ids'] ?? [];

        $grantRepo = new \FChubMemberships\Storage\GrantRepository();
        $grants = $grantRepo->getByUserId($userId, ['status' => Constants::STATUS_ACTIVE]);

        if (empty($grants)) {
            return false;
        }

        // No specific plans required - any active membership grants access
        if (empty($planIds)) {
            return true;
        }

        foreach ($grants as $grant) {
            if ($grant['plan_id'] !== null && in_array($grant['plan_id'], $planIds, false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get available special page types for the admin UI.
     *
     * @return array<string, string>
     */
    public static function getSpecialPageTypes(): array
    {
        return [
            'search'             => __('Search Results', 'fchub-memberships'),
            'author'             => __('Author Archives', 'fchub-memberships'),
            'date'               => __('Date Archives', 'fchub-memberships'),
            'post_type_archive'  => __('Post Type Archives', 'fchub-memberships'),
            'front_page'         => __('Front Page', 'fchub-memberships'),
            'blog_page'          => __('Blog Page', 'fchub-memberships'),
        ];
    }
}

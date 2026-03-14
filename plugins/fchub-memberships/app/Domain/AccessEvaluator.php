<?php

namespace FChubMemberships\Domain;

defined('ABSPATH') || exit;

use FChubMemberships\Domain\Plan\PlanRuleResolver;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\ProtectionRuleRepository;
use FChubMemberships\Support\Constants;

class AccessEvaluator
{
    private GrantRepository $grantRepo;
    private PlanRuleResolver $ruleResolver;
    private ProtectionRuleRepository $protectionRepo;

    /** @var array Per-request cache */
    private static array $cache = [];

    public function __construct()
    {
        $this->grantRepo = new GrantRepository();
        $this->ruleResolver = new PlanRuleResolver();
        $this->protectionRepo = new ProtectionRuleRepository();
    }

    /**
     * Check if a user can access a resource (full drip check).
     *
     * @return array ['allowed' => bool, 'reason' => string, 'drip_locked' => bool, 'drip_available_at' => ?string, 'grant' => ?array]
     */
    public function evaluate(int $userId, string $provider, string $resourceType, string $resourceId): array
    {
        $cacheKey = "{$userId}:{$provider}:{$resourceType}:{$resourceId}";
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        // Admin bypass
        if ($this->isAdminBypass($userId)) {
            $result = ['allowed' => true, 'reason' => Constants::REASON_ADMIN_BYPASS, 'drip_locked' => false, 'drip_available_at' => null, 'grant' => null, 'trial_active' => false];
            self::$cache[$cacheKey] = $result;
            return $result;
        }

        // Bug F fix: Check active grants FIRST, then paused. A paused grant from
        // plan B must not mask an active grant from plan A for the same resource.

        // Check direct grant
        $grant = $this->grantRepo->getActiveGrant($userId, $provider, $resourceType, $resourceId);

        if ($grant) {
            $now = current_time('timestamp', true);
            $trialActive = !empty($grant['trial_ends_at']) && strtotime($grant['trial_ends_at']) > $now;

            // Check drip
            if (!empty($grant['drip_available_at']) && strtotime($grant['drip_available_at']) > $now) {
                $result = [
                    'allowed'          => false,
                    'reason'           => Constants::REASON_DRIP_LOCKED,
                    'drip_locked'      => true,
                    'drip_available_at' => $grant['drip_available_at'],
                    'grant'            => $grant,
                    'trial_active'     => $trialActive,
                ];
                self::$cache[$cacheKey] = $result;
                return $result;
            }

            $result = ['allowed' => true, 'reason' => Constants::REASON_DIRECT_GRANT, 'drip_locked' => false, 'drip_available_at' => null, 'grant' => $grant, 'trial_active' => $trialActive];
            self::$cache[$cacheKey] = $result;
            return $result;
        }

        // Check plan-based grants (user has a plan that includes this resource)
        $planGrants = $this->grantRepo->getByUserId($userId, ['status' => Constants::STATUS_ACTIVE]);
        $checkedPlanIds = [];

        foreach ($planGrants as $planGrant) {
            // Bug #5: Use strict null check instead of falsy check (plan_id=0 is valid)
            if ($planGrant['plan_id'] === null || in_array($planGrant['plan_id'], $checkedPlanIds, true)) {
                continue;
            }
            $checkedPlanIds[] = $planGrant['plan_id'];

            // Bug #7: Check both the exact provider and taxonomy resource types
            $hasResource = $this->ruleResolver->planHasResource($planGrant['plan_id'], $provider, $resourceType, $resourceId);

            if (!$hasResource && $provider === Constants::PROVIDER_WORDPRESS_CORE) {
                // Also check if this resource is accessible via taxonomy rules in the plan
                $hasResource = $this->planHasTaxonomyAccessForResource($planGrant['plan_id'], $resourceType, $resourceId);
            }

            if ($hasResource) {
                $now = current_time('timestamp', true);

                // Check drip for this plan's rule
                $dripRule = $this->ruleResolver->getDripRule($planGrant['plan_id'], $provider, $resourceType, $resourceId);
                if ($dripRule && $dripRule['drip_type'] !== Constants::DRIP_TYPE_IMMEDIATE) {
                    $dripDate = $this->calculateDripDateForGrant($dripRule, $planGrant);
                    if ($dripDate && strtotime($dripDate) > $now) {
                        $planTrialActive = !empty($planGrant['trial_ends_at']) && strtotime($planGrant['trial_ends_at']) > $now;
                        $result = [
                            'allowed'          => false,
                            'reason'           => Constants::REASON_DRIP_LOCKED,
                            'drip_locked'      => true,
                            'drip_available_at' => $dripDate,
                            'grant'            => $planGrant,
                            'trial_active'     => $planTrialActive,
                        ];
                        self::$cache[$cacheKey] = $result;
                        return $result;
                    }
                }

                $planTrialActive = !empty($planGrant['trial_ends_at']) && strtotime($planGrant['trial_ends_at']) > $now;
                $result = ['allowed' => true, 'reason' => Constants::REASON_PLAN_GRANT, 'drip_locked' => false, 'drip_available_at' => null, 'grant' => $planGrant, 'trial_active' => $planTrialActive];
                self::$cache[$cacheKey] = $result;
                return $result;
            }
        }

        // Check wildcard grants (resource_id = '*')
        $wildcardGrant = $this->grantRepo->getActiveGrant($userId, $provider, $resourceType, '*');
        if ($wildcardGrant) {
            $now = current_time('timestamp', true);
            $wildcardTrialActive = !empty($wildcardGrant['trial_ends_at']) && strtotime($wildcardGrant['trial_ends_at']) > $now;
            $result = ['allowed' => true, 'reason' => Constants::REASON_WILDCARD_GRANT, 'drip_locked' => false, 'drip_available_at' => null, 'grant' => $wildcardGrant, 'trial_active' => $wildcardTrialActive];
            self::$cache[$cacheKey] = $result;
            return $result;
        }

        // Bug F fix: Check paused grants only AFTER all active grant checks have been exhausted.
        // This prevents a paused grant from plan B masking an active grant from plan A.
        $pausedGrant = $this->getPausedGrant($userId, $provider, $resourceType, $resourceId);
        if ($pausedGrant) {
            $result = [
                'allowed' => false,
                'reason' => Constants::REASON_MEMBERSHIP_PAUSED,
                'drip_locked' => false,
                'drip_available_at' => null,
                'grant' => $pausedGrant,
                'trial_active' => false,
            ];
            self::$cache[$cacheKey] = $result;
            return $result;
        }

        // No access
        $result = ['allowed' => false, 'reason' => Constants::REASON_NO_GRANT, 'drip_locked' => false, 'drip_available_at' => null, 'grant' => null, 'trial_active' => false];
        self::$cache[$cacheKey] = $result;
        return $result;
    }

    /**
     * Simple boolean check: does user have access?
     */
    public function canAccess(int $userId, string $provider, string $resourceType, string $resourceId): bool
    {
        $result = $this->evaluate($userId, $provider, $resourceType, $resourceId);
        return $result['allowed'];
    }

    /**
     * Check if a resource is protected (needs membership to access).
     */
    public function isProtected(string $provider, string $resourceType, string $resourceId): bool
    {
        // Check explicit protection rules
        if ($this->protectionRepo->isProtected($resourceType, $resourceId)) {
            return true;
        }

        // Check if resource is in any plan's rules (implicit protection)
        $planIds = $this->ruleResolver->findPlansWithResource($provider, $resourceType, $resourceId);
        if (!empty($planIds)) {
            return true;
        }

        // Check taxonomy inheritance: if any of this post's terms are protected,
        // the post inherits protection (only for WordPress post types)
        if ($provider === Constants::PROVIDER_WORDPRESS_CORE && post_type_exists($resourceType)) {
            if ($this->isProtectedViaTaxonomy($resourceType, $resourceId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a post is protected because one of its taxonomy terms has a protection rule
     * with inheritance_mode=all_posts.
     */
    private function isProtectedViaTaxonomy(string $postType, string $resourceId): bool
    {
        $post = get_post((int) $resourceId);
        if (!$post) {
            return false;
        }

        $taxonomies = get_object_taxonomies($postType, 'names');
        foreach ($taxonomies as $taxonomy) {
            $terms = get_the_terms($post->ID, $taxonomy);
            if (!$terms || is_wp_error($terms)) {
                continue;
            }

            foreach ($terms as $term) {
                $rule = $this->protectionRepo->findByResource($taxonomy, (string) $term->term_id);
                if ($rule) {
                    $meta = $rule['meta'] ?? [];
                    $inheritMode = $meta['inheritance_mode'] ?? 'none';
                    if ($inheritMode === 'all_posts') {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Get restriction message for a resource.
     * Bug #9: Check all associated plans for the most specific message.
     */
    public function getRestrictionMessage(string $resourceType, string $resourceId, string $context = 'no_access'): string
    {
        // Check resource-specific protection rule
        $rule = $this->protectionRepo->findByResource($resourceType, $resourceId);
        if ($rule && !empty($rule['restriction_message'])) {
            return $rule['restriction_message'];
        }

        // Check plan-level restriction message - iterate all plans, prefer non-empty message
        $planIds = $this->ruleResolver->findPlansWithResource(Constants::PROVIDER_WORDPRESS_CORE, $resourceType, $resourceId);
        if (!empty($planIds)) {
            $planRepo = new \FChubMemberships\Storage\PlanRepository();
            foreach ($planIds as $planId) {
                $plan = $planRepo->find($planId);
                if ($plan && !empty($plan['restriction_message'])) {
                    return $plan['restriction_message'];
                }
            }
        }

        // Default messages from settings
        $settings = get_option('fchub_memberships_settings', []);

        $defaults = [
            'logged_out'        => __('This content is available to members only. Please log in to access.', 'fchub-memberships'),
            'no_access'         => __('You don\'t have access to this content. View membership options to learn more.', 'fchub-memberships'),
            'expired'           => __('Your access to this content has expired. Renew your subscription to continue.', 'fchub-memberships'),
            'drip_locked'       => __('This content will be available to you soon. Check back later.', 'fchub-memberships'),
            'membership_paused' => __('Your membership is currently paused. Resume your membership to access this content.', 'fchub-memberships'),
        ];

        return $settings['restriction_message_' . $context] ?? $defaults[$context] ?? $defaults['no_access'];
    }

    /**
     * Get redirect URL for a restricted resource.
     */
    public function getRedirectUrl(string $resourceType, string $resourceId): ?string
    {
        $rule = $this->protectionRepo->findByResource($resourceType, $resourceId);
        if ($rule && !empty($rule['redirect_url'])) {
            return $rule['redirect_url'];
        }

        $settings = get_option('fchub_memberships_settings', []);
        return $settings['default_redirect_url'] ?? null;
    }

    /**
     * Check if teaser/excerpt should be shown.
     */
    public function shouldShowTeaser(string $resourceType, string $resourceId): bool
    {
        $rule = $this->protectionRepo->findByResource($resourceType, $resourceId);
        if ($rule) {
            return $rule['show_teaser'] === 'yes';
        }

        $settings = get_option('fchub_memberships_settings', []);
        return ($settings['show_teaser'] ?? 'no') === 'yes';
    }

    /**
     * Get user's drip progress for a plan.
     */
    public function getDripProgress(int $userId, int $planId): array
    {
        $rules = $this->ruleResolver->resolveUniqueRules($planId);
        $totalItems = count($rules);
        $unlockedItems = 0;

        foreach ($rules as $rule) {
            $result = $this->evaluate($userId, $rule['provider'], $rule['resource_type'], $rule['resource_id']);
            if ($result['allowed']) {
                $unlockedItems++;
            }
        }

        return [
            'total'          => $totalItems,
            'unlocked'       => $unlockedItems,
            'percentage'     => $totalItems > 0 ? round(($unlockedItems / $totalItems) * 100) : 0,
            'next_unlock'    => $this->getNextDripUnlock($userId, $planId),
        ];
    }

    /**
     * Get the next drip unlock date for a user's plan.
     */
    public function getNextDripUnlock(int $userId, int $planId): ?string
    {
        $grants = $this->grantRepo->getByUserId($userId, ['plan_id' => $planId, 'status' => Constants::STATUS_ACTIVE]);
        $now = current_time('timestamp', true);
        $nextUnlock = null;

        foreach ($grants as $grant) {
            if (!empty($grant['drip_available_at'])) {
                $dripTime = strtotime($grant['drip_available_at']);
                if ($dripTime > $now && ($nextUnlock === null || $dripTime < strtotime($nextUnlock))) {
                    $nextUnlock = $grant['drip_available_at'];
                }
            }
        }

        return $nextUnlock;
    }

    /**
     * Batch check: which post IDs can a user access?
     * Uses transient cache to avoid repeated DB queries.
     *
     * @param int    $userId
     * @param array  $postIds  Array of post ID strings
     * @param string $postType WordPress post type
     * @return string[] Post IDs the user can access
     */
    public function canAccessMultiple(int $userId, array $postIds, string $postType): array
    {
        if (empty($postIds)) {
            return [];
        }

        if ($this->isAdminBypass($userId)) {
            return $postIds;
        }

        // Bug #10: Include grant status in cache key
        $cacheKey = 'fchub_user_' . $userId . '_accessible_posts_active';
        $cached = get_transient($cacheKey);

        if ($cached === false) {
            $cached = $this->buildAccessiblePostsCache($userId);
            set_transient($cacheKey, $cached, 5 * MINUTE_IN_SECONDS);
        }

        $directlyGranted = $cached[$postType] ?? [];
        $wildcardGranted = !empty($cached[$postType . ':*']);

        if ($wildcardGranted) {
            return $postIds;
        }

        $accessible = [];
        foreach ($postIds as $postId) {
            if (in_array((string) $postId, $directlyGranted, true)) {
                $accessible[] = $postId;
                continue;
            }

            // Check plan-based access (plan rules may include this resource)
            if ($this->hasPlanBasedAccess($userId, $postType, $postId, $cached)) {
                $accessible[] = $postId;
            }
        }

        return $accessible;
    }

    /**
     * Build a cache of all resources a user has direct grants for.
     */
    private function buildAccessiblePostsCache(int $userId): array
    {
        $userResources = $this->grantRepo->getAllUserResourceIds($userId);

        // Mark wildcard grants
        foreach ($userResources as $resourceType => $ids) {
            if (in_array('*', $ids, true)) {
                $userResources[$resourceType . ':*'] = true;
            }
        }

        // Also store the user's active plan IDs for plan-based resolution
        $planGrants = $this->grantRepo->getByUserId($userId, ['status' => Constants::STATUS_ACTIVE]);
        $planIds = [];
        foreach ($planGrants as $grant) {
            if ($grant['plan_id'] !== null) {
                $planIds[] = $grant['plan_id'];
            }
        }
        $userResources['_plan_ids'] = array_unique($planIds);

        return $userResources;
    }

    /**
     * Check if a user has access via plan rules (used in batch mode).
     */
    private function hasPlanBasedAccess(int $userId, string $postType, string $postId, array $cached): bool
    {
        $planIds = $cached['_plan_ids'] ?? [];

        foreach ($planIds as $planId) {
            if ($this->ruleResolver->planHasResource($planId, Constants::PROVIDER_WORDPRESS_CORE, $postType, $postId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Clear the transient cache for a specific user.
     */
    public static function clearUserCache(int $userId): void
    {
        delete_transient('fchub_user_' . $userId . '_accessible_posts_active');
    }

    /**
     * Clear per-request cache.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Bug #4: Use repository method with proper hydration instead of raw SQL.
     */
    private function getPausedGrant(int $userId, string $provider, string $resourceType, string $resourceId): ?array
    {
        $pausedGrants = $this->grantRepo->getByUserId($userId, [
            'status'   => Constants::STATUS_PAUSED,
            'provider' => $provider,
        ]);

        foreach ($pausedGrants as $grant) {
            if ($grant['resource_type'] === $resourceType && $grant['resource_id'] === $resourceId) {
                return $grant;
            }
        }

        return null;
    }

    private function isAdminBypass(int $userId): bool
    {
        $settings = get_option('fchub_memberships_settings', []);
        if (($settings['admin_bypass'] ?? 'yes') !== 'yes') {
            return false;
        }

        return user_can($userId, 'manage_options');
    }

    /**
     * Bug #2: Use wp_date() for consistent timezone handling.
     * Bug #3: Add null check for $grant['created_at'] with fallback and warning.
     */
    private function calculateDripDateForGrant(array $dripRule, array $grant): ?string
    {
        if ($dripRule['drip_type'] === Constants::DRIP_TYPE_DELAYED && $dripRule['drip_delay_days'] > 0) {
            $grantDate = $grant['created_at'] ?? null;
            if ($grantDate === null) {
                $grantDate = current_time('mysql', true);
                \FChubMemberships\Support\Logger::log(
                    'Grant created_at is null',
                    'Using current time as fallback for drip calculation',
                    ['grant_id' => $grant['id'] ?? 'unknown']
                );
            }
            return wp_date('Y-m-d H:i:s', strtotime($grantDate . ' +' . $dripRule['drip_delay_days'] . ' days'));
        }

        if ($dripRule['drip_type'] === Constants::DRIP_TYPE_FIXED_DATE && !empty($dripRule['drip_date'])) {
            return $dripRule['drip_date'];
        }

        return null;
    }

    /**
     * Bug #7: Check if a plan has taxonomy-level rules that cover a specific post.
     */
    private function planHasTaxonomyAccessForResource(int $planId, string $postType, string $resourceId): bool
    {
        $post = get_post((int) $resourceId);
        if (!$post) {
            return false;
        }

        $taxonomies = get_object_taxonomies($postType, 'names');
        foreach ($taxonomies as $taxonomy) {
            $terms = get_the_terms($post->ID, $taxonomy);
            if (!$terms || is_wp_error($terms)) {
                continue;
            }
            foreach ($terms as $term) {
                if ($this->ruleResolver->planHasResource($planId, Constants::PROVIDER_WORDPRESS_CORE, $taxonomy, (string) $term->term_id)) {
                    return true;
                }
            }
        }

        return false;
    }
}

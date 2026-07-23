<?php

namespace FChubMemberships\Domain;

defined('ABSPATH') || exit;

use FChubMemberships\Domain\Access\ResourceAccessPolicy;
use FChubMemberships\Domain\Access\ResourceAccessPolicyResolver;
use FChubMemberships\Domain\Plan\PlanRuleResolver;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\ProtectionRuleRepository;
use FChubMemberships\Support\Constants;
use FChubMemberships\Support\Clock;

class AccessEvaluator
{
    private GrantRepository $grantRepo;
    private PlanRuleResolver $ruleResolver;
    private ProtectionRuleRepository $protectionRepo;
    private Clock $clock;
    private ?ResourceAccessPolicyResolver $policyResolver;

    /** @var array Per-request cache */
    private static array $cache = [];

    /** @var array<string, array<int|string, int>> */
    private static array $countCache = [];

    private static ?object $cacheContext = null;

    public function __construct(
        ?GrantRepository $grantRepo = null,
        ?PlanRuleResolver $ruleResolver = null,
        ?ProtectionRuleRepository $protectionRepo = null,
        ?Clock $clock = null,
        ?ResourceAccessPolicyResolver $policyResolver = null
    )
    {
        $this->grantRepo = $grantRepo ?? new GrantRepository();
        $this->ruleResolver = $ruleResolver ?? new PlanRuleResolver();
        $this->protectionRepo = $protectionRepo ?? new ProtectionRuleRepository();
        $this->clock = $clock ?? new Clock();
        $this->policyResolver = $policyResolver;
    }

    /**
     * Check if a user can access a resource (full drip check).
     *
     * @return array ['allowed' => bool, 'reason' => string, 'drip_locked' => bool, 'drip_available_at' => ?string, 'grant' => ?array]
     */
    public function evaluate(int $userId, string $provider, string $resourceType, string $resourceId): array
    {
        $this->ensureCacheContext();
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

        $dripLocked = null;

        // Check direct grant
        $grant = $this->grantRepo->getActiveGrant($userId, $provider, $resourceType, $resourceId);

        if ($grant) {
            $now = $this->clock->now()->getTimestamp();
            $trialActive = !empty($grant['trial_ends_at']) && $this->localTimestamp($grant['trial_ends_at']) > $now;

            // Check drip
            if (!empty($grant['drip_available_at']) && $this->localTimestamp($grant['drip_available_at']) > $now) {
                $result = [
                    'allowed'          => false,
                    'reason'           => Constants::REASON_DRIP_LOCKED,
                    'drip_locked'      => true,
                    'drip_available_at' => $grant['drip_available_at'],
                    'grant'            => $grant,
                    'trial_active'     => $trialActive,
                ];
                $dripLocked = $result;
            } else {
                $result = ['allowed' => true, 'reason' => Constants::REASON_DIRECT_GRANT, 'drip_locked' => false, 'drip_available_at' => null, 'grant' => $grant, 'trial_active' => $trialActive];
                self::$cache[$cacheKey] = $result;
                return $result;
            }
        }

        // Exact drip must not hide an independently accessible wildcard grant.
        $wildcardGrant = $this->grantRepo->getActiveGrant($userId, $provider, $resourceType, '*');
        if ($wildcardGrant) {
            $now = $this->clock->now()->getTimestamp();
            $trialActive = !empty($wildcardGrant['trial_ends_at'])
                && $this->localTimestamp($wildcardGrant['trial_ends_at']) > $now;
            if (!empty($wildcardGrant['drip_available_at'])
                && $this->localTimestamp($wildcardGrant['drip_available_at']) > $now
            ) {
                $candidate = [
                    'allowed' => false,
                    'reason' => Constants::REASON_DRIP_LOCKED,
                    'drip_locked' => true,
                    'drip_available_at' => $wildcardGrant['drip_available_at'],
                    'grant' => $wildcardGrant,
                    'trial_active' => $trialActive,
                ];
                $dripLocked = $this->earlierDripLock($dripLocked, $candidate);
            } else {
                $result = ['allowed' => true, 'reason' => Constants::REASON_WILDCARD_GRANT, 'drip_locked' => false, 'drip_available_at' => null, 'grant' => $wildcardGrant, 'trial_active' => $trialActive];
                self::$cache[$cacheKey] = $result;
                return $result;
            }
        }

        $policyResolver = $this->resourcePolicyResolver();
        $policy = $policyResolver->resolve($provider, $resourceType, $resourceId);
        foreach ($this->grantRepo->getEffectivePlanMembershipsForUser($userId) as $planGrant) {
            $planId = (int) ($planGrant['plan_id'] ?? 0);
            if ($planId <= 0) {
                continue;
            }
            $policyResolver->ensurePlanPath($policy, $planId);
            foreach ($policy->pathsForPlan($planId) as $path) {
                $pathResult = $this->evaluatePolicyPath($path, $planGrant);
                if (!$pathResult['applicable']) {
                    continue;
                }
                $trialActive = !empty($planGrant['trial_ends_at'])
                    && $this->localTimestamp($planGrant['trial_ends_at']) > $this->clock->now()->getTimestamp();
                if ($pathResult['drip_available_at'] === null) {
                    $result = ['allowed' => true, 'reason' => Constants::REASON_PLAN_GRANT, 'drip_locked' => false, 'drip_available_at' => null, 'grant' => $planGrant, 'trial_active' => $trialActive];
                    self::$cache[$cacheKey] = $result;
                    return $result;
                }
                $candidate = [
                    'allowed' => false,
                    'reason' => Constants::REASON_DRIP_LOCKED,
                    'drip_locked' => true,
                    'drip_available_at' => $pathResult['drip_available_at'],
                    'grant' => $planGrant,
                    'trial_active' => $trialActive,
                ];
                $dripLocked = $this->earlierDripLock($dripLocked, $candidate);
            }
        }

        if ($dripLocked !== null) {
            self::$cache[$cacheKey] = $dripLocked;
            return $dripLocked;
        }

        // Check paused grants only after every independently active access path.
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
     * Count effective access for a keyed page of resources.
     *
     * @param array<int|string, array{provider: string, resource_type: string, resource_id: string}> $resources
     * @return array<int|string, int>
     */
    public function countDistinctUsersWithResourceAccessBatch(array $resources): array
    {
        $this->ensureCacheContext();
        if ($resources === []) {
            return [];
        }

        $cacheKey = md5(wp_json_encode($resources));
        if (isset(self::$countCache[$cacheKey])) {
            return self::$countCache[$cacheKey];
        }

        foreach ($resources as $resource) {
            foreach (['provider', 'resource_type', 'resource_id'] as $required) {
                if (!isset($resource[$required]) || !is_scalar($resource[$required])) {
                    throw new \InvalidArgumentException('Each resource requires provider, resource_type and resource_id.');
                }
            }
        }

        $policies = $this->resourcePolicyResolver()->resolveBatch($resources);
        $counts = $this->grantRepo->countDistinctUsersWithResourceAccessBatch($policies);
        $normalised = [];
        foreach (array_keys($resources) as $key) {
            $normalised[$key] = (int) ($counts[$key] ?? 0);
        }
        self::$countCache[$cacheKey] = $normalised;
        return self::$countCache[$cacheKey];
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
        $policyResolver = $this->resourcePolicyResolver();
        if ($policyResolver->canUseGlobalRuleShortcut()
            && !$policyResolver->hasAnyProtectionOrPlanRules()
        ) {
            return false;
        }

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

        if ($context === 'membership_paused') {
            return $settings['restriction_message_paused']
                ?? $settings['restriction_message_membership_paused']
                ?? $defaults['membership_paused'];
        }

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
        $memberships = $this->grantRepo->getEffectivePlanMembershipsForUserByPlan($userId, $planId);
        $totalItems = count($rules);
        $unlockedItems = 0;
        $nextUnlock = null;

        foreach ($rules as $rule) {
            $result = $this->evaluatePlanDripRule($rule, $memberships);
            if ($result['allowed']) {
                $unlockedItems++;
                continue;
            }

            $candidate = $result['drip_available_at'];
            if ($candidate !== null
                && ($nextUnlock === null
                    || $this->localTimestamp($candidate) < $this->localTimestamp($nextUnlock))
            ) {
                $nextUnlock = $candidate;
            }
        }

        return [
            'total'          => $totalItems,
            'unlocked'       => $unlockedItems,
            'percentage'     => $totalItems > 0 ? round(($unlockedItems / $totalItems) * 100) : 0,
            'next_unlock'    => $nextUnlock,
        ];
    }

    /**
     * Get the next drip unlock date for a user's plan.
     */
    public function getNextDripUnlock(int $userId, int $planId): ?string
    {
        return $this->getDripProgress($userId, $planId)['next_unlock'];
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
        GrantRepository::clearRequestCache();
    }

    /**
     * Clear per-request cache.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
        self::$countCache = [];
        self::$cacheContext = null;
        ResourceAccessPolicyResolver::clearRequestCache();
        GrantRepository::clearRequestCache();
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
     * Bug #2: Use the site-local clock for consistent timezone handling.
     * Bug #3: Add null check for $grant['created_at'] with fallback and warning.
     */
    private function calculateDripDateForGrant(array $dripRule, array $grant): ?string
    {
        if ($dripRule['drip_type'] === Constants::DRIP_TYPE_DELAYED && $dripRule['drip_delay_days'] > 0) {
            $grantDate = $grant['created_at'] ?? null;
            if ($grantDate === null) {
                $grantDate = $this->clock->storage($this->clock->now());
                \FChubMemberships\Support\Logger::log(
                    'Grant created_at is null',
                    'Using current time as fallback for drip calculation',
                    ['grant_id' => $grant['id'] ?? 'unknown']
                );
            }
            return $this->clock->storage($this->clock->plusDays(
                (int) $dripRule['drip_delay_days'],
                $this->clock->parseLocal($grantDate)
            ));
        }

        if ($dripRule['drip_type'] === Constants::DRIP_TYPE_FIXED_DATE && !empty($dripRule['drip_date'])) {
            return $dripRule['drip_date'];
        }

        return null;
    }

    private function localTimestamp(string $value): int
    {
        return $this->clock->parseLocal($value)->getTimestamp();
    }

    private function earlierDripLock(?array $current, array $candidate): array
    {
        if ($current === null) {
            return $candidate;
        }
        $currentAt = (string) ($current['drip_available_at'] ?? '');
        $candidateAt = (string) ($candidate['drip_available_at'] ?? '');
        if ($currentAt === '' || ($candidateAt !== '' && $this->localTimestamp($candidateAt) < $this->localTimestamp($currentAt))) {
            return $candidate;
        }

        return $current;
    }

    /**
     * @param array<string, mixed> $path
     * @param array<string, mixed> $membership
     * @return array{applicable: bool, drip_available_at: ?string}
     */
    private function evaluatePolicyPath(array $path, array $membership): array
    {
        $basis = (string) ($path['basis'] ?? 'membership');
        if ($basis === 'resource') {
            $qualifier = is_array($path['qualifier'] ?? null) ? $path['qualifier'] : [];
            $hasResourceIdentity = isset(
                $membership['provider'],
                $membership['resource_type'],
                $membership['resource_id']
            );
            if (!$hasResourceIdentity || (
                (string) $membership['provider'] !== (string) ($qualifier['provider'] ?? '')
                || (string) $membership['resource_type'] !== (string) ($qualifier['resource_type'] ?? '')
                || (string) $membership['resource_id'] !== (string) ($qualifier['resource_id'] ?? '')
            )) {
                return ['applicable' => false, 'drip_available_at' => null];
            }
        }

        $unlockAt = null;
        if ($basis === 'resource'
            && isset($membership['provider'], $membership['resource_type'], $membership['resource_id'])
            && !empty($membership['drip_available_at'])
        ) {
            $unlockAt = (string) $membership['drip_available_at'];
        }
        $ruleUnlockAt = $this->calculateDripDateForGrant($path, $membership);
        if ($ruleUnlockAt !== null
            && ($unlockAt === null || $this->localTimestamp($ruleUnlockAt) > $this->localTimestamp($unlockAt))
        ) {
            $unlockAt = $ruleUnlockAt;
        }
        if ($unlockAt !== null && $this->localTimestamp($unlockAt) <= $this->clock->now()->getTimestamp()) {
            $unlockAt = null;
        }

        return ['applicable' => true, 'drip_available_at' => $unlockAt];
    }

    /**
     * @param array<string, mixed> $rule
     * @param list<array<string, mixed>> $memberships
     * @return array{allowed: bool, drip_available_at: ?string}
     */
    private function evaluatePlanDripRule(array $rule, array $memberships): array
    {
        $path = array_merge([
            'drip_type' => Constants::DRIP_TYPE_IMMEDIATE,
            'drip_delay_days' => 0,
            'drip_date' => null,
        ], $rule, [
            'basis' => 'resource',
            'qualifier' => [
                'provider' => (string) ($rule['provider'] ?? ''),
                'resource_type' => (string) ($rule['resource_type'] ?? ''),
                'resource_id' => (string) ($rule['resource_id'] ?? ''),
            ],
        ]);
        $nextUnlock = null;

        foreach ($memberships as $membership) {
            $result = $this->evaluatePolicyPath($path, $membership);
            if (!$result['applicable']) {
                continue;
            }
            if ($result['drip_available_at'] === null) {
                return ['allowed' => true, 'drip_available_at' => null];
            }
            if ($nextUnlock === null
                || $this->localTimestamp($result['drip_available_at']) < $this->localTimestamp($nextUnlock)
            ) {
                $nextUnlock = $result['drip_available_at'];
            }
        }

        return ['allowed' => false, 'drip_available_at' => $nextUnlock];
    }

    private function resourcePolicyResolver(): ResourceAccessPolicyResolver
    {
        if ($this->policyResolver === null) {
            $this->policyResolver = new ResourceAccessPolicyResolver(
                $this->ruleResolver,
                $this->protectionRepo
            );
        }

        return $this->policyResolver;
    }

    private function ensureCacheContext(): void
    {
        global $wpdb;
        if (self::$cacheContext === $wpdb) {
            return;
        }

        self::clearCache();
        self::$cacheContext = $wpdb;
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

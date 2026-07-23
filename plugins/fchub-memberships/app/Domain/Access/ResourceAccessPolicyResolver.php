<?php

namespace FChubMemberships\Domain\Access;

defined('ABSPATH') || exit;

use FChubMemberships\Domain\Plan\PlanRuleResolver;
use FChubMemberships\Storage\ProtectionRuleRepository;
use FChubMemberships\Support\Constants;

class ResourceAccessPolicyResolver
{
    private const CACHE_GROUP = 'fchub_memberships';
    private const CACHE_GENERATION_KEY = 'resource_access_policy_generation';

    private PlanRuleResolver $rules;
    private ProtectionRuleRepository $protection;

    /** @var array<string, ResourceAccessPolicy> */
    private static array $requestPolicies = [];

    private static ?object $cacheContext = null;

    public function __construct(
        ?PlanRuleResolver $rules = null,
        ?ProtectionRuleRepository $protection = null
    ) {
        $this->rules = $rules ?? new PlanRuleResolver();
        $this->protection = $protection ?? new ProtectionRuleRepository();
    }

    public function resolve(string $provider, string $resourceType, string $resourceId): ResourceAccessPolicy
    {
        $cacheKey = implode("\0", [$provider, $resourceType, $resourceId]);
        $cached = $this->cachedPolicy($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $policy = new ResourceAccessPolicy($provider, $resourceType, $resourceId);
        if ($this->canUseGlobalRuleShortcut() && !$this->hasAnyProtectionOrPlanRules()) {
            $this->cachePolicy($cacheKey, $policy);
            return $policy;
        }
        $this->addProtectionRule($policy, $this->protection->findByResource($resourceType, $resourceId));
        $this->addPlanRulePaths($policy, $provider, $resourceType, $resourceId);

        if ($provider === Constants::PROVIDER_WORDPRESS_CORE && post_type_exists($resourceType)) {
            $this->addTaxonomyPaths($policy, $resourceType, $resourceId);
        }

        $this->cachePolicy($cacheKey, $policy);
        return $policy;
    }

    /**
     * Resolve a keyed resource page with constant plan/protection repository reads.
     *
     * @param array<int|string, array{provider: string, resource_type: string, resource_id: string}> $resources
     * @return array<int|string, ResourceAccessPolicy>
     */
    public function resolveBatch(array $resources): array
    {
        $policies = [];
        $missing = [];
        foreach ($resources as $key => $resource) {
            $cacheKey = $this->cacheKey(
                (string) $resource['provider'],
                (string) $resource['resource_type'],
                (string) $resource['resource_id']
            );
            $cached = $this->cachedPolicy($cacheKey);
            if ($cached !== null) {
                $policies[$key] = $cached;
            } else {
                $missing[$key] = $resource;
            }
        }
        if ($missing === []) {
            return $this->orderPolicies($resources, $policies);
        }
        $protectionByResource = [];
        foreach ($this->protection->all() as $rule) {
            $protectionByResource[$this->resourceKey(
                (string) $rule['resource_type'],
                (string) $rule['resource_id']
            )] = $rule;
        }

        $ruleLookups = $missing;
        $taxonomyLookups = [];
        foreach ($missing as $key => $resource) {
            if ((string) $resource['provider'] !== Constants::PROVIDER_WORDPRESS_CORE
                || !post_type_exists((string) $resource['resource_type'])
            ) {
                continue;
            }
            $post = get_post((int) $resource['resource_id']);
            if (!$post) {
                continue;
            }
            foreach (get_object_taxonomies((string) $resource['resource_type'], 'names') as $taxonomy) {
                $terms = get_the_terms($post->ID, $taxonomy);
                if (!$terms || is_wp_error($terms)) {
                    continue;
                }
                foreach ($terms as $term) {
                    $lookupKey = 'taxonomy:' . $key . ':' . $taxonomy . ':' . (string) $term->term_id;
                    $taxonomyLookups[$key][] = $lookupKey;
                    $ruleLookups[$lookupKey] = [
                        'provider' => Constants::PROVIDER_WORDPRESS_CORE,
                        'resource_type' => (string) $taxonomy,
                        'resource_id' => (string) $term->term_id,
                    ];
                }
            }
        }

        $planPaths = $this->rules->findPathsForResourcesBatch($ruleLookups);
        foreach ($missing as $key => $resource) {
            $policy = new ResourceAccessPolicy(
                (string) $resource['provider'],
                (string) $resource['resource_type'],
                (string) $resource['resource_id']
            );
            $this->addProtectionRule(
                $policy,
                $protectionByResource[$this->resourceKey(
                    (string) $resource['resource_type'],
                    (string) $resource['resource_id']
                )] ?? null
            );
            foreach ($planPaths[$key] ?? [] as $path) {
                $this->addResolvedRulePath($policy, (int) $path['plan_id'], $path['rule']);
            }
            foreach ($taxonomyLookups[$key] ?? [] as $taxonomyKey) {
                $taxonomy = $ruleLookups[$taxonomyKey];
                $protectionRule = $protectionByResource[$this->resourceKey(
                    (string) $taxonomy['resource_type'],
                    (string) $taxonomy['resource_id']
                )] ?? null;
                if (($protectionRule['meta']['inheritance_mode'] ?? 'none') === 'all_posts') {
                    $this->addProtectionRule($policy, $protectionRule);
                }
                foreach ($planPaths[$taxonomyKey] ?? [] as $path) {
                    $this->addResolvedRulePath($policy, (int) $path['plan_id'], $path['rule']);
                }
            }

            $this->cachePolicy($this->cacheKey(
                $policy->provider(),
                $policy->resourceType(),
                $policy->resourceId()
            ), $policy);
            $policies[$key] = $policy;
        }

        return $this->orderPolicies($resources, $policies);
    }

    public static function clearRequestCache(): void
    {
        self::$requestPolicies = [];
        self::$cacheContext = null;
        $generation = (int) wp_cache_get(self::CACHE_GENERATION_KEY, self::CACHE_GROUP);
        wp_cache_set(self::CACHE_GENERATION_KEY, max(1, $generation + 1), self::CACHE_GROUP);
    }

    public function hasAnyProtectionOrPlanRules(): bool
    {
        $cacheKey = 'resource_access_policy:any:' . self::cacheGeneration();
        $cached = wp_cache_get($cacheKey, self::CACHE_GROUP);
        if ($cached !== false) {
            return (bool) $cached;
        }

        $hasProtectionRules = $this->protection->hasAnyRules();
        $hasRules = $hasProtectionRules || $this->rules->hasAnyRules();
        wp_cache_set($cacheKey, $hasRules ? 1 : 0, self::CACHE_GROUP);
        return $hasRules;
    }

    public function canUseGlobalRuleShortcut(): bool
    {
        return get_class($this->rules) === PlanRuleResolver::class
            && get_class($this->protection) === ProtectionRuleRepository::class;
    }

    public function ensurePlanPath(ResourceAccessPolicy $policy, int $planId): void
    {
        if ($policy->allowsPlan($planId)) {
            return;
        }

        if ($this->rules->planHasResource(
            $planId,
            $policy->provider(),
            $policy->resourceType(),
            $policy->resourceId()
        )) {
            $dripRule = $this->rules->getDripRule(
                $planId,
                $policy->provider(),
                $policy->resourceType(),
                $policy->resourceId()
            );
            $policy->addPlanPath(
                $planId,
                $dripRule,
                'resource',
                [
                    'provider' => $policy->provider(),
                    'resource_type' => (string) ($dripRule['resource_type'] ?? $policy->resourceType()),
                    'resource_id' => (string) ($dripRule['resource_id'] ?? $policy->resourceId()),
                ]
            );
            return;
        }

        if ($policy->provider() !== Constants::PROVIDER_WORDPRESS_CORE
            || !post_type_exists($policy->resourceType())
        ) {
            return;
        }

        $post = get_post((int) $policy->resourceId());
        if (!$post) {
            return;
        }
        foreach (get_object_taxonomies($policy->resourceType(), 'names') as $taxonomy) {
            $terms = get_the_terms($post->ID, $taxonomy);
            if (!$terms || is_wp_error($terms)) {
                continue;
            }
            foreach ($terms as $term) {
                $termId = (string) $term->term_id;
                if (!$this->rules->planHasResource(
                    $planId,
                    Constants::PROVIDER_WORDPRESS_CORE,
                    (string) $taxonomy,
                    $termId
                )) {
                    continue;
                }
                $dripRule = $this->rules->getDripRule(
                    $planId,
                    Constants::PROVIDER_WORDPRESS_CORE,
                    (string) $taxonomy,
                    $termId
                );
                $policy->addPlanPath($planId, $dripRule, 'resource', [
                    'provider' => Constants::PROVIDER_WORDPRESS_CORE,
                    'resource_type' => (string) $taxonomy,
                    'resource_id' => (string) ($dripRule['resource_id'] ?? $termId),
                ]);
            }
        }
    }

    private function addProtectionRule(ResourceAccessPolicy $policy, ?array $rule): void
    {
        if (!$rule) {
            return;
        }

        $planIds = array_values(array_unique(array_map('intval', $rule['plan_ids'] ?? [])));
        if ($planIds === []) {
            $policy->allowAnyActivePlan();
            return;
        }

        foreach ($this->rules->findPlansIncluding($planIds) as $planId) {
            $policy->addPlanPath($planId, null);
        }
    }

    private function addPlanRulePaths(
        ResourceAccessPolicy $policy,
        string $provider,
        string $resourceType,
        string $resourceId
    ): void {
        foreach ($this->rules->findPlansWithResource($provider, $resourceType, $resourceId) as $planId) {
            $dripRule = $this->rules->getDripRule((int) $planId, $provider, $resourceType, $resourceId);
            $policy->addPlanPath(
                (int) $planId,
                $dripRule,
                'resource',
                [
                    'provider' => $provider,
                    'resource_type' => (string) ($dripRule['resource_type'] ?? $resourceType),
                    'resource_id' => (string) ($dripRule['resource_id'] ?? $resourceId),
                ]
            );
        }
    }

    /** @param array<string, mixed> $rule */
    private function addResolvedRulePath(ResourceAccessPolicy $policy, int $planId, array $rule): void
    {
        $policy->addPlanPath($planId, $rule, 'resource', [
            'provider' => (string) $rule['provider'],
            'resource_type' => (string) $rule['resource_type'],
            'resource_id' => (string) $rule['resource_id'],
        ]);
    }

    private function cacheKey(string $provider, string $resourceType, string $resourceId): string
    {
        return implode("\0", [$provider, $resourceType, $resourceId]);
    }

    private function cachedPolicy(string $identity): ?ResourceAccessPolicy
    {
        $this->ensureCacheContext();
        return self::$requestPolicies[$identity] ?? null;
    }

    private function cachePolicy(string $identity, ResourceAccessPolicy $policy): void
    {
        $this->ensureCacheContext();
        self::$requestPolicies[$identity] = $policy;
    }

    private function ensureCacheContext(): void
    {
        global $wpdb;
        if (self::$cacheContext === $wpdb) {
            return;
        }

        self::$requestPolicies = [];
        self::$cacheContext = $wpdb;
    }

    private static function cacheGeneration(): int
    {
        $generation = wp_cache_get(self::CACHE_GENERATION_KEY, self::CACHE_GROUP);
        if ($generation === false) {
            $generation = 1;
            wp_cache_set(self::CACHE_GENERATION_KEY, $generation, self::CACHE_GROUP);
        }
        return (int) $generation;
    }

    private function resourceKey(string $resourceType, string $resourceId): string
    {
        return $resourceType . "\0" . $resourceId;
    }

    /**
     * @param array<int|string, mixed> $resources
     * @param array<int|string, ResourceAccessPolicy> $policies
     * @return array<int|string, ResourceAccessPolicy>
     */
    private function orderPolicies(array $resources, array $policies): array
    {
        $ordered = [];
        foreach (array_keys($resources) as $key) {
            $ordered[$key] = $policies[$key];
        }
        return $ordered;
    }

    private function addTaxonomyPaths(
        ResourceAccessPolicy $policy,
        string $postType,
        string $resourceId
    ): void {
        $post = get_post((int) $resourceId);
        if (!$post) {
            return;
        }

        foreach (get_object_taxonomies($postType, 'names') as $taxonomy) {
            $terms = get_the_terms($post->ID, $taxonomy);
            if (!$terms || is_wp_error($terms)) {
                continue;
            }

            foreach ($terms as $term) {
                $termId = (string) $term->term_id;
                $protectionRule = $this->protection->findByResource($taxonomy, $termId);
                if (($protectionRule['meta']['inheritance_mode'] ?? 'none') === 'all_posts') {
                    $this->addProtectionRule($policy, $protectionRule);
                }
                $this->addPlanRulePaths(
                    $policy,
                    Constants::PROVIDER_WORDPRESS_CORE,
                    (string) $taxonomy,
                    $termId
                );
            }
        }
    }
}

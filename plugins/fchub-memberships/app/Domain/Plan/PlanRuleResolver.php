<?php

namespace FChubMemberships\Domain\Plan;

defined('ABSPATH') || exit;

use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Storage\PlanRuleRepository;

class PlanRuleResolver
{
    private const CACHE_GROUP = 'fchub_memberships';
    private const CACHE_GENERATION_KEY = 'access_policy_generation';

    /**
     * Maximum plan hierarchy depth to prevent infinite recursion.
     * Plans nested deeper than this level are silently excluded.
     */
    public const MAX_HIERARCHY_DEPTH = 5;

    private PlanRepository $planRepo;
    private PlanRuleRepository $ruleRepo;

    public function __construct(?PlanRepository $planRepo = null, ?PlanRuleRepository $ruleRepo = null)
    {
        $this->planRepo = $planRepo ?? new PlanRepository();
        $this->ruleRepo = $ruleRepo ?? new PlanRuleRepository();
    }

    /**
     * Resolve all plan IDs in the hierarchy (including the plan itself and all included plans).
     */
    public function resolvePlanIds(int $planId): array
    {
        $cacheKey = $this->cacheKey('hierarchy:' . $planId);
        $cached = wp_cache_get($cacheKey, self::CACHE_GROUP);
        if ($cached !== false) {
            return $cached;
        }

        $collected = [];
        $this->collectPlanIds($planId, $collected, 0);

        wp_cache_set($cacheKey, $collected, self::CACHE_GROUP, 300);
        return $collected;
    }

    /**
     * Resolve all access rules for a plan (including inherited from included plans).
     */
    public function resolveRules(int $planId): array
    {
        $cacheKey = $this->cacheKey('rules:' . $planId);
        $cached = wp_cache_get($cacheKey, self::CACHE_GROUP);
        if ($cached !== false) {
            return $cached;
        }
        $planIds = $this->resolvePlanIds($planId);
        $rules = $this->ruleRepo->getByPlanIds($planIds);
        wp_cache_set($cacheKey, $rules, self::CACHE_GROUP, 300);
        return $rules;
    }

    /**
     * Resolve all unique rules for a plan, deduplicating by resource.
     * When duplicates exist, the most permissive drip (earliest unlock) wins.
     */
    public function resolveUniqueRules(int $planId): array
    {
        $allRules = $this->resolveRules($planId);

        $uniqueRules = [];
        foreach ($allRules as $rule) {
            $key = $rule['provider'] . ':' . $rule['resource_type'] . ':' . $rule['resource_id'];

            if (!isset($uniqueRules[$key])) {
                $uniqueRules[$key] = $rule;
                continue;
            }

            // Keep the most permissive (earliest drip unlock)
            $existing = $uniqueRules[$key];
            if ($this->isMorePermissive($rule, $existing)) {
                $uniqueRules[$key] = $rule;
            }
        }

        return array_values($uniqueRules);
    }

    /**
     * Check if a specific resource is in a plan's rules (including inherited plans).
     */
    public function planHasResource(int $planId, string $provider, string $resourceType, string $resourceId): bool
    {
        $rules = $this->resolveUniqueRules($planId);

        foreach ($rules as $rule) {
            if ($rule['provider'] === $provider
                && $rule['resource_type'] === $resourceType
                && ($rule['resource_id'] === $resourceId || $rule['resource_id'] === '*')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the drip rule for a specific resource in a plan.
     */
    public function getDripRule(int $planId, string $provider, string $resourceType, string $resourceId): ?array
    {
        $rules = $this->resolveUniqueRules($planId);
        $winner = null;

        foreach ($rules as $rule) {
            if ($rule['provider'] === $provider
                && $rule['resource_type'] === $resourceType
                && ($rule['resource_id'] === $resourceId || $rule['resource_id'] === '*')
            ) {
                if ($winner === null || $this->isMorePermissive($rule, $winner)) {
                    $winner = $rule;
                }
            }
        }

        return $winner;
    }

    /**
     * Get all plans that include a specific resource in their rules.
     */
    public function findPlansWithResource(string $provider, string $resourceType, string $resourceId): array
    {
        $cacheKey = implode("\0", [$provider, $resourceType, $resourceId]);
        $sharedKey = $this->cacheKey('resource:' . hash('sha256', $cacheKey));
        $cached = wp_cache_get($sharedKey, self::CACHE_GROUP);
        if ($cached !== false) {
            return $cached;
        }

        $directPlanIds = $this->ruleRepo->findPlansWithResource($provider, $resourceType, $resourceId);

        // Also find plans that include these plans via hierarchy
        $allPlanIds = $directPlanIds;
        $allPlans = $this->planRepo->getActivePlans();

        foreach ($allPlans as $plan) {
            if (in_array($plan['id'], $allPlanIds, true)) {
                continue;
            }

            $resolvedIds = $this->resolvePlanIds($plan['id']);
            foreach ($directPlanIds as $directId) {
                if (in_array($directId, $resolvedIds, true)) {
                    $allPlanIds[] = $plan['id'];
                    break;
                }
            }
        }

        $allPlanIds = array_values(array_unique(array_map('intval', $allPlanIds)));
        wp_cache_set($sharedKey, $allPlanIds, self::CACHE_GROUP, 300);
        return $allPlanIds;
    }

    /**
     * Expand explicitly eligible plans to active plans which include them.
     *
     * @param array<int> $planIds
     * @return array<int>
     */
    public function findPlansIncluding(array $planIds): array
    {
        $planIds = array_values(array_unique(array_map('intval', $planIds)));
        if ($planIds === []) {
            return [];
        }

        $eligible = $planIds;
        foreach ($this->planRepo->getActivePlans() as $plan) {
            $candidateId = (int) $plan['id'];
            if (in_array($candidateId, $eligible, true)) {
                continue;
            }
            if (array_intersect($planIds, $this->resolvePlanIds($candidateId)) !== []) {
                $eligible[] = $candidateId;
            }
        }

        sort($eligible, SORT_NUMERIC);
        return $eligible;
    }

    /**
     * Resolve matching plan-rule paths for a keyed resource batch.
     *
     * @param array<int|string, array{provider: string, resource_type: string, resource_id: string}> $resources
     * @return array<int|string, list<array{plan_id: int, rule: array<string, mixed>}>>
     */
    public function findPathsForResourcesBatch(array $resources): array
    {
        $result = array_fill_keys(array_keys($resources), []);
        if ($resources === []) {
            return $result;
        }

        $plans = $this->planRepo->getActivePlans();
        $allRules = $this->ruleRepo->getAllForAccessResolution();
        if ($allRules === []) {
            return $result;
        }

        $rulesByPlan = [];
        foreach ($allRules as $rule) {
            $rulesByPlan[(int) $rule['plan_id']][] = $rule;
        }
        $planMap = [];
        foreach ($plans as $plan) {
            $planMap[(int) $plan['id']] = $plan;
        }

        $candidateIds = array_values(array_unique(array_merge(
            array_map(static fn(array $plan): int => (int) $plan['id'], $plans),
            array_map('intval', array_keys($rulesByPlan))
        )));
        foreach ($candidateIds as $candidateId) {
            $resolvedPlanIds = [];
            $this->collectPlanIdsFromMap($candidateId, $planMap, $resolvedPlanIds, 0);
            foreach ($resources as $resourceKey => $resource) {
                $winner = null;
                foreach ($resolvedPlanIds as $resolvedPlanId) {
                    foreach ($rulesByPlan[$resolvedPlanId] ?? [] as $rule) {
                        if ((string) $rule['provider'] !== (string) $resource['provider']
                            || (string) $rule['resource_type'] !== (string) $resource['resource_type']
                            || !in_array((string) $rule['resource_id'], [(string) $resource['resource_id'], '*'], true)
                        ) {
                            continue;
                        }
                        if ($winner === null || $this->isMorePermissive($rule, $winner)) {
                            $winner = $rule;
                        }
                    }
                }
                if ($winner !== null) {
                    $result[$resourceKey][] = ['plan_id' => $candidateId, 'rule' => $winner];
                }
            }
        }

        return $result;
    }

    public function hasAnyRules(): bool
    {
        return $this->ruleRepo->hasAnyRules();
    }

    public function clearRequestCache(): void
    {
        self::invalidateSharedCache();
    }

    public static function invalidateSharedCache(): void
    {
        $generation = (int) wp_cache_get(self::CACHE_GENERATION_KEY, self::CACHE_GROUP);
        wp_cache_set(self::CACHE_GENERATION_KEY, max(1, $generation + 1), self::CACHE_GROUP);
    }

    /**
     * @param array<int, array<string, mixed>> $planMap
     * @param array<int> $collected
     */
    private function collectPlanIdsFromMap(int $planId, array $planMap, array &$collected, int $depth): void
    {
        if ($depth > self::MAX_HIERARCHY_DEPTH || in_array($planId, $collected, true)) {
            return;
        }
        $collected[] = $planId;
        foreach ($planMap[$planId]['includes_plan_ids'] ?? [] as $includedId) {
            $this->collectPlanIdsFromMap((int) $includedId, $planMap, $collected, $depth + 1);
        }
    }

    private function cacheKey(string $suffix): string
    {
        return 'access_policy:' . self::cacheGeneration() . ':' . $suffix;
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

    private function collectPlanIds(int $planId, array &$collected, int $depth): void
    {
        if ($depth > self::MAX_HIERARCHY_DEPTH || in_array($planId, $collected, true)) {
            return;
        }

        $collected[] = $planId;

        $plan = $this->planRepo->find($planId);
        if (!$plan || empty($plan['includes_plan_ids'])) {
            return;
        }

        foreach ($plan['includes_plan_ids'] as $includedId) {
            $this->collectPlanIds((int) $includedId, $collected, $depth + 1);
        }
    }

    /**
     * Check if rule A is more permissive than rule B (earlier drip unlock).
     */
    private function isMorePermissive(array $ruleA, array $ruleB): bool
    {
        // Immediate is always most permissive
        if ($ruleA['drip_type'] === 'immediate') {
            return true;
        }
        if ($ruleB['drip_type'] === 'immediate') {
            return false;
        }

        // For delayed type, fewer days = more permissive
        if ($ruleA['drip_type'] === 'delayed' && $ruleB['drip_type'] === 'delayed') {
            return $ruleA['drip_delay_days'] < $ruleB['drip_delay_days'];
        }

        // For fixed_date, earlier date = more permissive
        if ($ruleA['drip_type'] === 'fixed_date' && $ruleB['drip_type'] === 'fixed_date') {
            return strtotime($ruleA['drip_date']) < strtotime($ruleB['drip_date']);
        }

        // Mixed comparison: delayed vs fixed_date cannot be accurately resolved without
        // the grant's created_at date (needed to convert delay_days to an absolute date).
        // Intentional simplification: delayed is treated as more permissive. In practice,
        // delay values are short (days/weeks) while fixed_date tends to be further out.
        // If this assumption breaks, resolveUniqueRules() should accept a reference date.
        return $ruleA['drip_type'] === 'delayed';
    }
}

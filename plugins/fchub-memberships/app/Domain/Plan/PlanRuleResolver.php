<?php

namespace FChubMemberships\Domain\Plan;

defined('ABSPATH') || exit;

use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Storage\PlanRuleRepository;

class PlanRuleResolver
{
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
        $cacheKey = 'fchub_memberships_hierarchy_' . $planId;
        $cached = wp_cache_get($cacheKey, 'fchub_memberships');
        if ($cached !== false) {
            return $cached;
        }

        $collected = [];
        $this->collectPlanIds($planId, $collected, 0);

        wp_cache_set($cacheKey, $collected, 'fchub_memberships', 300);
        return $collected;
    }

    /**
     * Resolve all access rules for a plan (including inherited from included plans).
     */
    public function resolveRules(int $planId): array
    {
        $planIds = $this->resolvePlanIds($planId);
        return $this->ruleRepo->getByPlanIds($planIds);
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

        foreach ($rules as $rule) {
            if ($rule['provider'] === $provider
                && $rule['resource_type'] === $resourceType
                && ($rule['resource_id'] === $resourceId || $rule['resource_id'] === '*')
            ) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Get all plans that include a specific resource in their rules.
     */
    public function findPlansWithResource(string $provider, string $resourceType, string $resourceId): array
    {
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

        return array_unique($allPlanIds);
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

<?php

namespace FChubMemberships\Integration;

defined('ABSPATH') || exit;

use FChubMemberships\Storage\PlanRuleRepository;

final class FluentCommunitySync
{
    private PlanRuleRepository $rules;

    /** @var \Closure(int): ?string */
    private \Closure $resourceTypeResolver;

    private MembershipSettingsOptionCoordinator $settings;

    public function __construct(
        ?PlanRuleRepository $rules = null,
        ?callable $resourceTypeResolver = null,
        ?MembershipSettingsOptionCoordinator $settings = null
    ) {
        $this->rules = $rules ?? new PlanRuleRepository();
        $this->resourceTypeResolver = $resourceTypeResolver !== null
            ? \Closure::fromCallable($resourceTypeResolver)
            : $this->resolveInstalledResourceType(...);
        $this->settings = $settings ?? new MembershipSettingsOptionCoordinator();
    }

    public function register(): void
    {
        if (!defined('FLUENT_COMMUNITY_PLUGIN_VERSION')) {
            return;
        }

        $this->settings->synchronized(function (MembershipSettingsOptionCoordinator $coordinator): bool {
            $settings = $coordinator->read();
            if (($settings['fc_enabled'] ?? 'no') !== 'yes') {
                return true;
            }

            return $this->migrateLegacyMappingsLocked($coordinator, $settings);
        });
    }

    /**
     * Convert the legacy plan-to-space map into canonical plan rules.
     *
     * The saved map is cleared only after every missing rule has been written.
     * Failed attempts retain both the compatibility payload and any canonical
     * rule already created, so retries can deduplicate without removing access.
     */
    public function migrateLegacyMappings(?int $onlyPlanId = null): bool
    {
        $result = $this->settings->synchronized(
            fn(MembershipSettingsOptionCoordinator $coordinator): bool => $this->migrateLegacyMappingsLocked(
                $coordinator,
                $coordinator->read(),
                $onlyPlanId
            )
        );

        if (!$result['success']) {
            return false;
        }

        return $result['value'] === true;
    }

    /** @return array{ready:bool, reason:string, message?:string} */
    public function ensurePlanReady(int $planId): array
    {
        $result = $this->settings->synchronized(function (MembershipSettingsOptionCoordinator $coordinator) use ($planId): array {
            $settings = $coordinator->read();
            if (($settings['fc_enabled'] ?? 'no') !== 'yes') {
                return ['ready' => true, 'reason' => 'integration_disabled'];
            }

            $mappings = $settings['fc_space_mappings'] ?? [];
            $resourceId = is_array($mappings) ? ($mappings[$planId] ?? null) : null;
            if ($resourceId === null || (is_string($resourceId) && trim($resourceId) === '')) {
                return ['ready' => true, 'reason' => 'no_legacy_mapping'];
            }

            $this->migrateLegacyMappingsLocked($coordinator, $settings, $planId);
            if ($this->canonicalEntitlementExists($planId, $resourceId)) {
                return ['ready' => true, 'reason' => 'canonical_rule_present'];
            }

            return [
                'ready' => false,
                'reason' => 'legacy_mapping_not_converted',
                'message' => __('The legacy FluentCommunity mapping could not be converted to a canonical plan rule.', 'fchub-memberships'),
            ];
        });

        if (!$result['success']) {
            return [
                'ready' => false,
                'reason' => $result['reason'] ?? 'legacy_mapping_check_failed',
                'message' => __('The legacy FluentCommunity mapping could not be checked safely.', 'fchub-memberships'),
            ];
        }

        return $result['value'];
    }

    private function migrateLegacyMappingsLocked(
        MembershipSettingsOptionCoordinator $coordinator,
        array $settings,
        ?int $onlyPlanId = null
    ): bool {
        $mappings = $settings['fc_space_mappings'] ?? [];
        if (!is_array($mappings)) {
            return false;
        }

        if ($onlyPlanId !== null) {
            $mappings = array_key_exists($onlyPlanId, $mappings)
                ? [$onlyPlanId => $mappings[$onlyPlanId]]
                : [];
        }

        if ($mappings === []) {
            return true;
        }

        $candidates = $this->migrationCandidates($mappings);
        if ($candidates === null) {
            return false;
        }

        foreach ($candidates as $candidate) {
            if ($this->canonicalRuleExists($candidate)) {
                continue;
            }

            $ruleId = $this->rules->create([
                'plan_id' => $candidate['plan_id'],
                'provider' => 'fluent_community',
                'resource_type' => $candidate['resource_type'],
                'resource_id' => $candidate['resource_id'],
                'drip_type' => 'immediate',
                'drip_delay_days' => 0,
                'meta' => ['legacy_source' => 'fc_space_mappings'],
            ]);

            if ($ruleId <= 0) {
                return false;
            }
        }

        $next = $settings;
        if ($onlyPlanId === null) {
            $next['fc_space_mappings'] = [];
        } else {
            unset($next['fc_space_mappings'][$onlyPlanId]);
        }

        return $coordinator->compareAndSwap($settings, $next)['success'];
    }

    /**
     * @return list<array{plan_id:int, resource_type:string, resource_id:string}>|null
     */
    private function migrationCandidates(array $mappings): ?array
    {
        $candidates = [];

        foreach ($mappings as $planId => $resourceId) {
            if ($resourceId === null || (is_string($resourceId) && trim($resourceId) === '')) {
                continue;
            }

            if (!$this->isPositiveInteger($planId) || !$this->isPositiveInteger($resourceId)) {
                return null;
            }

            $planId = (int) $planId;
            $resourceId = (int) $resourceId;
            $installedType = ($this->resourceTypeResolver)($resourceId);
            $resourceType = match ($installedType) {
                'community' => 'fc_space',
                'course' => 'fc_course',
                default => null,
            };

            if ($resourceType === null) {
                return null;
            }

            $key = $planId . ':' . $resourceType . ':' . $resourceId;
            $candidates[$key] = [
                'plan_id' => $planId,
                'resource_type' => $resourceType,
                'resource_id' => (string) $resourceId,
            ];
        }

        return array_values($candidates);
    }

    /** @param array{plan_id:int, resource_type:string, resource_id:string} $candidate */
    private function canonicalRuleExists(array $candidate): bool
    {
        foreach ($this->rules->getByPlanId($candidate['plan_id']) as $rule) {
            if (($rule['provider'] ?? '') === 'fluent_community'
                && ($rule['resource_type'] ?? '') === $candidate['resource_type']
                && (string) ($rule['resource_id'] ?? '') === $candidate['resource_id']
            ) {
                return true;
            }
        }

        return false;
    }

    private function canonicalEntitlementExists(int $planId, mixed $resourceId): bool
    {
        if (!$this->isPositiveInteger($resourceId)) {
            return false;
        }

        $resourceId = (string) (int) $resourceId;
        foreach ($this->rules->getByPlanId($planId) as $rule) {
            if (($rule['provider'] ?? '') === 'fluent_community'
                && in_array(($rule['resource_type'] ?? ''), ['fc_space', 'fc_course'], true)
                && (string) ($rule['resource_id'] ?? '') === $resourceId
            ) {
                return true;
            }
        }

        return false;
    }

    private function resolveInstalledResourceType(int $resourceId): ?string
    {
        if (!class_exists('FluentCommunity\\App\\Models\\BaseSpace')) {
            return null;
        }

        try {
            $space = \FluentCommunity\App\Models\BaseSpace::withoutGlobalScopes()->find($resourceId);
        } catch (\Throwable) {
            return null;
        }

        $type = (string) ($space->type ?? '');
        return in_array($type, ['community', 'course'], true) ? $type : null;
    }

    private function isPositiveInteger(mixed $value): bool
    {
        return (is_int($value) && $value > 0)
            || (is_string($value) && ctype_digit($value) && (int) $value > 0);
    }
}

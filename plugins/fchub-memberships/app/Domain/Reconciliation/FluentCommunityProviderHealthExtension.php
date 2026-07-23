<?php

declare(strict_types=1);

namespace FChubMemberships\Domain\Reconciliation;

use FluentCommunity\App\Services\Helper;
use FluentCommunity\App\Models\XProfile;
use FChubMemberships\Domain\Reconciliation\Contracts\ProviderHealthExtensionInterface;
use FChubMemberships\Integration\Community\CommunityCapabilityRegistry;

defined('ABSPATH') || exit;

final class FluentCommunityProviderHealthExtension implements ProviderHealthExtensionInterface
{
    private \Closure $membershipResolver;
    private \Closure $badgeMembershipResolver;
    private ?\Closure $availability = null;
    private CommunityCapabilityRegistry $communityCapabilities;
    private bool $capabilityResolved = false;
    private bool $capabilityFailed = false;
    private ?ProviderHealthCapability $resolvedCapability = null;

    public function __construct(
        ?callable $membershipResolver = null,
        ?callable $availability = null,
        ?CommunityCapabilityRegistry $communityCapabilities = null,
        ?callable $badgeMembershipResolver = null
    ) {
        $this->membershipResolver = \Closure::fromCallable($membershipResolver ?? self::resolveMembership(...));
        $this->badgeMembershipResolver = \Closure::fromCallable(
            $badgeMembershipResolver ?? self::resolveBadgeMembership(...)
        );
        if ($availability !== null) {
            $this->availability = \Closure::fromCallable($availability);
        }
        $this->communityCapabilities = $communityCapabilities ?? new CommunityCapabilityRegistry();
    }

    public function capability(): ProviderHealthCapability
    {
        if (!$this->capabilityResolved) {
            $this->capabilityResolved = true;
            try {
                $this->resolvedCapability = $this->resolveCapability();
            } catch (\Throwable) {
                $this->capabilityFailed = true;
            }
        }
        if ($this->capabilityFailed || !$this->resolvedCapability instanceof ProviderHealthCapability) {
            throw new \RuntimeException('Provider capability could not be resolved.');
        }

        return $this->resolvedCapability;
    }

    private function resolveCapability(): ProviderHealthCapability
    {
        if ($this->availability !== null) {
            $available = (bool) ($this->availability)();

            return new ProviderHealthCapability(
                'fluent_community',
                true,
                $available,
                ['fc_space', 'fc_course'],
                $available ? 'healthy' : 'inactive',
                defined('FLUENT_COMMUNITY_PLUGIN_VERSION') ? (string) FLUENT_COMMUNITY_PLUGIN_VERSION : null,
                $available ? 'community_core_available' : 'community_core_inactive'
            );
        }

        $capabilities = $this->communityCapabilities->capabilities();
        $resourceTypes = [];
        if ($this->communityCapabilities->supports('spaces')) {
            $resourceTypes[] = 'fc_space';
        }
        if ($this->communityCapabilities->supports('courses')) {
            $resourceTypes[] = 'fc_course';
        }
        if ($this->communityCapabilities->supports('badges')) {
            $resourceTypes[] = 'fc_badge';
        }
        $coreStates = array_intersect_key(
            $capabilities,
            array_flip(['spaces', 'courses', 'profile_verification_read'])
        );
        $statuses = array_column($coreStates, 'status');
        $status = 'healthy';
        foreach (['inactive', 'incompatible', 'disabled', 'unverified'] as $candidate) {
            if (in_array($candidate, $statuses, true)) {
                $status = $candidate;
                break;
            }
        }
        $reason = 'community_core_available';
        if ($status !== 'healthy') {
            foreach ($coreStates as $state) {
                if (($state['status'] ?? '') === $status) {
                    $reason = (string) ($state['reason'] ?? 'community_capability_unavailable');
                    break;
                }
            }
        }

        return new ProviderHealthCapability(
            'fluent_community',
            true,
            $resourceTypes !== [],
            $resourceTypes,
            $status,
            $capabilities['spaces']['version'] ?? null,
            $reason,
            $capabilities
        );
    }

    public function observe(ProviderResource $resource): ProviderHealthObservation
    {
        try {
            $capability = $this->capability();
        } catch (\Throwable) {
            return new ProviderHealthObservation('unknown', 'provider_observation_failed');
        }
        if (!$capability->supports($resource) || !$capability->available) {
            return new ProviderHealthObservation('unknown', 'provider_unavailable');
        }

        try {
            $present = $resource->resourceType === 'fc_badge'
                ? (bool) ($this->badgeMembershipResolver)($resource->userId, $resource->resourceId)
                : (bool) ($this->membershipResolver)($resource->userId, (int) $resource->resourceId);

            return new ProviderHealthObservation(
                $present ? 'present' : 'absent',
                $present ? 'relation_present' : 'relation_absent'
            );
        } catch (\Throwable) {
            return new ProviderHealthObservation('unknown', 'provider_observation_failed');
        }
    }

    private static function resolveMembership(int $userId, int $resourceId): bool
    {
        return Helper::isUserInSpace($userId, $resourceId);
    }

    private static function resolveBadgeMembership(int $userId, string $badgeSlug): bool
    {
        $profile = XProfile::where('user_id', $userId)->first();
        $meta = $profile->meta ?? [];
        if (is_string($meta)) {
            $meta = json_decode($meta, true) ?: [];
        }
        $slugs = is_array($meta) && is_array($meta['badge_slug'] ?? null)
            ? $meta['badge_slug']
            : [];

        return in_array($badgeSlug, $slugs, true);
    }
}

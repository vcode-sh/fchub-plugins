<?php

namespace FChubMemberships\Integration;

defined('ABSPATH') || exit;

final class FluentCommunityMappingPolicy
{
    public function isStillGranted(
        array $mappings,
        string $resourceId,
        array $activePlanIds
    ): bool {
        foreach ($activePlanIds as $activePlanId) {
            $activePlanId = (int) $activePlanId;
            if ((string) ($mappings[$activePlanId] ?? '') === $resourceId) {
                return true;
            }
        }

        return false;
    }
}

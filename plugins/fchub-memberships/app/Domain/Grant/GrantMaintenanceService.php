<?php

namespace FChubMemberships\Domain\Grant;

use FChubMemberships\Domain\AuditLogger;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\GrantSourceRepository;

defined('ABSPATH') || exit;

final class GrantMaintenanceService
{
    public function __construct(
        private GrantRepository $grants,
        private GrantSourceRepository $sources
    ) {
    }

    public function extendExpiry(int $userId, int $planId, string $newExpiresAt, ?int $renewalSourceId = null): int
    {
        $grants = $this->grants->getByUserId($userId, ['plan_id' => $planId, 'status' => 'active']);
        $extended = 0;

        foreach ($grants as $grant) {
            $updateData = ['expires_at' => $newExpiresAt];

            if ($renewalSourceId) {
                $sourceIds = $grant['source_ids'];
                if (!in_array($renewalSourceId, $sourceIds, false)) {
                    $sourceIds[] = $renewalSourceId;
                    $updateData['source_ids'] = $sourceIds;
                }
                $this->sources->addSource($grant['id'], $grant['source_type'] ?: 'order', $renewalSourceId);
            }

            $this->grants->update($grant['id'], $updateData);
            $extended++;
        }

        return $extended;
    }

    public function expireOverdueGrantsWithHooks(): int
    {
        $overdueGrants = $this->grants->getOverdueGrants();
        if (empty($overdueGrants)) {
            return 0;
        }

        foreach ($overdueGrants as $grant) {
            do_action('fchub_memberships/grant_expired', $grant);
            AuditLogger::logGrantChange($grant['id'], 'expired', $grant, ['status' => 'expired']);
        }

        return $this->grants->expireOverdueGrants();
    }
}

<?php

namespace FChubMemberships\Domain\Grant;

use FChubMemberships\Domain\AuditLogger;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\GrantSourceRepository;
use FChubMemberships\Domain\Grant\GrantStatusService;

defined('ABSPATH') || exit;

final class GrantMaintenanceService
{
    private ?GrantStatusService $statusService;

    public function __construct(
        private GrantRepository $grants,
        private GrantSourceRepository $sources,
        ?GrantStatusService $statusService = null
    ) {
        $this->statusService = $statusService;
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

    /**
     * Pause overdue anchor grants instead of expiring them.
     * Anchor grants are recoverable — late payment resumes access.
     */
    public function pauseOverdueAnchorGrants(): int
    {
        $overdueGrants = $this->grants->getOverdueAnchorGrants();
        if (empty($overdueGrants)) {
            return 0;
        }

        $statusService = $this->statusService;
        $count = 0;

        foreach ($overdueGrants as $grant) {
            if ($statusService) {
                $statusService->pauseGrant($grant['id'], 'Anchor billing date overdue');
            } else {
                $this->grants->update($grant['id'], [
                    'status' => 'paused',
                    'meta' => array_merge($grant['meta'], [
                        'paused_at' => current_time('mysql'),
                        'pause_reason' => 'Anchor billing date overdue',
                    ]),
                ]);
                AuditLogger::logGrantChange($grant['id'], 'paused', $grant, ['status' => 'paused'], 'Anchor billing date overdue');
                do_action('fchub_memberships/grant_paused', $grant, 'Anchor billing date overdue');
            }
            $count++;
        }

        return $count;
    }

    /**
     * Expire grants whose membership term has ended.
     * Catches lifetime grants (expires_at = null) that have a term cap,
     * plus any other grants whose term date has passed.
     */
    public function expireTermExpiredGrants(): int
    {
        $termExpired = $this->grants->getTermExpiredGrants();
        if (empty($termExpired)) {
            return 0;
        }

        $count = 0;
        foreach ($termExpired as $grant) {
            $meta = array_merge($grant['meta'], [
                'expired_reason' => 'membership_term_reached',
            ]);

            $this->grants->update($grant['id'], [
                'status' => 'expired',
                'meta'   => $meta,
            ]);

            AuditLogger::logGrantChange($grant['id'], 'expired', $grant, [
                'status' => 'expired',
                'meta'   => $meta,
            ], 'Membership term reached');

            do_action('fchub_memberships/grant_expired', $grant);
            do_action('fchub_memberships/grant_term_expired', $grant);
            $count++;
        }

        return $count;
    }

    public function expireOverdueGrantsWithHooks(): int
    {
        $overdueGrants = $this->grants->getOverdueGrants();
        if (empty($overdueGrants)) {
            return 0;
        }

        $count = $this->grants->expireOverdueGrants();

        foreach ($overdueGrants as $grant) {
            AuditLogger::logGrantChange($grant['id'], 'expired', $grant, ['status' => 'expired']);
            do_action('fchub_memberships/grant_expired', $grant);
        }

        return $count;
    }
}

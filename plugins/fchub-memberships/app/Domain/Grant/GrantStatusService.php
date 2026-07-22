<?php

namespace FChubMemberships\Domain\Grant;

use FChubMemberships\Domain\AuditLogger;
use FChubMemberships\Domain\GrantNotificationService;
use FChubMemberships\Domain\StatusTransitionValidator;
use FChubMemberships\Storage\GrantRepository;

defined('ABSPATH') || exit;

final class GrantStatusService
{
    public function __construct(
        private GrantRepository $grants,
        private GrantNotificationService $notifications
    ) {
    }

    public function pauseGrant(int $grantId, string $reason = ''): array
    {
        $grant = $this->grants->find($grantId);
        if (!$grant) {
            return ['error' => 'Grant not found'];
        }

        StatusTransitionValidator::assertTransition($grant['status'], 'paused');

        $updated = $this->grants->update($grantId, [
            'status' => 'paused',
            'meta' => array_merge($grant['meta'], [
                'paused_at' => current_time('mysql'),
                'pause_reason' => $reason,
            ]),
        ]);
        if (!$updated) {
            return [
                'success' => false,
                'error' => 'Grant update failed',
                'grant_id' => $grantId,
            ];
        }

        AuditLogger::logGrantChange($grantId, 'paused', $grant, ['status' => 'paused'], $reason);
        do_action('fchub_memberships/grant_paused', $grant, $reason);
        $this->notifications->sendPaused($grant);

        return ['success' => true, 'grant_id' => $grantId];
    }

    public function resumeGrant(int $grantId): array
    {
        $grant = $this->grants->find($grantId);
        if (!$grant) {
            return ['error' => 'Grant not found'];
        }

        StatusTransitionValidator::assertTransition($grant['status'], 'active');

        $updated = $this->grants->update($grantId, [
            'status' => 'active',
            'meta' => array_merge($grant['meta'], [
                'resumed_at' => current_time('mysql'),
            ]),
        ]);
        if (!$updated) {
            return [
                'success' => false,
                'error' => 'Grant update failed',
                'grant_id' => $grantId,
            ];
        }

        AuditLogger::logGrantChange($grantId, 'resumed', $grant, ['status' => 'active']);
        do_action('fchub_memberships/grant_resumed', $grant);
        $this->notifications->sendResumed($grant);

        return ['success' => true, 'grant_id' => $grantId];
    }
}

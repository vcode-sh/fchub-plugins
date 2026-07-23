<?php

namespace FChubMemberships\Domain\Grant;

use FChubMemberships\Domain\AuditLogger;
use FChubMemberships\Domain\Entitlement\EntitlementService;
use FChubMemberships\Domain\GrantNotificationService;
use FChubMemberships\Domain\ProviderOperationOutcome;
use FChubMemberships\Domain\ProviderOperationWorker;
use FChubMemberships\Storage\DripScheduleRepository;
use FChubMemberships\Storage\EntitlementEdgeRepository;
use FChubMemberships\Domain\StatusTransitionValidator;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\GrantSourceRepository;
use FChubMemberships\Storage\ProviderOperationRepository;

defined('ABSPATH') || exit;

final class GrantStatusService
{
    public function __construct(
        private GrantRepository $grants,
        private GrantNotificationService $notifications,
        private ?EntitlementService $entitlements = null,
        private ?ProviderOperationWorker $providerOperations = null
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

        $providerOutcome = $this->applyProviderTransition($grant, 'suspend');
        if (!in_array($providerOutcome->status, ['applied', 'already-applied'], true)) {
            return [
                'success' => false,
                'pending' => in_array($providerOutcome->status, ['deferred', 'retryable-failure'], true),
                'grant_id' => $grantId,
                'provider_outcome' => $providerOutcome,
                'error' => 'Provider suspension was not applied',
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

        $providerOutcome = $this->applyProviderTransition($grant, 'resume');
        if (!in_array($providerOutcome->status, ['applied', 'already-applied'], true)) {
            return [
                'success' => false,
                'pending' => in_array($providerOutcome->status, ['deferred', 'retryable-failure'], true),
                'grant_id' => $grantId,
                'provider_outcome' => $providerOutcome,
                'error' => 'Provider resumption was not applied',
            ];
        }

        AuditLogger::logGrantChange($grantId, 'resumed', $grant, ['status' => 'active']);
        do_action('fchub_memberships/grant_resumed', $grant);
        $this->notifications->sendResumed($grant);

        return ['success' => true, 'grant_id' => $grantId];
    }

    private function applyProviderTransition(array $grant, string $action): ProviderOperationOutcome
    {
        $provider = (string) ($grant['provider'] ?? '');
        if ($provider === '' || $provider === 'wordpress_core') {
            return ProviderOperationOutcome::alreadyApplied(
                'local_only_provider_operation',
                'WordPress content access is local-only.'
            );
        }

        try {
            $edges = $this->entitlements()->getActiveByResource($grant);
        } catch (\Throwable) {
            return ProviderOperationOutcome::retryableFailure(
                'provider_state_unreadable',
                'Provider assignment state could not be read.'
            );
        }
        if ($edges === []) {
            return ProviderOperationOutcome::terminalFailure(
                'provider_operation_edge_missing',
                'The provider operation edge no longer exists.'
            );
        }

        foreach ($edges as $edge) {
            if (($edge['owner'] ?? '') !== 'fchub'
                || ($edge['assignment_provenance'] ?? '') !== 'fchub_created'
            ) {
                return ProviderOperationOutcome::alreadyApplied(
                    'provider_assignment_preserved',
                    'The provider assignment is not exclusively owned by FCHub.'
                );
            }
        }

        $edge = reset($edges);
        $origin = 'membership_status:' . $action . ':' . (int) $grant['id'] . ':' . substr(
            hash('sha256', wp_json_encode($grant)),
            0,
            24
        );
        try {
            $operation = $this->providerOperations()->enqueue(
                (int) $edge['id'],
                $action,
                $origin
            );
            if ($operation === null) {
                return ProviderOperationOutcome::terminalFailure(
                    'provider_operation_missing',
                    'The provider operation no longer exists.'
                );
            }

            return $this->providerOperations()->process((int) $operation['id']);
        } catch (\Throwable) {
            return ProviderOperationOutcome::retryableFailure(
                'provider_operation_failed',
                'Provider operation failed.'
            );
        }
    }

    private function entitlements(): EntitlementService
    {
        return $this->entitlements ??= new EntitlementService(
            new EntitlementEdgeRepository(),
            $this->grants,
            null,
            new GrantSourceRepository(),
            new DripScheduleRepository(),
            new ProviderOperationRepository()
        );
    }

    private function providerOperations(): ProviderOperationWorker
    {
        return $this->providerOperations ??= new ProviderOperationWorker();
    }
}

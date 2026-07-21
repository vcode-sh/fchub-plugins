<?php

namespace FChubMemberships\Domain\Grant;

use FChubMemberships\Domain\AuditLogger;
use FChubMemberships\Domain\GrantAdapterRegistry;
use FChubMemberships\Domain\GrantNotificationService;
use FChubMemberships\Domain\StatusTransitionValidator;
use FChubMemberships\Storage\DripScheduleRepository;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\GrantSourceRepository;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Support\Logger;

defined('ABSPATH') || exit;

final class GrantRevocationService
{
    public function __construct(
        private GrantRepository $grants,
        private GrantSourceRepository $sources,
        private DripScheduleRepository $drips,
        private GrantAdapterRegistry $adapters,
        private GrantNotificationService $notifications
    ) {
    }

    public function revokePlan(int $userId, int $planId, array $context = []): array
    {
        $sourceId = (int) ($context['source_id'] ?? 0);
        $reason = $context['reason'] ?? '';
        $order = $context['order'] ?? null;

        $plan = (new PlanRepository())->find($planId);
        $gracePeriodDays = (int) ($context['grace_period_days'] ?? ($plan['grace_period_days'] ?? 0));

        $grants = $this->grants->getByUserId($userId, ['plan_id' => $planId]);
        $revoked = 0;
        $retained = 0;
        $failed = 0;
        $errors = [];
        $revokedGrants = [];

        foreach ($grants as $grant) {
            try {
                StatusTransitionValidator::assertTransition($grant['status'], 'revoked');
            } catch (\InvalidArgumentException) {
                continue;
            }

            if ($sourceId) {
                $sourceIds = array_values(array_filter(
                    $grant['source_ids'],
                    static fn($id): bool => (int) $id !== $sourceId
                ));

                if (!empty($sourceIds)) {
                    $persistence = $this->persistGrantUpdate($grant['id'], ['source_ids' => $sourceIds]);
                    if (!$persistence['success']) {
                        $failed++;
                        $failure = $this->localFailure($grant, __(
                            'The remaining grant sources could not be persisted.',
                            'fchub-memberships'
                        ));
                        $errors[] = $this->withPersistenceError($failure, $persistence);
                        continue;
                    }

                    $this->sources->removeSource($grant['id'], $grant['source_type'], $sourceId);
                    $retained++;
                    continue;
                }
            }

            if ($gracePeriodDays > 0) {
                $persistence = $this->persistGrantUpdate($grant['id'], [
                    'source_ids' => [],
                    'cancellation_requested_at' => current_time('mysql'),
                    'cancellation_effective_at' => date('Y-m-d H:i:s', strtotime("+{$gracePeriodDays} days", current_time('timestamp'))),
                    'cancellation_reason' => $reason,
                    'meta' => array_merge($grant['meta'], ['revoke_reason' => $reason]),
                ]);
                if (!$persistence['success']) {
                    $failed++;
                    $failure = $this->localFailure($grant, __(
                        'The grant grace period could not be persisted.',
                        'fchub-memberships'
                    ));
                    $errors[] = $this->withPersistenceError($failure, $persistence);
                    continue;
                }

                $this->sources->removeAllByGrant($grant['id']);

                AuditLogger::logGrantChange($grant['id'], 'grace_period_started', $grant, [
                    'cancellation_effective_at' => date('Y-m-d H:i:s', strtotime("+{$gracePeriodDays} days", current_time('timestamp'))),
                ]);
            } else {
                $providerRevocation = $this->revokeOwnedProviderAccess(
                    $grant,
                    $userId,
                    ['plan_id' => $planId]
                );
                if (!$providerRevocation['success']) {
                    $failed++;
                    $errors[] = $this->providerFailure($grant, $providerRevocation);
                    continue;
                }

                $persistence = $this->persistGrantUpdate($grant['id'], [
                    'status' => 'revoked',
                    'source_ids' => [],
                    'cancellation_requested_at' => current_time('mysql'),
                    'cancellation_reason' => $reason,
                    'meta' => array_merge($grant['meta'], ['revoke_reason' => $reason]),
                ]);
                if (!$persistence['success']) {
                    $failed++;
                    $failure = $this->compensateFailedLocalRevocation(
                        $grant,
                        $userId,
                        ['plan_id' => $planId],
                        $providerRevocation
                    );
                    $errors[] = $this->withPersistenceError($failure, $persistence);
                    continue;
                }

                $this->sources->removeAllByGrant($grant['id']);
                $this->drips->deleteByGrantId($grant['id']);

                AuditLogger::logGrantChange($grant['id'], 'revoked', $grant, ['status' => 'revoked']);
            }

            $revokedGrants[] = $grant;
            $revoked++;
        }

        Logger::log(
            'Plan revoked',
            sprintf('User #%d plan #%d: %d revoked, %d retained, %d failed', $userId, $planId, $revoked, $retained, $failed),
            ['module_id' => $sourceId, 'module_name' => 'Order']
        );

        if ($order) {
            $partial = $failed > 0 && ($revoked > 0 || $retained > 0);
            Logger::orderLog(
                $order,
                $failed > 0
                    ? ($partial
                        ? __('Membership plan partially revoked', 'fchub-memberships')
                        : __('Membership plan revocation failed', 'fchub-memberships'))
                    : __('Membership plan revoked', 'fchub-memberships'),
                sprintf(__('Plan #%d: %d resources revoked, %d retained, %d failed', 'fchub-memberships'), $planId, $revoked, $retained, $failed),
                $failed > 0 ? ($partial ? 'warning' : 'error') : 'info'
            );
        }

        if ($revoked > 0 && $failed === 0) {
            do_action('fchub_memberships/grant_revoked', $revokedGrants, $planId, $userId, $reason);
            $this->notifications->sendRevoked($userId, $planId, $reason);
        }

        return [
            'success' => $failed === 0,
            'partial' => $failed > 0 && ($revoked > 0 || $retained > 0),
            'revoked' => $revoked,
            'retained' => $retained,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    public function revokeBySource(int $sourceId, string $sourceType = 'order', array $context = []): array
    {
        $grants = $this->grants->getBySourceId($sourceId, $sourceType);
        $reason = $context['reason'] ?? '';
        $revoked = 0;
        $retained = 0;
        $failed = 0;
        $errors = [];
        $planOutcomes = [];

        foreach ($grants as $grant) {
            $planId = (int) ($grant['plan_id'] ?? 0);
            $userId = (int) $grant['user_id'];
            $planKey = $userId . ':' . $planId;
            if ($planId && !isset($planOutcomes[$planKey])) {
                $planOutcomes[$planKey] = [
                    'user_id' => $userId,
                    'plan_id' => $planId,
                    'revoked_grants' => [],
                    'failed' => 0,
                ];
            }

            if (in_array($grant['status'], ['expired', 'revoked'], true)) {
                continue;
            }

            $sourceIds = array_values(array_filter(
                $grant['source_ids'],
                static fn($id): bool => (int) $id !== $sourceId
            ));

            if (!empty($sourceIds)) {
                $persistence = $this->persistGrantUpdate($grant['id'], ['source_ids' => $sourceIds]);
                if (!$persistence['success']) {
                    $failed++;
                    $failure = $this->localFailure($grant, __(
                        'The remaining grant sources could not be persisted.',
                        'fchub-memberships'
                    ));
                    $errors[] = $this->withPersistenceError($failure, $persistence);
                    if ($planId) {
                        $planOutcomes[$planKey]['failed']++;
                    }
                    continue;
                }

                $this->sources->removeSource($grant['id'], $sourceType, $sourceId);
                $retained++;
                continue;
            }

            $providerRevocation = $this->revokeOwnedProviderAccess(
                $grant,
                (int) $grant['user_id'],
                ['plan_id' => $grant['plan_id'] ?? null]
            );
            if (!$providerRevocation['success']) {
                $failed++;
                $errors[] = $this->providerFailure($grant, $providerRevocation);
                if ($planId) {
                    $planOutcomes[$planKey]['failed']++;
                }
                continue;
            }

            $persistence = $this->persistGrantUpdate($grant['id'], [
                'status' => 'revoked',
                'source_ids' => [],
            ]);
            if (!$persistence['success']) {
                $failed++;
                $failure = $this->compensateFailedLocalRevocation(
                    $grant,
                    (int) $grant['user_id'],
                    ['plan_id' => $grant['plan_id'] ?? null],
                    $providerRevocation
                );
                $errors[] = $this->withPersistenceError($failure, $persistence);
                if ($planId) {
                    $planOutcomes[$planKey]['failed']++;
                }
                continue;
            }

            $this->sources->removeAllByGrant($grant['id']);
            $this->drips->deleteByGrantId($grant['id']);

            AuditLogger::logGrantChange($grant['id'], 'revoked', $grant, ['status' => 'revoked']);

            if ($planId) {
                $planOutcomes[$planKey]['revoked_grants'][] = $grant;
            }

            $revoked++;
        }

        // Fire hooks and notifications per user+plan combination (matching revokePlan behaviour).
        foreach ($planOutcomes as $entry) {
            if ($entry['failed'] > 0 || empty($entry['revoked_grants'])) {
                continue;
            }

            do_action('fchub_memberships/grant_revoked', $entry['revoked_grants'], $entry['plan_id'], $entry['user_id'], $reason);
            $this->notifications->sendRevoked($entry['user_id'], $entry['plan_id'], $reason);
        }

        return [
            'success' => $failed === 0,
            'partial' => $failed > 0 && ($revoked > 0 || $retained > 0),
            'revoked' => $revoked,
            'retained' => $retained,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    public function revokeExpiredGracePeriodGrants(): int
    {
        $grants = $this->grants->getDueGracePeriodGrants();
        if (empty($grants)) {
            return 0;
        }

        $revoked = 0;
        $planOutcomes = [];
        foreach ($grants as $grant) {
            $reason = $grant['cancellation_reason'] ?: 'Grace period expired';
            $planId = (int) ($grant['plan_id'] ?? 0);
            $userId = (int) $grant['user_id'];
            $planKey = $userId . ':' . $planId;
            if ($planId && !isset($planOutcomes[$planKey])) {
                $planOutcomes[$planKey] = [
                    'user_id' => $userId,
                    'plan_id' => $planId,
                    'revoked_grants' => [],
                    'failed' => 0,
                    'reason' => $reason,
                ];
            }

            $providerRevocation = $this->revokeOwnedProviderAccess(
                $grant,
                (int) $grant['user_id'],
                ['plan_id' => $grant['plan_id'] ?? null]
            );
            if (!$providerRevocation['success']) {
                Logger::error(
                    'Grace period provider revocation failed',
                    (string) ($providerRevocation['provider_result']['message'] ?? ''),
                    [
                        'grant_id' => (int) $grant['id'],
                        'provider_result' => $providerRevocation['provider_result'],
                        'provider_outcome' => $providerRevocation,
                    ]
                );
                if ($planId) {
                    $planOutcomes[$planKey]['failed']++;
                }
                continue;
            }

            $persistence = $this->persistGrantUpdate($grant['id'], [
                'status' => 'revoked',
                'meta' => array_merge($grant['meta'], ['revoke_reason' => $reason]),
            ]);
            if (!$persistence['success']) {
                $failure = $this->compensateFailedLocalRevocation(
                    $grant,
                    (int) $grant['user_id'],
                    ['plan_id' => $grant['plan_id'] ?? null],
                    $providerRevocation
                );
                $failure = $this->withPersistenceError($failure, $persistence);
                Logger::error(
                    'Grace period local revocation failed',
                    $failure['message'],
                    $failure
                );
                if ($planId) {
                    $planOutcomes[$planKey]['failed']++;
                }
                continue;
            }

            $this->drips->deleteByGrantId($grant['id']);

            AuditLogger::logGrantChange($grant['id'], 'grace_period_revoked', $grant, ['status' => 'revoked']);
            if ($planId) {
                $planOutcomes[$planKey]['revoked_grants'][] = $grant;
            }
            $revoked++;
        }

        foreach ($planOutcomes as $entry) {
            if ($entry['failed'] > 0 || empty($entry['revoked_grants'])) {
                continue;
            }

            do_action(
                'fchub_memberships/grant_revoked',
                $entry['revoked_grants'],
                $entry['plan_id'],
                $entry['user_id'],
                $entry['reason']
            );
        }

        if ($revoked > 0) {
            Logger::log('Grace period', sprintf('%d grants revoked after grace period', $revoked));
        }

        return $revoked;
    }

    private function revokeOwnedProviderAccess(array $grant, int $userId, array $context): array
    {
        $owner = $grant['meta']['provider_access_owner'] ?? 'unknown';
        if ($owner !== 'fchub') {
            return [
                'success' => true,
                'detached' => false,
                'provider_result' => [
                    'success' => true,
                    'message' => __('Provider access is not owned by FCHub and was preserved.', 'fchub-memberships'),
                ],
            ];
        }

        $adapter = $this->adapters->resolve($grant['provider']);
        if (!$adapter) {
            return [
                'success' => false,
                'detached' => false,
                'provider_result' => [
                    'success' => false,
                    'message' => __('No access adapter is available to revoke FCHub-owned provider access.', 'fchub-memberships'),
                ],
            ];
        }

        try {
            $hadAccess = $adapter->check(
                $userId,
                $grant['resource_type'],
                $grant['resource_id']
            );
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'detached' => false,
                'provider_result' => [
                    'success' => false,
                    'message' => $exception->getMessage(),
                    'exception' => get_class($exception),
                    'stage' => 'precheck',
                ],
                'reconciliation' => [
                    'success' => false,
                    'stage' => 'precheck',
                    'message' => __('Provider access state could not be checked before revocation.', 'fchub-memberships'),
                    'exception' => get_class($exception),
                ],
                'compensation' => [
                    'attempted' => false,
                    'success' => false,
                    'message' => __('Provider revocation was not attempted because its initial state is unknown.', 'fchub-memberships'),
                ],
            ];
        }

        if (!$hadAccess) {
            return [
                'success' => true,
                'detached' => false,
                'provider_result' => [
                    'success' => true,
                    'message' => __('Provider access was already absent.', 'fchub-memberships'),
                ],
            ];
        }

        try {
            $providerResult = $adapter->revoke(
                $userId,
                $grant['resource_type'],
                $grant['resource_id'],
                $context
            );
        } catch (\Throwable $exception) {
            $providerResult = [
                'success' => false,
                'message' => $exception->getMessage(),
                'exception' => get_class($exception),
            ];
        }

        if (!is_array($providerResult) || empty($providerResult['success'])) {
            $providerResult = is_array($providerResult) ? $providerResult : [];
            $providerResult['success'] = false;
            $providerResult['message'] = (string) ($providerResult['message'] ?? __(
                'The access provider did not confirm the revocation.',
                'fchub-memberships'
            ));

            return array_merge([
                'success' => false,
                'detached' => false,
                'provider_result' => $providerResult,
            ], $this->reconcileFailedProviderRevocation(
                $adapter,
                $grant,
                $userId,
                $context
            ));
        }

        return [
            'success' => true,
            'detached' => true,
            'provider_result' => $providerResult,
        ];
    }

    private function reconcileFailedProviderRevocation(
        object $adapter,
        array $grant,
        int $userId,
        array $context
    ): array {
        try {
            $postFailureAccess = $adapter->check(
                $userId,
                $grant['resource_type'],
                $grant['resource_id']
            );
        } catch (\Throwable $exception) {
            return [
                'reconciliation' => [
                    'success' => false,
                    'stage' => 'post_failure_check',
                    'pre_access' => true,
                    'post_failure_access' => null,
                    'message' => $exception->getMessage(),
                    'exception' => get_class($exception),
                ],
                'compensation' => [
                    'attempted' => false,
                    'success' => false,
                    'message' => __('Provider access may have changed, but its state could not be verified before restoration.', 'fchub-memberships'),
                ],
            ];
        }

        if ($postFailureAccess) {
            return [
                'reconciliation' => [
                    'success' => true,
                    'stage' => 'post_failure_check',
                    'pre_access' => true,
                    'post_failure_access' => true,
                    'message' => __('The failed provider revocation left access in place.', 'fchub-memberships'),
                ],
                'compensation' => [
                    'attempted' => false,
                    'success' => true,
                    'message' => __('No provider restoration was required.', 'fchub-memberships'),
                ],
            ];
        }

        try {
            $compensation = $adapter->grant(
                $userId,
                $grant['resource_type'],
                $grant['resource_id'],
                $context
            );
        } catch (\Throwable $exception) {
            $compensation = [
                'success' => false,
                'message' => $exception->getMessage(),
                'exception' => get_class($exception),
            ];
        }

        if (!is_array($compensation) || empty($compensation['success'])) {
            $compensation = is_array($compensation) ? $compensation : [];
            $compensation['success'] = false;
            $compensation['message'] = (string) ($compensation['message'] ?? __(
                'The access provider did not confirm revocation compensation.',
                'fchub-memberships'
            ));
        }
        $compensation['attempted'] = true;

        return [
            'reconciliation' => [
                'success' => !empty($compensation['success']),
                'stage' => 'compensation',
                'pre_access' => true,
                'post_failure_access' => false,
                'message' => !empty($compensation['success'])
                    ? __('Provider access removed by the failed revocation was restored.', 'fchub-memberships')
                    : __('Provider access removed by the failed revocation could not be restored.', 'fchub-memberships'),
            ],
            'compensation' => $compensation,
        ];
    }

    private function providerFailure(array $grant, array $providerOutcome): array
    {
        $providerResult = $providerOutcome['provider_result'];
        $failure = [
            'grant_id' => (int) $grant['id'],
            'message' => (string) ($providerResult['message'] ?? __('Provider revocation failed.', 'fchub-memberships')),
            'provider_result' => $providerResult,
        ];
        foreach (['reconciliation', 'compensation'] as $key) {
            if (isset($providerOutcome[$key])) {
                $failure[$key] = $providerOutcome[$key];
            }
        }

        return $failure;
    }

    private function localFailure(array $grant, string $message): array
    {
        return [
            'grant_id' => (int) $grant['id'],
            'message' => $message,
        ];
    }

    private function compensateFailedLocalRevocation(
        array $grant,
        int $userId,
        array $context,
        array $providerRevocation
    ): array {
        $failure = $this->localFailure($grant, __(
            'Provider access was revoked, but the local grant could not be closed.',
            'fchub-memberships'
        ));
        $failure['provider_result'] = $providerRevocation['provider_result'];

        if (!$providerRevocation['detached']) {
            return $failure;
        }

        $adapter = $this->adapters->resolve($grant['provider']);
        if (!$adapter) {
            $failure['compensation'] = [
                'success' => false,
                'message' => __('No access adapter is available to restore provider access.', 'fchub-memberships'),
            ];
            return $failure;
        }

        try {
            $failure['compensation'] = $adapter->grant(
                $userId,
                $grant['resource_type'],
                $grant['resource_id'],
                $context
            );
        } catch (\Throwable $exception) {
            $failure['compensation'] = [
                'success' => false,
                'message' => $exception->getMessage(),
                'exception' => get_class($exception),
            ];
        }

        return $failure;
    }

    private function persistGrantUpdate(int $grantId, array $data): array
    {
        try {
            return [
                'success' => $this->grants->update($grantId, $data),
                'exception' => null,
            ];
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'exception' => $exception,
            ];
        }
    }

    private function withPersistenceError(array $failure, array $persistence): array
    {
        $exception = $persistence['exception'] ?? null;
        if ($exception instanceof \Throwable) {
            $failure['persistence_error'] = [
                'message' => $exception->getMessage(),
                'exception' => get_class($exception),
            ];
        }

        return $failure;
    }
}

<?php

namespace FChubMemberships\Domain\Grant;

use FChubMemberships\Domain\AuditLogger;
use FChubMemberships\Domain\GrantAdapterRegistry;
use FChubMemberships\Storage\DripScheduleRepository;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\GrantSourceRepository;

defined('ABSPATH') || exit;

final class GrantCreationService
{
    public function __construct(
        private GrantRepository $grants,
        private GrantSourceRepository $sources,
        private DripScheduleRepository $drips,
        private GrantAdapterRegistry $adapters
    ) {
    }

    public function grantResource(int $userId, string $provider, string $resourceType, string $resourceId, array $context = []): array
    {
        $grantKey = GrantRepository::makeGrantKey($userId, $provider, $resourceType, $resourceId);
        $existing = $this->grants->findByGrantKey($grantKey);
        $sourceId = (int) ($context['source_id'] ?? 0);
        $adapter = $this->adapters->resolve($provider);
        $providerApplication = $this->applyProviderGrant(
            $adapter,
            $userId,
            $resourceType,
            $resourceId,
            $context
        );

        if (!$providerApplication['success']) {
            $failure = [
                'action' => 'failed',
                'grant_id' => $existing['id'] ?? null,
                'message' => $providerApplication['provider_result']['message'],
                'provider_result' => $providerApplication['provider_result'],
            ];
            foreach (['reconciliation', 'compensation'] as $key) {
                if (isset($providerApplication[$key])) {
                    $failure[$key] = $providerApplication[$key];
                }
            }
            return $failure;
        }

        if ($existing) {
            $sourceIds = $existing['source_ids'];
            $needsSourceLink = $sourceId && !in_array($sourceId, $sourceIds, false);
            if ($needsSourceLink) {
                $sourceIds[] = $sourceId;
            }

            $updateData = [
                'status' => 'active',
                'source_ids' => $sourceIds,
                'renewal_count' => ($existing['renewal_count'] ?? 0) + 1,
            ];

            if (!empty($context['preserve_expiry']) && array_key_exists('expires_at', $context)) {
                $updateData['expires_at'] = $context['expires_at'];
            } elseif (!empty($context['expires_at'])) {
                if (empty($existing['expires_at']) || strtotime($context['expires_at']) > strtotime($existing['expires_at'])) {
                    $updateData['expires_at'] = $context['expires_at'];
                }
            }

            if (isset($context['plan_id'])) {
                $updateData['plan_id'] = $context['plan_id'];
            }

            // Merge context meta into existing grant meta (preserves existing keys,
            // overwrites overlapping ones like membership_term_ends_at on renewal)
            $contextMeta = $context['meta'] ?? [];
            $existingMeta = $existing['meta'] ?? [];
            $updateData['meta'] = array_merge($existingMeta, $contextMeta, [
                'provider_access_owner' => $this->resolveProviderAccessOwner(
                    $existingMeta['provider_access_owner'] ?? 'unknown',
                    $providerApplication['had_access']
                ),
            ]);
            $incident = $updateData['meta']['payment_incident'] ?? null;
            if (
                is_array($incident)
                && ($context['source_type'] ?? null) === 'subscription'
                && (int) ($context['source_id'] ?? 0) === (int) ($incident['subscription_id'] ?? 0)
                && empty($incident['recovered_at'])
            ) {
                $updateData['meta']['payment_incident']['recovered_at'] = current_time('mysql');
                $updateData['meta']['payment_incident']['recovery_renewal_count'] = $updateData['renewal_count'];
            }

            try {
                $updated = $this->grants->update($existing['id'], $updateData);
            } catch (\Throwable $exception) {
                return $this->localPersistenceFailure(
                    $adapter,
                    $userId,
                    $resourceType,
                    $resourceId,
                    $context,
                    $providerApplication,
                    (int) $existing['id'],
                    $exception
                );
            }
            if (!$updated) {
                return $this->localPersistenceFailure(
                    $adapter,
                    $userId,
                    $resourceType,
                    $resourceId,
                    $context,
                    $providerApplication,
                    (int) $existing['id']
                );
            }

            if ($needsSourceLink) {
                $sourceType = $context['source_type'] ?? 'manual';
                $sourcePersistence = $this->persistSourceLink((int) $existing['id'], $sourceType, $sourceId);
                if (!$sourcePersistence['success']) {
                    $sourceCleanup = $this->removeSourceLink((int) $existing['id'], $sourceType, $sourceId);
                    $localRollback = $this->rollbackExistingGrant($existing);
                    $failure = $this->localPersistenceFailure(
                        $adapter,
                        $userId,
                        $resourceType,
                        $resourceId,
                        $context,
                        $providerApplication,
                        (int) $existing['id'],
                        $sourcePersistence['exception']
                    );
                    $failure['persistence_stage'] = 'source_link';
                    $failure['rollback'] = [
                        'grant' => $localRollback,
                        'source' => $sourceCleanup,
                    ];
                    return $failure;
                }
            }

            $snapshot = $this->grants->find((int) $existing['id']);
            if ($snapshot) {
                AuditLogger::logGrantChange($existing['id'], 'renewed', $existing, $updateData);
                do_action('fchub_memberships/grant_renewed', $snapshot, $snapshot['renewal_count']);
            }

            return ['action' => 'updated', 'grant_id' => $existing['id']];
        }

        $dripAvailableAt = $this->calculateDripDate($context['drip_rule'] ?? null);
        $isTrial = !empty($context['is_trial']);

        $grantData = [
            'user_id' => $userId,
            'plan_id' => $context['plan_id'] ?? null,
            'provider' => $provider,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'source_type' => $isTrial ? 'trial' : ($context['source_type'] ?? 'manual'),
            'source_id' => $sourceId,
            'feed_id' => $context['feed_id'] ?? null,
            'grant_key' => $grantKey,
            'status' => 'active',
            'expires_at' => $isTrial ? ($context['trial_ends_at'] ?? null) : ($context['expires_at'] ?? null),
            'trial_ends_at' => $context['trial_ends_at'] ?? null,
            'drip_available_at' => $dripAvailableAt,
            'source_ids' => $sourceId ? [$sourceId] : [],
            'meta' => array_merge($context['meta'] ?? [], $isTrial ? [
                'trial_started_at' => current_time('mysql'),
            ] : [], [
                'provider_access_owner' => $providerApplication['had_access'] ? 'preexisting' : 'fchub',
            ]),
        ];

        try {
            $grantId = $this->grants->create($grantData);
        } catch (\Throwable $exception) {
            return $this->localPersistenceFailure(
                $adapter,
                $userId,
                $resourceType,
                $resourceId,
                $context,
                $providerApplication,
                null,
                $exception
            );
        }
        if ($grantId <= 0) {
            return $this->localPersistenceFailure(
                $adapter,
                $userId,
                $resourceType,
                $resourceId,
                $context,
                $providerApplication
            );
        }

        if ($sourceId) {
            $sourcePersistence = $this->persistSourceLink($grantId, $grantData['source_type'], $sourceId);
            if (!$sourcePersistence['success']) {
                $failure = $this->localPersistenceFailure(
                    $adapter,
                    $userId,
                    $resourceType,
                    $resourceId,
                    $context,
                    $providerApplication,
                    $grantId,
                    $sourcePersistence['exception']
                );
                $failure['persistence_stage'] = 'source_link';
                $failure['rollback'] = $this->rollbackNewGrant($grantId);
                return $failure;
            }
        }

        if ($dripAvailableAt && isset($context['drip_rule'])) {
            $dripPersistence = $this->persistDripSchedule([
                'grant_id' => $grantId,
                'plan_rule_id' => $context['drip_rule']['id'] ?? 0,
                'user_id' => $userId,
                'notify_at' => $dripAvailableAt,
            ]);
            if (!$dripPersistence['success']) {
                $failure = $this->localPersistenceFailure(
                    $adapter,
                    $userId,
                    $resourceType,
                    $resourceId,
                    $context,
                    $providerApplication,
                    $grantId,
                    $dripPersistence['exception']
                );
                $failure['persistence_stage'] = 'drip_schedule';
                $failure['rollback'] = $this->rollbackNewGrant($grantId);
                return $failure;
            }
        }

        AuditLogger::logGrantChange($grantId, 'created', [], $grantData);

        return ['action' => 'created', 'grant_id' => $grantId];
    }

    private function applyProviderGrant(
        ?object $adapter,
        int $userId,
        string $resourceType,
        string $resourceId,
        array $context
    ): array {
        if (!$adapter) {
            return [
                'success' => false,
                'had_access' => false,
                'provider_result' => [
                    'success' => false,
                    'message' => __('No access adapter is available for this provider.', 'fchub-memberships'),
                ],
            ];
        }

        try {
            $hadAccess = $adapter->check($userId, $resourceType, $resourceId);
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'had_access' => false,
                'provider_result' => [
                    'success' => false,
                    'message' => $exception->getMessage(),
                    'exception' => get_class($exception),
                    'stage' => 'precheck',
                ],
                'reconciliation' => [
                    'success' => false,
                    'stage' => 'precheck',
                    'message' => __('Provider access state could not be checked before the grant.', 'fchub-memberships'),
                    'exception' => get_class($exception),
                ],
                'compensation' => [
                    'attempted' => false,
                    'success' => false,
                    'message' => __('The provider grant was not attempted because its initial state is unknown.', 'fchub-memberships'),
                ],
            ];
        }

        try {
            $providerResult = $adapter->grant($userId, $resourceType, $resourceId, $context);
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
                'The access provider did not confirm the grant.',
                'fchub-memberships'
            ));

            return array_merge([
                'success' => false,
                'had_access' => $hadAccess,
                'provider_result' => $providerResult,
            ], $this->reconcileFailedProviderGrant(
                $adapter,
                $userId,
                $resourceType,
                $resourceId,
                $context,
                $hadAccess
            ));
        }

        return [
            'success' => true,
            'had_access' => $hadAccess,
            'provider_result' => $providerResult,
        ];
    }

    private function reconcileFailedProviderGrant(
        object $adapter,
        int $userId,
        string $resourceType,
        string $resourceId,
        array $context,
        bool $hadAccess
    ): array {
        if ($hadAccess) {
            return [
                'reconciliation' => [
                    'success' => true,
                    'stage' => 'preexisting_access',
                    'pre_access' => true,
                    'message' => __('Provider access existed before the failed grant.', 'fchub-memberships'),
                ],
                'compensation' => [
                    'attempted' => false,
                    'success' => true,
                    'message' => __('No compensation was required for pre-existing provider access.', 'fchub-memberships'),
                ],
            ];
        }

        try {
            $postFailureAccess = $adapter->check($userId, $resourceType, $resourceId);
        } catch (\Throwable $exception) {
            return [
                'reconciliation' => [
                    'success' => false,
                    'stage' => 'post_failure_check',
                    'pre_access' => false,
                    'post_failure_access' => null,
                    'message' => $exception->getMessage(),
                    'exception' => get_class($exception),
                ],
                'compensation' => [
                    'attempted' => false,
                    'success' => false,
                    'message' => __('Provider access may have changed, but its state could not be verified before compensation.', 'fchub-memberships'),
                ],
            ];
        }

        if (!$postFailureAccess) {
            return [
                'reconciliation' => [
                    'success' => true,
                    'stage' => 'post_failure_check',
                    'pre_access' => false,
                    'post_failure_access' => false,
                    'message' => __('The failed provider grant did not create access.', 'fchub-memberships'),
                ],
                'compensation' => [
                    'attempted' => false,
                    'success' => true,
                    'message' => __('No provider compensation was required.', 'fchub-memberships'),
                ],
            ];
        }

        try {
            $compensation = $adapter->revoke($userId, $resourceType, $resourceId, $context);
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
                'The access provider did not confirm grant compensation.',
                'fchub-memberships'
            ));
        }
        $compensation['attempted'] = true;

        return [
            'reconciliation' => [
                'success' => !empty($compensation['success']),
                'stage' => 'compensation',
                'pre_access' => false,
                'post_failure_access' => true,
                'message' => !empty($compensation['success'])
                    ? __('Provider access created by the failed grant was revoked.', 'fchub-memberships')
                    : __('Provider access created by the failed grant could not be revoked.', 'fchub-memberships'),
            ],
            'compensation' => $compensation,
        ];
    }

    private function resolveProviderAccessOwner(string $existingOwner, bool $hadAccess): string
    {
        if (!$hadAccess) {
            return 'fchub';
        }

        return in_array($existingOwner, ['fchub', 'preexisting'], true)
            ? $existingOwner
            : 'unknown';
    }

    private function localPersistenceFailure(
        ?object $adapter,
        int $userId,
        string $resourceType,
        string $resourceId,
        array $context,
        array $providerApplication,
        ?int $grantId = null,
        ?\Throwable $persistenceException = null
    ): array {
        $compensation = [
            'success' => true,
            'message' => __('No provider compensation was required.', 'fchub-memberships'),
        ];

        if (!$providerApplication['had_access'] && $adapter) {
            try {
                $compensation = $adapter->revoke($userId, $resourceType, $resourceId, $context);
            } catch (\Throwable $exception) {
                $compensation = [
                    'success' => false,
                    'message' => $exception->getMessage(),
                    'exception' => get_class($exception),
                ];
            }
        }

        $failure = [
            'action' => 'failed',
            'grant_id' => $grantId,
            'message' => __('Provider access was applied, but the membership grant could not be persisted.', 'fchub-memberships'),
            'provider_result' => $providerApplication['provider_result'],
            'compensation' => $compensation,
        ];

        if ($persistenceException) {
            $failure['persistence_error'] = [
                'message' => $persistenceException->getMessage(),
                'exception' => get_class($persistenceException),
            ];
        }

        return $failure;
    }

    private function persistSourceLink(int $grantId, string $sourceType, int $sourceId): array
    {
        try {
            return [
                'success' => $this->sources->addSource($grantId, $sourceType, $sourceId),
                'exception' => null,
            ];
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'exception' => $exception,
            ];
        }
    }

    private function persistDripSchedule(array $data): array
    {
        try {
            return [
                'success' => $this->drips->schedule($data) > 0,
                'exception' => null,
            ];
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'exception' => $exception,
            ];
        }
    }

    private function rollbackExistingGrant(array $existing): array
    {
        $rollbackData = [];
        foreach ([
            'status',
            'source_ids',
            'renewal_count',
            'expires_at',
            'plan_id',
            'meta',
        ] as $field) {
            if (array_key_exists($field, $existing)) {
                $rollbackData[$field] = $existing[$field];
            }
        }

        try {
            return [
                'success' => $this->grants->update((int) $existing['id'], $rollbackData),
                'exception' => null,
            ];
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'exception' => [
                    'message' => $exception->getMessage(),
                    'exception' => get_class($exception),
                ],
            ];
        }
    }

    private function removeSourceLink(int $grantId, string $sourceType, int $sourceId): array
    {
        try {
            return [
                'success' => $this->sources->removeSource($grantId, $sourceType, $sourceId),
                'exception' => null,
            ];
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'exception' => [
                    'message' => $exception->getMessage(),
                    'exception' => get_class($exception),
                ],
            ];
        }
    }

    private function rollbackNewGrant(int $grantId): array
    {
        $rollback = [];

        try {
            $this->drips->deleteByGrantId($grantId);
            $rollback['drips'] = ['success' => true];
        } catch (\Throwable $exception) {
            $rollback['drips'] = [
                'success' => false,
                'message' => $exception->getMessage(),
                'exception' => get_class($exception),
            ];
        }

        try {
            $rollback['sources'] = ['success' => $this->sources->removeAllByGrant($grantId)];
        } catch (\Throwable $exception) {
            $rollback['sources'] = [
                'success' => false,
                'message' => $exception->getMessage(),
                'exception' => get_class($exception),
            ];
        }

        try {
            $rollback['grant'] = ['success' => $this->grants->delete($grantId)];
        } catch (\Throwable $exception) {
            $rollback['grant'] = [
                'success' => false,
                'message' => $exception->getMessage(),
                'exception' => get_class($exception),
            ];
        }

        return $rollback;
    }

    private function calculateDripDate(?array $dripRule): ?string
    {
        if (!$dripRule || $dripRule['drip_type'] === 'immediate') {
            return null;
        }

        if ($dripRule['drip_type'] === 'delayed' && $dripRule['drip_delay_days'] > 0) {
            return date('Y-m-d H:i:s', strtotime('+' . (int) $dripRule['drip_delay_days'] . ' days'));
        }

        if ($dripRule['drip_type'] === 'fixed_date' && !empty($dripRule['drip_date'])) {
            return $dripRule['drip_date'];
        }

        return null;
    }
}

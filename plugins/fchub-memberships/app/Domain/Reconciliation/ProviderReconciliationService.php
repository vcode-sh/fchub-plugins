<?php

declare(strict_types=1);

namespace FChubMemberships\Domain\Reconciliation;

use FChubMemberships\Domain\AuditLogger;
use FChubMemberships\Domain\ProviderOperationWorker;
use FChubMemberships\Domain\Reconciliation\Contracts\ProviderHealthExtensionInterface;
use FChubMemberships\Integration\Community\CommunityCapabilityRegistry;
use FChubMemberships\Integration\FluentCrmIntegrationHealth;
use FChubMemberships\Storage\EntitlementEdgeRepository;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\ProviderOperationRepository;
use FChubMemberships\Support\Clock;

defined('ABSPATH') || exit;

final class ProviderReconciliationService
{
    /** @var list<ProviderHealthExtensionInterface> */
    private array $extensions = [];
    /** @var array<int, ProviderHealthCapability|null> */
    private array $capabilities = [];
    private \Closure $audit;
    private \Closure $runtimeProviderResolver;

    /** @param null|list<ProviderHealthExtensionInterface> $extensions */
    public function __construct(
        private ?EntitlementEdgeRepository $edges = null,
        private ?ProviderOperationRepository $operations = null,
        private ?ProviderOperationWorker $worker = null,
        ?array $extensions = null,
        private ?Clock $clock = null,
        ?callable $audit = null,
        private ?GrantRepository $grants = null,
        private ?FluentCrmIntegrationHealth $crmHealth = null,
        private ?CommunityCapabilityRegistry $communityCapabilities = null,
        ?callable $runtimeProviderResolver = null
    ) {
        $this->edges ??= new EntitlementEdgeRepository();
        $this->operations ??= new ProviderOperationRepository();
        $this->worker ??= new ProviderOperationWorker();
        $this->clock ??= new Clock();
        $this->grants ??= new GrantRepository($this->clock);
        $this->crmHealth ??= new FluentCrmIntegrationHealth();
        $this->communityCapabilities ??= new CommunityCapabilityRegistry();
        $this->runtimeProviderResolver = \Closure::fromCallable(
            $runtimeProviderResolver ?? self::runtimeProviders(...)
        );
        $extensions ??= [
            new FluentCrmProviderHealthExtension(),
            new FluentCommunityProviderHealthExtension(),
        ];
        $this->extensions = array_values($extensions);
        $this->audit = \Closure::fromCallable($audit ?? AuditLogger::logRequired(...));
    }

    /** @return list<array<string, mixed>> */
    public function providerSummaries(): array
    {
        $operationsReadable = true;
        try {
            $operationSummaries = $this->operations->summarizeByProvider();
        } catch (\Throwable) {
            $operationsReadable = false;
            $operationSummaries = [];
        }
        try {
            $runtime = ($this->runtimeProviderResolver)();
        } catch (\Throwable) {
            $runtime = [];
        }
        try {
            $communityCapabilities = $this->communityCapabilities->capabilities();
        } catch (\Throwable) {
            $communityCapabilities = [];
        }
        try {
            $crm = $this->crmHealth->status();
        } catch (\Throwable) {
            $crm = [
                'status' => 'degraded',
                'provider' => ['version' => null],
                'compatible' => false,
                'lifecycle_sync' => false,
                'last_reconciliation' => null,
                'last_successful_projection' => null,
                'projection_jobs_readable' => false,
                'pending_projections' => null,
                'failed_reconciliations' => 0,
                'failed_projections' => null,
                'drift' => 0,
            ];
        }

        $labels = [
            'wordpress_core' => __('WordPress Core', 'fchub-memberships'),
            'learndash' => __('LearnDash', 'fchub-memberships'),
            'fluentcrm' => __('FluentCRM', 'fchub-memberships'),
            'fluent_community' => __('FluentCommunity', 'fchub-memberships'),
        ];
        $community = $this->communitySummary($communityCapabilities);
        $crmStatus = self::providerStatus((string) ($crm['status'] ?? 'degraded'));
        $crmReason = 'fluentcrm_' . $crmStatus;
        $crmProjectionJobsReadable = ($crm['projection_jobs_readable'] ?? false) === true;
        $crmPendingProjections = $crmProjectionJobsReadable
            ? max(0, (int) ($crm['pending_projections'] ?? 0))
            : 0;
        $crmFailedProjections = $crmProjectionJobsReadable
            ? max(0, (int) ($crm['failed_projections'] ?? 0))
            : 0;
        $crmHasUnresolvedFailures = (int) ($crm['failed_reconciliations'] ?? 0) > 0
            || $crmFailedProjections > 0
            || (int) ($crm['drift'] ?? 0) > 0;
        $summaries = [
            [
                'value' => 'wordpress_core',
                'label' => $labels['wordpress_core'],
                'status' => 'healthy',
                'version' => self::safeVersion($runtime['wordpress_version'] ?? null),
                'reason' => 'wordpress_core_available',
                'capabilities' => [
                    'content' => [
                        'status' => 'available',
                        'available' => true,
                        'reason' => 'wordpress_core_available',
                    ],
                ],
                'pending_operations' => 0,
                'failed_operations' => 0,
                'last_successful_reconciliation' => null,
                'repair_url' => null,
            ],
            [
                'value' => 'learndash',
                'label' => $labels['learndash'],
                'status' => 'unverified',
                'version' => self::safeVersion($runtime['learndash_version'] ?? null),
                'reason' => 'learndash_runtime_not_certified',
                'capabilities' => [
                    'courses' => self::unverifiedCapability('learndash_runtime_not_certified'),
                    'groups' => self::unverifiedCapability('learndash_runtime_not_certified'),
                ],
                'pending_operations' => 0,
                'failed_operations' => 0,
                'last_successful_reconciliation' => null,
                'repair_url' => null,
            ],
            [
                'value' => 'fluentcrm',
                'label' => $labels['fluentcrm'],
                'status' => $crmStatus,
                'version' => self::safeVersion($crm['provider']['version'] ?? null),
                'reason' => $crmReason,
                'capabilities' => [
                    'lifecycle_sync' => [
                        'status' => $crmStatus,
                        'available' => !empty($crm['compatible']) && !empty($crm['lifecycle_sync']),
                        'reason' => $crmReason,
                    ],
                ],
                'pending_operations' => $crmPendingProjections,
                'failed_operations' => $crmFailedProjections,
                'last_successful_reconciliation' => $crmHasUnresolvedFailures
                    ? null
                    : self::safeDate($crm['last_successful_projection'] ?? null),
                'repair_url' => self::providerActionUrl('fluentcrm', $crmStatus),
            ],
            [
                'value' => 'fluent_community',
                'label' => $labels['fluent_community'],
                'status' => $community['status'],
                'version' => $community['version'],
                'reason' => $community['reason'],
                'capabilities' => $communityCapabilities,
                'pending_operations' => 0,
                'failed_operations' => 0,
                'last_successful_reconciliation' => null,
                'repair_url' => self::providerActionUrl('fluent_community', $community['status']),
            ],
        ];

        foreach ($summaries as &$summary) {
            if ($summary['value'] === 'fluentcrm') {
                continue;
            }
            $counts = $operationSummaries[$summary['value']] ?? [];
            $summary['pending_operations'] = max(0, (int) ($counts['pending_operations'] ?? 0));
            $summary['failed_operations'] = max(0, (int) ($counts['failed_operations'] ?? 0));
            if ($summary['value'] !== 'wordpress_core'
                && $summary['status'] === 'healthy'
                && ($summary['pending_operations'] > 0 || $summary['failed_operations'] > 0)
            ) {
                $summary['status'] = 'degraded';
                $summary['reason'] = 'provider_operations_require_attention';
            }
            if ($summary['value'] !== 'wordpress_core'
                && !$operationsReadable
                && $summary['status'] === 'healthy'
            ) {
                $summary['status'] = 'degraded';
                $summary['reason'] = 'provider_operations_unavailable';
            }
            $summary['repair_url'] = self::providerActionUrl(
                (string) $summary['value'],
                (string) $summary['status']
            );
        }
        unset($summary);

        return $summaries;
    }

    /** @return array{items: list<array<string, mixed>>, next_cursor: ?string} */
    public function scanPage(?string $cursor = null, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        if ($cursor === null || trim($cursor) === '') {
            $afterId = 0;
            $throughId = $this->edges->maxReconciliationEdgeId();
        } else {
            [$afterId, $throughId] = $this->decodeCursor($cursor);
        }

        $unions = $this->edges->getReconciliationResourcePage($afterId, $throughId, $limit + 1);
        $hasMore = count($unions) > $limit;
        $page = array_slice($unions, 0, $limit);
        $items = array_map(fn(array $union): array => $this->classifyUnion($union), $page);
        $nextCursor = null;
        if ($hasMore && $page !== []) {
            $last = $page[array_key_last($page)];
            $nextCursor = $this->encodeCursor((int) $last['cursor_id'], $throughId);
        }

        return ['items' => $items, 'next_cursor' => $nextCursor];
    }

    /** @return array<string, mixed> */
    public function repair(array $resourceData, string $requestId, string $expectedClassification): array
    {
        try {
            $resource = ProviderResource::fromArray($resourceData);
            $requestId = trim($requestId);
            $expectedClassification = trim($expectedClassification);
            if ($requestId === '' || strlen($requestId) > 191 || $expectedClassification === '') {
                throw new \InvalidArgumentException('Provider reconciliation request ID is invalid.');
            }
            $requestDigest = hash('sha256', $requestId);

            return $this->edges->resourceTransaction(
                $resource->toArray(),
                function () use ($resource, $requestDigest, $expectedClassification): array {
                    $edges = $this->edges->getByResource(
                        $resource->userId,
                        $resource->provider,
                        $resource->resourceType,
                        $resource->resourceId
                    );
                    if ($edges === []) {
                        return $this->refused('resource_not_found');
                    }

                    $latestOperation = $this->operations->findLatestForResource($resource->toArray());
                    $classification = $this->classify($resource, $edges, $latestOperation);
                    if ($classification['classification'] !== $expectedClassification) {
                        return [
                            'success' => false,
                            'status' => 'refused',
                            'code' => 'reconciliation_state_changed',
                            'current_classification' => $classification['classification'],
                        ];
                    }
                    if ($classification['classification'] === 'unknown_ownership') {
                        return $this->refused('unsafe_provider_detach');
                    }
                    $action = $classification['repair_action'];
                    if (!is_string($action)) {
                        return $this->refused('repair_not_available');
                    }
                    if (in_array($action, ['revoke', 'suspend'], true)
                        && $this->unsafeDetach($resource, $edges, true)
                    ) {
                        return $this->refused('unsafe_provider_detach');
                    }

                    $edge = $this->operationEdge($edges, $action);
                    $auditValue = [
                        'request_digest' => $requestDigest,
                        'provider' => $resource->provider,
                        'resource_type' => $resource->resourceType,
                        'resource_id' => $resource->resourceId,
                        'action' => $action,
                    ];
                    ($this->audit)(
                        'provider_reconciliation',
                        (int) $edge['id'],
                        'provider_repair_intent',
                        [],
                        $auditValue,
                        $this->clock,
                        'provider_reconciliation'
                    );

                    if ($latestOperation !== null
                        && $this->operationClassification($latestOperation) !== null
                    ) {
                        return $this->resolvePersistedOperation(
                            $latestOperation,
                            $edge,
                            $auditValue,
                            true
                        );
                    }

                    $operation = $this->operations->createOrFind(
                        (int) $edge['id'],
                        $action,
                        'provider_reconcile:' . $requestDigest
                    );

                    return $this->resolvePersistedOperation($operation, $edge, $auditValue, false);
                }
            );
        } catch (\Throwable) {
            return [
                'success' => false,
                'status' => 'failed',
                'code' => 'provider_reconciliation_failed',
            ];
        }
    }

    /** @return array<string, mixed> */
    private function classifyUnion(array $union): array
    {
        $resource = ProviderResource::fromArray((array) ($union['resource'] ?? []));
        $edges = (array) ($union['edges'] ?? []);
        $operation = $this->operations->findLatestForResource($resource->toArray());
        $result = $this->classify($resource, $edges, $operation);

        return array_merge($resource->toArray(), [
            'cursor_id' => (int) ($union['cursor_id'] ?? 0),
            'edge_count' => count($edges),
        ], $result);
    }

    /** @return array{classification: string, repair_action: ?string, observation_code?: string} */
    private function classify(ProviderResource $resource, array $edges, ?array $operation): array
    {
        if ($resource->provider === 'wordpress_core') {
            return ['classification' => 'local_only', 'repair_action' => null];
        }
        if ($resource->provider === 'learndash') {
            return ['classification' => 'provider_uncertified', 'repair_action' => null];
        }

        $extensionResult = $this->resolveExtension($resource);
        if ($extensionResult['status'] === 'unknown') {
            return ['classification' => 'provider_unknown', 'repair_action' => null];
        }
        if ($extensionResult['status'] === 'uncertified') {
            return ['classification' => 'provider_uncertified', 'repair_action' => null];
        }
        if ($extensionResult['status'] === 'unavailable') {
            return ['classification' => 'provider_unavailable', 'repair_action' => null];
        }
        $extension = $extensionResult['extension'];

        $operationClassification = $this->operationClassification($operation);
        if ($operationClassification !== null) {
            $operationAction = (string) ($operation['desired_action'] ?? '');
            return [
                'classification' => $operationClassification,
                'repair_action' => in_array($operationAction, ['grant', 'revoke', 'suspend', 'resume'], true)
                    ? $operationAction
                    : null,
            ];
        }

        $intent = $this->desiredIntent($resource, $edges, $operation);
        if ($intent['state'] === 'unknown') {
            return ['classification' => 'provider_unknown', 'repair_action' => null];
        }

        try {
            $observation = $extension->observe($resource);
            if (!$observation instanceof ProviderHealthObservation) {
                throw new \UnexpectedValueException('Provider observation is incompatible.');
            }
        } catch (\Throwable) {
            $observation = new ProviderHealthObservation('unknown', 'provider_observation_failed');
        }
        if ($observation->state === 'unknown') {
            return [
                'classification' => 'provider_unknown',
                'repair_action' => null,
                'observation_code' => $observation->code,
            ];
        }

        if ($intent['state'] === 'present' && $observation->state === 'absent') {
            return ['classification' => 'internal_active_provider_absent', 'repair_action' => $intent['action']];
        }
        if ($intent['state'] === 'absent' && $observation->state === 'present') {
            if ($this->unsafeDetach($resource, $edges, false)) {
                return ['classification' => 'unknown_ownership', 'repair_action' => null];
            }

            return [
                'classification' => $intent['action'] === 'suspend'
                    ? 'internal_paused_provider_present'
                    : 'internal_ended_provider_present',
                'repair_action' => $intent['action'],
            ];
        }

        return ['classification' => 'healthy', 'repair_action' => null];
    }

    private function desiredState(array $edges): string
    {
        $hasUnknown = false;
        foreach ($edges as $edge) {
            $state = $this->edgeState($edge);
            if ($state === 'present') {
                return 'present';
            }
            $hasUnknown = $hasUnknown || $state === 'unknown';
        }

        return $hasUnknown ? 'unknown' : 'absent';
    }

    /** @return array{state: string, action: ?string} */
    private function desiredIntent(ProviderResource $resource, array $edges, ?array $operation): array
    {
        $appliedAction = ($operation['state'] ?? '') === 'applied'
            ? (string) ($operation['desired_action'] ?? '')
            : '';
        try {
            $grant = $this->grants->findByGrantKey(GrantRepository::makeGrantKey(
                $resource->userId,
                $resource->provider,
                $resource->resourceType,
                $resource->resourceId
            ));
        } catch (\Throwable) {
            return ['state' => 'unknown', 'action' => null];
        }

        if ($grant !== null) {
            $status = (string) ($grant['status'] ?? '');
            if ($status === 'paused') {
                return ['state' => 'absent', 'action' => 'suspend'];
            }
            if ($status !== 'active') {
                return in_array($status, ['expired', 'revoked'], true)
                    ? ['state' => 'absent', 'action' => 'revoke']
                    : ['state' => 'unknown', 'action' => null];
            }
        } elseif ($appliedAction !== '') {
            if ($appliedAction === 'suspend') {
                return ['state' => 'absent', 'action' => 'suspend'];
            }
            if ($appliedAction === 'revoke') {
                return ['state' => 'absent', 'action' => 'revoke'];
            }
        }

        $edgeState = $this->desiredState($edges);
        return [
            'state' => $edgeState,
            'action' => $edgeState === 'absent'
                ? 'revoke'
                : ($edgeState === 'present' ? ($appliedAction === 'resume' ? 'resume' : 'grant') : null),
        ];
    }

    private function edgeState(array $edge): string
    {
        if (($edge['lifecycle'] ?? '') === 'ended') {
            return 'absent';
        }
        if (($edge['lifecycle'] ?? '') !== 'active') {
            return 'unknown';
        }

        try {
            $now = $this->clock->now();
            foreach (['starts_at', 'drip_available_at'] as $field) {
                $value = trim((string) ($edge[$field] ?? ''));
                if ($value !== '' && $this->clock->parseLocal($value) > $now) {
                    return 'absent';
                }
            }
            $expiry = trim((string) ($edge['expires_at'] ?? ''));
            if ($expiry !== '' && $this->clock->parseLocal($expiry) <= $now) {
                return 'absent';
            }
            $policy = is_array($edge['policy'] ?? null) ? $edge['policy'] : [];
            $termEnd = trim((string) ($policy['membership_term_ends_at'] ?? ''));
            if ($termEnd !== '' && $this->clock->parseLocal($termEnd) <= $now) {
                return 'absent';
            }
        } catch (\Throwable) {
            return 'unknown';
        }

        return 'present';
    }

    private function operationClassification(?array $operation): ?string
    {
        if ($operation === null || ($operation['state'] ?? '') === 'applied') {
            return null;
        }

        return match ((string) ($operation['state'] ?? '')) {
            'pending', 'deferred' => 'operation_pending',
            'processing' => $this->processingOperationClassification($operation),
            'failed' => ($operation['retryable'] ?? false)
                ? 'operation_retryable_failed'
                : 'operation_terminal_failed',
            default => null,
        };
    }

    private function processingOperationClassification(array $operation): string
    {
        $lease = trim((string) ($operation['lease_expires_at'] ?? ''));
        if ($lease === '') {
            return 'operation_stale';
        }

        try {
            return $this->clock->parseLocal($lease) <= $this->clock->now()
                ? 'operation_stale'
                : 'operation_processing';
        } catch (\Throwable) {
            return 'operation_stale';
        }
    }

    private function unsafeDetach(ProviderResource $resource, array $edges, bool $readEvidence): bool
    {
        foreach ($edges as $edge) {
            if (($edge['owner'] ?? '') !== 'fchub'
                || ($edge['assignment_provenance'] ?? '') !== 'fchub_created') {
                return true;
            }
        }

        return $readEvidence && $this->edges->hasUnsafeAssignmentEvidence(
            $resource->userId,
            $resource->provider,
            $resource->resourceType,
            $resource->resourceId
        );
    }

    /** @return array{status: string, extension?: ProviderHealthExtensionInterface} */
    private function resolveExtension(ProviderResource $resource): array
    {
        $hadFailure = false;
        foreach ($this->extensions as $extension) {
            $id = spl_object_id($extension);
            if (!array_key_exists($id, $this->capabilities)) {
                try {
                    $capability = $extension->capability();
                    $this->capabilities[$id] = $capability instanceof ProviderHealthCapability
                        ? $capability
                        : null;
                } catch (\Throwable) {
                    $this->capabilities[$id] = null;
                }
            }
            $capability = $this->capabilities[$id];
            if (!$capability instanceof ProviderHealthCapability) {
                $hadFailure = true;
                continue;
            }
            if ($capability->provider !== $resource->provider) {
                continue;
            }
            if (!$capability->certified || !$capability->supports($resource)) {
                return ['status' => 'uncertified'];
            }
            if (!$capability->available) {
                return ['status' => 'unavailable'];
            }

            return ['status' => 'ready', 'extension' => $extension];
        }

        return ['status' => $hadFailure ? 'unknown' : 'uncertified'];
    }

    /** @return array<string, mixed> */
    private function resolvePersistedOperation(
        array $operation,
        array $edge,
        array $auditValue,
        bool $reused
    ): array {
        $operationId = (int) ($operation['id'] ?? 0);
        $action = (string) ($operation['desired_action'] ?? $auditValue['action']);
        $state = (string) ($operation['state'] ?? '');
        $status = 'scheduled';
        $success = true;
        $shouldSchedule = false;

        if (in_array($state, ['pending', 'deferred'], true)) {
            $shouldSchedule = true;
            $status = $reused ? 'rescheduled' : 'scheduled';
        } elseif ($state === 'processing') {
            $classification = $this->processingOperationClassification($operation);
            $shouldSchedule = $classification === 'operation_stale'
                && $this->operations->recoverStaleProcessing($operationId);
            $status = $shouldSchedule ? 'rescheduled' : 'in_progress';
        } elseif ($state === 'failed') {
            $shouldSchedule = (bool) ($operation['retryable'] ?? false);
            $status = $shouldSchedule ? 'rescheduled' : 'terminal';
            $success = $shouldSchedule;
        } elseif ($state === 'applied') {
            $status = 'already_applied';
        } else {
            $status = 'failed';
            $success = false;
        }

        if ($shouldSchedule) {
            $this->worker->schedulePersisted($operationId);
        }
        ($this->audit)(
            'provider_reconciliation',
            (int) $edge['id'],
            $shouldSchedule ? 'provider_repair_scheduled' : 'provider_repair_outcome',
            $auditValue,
            array_merge($auditValue, ['operation_id' => $operationId, 'status' => $status]),
            $this->clock,
            'provider_reconciliation'
        );

        return [
            'success' => $success,
            'status' => $status,
            'action' => $action,
            'operation_id' => $operationId,
        ];
    }

    private function operationEdge(array $edges, string $action): array
    {
        $targetLifecycle = in_array($action, ['grant', 'suspend', 'resume'], true) ? 'active' : 'ended';
        $matches = array_values(array_filter(
            $edges,
            static fn(array $edge): bool => ($edge['lifecycle'] ?? '') === $targetLifecycle
        ));
        $candidates = $matches !== [] ? $matches : $edges;
        usort($candidates, static fn(array $left, array $right): int => (int) $right['id'] <=> (int) $left['id']);

        return $candidates[0];
    }

    /** @return array{success: false, status: string, code: string} */
    private function refused(string $code): array
    {
        return ['success' => false, 'status' => 'refused', 'code' => $code];
    }

    private function encodeCursor(int $afterId, int $throughId): string
    {
        return rtrim(strtr(base64_encode(wp_json_encode([
            'after_id' => $afterId,
            'through_id' => $throughId,
        ])), '+/', '-_'), '=');
    }

    /** @return array{int, int} */
    private function decodeCursor(string $cursor): array
    {
        $padding = strlen($cursor) % 4;
        $decoded = base64_decode(strtr($cursor . ($padding === 0 ? '' : str_repeat('=', 4 - $padding)), '-_', '+/'), true);
        $data = is_string($decoded) ? json_decode($decoded, true) : null;
        $afterId = (int) ($data['after_id'] ?? -1);
        $throughId = (int) ($data['through_id'] ?? -1);
        if ($afterId < 0 || $throughId < $afterId) {
            throw new \InvalidArgumentException('Provider reconciliation cursor is invalid.');
        }

        return [$afterId, $throughId];
    }

    /** @param array<string, array<string, mixed>> $capabilities
     *  @return array{status:string, version:?string, reason:string}
     */
    private function communitySummary(array $capabilities): array
    {
        $core = array_intersect_key(
            $capabilities,
            array_flip(['spaces', 'courses', 'profile_verification_read'])
        );
        foreach (['inactive', 'incompatible', 'disabled', 'unverified'] as $status) {
            foreach ($core as $state) {
                if (($state['status'] ?? '') === $status) {
                    return [
                        'status' => $status,
                        'version' => self::safeVersion($state['version'] ?? null),
                        'reason' => (string) ($state['reason'] ?? 'community_capability_unavailable'),
                    ];
                }
            }
        }
        if (count($core) !== 3) {
            return [
                'status' => 'incompatible',
                'version' => null,
                'reason' => 'community_capability_unavailable',
            ];
        }

        return [
            'status' => 'healthy',
            'version' => self::safeVersion($core['spaces']['version'] ?? null),
            'reason' => 'community_core_available',
        ];
    }

    /** @return array{status:string, available:false, reason:string} */
    private static function unverifiedCapability(string $reason): array
    {
        return ['status' => 'unverified', 'available' => false, 'reason' => $reason];
    }

    private static function providerStatus(string $status): string
    {
        return in_array(
            $status,
            ['inactive', 'disabled', 'incompatible', 'unverified', 'degraded', 'healthy'],
            true
        ) ? $status : 'degraded';
    }

    private static function providerActionUrl(string $provider, string $status): ?string
    {
        if (!in_array($provider, ['fluentcrm', 'fluent_community'], true)) {
            return null;
        }

        if (in_array($status, ['inactive', 'disabled', 'incompatible'], true)) {
            return '/settings?category=integrations&provider=' . $provider;
        }

        return $status === 'degraded'
            ? '/integrations?provider=' . $provider
            : null;
    }

    private static function safeVersion(mixed $version): ?string
    {
        $version = trim((string) $version);

        return $version !== '' && preg_match('/^[0-9A-Za-z.+_-]{1,32}$/', $version) === 1
            ? $version
            : null;
    }

    private static function safeDate(mixed $date): ?string
    {
        $date = trim((string) $date);

        return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $date) === 1
            ? $date
            : null;
    }

    /** @return array<string, mixed> */
    private static function runtimeProviders(): array
    {
        return [
            'wordpress_version' => function_exists('get_bloginfo') ? get_bloginfo('version') : null,
            'learndash_active' => defined('LEARNDASH_VERSION'),
            'learndash_version' => defined('LEARNDASH_VERSION') ? (string) LEARNDASH_VERSION : null,
        ];
    }
}

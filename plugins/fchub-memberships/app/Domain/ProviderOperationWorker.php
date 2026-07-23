<?php

declare(strict_types=1);

namespace FChubMemberships\Domain;

use FChubMemberships\Adapters\Contracts\AccessAdapterInterface;
use FChubMemberships\Storage\EntitlementEdgeRepository;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\ProviderOperationRepository;
use FChubMemberships\Support\Clock;

defined('ABSPATH') || exit;

class ProviderOperationWorker
{
    public const HOOK = 'fchub_memberships_process_provider_operation';

    private \Closure $scheduler;
    private \Closure $ownerFactory;

    public function __construct(
        private ?ProviderOperationRepository $operations = null,
        private ?EntitlementEdgeRepository $edges = null,
        private ?GrantAdapterRegistry $adapters = null,
        ?callable $scheduler = null,
        ?callable $ownerFactory = null,
        private ?Clock $clock = null,
        private ?GrantRepository $grants = null
    ) {
        $this->operations ??= new ProviderOperationRepository();
        $this->edges ??= new EntitlementEdgeRepository();
        $this->adapters ??= new GrantAdapterRegistry();
        $this->clock ??= new Clock();
        $this->grants ??= new GrantRepository();
        $this->scheduler = \Closure::fromCallable($scheduler ?? self::defaultScheduler(...));
        $this->ownerFactory = \Closure::fromCallable($ownerFactory ?? self::defaultOwner(...));
    }

    public function enqueue(
        int $edgeId,
        string $desiredAction,
        string $originEvent,
        ?\DateTimeImmutable $eligibleAt = null
    ): ?array
    {
        $edge = $this->edges->findById($edgeId);
        if ($edge === null) {
            throw new \RuntimeException('Provider operation edge was not found.');
        }

        if (($edge['provider'] ?? '') === 'wordpress_core') {
            return null;
        }

        $operation = $this->operations->createOrFind($edgeId, $desiredAction, $originEvent, $eligibleAt);
        if (($operation['state'] ?? '') !== 'deferred') {
            ($this->scheduler)((int) $operation['id']);
        }

        return $operation;
    }

    public function schedulePersisted(int $operationId): void
    {
        if ($operationId <= 0) {
            throw new \InvalidArgumentException('Provider operation ID must be greater than zero.');
        }

        ($this->scheduler)($operationId);
    }

    /** @return array<int, ProviderOperationOutcome> */
    public function unlockDeferredGrant(array $grant, array $schedule = []): array
    {
        if (($grant['provider'] ?? '') === 'wordpress_core') {
            return [];
        }

        $eligibleAt = trim((string) ($schedule['notify_at'] ?? $grant['drip_available_at'] ?? ''));
        if ($eligibleAt === '') {
            return [0 => ProviderOperationOutcome::terminalFailure(
                'provider_operation_eligibility_missing',
                'The provider operation eligibility time is missing.'
            )];
        }

        $operationIds = $this->operations->findGrantOperationIdsForResource($grant, $eligibleAt, 50);
        if ($operationIds === []) {
            return [0 => ProviderOperationOutcome::terminalFailure(
                'provider_operation_missing',
                'The provider operation no longer exists.'
            )];
        }

        $outcomes = [];
        foreach ($operationIds as $operationId) {
            $operation = $this->operations->findById($operationId);
            if (($operation['state'] ?? '') === 'deferred') {
                $this->operations->makeEligible($operationId);
            }
            $outcomes[$operationId] = $this->process($operationId);
        }

        return $outcomes;
    }

    public function process(int $operationId): ProviderOperationOutcome
    {
        $owner = ($this->ownerFactory)();
        $claim = $this->operations->claim($operationId, $owner, 300);
        if ($claim->outcome !== 'acquired' || $claim->operation === null) {
            return $this->claimOutcome($claim->outcome);
        }

        $operation = $claim->operation;
        try {
            $edge = $this->edges->findById((int) $operation['edge_id']);
        } catch (\Throwable) {
            return $this->complete($operationId, $owner, self::unreadableProviderState($operation));
        }
        if ($edge === null) {
            return $this->complete(
                $operationId,
                $owner,
                ProviderOperationOutcome::terminalFailure(
                    'provider_operation_edge_missing',
                    'The provider operation edge no longer exists.'
                )
            );
        }

        $provider = (string) ($edge['provider'] ?? '');
        if ($provider === 'wordpress_core') {
            return $this->complete(
                $operationId,
                $owner,
                ProviderOperationOutcome::terminalFailure(
                    'local_only_provider_operation',
                    'WordPress content access is local-only.'
                )
            );
        }
        if ($provider === 'learndash') {
            return $this->complete(
                $operationId,
                $owner,
                ProviderOperationOutcome::terminalFailure(
                    'provider_not_certified',
                    'The provider is not certified for automated assignment.'
                )
            );
        }
        if (!in_array($provider, ['fluentcrm', 'fluent_community'], true)) {
            return $this->complete(
                $operationId,
                $owner,
                ProviderOperationOutcome::terminalFailure(
                    'provider_not_certified',
                    'The provider is not certified for automated assignment.'
                )
            );
        }

        $action = (string) ($operation['desired_action'] ?? '');
        if (!in_array($action, ['grant', 'revoke', 'suspend', 'resume'], true)) {
            return $this->complete(
                $operationId,
                $owner,
                ProviderOperationOutcome::terminalFailure(
                    'invalid_provider_operation_action',
                    'The provider operation action is invalid.'
                )
            );
        }

        try {
            $hasNewerIntent = $this->operations->hasNewerAssignmentIntent($operation);
        } catch (\Throwable) {
            return $this->complete($operationId, $owner, self::unreadableProviderState($operation));
        }
        if ($hasNewerIntent) {
            return $this->complete(
                $operationId,
                $owner,
                ProviderOperationOutcome::alreadyApplied(
                    'desired_action_superseded',
                    'A newer provider operation superseded this operation.'
                )
            );
        }

        try {
            $activeEdges = $this->edges->getActiveByResource(
                (int) $edge['user_id'],
                $provider,
                (string) $edge['resource_type'],
                (string) $edge['resource_id']
            );
        } catch (\Throwable) {
            return $this->complete($operationId, $owner, self::unreadableProviderState($operation));
        }
        $isGrantAction = in_array($action, ['grant', 'resume'], true);
        $isDetachAction = in_array($action, ['revoke', 'suspend'], true);
        $edgeStates = array_map(
            fn(array $activeEdge): string => $this->edgeAccessState($activeEdge),
            $activeEdges
        );
        if (in_array('unknown', $edgeStates, true)) {
            return $this->complete(
                $operationId,
                $owner,
                $isDetachAction ? self::unsafeProviderDetach() : self::unreadableProviderState($operation)
            );
        }
        $effectiveEdges = [];
        foreach ($activeEdges as $index => $activeEdge) {
            if (($edgeStates[$index] ?? 'unknown') === 'effective') {
                $effectiveEdges[] = $activeEdge;
            }
        }
        $hasOtherEffectiveEdge = count(array_filter(
            $effectiveEdges,
            static fn(array $activeEdge): bool => (int) ($activeEdge['id'] ?? 0) !== (int) $edge['id']
        )) > 0;
        if ($action === 'suspend' && $hasOtherEffectiveEdge) {
            $aggregateState = $this->aggregatePauseState($edge);
            if ($aggregateState === 'unknown') {
                return $this->complete(
                    $operationId,
                    $owner,
                    self::unreadableProviderState($operation)
                );
            }
            if ($aggregateState === 'missing') {
                return $this->complete(
                    $operationId,
                    $owner,
                    ProviderOperationOutcome::terminalFailure(
                        'provider_operation_grant_missing',
                        'The aggregate grant no longer exists.'
                    )
                );
            }
            if ($aggregateState === 'paused') {
                $hasOtherEffectiveEdge = false;
            }
        }
        if (($isGrantAction && $effectiveEdges === [])
            || ($action === 'revoke' && $effectiveEdges !== [])
            || ($action === 'suspend' && $hasOtherEffectiveEdge)
        ) {
            return $this->complete(
                $operationId,
                $owner,
                ProviderOperationOutcome::alreadyApplied(
                    'desired_action_superseded',
                    'The current entitlement state superseded this operation.'
                )
            );
        }

        if ($isDetachAction) {
            if (($edge['owner'] ?? '') !== 'fchub'
                || ($edge['assignment_provenance'] ?? '') !== 'fchub_created'
            ) {
                return $this->complete(
                    $operationId,
                    $owner,
                    self::unsafeProviderDetach()
                );
            }

            try {
                $unsafeAssignmentEvidence = $this->edges->hasUnsafeAssignmentEvidence(
                    (int) $edge['user_id'],
                    $provider,
                    (string) $edge['resource_type'],
                    (string) $edge['resource_id']
                );
            } catch (\Throwable) {
                return $this->complete($operationId, $owner, self::unreadableProviderState($operation));
            }
            if ($unsafeAssignmentEvidence) {
                return $this->complete($operationId, $owner, self::unsafeProviderDetach());
            }
        }

        try {
            $hasOlderActionable = $this->operations->hasOlderActionableAssignment($operation);
        } catch (\Throwable) {
            return $this->complete($operationId, $owner, self::unreadableProviderState($operation));
        }
        if ($hasOlderActionable) {
            return $this->complete(
                $operationId,
                $owner,
                ProviderOperationOutcome::deferred(
                    'older_provider_operation_actionable',
                    'An older provider operation must be resolved first.'
                )
            );
        }

        $adapter = $this->adapters->resolve($provider);
        if (!$adapter instanceof AccessAdapterInterface
            || !$adapter->supports((string) $edge['resource_type'])
        ) {
            return $this->complete(
                $operationId,
                $owner,
                ProviderOperationOutcome::terminalFailure(
                    'provider_resource_not_supported',
                    'The provider resource is not supported.'
                )
            );
        }

        $desiredAssigned = $isGrantAction;
        $adapterAction = $desiredAssigned ? 'grant' : 'revoke';
        if ((int) $operation['attempt_count'] >= 4) {
            try {
                $alreadyApplied = $adapter->check(
                    (int) $edge['user_id'],
                    (string) $edge['resource_type'],
                    (string) $edge['resource_id']
                ) === $desiredAssigned;
            } catch (\Throwable) {
                return $this->complete($operationId, $owner, self::retryExhausted());
            }

            return $this->complete(
                $operationId,
                $owner,
                $alreadyApplied
                    ? ProviderOperationOutcome::alreadyApplied()
                    : self::retryExhausted()
            );
        }

        try {
            $attemptCount = $this->operations->beginAttempt(
                $operationId,
                $owner,
                (int) $operation['attempt_count']
            );
        } catch (\Throwable) {
            return $this->complete(
                $operationId,
                $owner,
                ProviderOperationOutcome::retryableFailure(
                    'provider_attempt_unavailable',
                    'The provider operation attempt could not be started.'
                )
            );
        }
        if ($attemptCount === null) {
            return ProviderOperationOutcome::retryableFailure(
                'provider_operation_state_not_persisted',
                'The provider operation state could not be persisted.'
            );
        }
        $operation['attempt_count'] = $attemptCount;

        try {
            if ($adapter->check(
                (int) $edge['user_id'],
                (string) $edge['resource_type'],
                (string) $edge['resource_id']
            ) === $desiredAssigned) {
                return $this->complete(
                    $operationId,
                    $owner,
                    ProviderOperationOutcome::alreadyApplied()
                );
            }

            $result = $adapter->{$adapterAction}(
                (int) $edge['user_id'],
                (string) $edge['resource_type'],
                (string) $edge['resource_id'],
                [
                    'edge_id' => (int) $edge['id'],
                    'origin_event' => (string) $operation['origin_event'],
                ]
            );
            if (($result['success'] ?? false) !== true) {
                return $this->complete($operationId, $owner, self::providerFailure($operation));
            }

            if ($adapter->check(
                (int) $edge['user_id'],
                (string) $edge['resource_type'],
                (string) $edge['resource_id']
            ) !== $desiredAssigned) {
                return $this->complete($operationId, $owner, self::providerFailure($operation));
            }
        } catch (\Throwable) {
            return $this->complete($operationId, $owner, self::providerFailure($operation));
        }

        return $this->complete($operationId, $owner, ProviderOperationOutcome::applied());
    }

    /** @return array<int, ProviderOperationOutcome> */
    public function recoverDue(int $limit = 50): array
    {
        $limit = max(1, min(50, $limit));
        foreach ($this->operations->findDueDeferredIds($limit) as $operationId) {
            $this->operations->makeEligible($operationId);
        }
        $outcomes = [];
        foreach ($this->operations->findRecoverableIds($limit) as $operationId) {
            $outcomes[$operationId] = $this->process($operationId);
        }

        return $outcomes;
    }

    private function complete(
        int $operationId,
        string $owner,
        ProviderOperationOutcome $outcome
    ): ProviderOperationOutcome {
        if ($this->operations->recordOutcome($operationId, $owner, $outcome)) {
            return $outcome;
        }

        return ProviderOperationOutcome::retryableFailure(
            'provider_operation_state_not_persisted',
            'The provider operation state could not be persisted.'
        );
    }

    private function claimOutcome(string $claimOutcome): ProviderOperationOutcome
    {
        return match ($claimOutcome) {
            'applied' => ProviderOperationOutcome::alreadyApplied(),
            'in-progress', 'not-due', 'deferred' => ProviderOperationOutcome::deferred(),
            'terminal' => ProviderOperationOutcome::terminalFailure(),
            default => ProviderOperationOutcome::terminalFailure(
                'provider_operation_missing',
                'The provider operation no longer exists.'
            ),
        };
    }

    private static function providerFailure(array $operation): ProviderOperationOutcome
    {
        if ((int) ($operation['attempt_count'] ?? 0) >= 4) {
            return self::retryExhausted();
        }

        return ProviderOperationOutcome::retryableFailure(
            'provider_operation_failed',
            'Provider operation failed.'
        );
    }

    private static function unreadableProviderState(array $operation): ProviderOperationOutcome
    {
        if ((int) ($operation['attempt_count'] ?? 0) >= 4) {
            return self::retryExhausted();
        }

        return ProviderOperationOutcome::retryableFailure(
            'provider_state_unreadable',
            'Provider assignment state could not be read.'
        );
    }

    private static function retryExhausted(): ProviderOperationOutcome
    {
        return ProviderOperationOutcome::terminalFailure(
            'provider_operation_retry_exhausted',
            'Provider operation failed after the final attempt.'
        );
    }

    private static function unsafeProviderDetach(): ProviderOperationOutcome
    {
        return ProviderOperationOutcome::terminalFailure(
            'unsafe_provider_detach',
            'The provider assignment is not exclusively owned by FCHub.'
        );
    }

    private function edgeAccessState(array $edge): string
    {
        if (($edge['lifecycle'] ?? '') !== 'active') {
            return 'inactive';
        }

        $now = $this->clock->now();
        $policy = is_array($edge['policy'] ?? null) ? $edge['policy'] : [];
        $dates = [
            ['value' => $edge['starts_at'] ?? null, 'must_be_started' => true],
            ['value' => $edge['drip_available_at'] ?? null, 'must_be_started' => true],
            ['value' => $edge['expires_at'] ?? null, 'must_be_started' => false],
            ['value' => $policy['membership_term_ends_at'] ?? null, 'must_be_started' => false],
        ];
        foreach ($dates as $date) {
            if (!is_string($date['value']) || trim($date['value']) === '') {
                continue;
            }
            try {
                $boundary = $this->clock->parseLocal($date['value']);
            } catch (\Throwable) {
                return 'unknown';
            }
            if ($date['must_be_started'] ? $boundary > $now : $boundary <= $now) {
                return 'inactive';
            }
        }

        return 'effective';
    }

    private function aggregatePauseState(array $edge): string
    {
        try {
            $grant = $this->grants->findByGrantKey(GrantRepository::makeGrantKey(
                (int) $edge['user_id'],
                (string) $edge['provider'],
                (string) $edge['resource_type'],
                (string) $edge['resource_id']
            ));
        } catch (\Throwable) {
            return 'unknown';
        }

        global $wpdb;
        if (trim((string) ($wpdb->last_error ?? '')) !== '') {
            return 'unknown';
        }
        if ($grant === null) {
            return 'missing';
        }

        return ($grant['status'] ?? '') === 'paused' ? 'paused' : 'not-paused';
    }

    private static function defaultScheduler(int $operationId): void
    {
        if (!function_exists('as_enqueue_async_action')) {
            return;
        }

        as_enqueue_async_action(
            self::HOOK,
            ['operation_id' => $operationId],
            'fchub-memberships-provider',
            true
        );
    }

    private static function defaultOwner(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable) {
            return substr(hash('sha256', uniqid('', true)), 0, 32);
        }
    }
}

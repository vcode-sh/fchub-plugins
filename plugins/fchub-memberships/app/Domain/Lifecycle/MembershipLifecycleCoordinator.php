<?php

namespace FChubMemberships\Domain\Lifecycle;

use FChubMemberships\Domain\AccessGrantService;
use FChubMemberships\Domain\Entitlement\EntitlementService;
use FChubMemberships\Domain\Event\EventClaimResult;
use FChubMemberships\Domain\Grant\AnchorDateCalculator;
use FChubMemberships\Storage\DripScheduleRepository;
use FChubMemberships\Storage\EntitlementEdgeRepository;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\GrantSourceRepository;
use FChubMemberships\Storage\ProviderOperationRepository;
use FChubMemberships\Support\Clock;

defined('ABSPATH') || exit;

class MembershipLifecycleCoordinator
{
    private AccessGrantService $access;
    private EntitlementService $entitlements;
    private GrantRepository $grants;
    private Clock $clock;
    private \Closure $ownerTokenFactory;

    public function __construct(
        ?AccessGrantService $access = null,
        ?EntitlementService $entitlements = null,
        ?GrantRepository $grants = null,
        ?Clock $clock = null,
        ?callable $ownerTokenFactory = null
    ) {
        $this->access = $access ?? new AccessGrantService();
        $this->grants = $grants ?? new GrantRepository();
        $this->clock = $clock ?? new Clock();
        $this->entitlements = $entitlements ?? new EntitlementService(
            new EntitlementEdgeRepository(),
            $this->grants,
            $this->clock,
            new GrantSourceRepository(),
            new DripScheduleRepository(),
            new ProviderOperationRepository()
        );
        $this->ownerTokenFactory = $ownerTokenFactory !== null
            ? \Closure::fromCallable($ownerTokenFactory)
            : static fn(): string => bin2hex(random_bytes(32));
    }

    public function paid(int $userId, int $planId, array $context): array
    {
        try {
            return $this->access->grantPlan($userId, $planId, $context);
        } catch (\Throwable) {
            return $this->stableCommandFailure();
        }
    }

    public function refund(int $userId, int $planId, array $context): array
    {
        try {
            return $this->access->revokePlan($userId, $planId, $context);
        } catch (\Throwable) {
            return $this->stableCommandFailure();
        }
    }

    public function renew(array $payload): array
    {
        try {
            $subscriptionId = $this->payloadId($payload, 'subscription');
            $renewalOrderId = $this->payloadId($payload, 'order');
            $owner = ($this->ownerTokenFactory)();
            $receipt = $this->access->subscriptionRenewalEventHash($payload);
            $claim = $this->access->claimSubscriptionRenewalEvent($payload, $owner);
        } catch (\Throwable $exception) {
            return ['status' => 'unverified', 'reason' => 'invalid_lifecycle_payload'];
        }

        if ($claim->outcome === EventClaimResult::DUPLICATE_SUCCEEDED) {
            return ['status' => 'duplicate', 'receipt' => $receipt];
        }
        if ($claim->outcome !== EventClaimResult::ACQUIRED) {
            return ['status' => 'deferred', 'receipt' => $receipt, 'claim' => $claim->outcome];
        }

        try {
            $active = $this->entitlements->getBySubscriptionCorrelation($subscriptionId, 'active');
            $ended = $this->entitlements->getBySubscriptionCorrelation($subscriptionId, 'ended');
            $representedLineages = [];
            foreach ($active as $edge) {
                $representedLineages[$this->renewalLineageKey($edge)] = true;
            }
            $latestEnded = [];
            foreach ($ended as $edge) {
                $lineage = $this->renewalLineageKey($edge);
                if (isset($representedLineages[$lineage])) {
                    continue;
                }
                if (!isset($latestEnded[$lineage]) || $this->isLaterRenewalGeneration($edge, $latestEnded[$lineage])) {
                    $latestEnded[$lineage] = $edge;
                }
            }
            $ended = array_values($latestEnded);
            $activeWindows = [];
            $endedWindows = [];
            foreach ($active as $index => $edge) {
                $activeWindows[$index] = $this->renewalWindow($edge, $payload['subscription']);
            }
            foreach ($ended as $index => $edge) {
                $endedWindows[$index] = $this->renewalWindow($edge, $payload['subscription']);
            }

            $results = [];
            $projectionEdges = [];
            foreach ($active as $index => $edge) {
                $window = $activeWindows[$index];
                if (!$window['eligible']) {
                    continue;
                }
                if ($window['expires_at'] !== null) {
                    $result = $this->entitlements->extendActiveExpiry(
                        $edge,
                        $window['expires_at'],
                        $receipt
                    );
                    $results[] = $result;
                    if (isset($result['edge']) && is_array($result['edge'])) {
                        $edge = $result['edge'];
                    }
                }
                $projectionEdges[$this->resourceKey($edge)] = $edge;
            }
            foreach ($ended as $index => $edge) {
                $window = $endedWindows[$index];
                $lineage = $this->renewalLineageKey($edge);
                if (!$window['eligible'] || isset($representedLineages[$lineage])) {
                    continue;
                }
                $successor = $this->entitlements->createRenewalSuccessor(
                    $edge,
                    $renewalOrderId,
                    $subscriptionId,
                    $window['expires_at'],
                    $receipt
                );
                $results[] = $successor;
                if (isset($successor['edge']) && is_array($successor['edge'])) {
                    $projectionEdges[$this->resourceKey($successor['edge'])] = $successor['edge'];
                    $representedLineages[$lineage] = true;
                }
            }
            $projections = [];
            foreach ($projectionEdges as $edge) {
                $projections[] = $this->entitlements->projectLifecycleRenewal($edge, $receipt);
            }
            if (!$this->access->succeedEventLock($receipt, $owner)) {
                throw new \RuntimeException('Renewal receipt completion failed.');
            }
        } catch (\Throwable $exception) {
            $reason = match (true) {
                $exception instanceof \DomainException => 'invalid_lifecycle_policy',
                $exception instanceof \UnexpectedValueException => 'invalid_lifecycle_payload',
                default => 'lifecycle_processing_failed',
            };
            $retryable = $reason === 'lifecycle_processing_failed';
            $this->access->failEventLock($receipt, $owner, $reason, $retryable);

            return ['status' => 'failed', 'receipt' => $receipt, 'reason' => $reason];
        }

        foreach ($projections as $projection) {
            try {
                do_action(
                    'fchub_memberships/grant_renewed',
                    $projection['grant'],
                    (int) $projection['renewal_count']
                );
            } catch (\Throwable) {
                // Observers cannot reverse a receipt after canonical lifecycle work succeeded.
            }
        }

        return ['status' => 'processed', 'receipt' => $receipt, 'results' => $results];
    }

    public function pause(array|object $payload): array
    {
        return $this->changeLocalStatus($payload, true);
    }

    public function resume(array|object $payload): array
    {
        return $this->changeLocalStatus($payload, false);
    }

    public function cancel(array|object $payload): array
    {
        return $this->terminate($payload, false, 'Subscription cancelled');
    }

    public function endOfTerm(array|object $payload): array
    {
        return $this->terminate($payload, true, 'Subscription reached end of term');
    }

    public function expire(array|object $payload): array
    {
        return $this->terminate($payload, true, 'Subscription validity expired');
    }

    public function reactivate(array $payload): array
    {
        if (!isset($payload['order']) || !is_object($payload['order'])) {
            return ['status' => 'unverified', 'reason' => 'missing_positive_renewal_order'];
        }

        return $this->renew($payload);
    }

    public function checkValidity(): array
    {
        $expired = 0;
        $anchorPaused = 0;
        $failed = false;
        $groups = [];
        $anchorGrants = [];
        try {
            $due = $this->entitlements->getDueActive($this->clock->storage($this->clock->now()));
        } catch (\Throwable) {
            return [
                'anchor_paused' => 0,
                'term_expired' => 0,
                'grace_completed' => 0,
                'expired' => 0,
                'error' => 'lifecycle_processing_failed',
            ];
        }
        foreach ($due as $edge) {
            $policy = is_array($edge['policy'] ?? null) ? $edge['policy'] : [];
            $absoluteTerm = $this->policyDate($policy['membership_term_ends_at'] ?? null);
            if (array_key_exists('membership_term_ends_at', $policy)
                && $policy['membership_term_ends_at'] !== null
                && $absoluteTerm === null
            ) {
                $failed = true;
                continue;
            }
            if (($policy['validity_mode'] ?? null) === 'anchor_billing'
                && ($absoluteTerm === null || $absoluteTerm > $this->clock->now())
            ) {
                try {
                    $resourceEdges = $this->entitlements->getActiveByResource($edge);
                    $hasEffectiveSurvivor = false;
                    foreach ($resourceEdges as $resourceEdge) {
                        if ((int) ($resourceEdge['id'] ?? 0) !== (int) ($edge['id'] ?? 0)
                            && $this->edgeProvidesCurrentAccess($resourceEdge)
                        ) {
                            $hasEffectiveSurvivor = true;
                            break;
                        }
                    }
                    if ($hasEffectiveSurvivor) {
                        continue;
                    }
                    $grant = $this->grants->findByGrantKey(GrantRepository::makeGrantKey(
                        (int) $edge['user_id'],
                        (string) $edge['provider'],
                        (string) $edge['resource_type'],
                        (string) $edge['resource_id']
                    ));
                } catch (\Throwable) {
                    $failed = true;
                    continue;
                }
                if ($grant && ($grant['status'] ?? '') === 'active') {
                    $anchorGrants[(int) $grant['id']] = (int) $grant['id'];
                } elseif (!$grant) {
                    $failed = true;
                }
                continue;
            }
            $key = (int) $edge['user_id'] . ':' . (int) $edge['plan_id'];
            $groups[$key]['user_id'] = (int) $edge['user_id'];
            $groups[$key]['plan_id'] = (int) $edge['plan_id'];
            $groups[$key]['edge_ids'][] = (int) $edge['id'];
        }
        foreach ($groups as $group) {
            try {
                $result = $this->access->revokePlan($group['user_id'], $group['plan_id'], [
                    'edge_ids' => array_values(array_unique($group['edge_ids'])),
                    'grace_period_days' => 0,
                    'terminal_status' => 'expired',
                    'reason' => 'Membership validity expired',
                    'origin_event' => 'membership:lifecycle:expiry:' . hash(
                        'sha256',
                        implode(',', $group['edge_ids'])
                    ),
                ]);
            } catch (\Throwable) {
                $failed = true;
                continue;
            }
            if (!empty($result['success'])
                && (int) ($result['pending'] ?? 0) === 0
                && (int) ($result['failed'] ?? 0) === 0
            ) {
                $expired += (int) ($result['revoked'] ?? 0);
            } else {
                $failed = true;
            }
        }
        foreach ($anchorGrants as $grantId) {
            try {
                $result = $this->access->pauseGrant($grantId, 'Anchor billing date overdue');
                if (!empty($result['success'])) {
                    $anchorPaused++;
                } else {
                    $failed = true;
                }
            } catch (\Throwable) {
                $failed = true;
            }
        }

        try {
            $graceCompleted = $this->access->revokeExpiredGracePeriodGrants();
        } catch (\Throwable) {
            $graceCompleted = 0;
            $failed = true;
        }

        $result = [
            'anchor_paused' => $anchorPaused,
            'term_expired' => 0,
            'grace_completed' => $graceCompleted,
            'expired' => $expired,
        ];
        if ($failed) {
            $result['error'] = 'lifecycle_processing_failed';
        }

        return $result;
    }

    private function changeLocalStatus(array|object $payload, bool $pause): array
    {
        $subscriptionId = $this->subscriptionId($payload);
        if ($subscriptionId <= 0) {
            return ['status' => 'unverified'];
        }
        $changed = 0;
        $pending = false;
        $seen = [];
        try {
            $edges = $this->entitlements->getBySubscriptionCorrelation($subscriptionId, 'active');
        } catch (\Throwable) {
            return ['status' => 'failed', 'reason' => 'lifecycle_processing_failed'];
        }
        $handledResources = [];
        foreach ($edges as $edge) {
            $key = GrantRepository::makeGrantKey(
                (int) $edge['user_id'],
                (string) $edge['provider'],
                (string) $edge['resource_type'],
                (string) $edge['resource_id']
            );
            if (isset($handledResources[$key])) {
                continue;
            }
            $handledResources[$key] = true;
            $targetEdges = array_values(array_filter(
                $edges,
                function (array $candidate) use ($key, $pause): bool {
                    if (GrantRepository::makeGrantKey(
                        (int) $candidate['user_id'],
                        (string) $candidate['provider'],
                        (string) $candidate['resource_type'],
                        (string) $candidate['resource_id']
                    ) !== $key) {
                        return false;
                    }
                    if (!$pause && !$this->edgeCanResume($candidate)) {
                        return false;
                    }

                    return ($candidate['access_status'] ?? 'active') !== ($pause ? 'paused' : 'active');
                }
            ));
            if ($targetEdges === []) {
                continue;
            }
            $targetEdgeIds = array_fill_keys(array_map(
                static fn(array $target): int => (int) ($target['id'] ?? 0),
                $targetEdges
            ), true);
            try {
                $resourceEdges = $this->entitlements->getActiveByResource($edge);
            } catch (\Throwable) {
                return ['status' => 'failed', 'reason' => 'lifecycle_processing_failed'];
            }
            $hasEffectiveSurvivor = false;
            foreach ($resourceEdges as $resourceEdge) {
                if (!isset($targetEdgeIds[(int) ($resourceEdge['id'] ?? 0)])
                    && $this->edgeProvidesCurrentAccess($resourceEdge)
                ) {
                    $hasEffectiveSurvivor = true;
                    break;
                }
            }
            $transitionGrantId = null;
            $transitionPending = false;
            if (!$hasEffectiveSurvivor) {
                $grant = $this->grants->findByGrantKey($key);
                if (!$grant || isset($seen[(int) $grant['id']])) {
                    continue;
                }
                $seen[(int) $grant['id']] = true;
                $transitionGrantId = (int) $grant['id'];
                try {
                    $result = $pause
                        ? $this->access->pauseGrant((int) $grant['id'], 'Subscription paused')
                        : $this->access->resumeGrant((int) $grant['id']);
                } catch (\Throwable) {
                    return ['status' => 'failed', 'reason' => 'lifecycle_processing_failed'];
                }
                if (empty($result['success'])) {
                    if (empty($result['pending'])) {
                        return ['status' => 'failed', 'reason' => 'provider_transition_failed'];
                    }
                    $transitionPending = true;
                }
            }
            try {
                $statusChanged = $this->entitlements->setAccessStatus(
                    $targetEdges,
                    $pause ? 'paused' : 'active'
                );
            } catch (\Throwable) {
                if ($transitionGrantId !== null) {
                    $this->compensateStatusTransition($transitionGrantId, $pause);
                }
                return ['status' => 'failed', 'reason' => 'lifecycle_processing_failed'];
            }
            if ($statusChanged > 0) {
                $changed++;
            }
            $pending = $pending || $transitionPending;
        }

        return ['status' => $pending ? 'pending' : 'processed', 'changed' => $changed];
    }

    private function compensateStatusTransition(int $grantId, bool $paused): void
    {
        try {
            if ($paused) {
                $this->access->resumeGrant($grantId);
            } else {
                $this->access->pauseGrant($grantId, 'Subscription resume compensation');
            }
        } catch (\Throwable) {
            // The original failure remains authoritative; recovery can retry from durable provider state.
        }
    }

    private function terminate(array|object $payload, bool $forceImmediate, string $reason): array
    {
        $subscriptionId = $this->subscriptionId($payload);
        if ($subscriptionId <= 0) {
            return ['status' => 'unverified'];
        }
        $results = [];
        try {
            $edges = $this->entitlements->getBySubscriptionCorrelation($subscriptionId, 'active');
        } catch (\Throwable) {
            return ['status' => 'failed', 'reason' => 'lifecycle_processing_failed'];
        }
        foreach ($edges as $edge) {
            $policy = is_array($edge['policy'] ?? null) ? $edge['policy'] : [];
            $cancelBehavior = $policy['cancel_behavior'] ?? 'wait_validity';
            if (!$forceImmediate && $cancelBehavior !== 'immediate') {
                $results[] = ['status' => 'deferred', 'edge_id' => (int) $edge['id']];
                continue;
            }
            try {
                $results[] = $this->access->revokePlan((int) $edge['user_id'], (int) $edge['plan_id'], [
                    'source_type' => (string) $edge['source_type'],
                    'source_id' => (int) $edge['source_id'],
                    'feed_id' => (int) $edge['feed_id'],
                    'feed_scope' => (string) $edge['feed_scope'],
                    'edge_ids' => [(int) $edge['id']],
                    'grace_period_days' => $forceImmediate ? 0 : max(0, (int) ($policy['grace_period_days'] ?? 0)),
                    'reason' => $reason,
                    'origin_event' => 'membership:lifecycle:' . hash('sha256', $reason . '|' . (int) $edge['id']),
                ]);
            } catch (\Throwable) {
                return ['status' => 'failed', 'reason' => 'lifecycle_processing_failed'];
            }
        }

        return ['status' => 'processed', 'results' => $results];
    }

    private function subscriptionId(array|object $payload): int
    {
        $subscription = is_array($payload) ? ($payload['subscription'] ?? null) : $payload;
        return is_object($subscription) ? $this->positiveId($subscription->id ?? null) : 0;
    }

    private function payloadId(array $payload, string $key): int
    {
        $object = $payload[$key] ?? null;
        $id = is_object($object) ? $this->positiveId($object->id ?? null) : 0;
        if ($id <= 0) {
            throw new \InvalidArgumentException("Lifecycle payload {$key} ID is invalid.");
        }
        return $id;
    }

    private function positiveId(mixed $value): int
    {
        return (is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0
            ? (int) $value
            : 0;
    }

    private function renewalWindow(array $edge, object $subscription): array
    {
        $policy = is_array($edge['policy'] ?? null) ? $edge['policy'] : [];
        $mode = (string) ($policy['validity_mode'] ?? (empty($edge['expires_at']) ? 'lifetime' : 'mirror_subscription'));
        $absoluteTerm = $this->policyDate($policy['membership_term_ends_at'] ?? null);
        if (array_key_exists('membership_term_ends_at', $policy)
            && $policy['membership_term_ends_at'] !== null
            && $absoluteTerm === null
        ) {
            throw new \DomainException('invalid_lifecycle_policy');
        }
        if ($absoluteTerm !== null && $absoluteTerm <= $this->clock->now()) {
            return ['eligible' => false, 'expires_at' => null];
        }
        if ($mode === 'lifetime') {
            return ['eligible' => true, 'expires_at' => null];
        }
        if ($mode === 'fixed_duration') {
            $days = (int) ($policy['validity_days'] ?? 0);
            $from = $this->policyDate($edge['expires_at'] ?? null);
            if ($days <= 0 || $from === null) {
                throw new \DomainException('invalid_lifecycle_policy');
            }
            $expiry = $this->clock->storage($this->clock->plusDays($days, $from));
        } else {
            $expiry = null;
        }
        if ($mode === 'anchor_billing') {
            $anchorDay = (int) ($policy['billing_anchor_day'] ?? 0);
            if ($anchorDay < 1 || $anchorDay > 31) {
                throw new \DomainException('invalid_lifecycle_policy');
            }
            $from = is_string($edge['expires_at'] ?? null) && $edge['expires_at'] !== ''
                ? (string) $edge['expires_at']
                : $this->clock->storage($this->clock->now());
            $expiry = AnchorDateCalculator::nextAnchorAfter($anchorDay, $from, $this->clock);
        } elseif ($mode === 'mirror_subscription') {
            $expiryDate = $this->policyDate($subscription->next_billing_date ?? null);
            if ($expiryDate === null) {
                throw new \UnexpectedValueException('invalid_lifecycle_payload');
            }
            $expiry = $this->clock->storage($expiryDate);
        } elseif ($mode !== 'fixed_duration') {
            throw new \DomainException('invalid_lifecycle_policy');
        }

        if ($absoluteTerm !== null) {
            $absoluteStorage = $this->clock->storage($absoluteTerm);
            if (strcmp($expiry, $absoluteStorage) > 0) {
                $expiry = $absoluteStorage;
            }
        }

        return ['eligible' => true, 'expires_at' => $expiry];
    }

    private function edgeCanResume(array $edge): bool
    {
        $policy = is_array($edge['policy'] ?? null) ? $edge['policy'] : [];
        $absoluteTerm = $this->policyDate($policy['membership_term_ends_at'] ?? null);
        if (array_key_exists('membership_term_ends_at', $policy)
            && $policy['membership_term_ends_at'] !== null
            && $absoluteTerm === null
        ) {
            return false;
        }
        if ($absoluteTerm !== null && $absoluteTerm <= $this->clock->now()) {
            return false;
        }
        if (($policy['validity_mode'] ?? null) === 'anchor_billing') {
            return true;
        }
        $expiry = $this->policyDate($edge['expires_at'] ?? null);

        return $expiry === null || $expiry > $this->clock->now();
    }

    private function edgeProvidesCurrentAccess(array $edge): bool
    {
        if (($edge['access_status'] ?? 'active') !== 'active') {
            return false;
        }
        $policy = is_array($edge['policy'] ?? null) ? $edge['policy'] : [];
        if (array_key_exists('membership_term_ends_at', $policy)
            && $policy['membership_term_ends_at'] !== null
        ) {
            $absoluteTerm = $this->policyDate($policy['membership_term_ends_at']);
            if ($absoluteTerm === null || $absoluteTerm <= $this->clock->now()) {
                return false;
            }
        }
        foreach (['starts_at', 'drip_available_at'] as $field) {
            if (!isset($edge[$field]) || $edge[$field] === '') {
                continue;
            }
            $availableAt = $this->policyDate($edge[$field]);
            if ($availableAt === null || $availableAt > $this->clock->now()) {
                return false;
            }
        }
        if (!isset($edge['expires_at']) || $edge['expires_at'] === '') {
            return true;
        }
        $expiry = $this->policyDate($edge['expires_at']);

        return $expiry !== null && $expiry > $this->clock->now();
    }

    private function policyDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            trim($value),
            $this->clock->now()->getTimezone()
        );
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $date;
    }

    private function resourceKey(array $edge): string
    {
        return implode('|', [
            (int) ($edge['user_id'] ?? 0),
            (string) ($edge['provider'] ?? ''),
            (string) ($edge['resource_type'] ?? ''),
            (string) ($edge['resource_id'] ?? ''),
        ]);
    }

    private function renewalLineageKey(array $edge): string
    {
        return implode('|', [
            $this->resourceKey($edge),
            (int) ($edge['plan_id'] ?? 0),
            (int) ($edge['feed_id'] ?? 0),
            (string) ($edge['feed_scope'] ?? ''),
        ]);
    }

    private function isLaterRenewalGeneration(array $candidate, array $current): bool
    {
        $candidateStarts = (string) ($candidate['starts_at'] ?? '');
        $currentStarts = (string) ($current['starts_at'] ?? '');
        if ($candidateStarts !== $currentStarts) {
            return strcmp($candidateStarts, $currentStarts) > 0;
        }

        return (int) ($candidate['id'] ?? 0) > (int) ($current['id'] ?? 0);
    }

    private function stableCommandFailure(): array
    {
        return [
            'success' => false,
            'failed' => 1,
            'errors' => [[
                'reason' => 'lifecycle_processing_failed',
                'message' => 'Membership lifecycle processing failed.',
            ]],
        ];
    }
}

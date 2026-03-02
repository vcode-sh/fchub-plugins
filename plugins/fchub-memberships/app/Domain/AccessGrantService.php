<?php

namespace FChubMemberships\Domain;

defined('ABSPATH') || exit;

use FChubMemberships\Domain\AuditLogger;
use FChubMemberships\Domain\Plan\PlanRuleResolver;
use FChubMemberships\Domain\StatusTransitionValidator;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\GrantSourceRepository;
use FChubMemberships\Storage\DripScheduleRepository;
use FChubMemberships\Storage\EventLockRepository;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Support\Logger;

class AccessGrantService
{
    private GrantRepository $grantRepo;
    private GrantSourceRepository $sourceRepo;
    private PlanRuleResolver $ruleResolver;
    private DripScheduleRepository $dripRepo;
    private EventLockRepository $lockRepo;

    public function __construct()
    {
        $this->grantRepo = new GrantRepository();
        $this->sourceRepo = new GrantSourceRepository();
        $this->ruleResolver = new PlanRuleResolver();
        $this->dripRepo = new DripScheduleRepository();
        $this->lockRepo = new EventLockRepository();
    }

    /**
     * Grant a plan's resources to a user.
     *
     * @param int    $userId
     * @param int    $planId
     * @param array  $context Keys: source_type, source_id, feed_id, expires_at, order (optional)
     * @return array Summary of grants created/updated
     */
    public function grantPlan(int $userId, int $planId, array $context = []): array
    {
        $rules = $this->ruleResolver->resolveUniqueRules($planId);
        $sourceType = $context['source_type'] ?? 'manual';
        $sourceId = (int) ($context['source_id'] ?? 0);
        $feedId = $context['feed_id'] ?? null;
        $expiresAt = $context['expires_at'] ?? null;
        $order = $context['order'] ?? null;

        // Load plan for trial/duration configuration
        $planRepo = new PlanRepository();
        $plan = $planRepo->find($planId);

        // Check for trial eligibility
        if ($plan && ($plan['trial_days'] ?? 0) > 0) {
            $existingGrants = $this->grantRepo->getByUserId($userId, ['plan_id' => $planId]);
            $hasActiveOrPaused = array_filter($existingGrants, fn($g) => in_array($g['status'], ['active', 'paused'], true));
            if (empty($hasActiveOrPaused)) {
                $context['is_trial'] = true;
                $context['trial_ends_at'] = date('Y-m-d H:i:s', strtotime('+' . $plan['trial_days'] . ' days'));
            }
        }

        // Multi-membership mode enforcement
        $settings = get_option('fchub_memberships_settings', []);
        $mode = $settings['membership_mode'] ?? 'stack';

        if ($mode !== 'stack') {
            $activePlanIds = $this->grantRepo->getUserActivePlanIds($userId);
            // Remove current plan from the list (it's being renewed, not conflicting)
            $otherPlanIds = array_filter($activePlanIds, fn($id) => $id !== $planId);

            if (!empty($otherPlanIds)) {
                if ($mode === 'exclusive') {
                    // Revoke all other plans
                    foreach ($otherPlanIds as $oldPlanId) {
                        $this->revokePlan($userId, $oldPlanId, [
                            'reason' => sprintf('Replaced by plan #%d (exclusive mode)', $planId),
                        ]);
                    }
                    do_action('fchub_memberships/plan_replaced', $userId, $planId, $otherPlanIds);
                } elseif ($mode === 'upgrade_only') {
                    $planLevel = $plan['level'] ?? 0;
                    $currentHighest = $this->grantRepo->getHighestActivePlanLevel($userId);

                    if ($planLevel < $currentHighest) {
                        // Block downgrade
                        Logger::log('Downgrade blocked', sprintf(
                            'User #%d attempted plan #%d (level %d) but has active plan at level %d',
                            $userId, $planId, $planLevel, $currentHighest
                        ));
                        if ($order) {
                            Logger::orderLog(
                                $order,
                                __('Membership grant blocked', 'fchub-memberships'),
                                sprintf(
                                    __('Plan level %d is lower than current level %d. Upgrade-only mode prevents downgrades.', 'fchub-memberships'),
                                    $planLevel,
                                    $currentHighest
                                ),
                                'warning'
                            );
                        }
                        return [
                            'created' => 0,
                            'updated' => 0,
                            'total'   => 0,
                            'blocked' => true,
                            'reason'  => 'downgrade_blocked',
                        ];
                    }

                    // Revoke lower-level plans
                    $planRepo2 = new PlanRepository();
                    $revokedPlanIds = [];
                    foreach ($otherPlanIds as $oldPlanId) {
                        $oldPlan = $planRepo2->find($oldPlanId);
                        if ($oldPlan && ($oldPlan['level'] ?? 0) < $planLevel) {
                            $this->revokePlan($userId, $oldPlanId, [
                                'reason' => sprintf('Upgraded to plan #%d level %d (upgrade_only mode)', $planId, $planLevel),
                            ]);
                            $revokedPlanIds[] = $oldPlanId;
                        }
                    }
                    if (!empty($revokedPlanIds)) {
                        do_action('fchub_memberships/plan_upgraded', $userId, $planId, $revokedPlanIds);
                    }
                }
            }
        }

        // Calculate expiry from plan duration if not provided
        if ($plan && empty($expiresAt)) {
            $durationType = $plan['duration_type'] ?? 'lifetime';
            if ($durationType === 'fixed_days' && ($plan['duration_days'] ?? 0) > 0) {
                $expiresAt = date('Y-m-d H:i:s', strtotime('+' . $plan['duration_days'] . ' days'));
            } elseif ($durationType === 'lifetime') {
                $expiresAt = null;
            }
        }

        $created = 0;
        $updated = 0;

        foreach ($rules as $rule) {
            $result = $this->grantResource(
                $userId,
                $rule['provider'],
                $rule['resource_type'],
                $rule['resource_id'],
                [
                    'plan_id'     => $planId,
                    'source_type' => $sourceType,
                    'source_id'   => $sourceId,
                    'feed_id'     => $feedId,
                    'expires_at'  => $expiresAt,
                    'drip_rule'   => $rule,
                ]
            );

            if ($result['action'] === 'created') {
                $created++;
            } elseif ($result['action'] === 'updated') {
                $updated++;
            }
        }

        Logger::log(
            'Plan granted',
            sprintf('User #%d granted plan #%d: %d created, %d updated', $userId, $planId, $created, $updated),
            ['module_id' => $context['source_id'] ?? 0, 'module_name' => 'Order']
        );

        if ($order) {
            Logger::orderLog(
                $order,
                __('Membership plan granted', 'fchub-memberships'),
                sprintf(__('Plan #%d: %d resources granted', 'fchub-memberships'), $planId, $created + $updated)
            );
        }

        // Send access granted email
        $this->sendGrantedEmail($userId, $planId, $rules);

        do_action('fchub_memberships/grant_created', $userId, $planId, $context);

        if (!empty($context['is_trial'])) {
            do_action('fchub_memberships/trial_started', $context, $planId, $userId);
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'total'   => count($rules),
        ];
    }

    /**
     * Grant access to a single resource.
     */
    public function grantResource(int $userId, string $provider, string $resourceType, string $resourceId, array $context = []): array
    {
        $grantKey = GrantRepository::makeGrantKey($userId, $provider, $resourceType, $resourceId);
        $existing = $this->grantRepo->findByGrantKey($grantKey);

        $sourceId = (int) ($context['source_id'] ?? 0);

        if ($existing) {
            // Update existing grant
            $sourceIds = $existing['source_ids'];
            if ($sourceId && !in_array($sourceId, $sourceIds, false)) {
                $sourceIds[] = $sourceId;
            }

            $updateData = [
                'status'       => 'active',
                'source_ids'   => $sourceIds,
                'renewal_count' => ($existing['renewal_count'] ?? 0) + 1,
            ];

            // Update expiry if provided and later than current
            if (!empty($context['expires_at'])) {
                if (empty($existing['expires_at']) || strtotime($context['expires_at']) > strtotime($existing['expires_at'])) {
                    $updateData['expires_at'] = $context['expires_at'];
                }
            }

            // Update plan_id if provided
            if (isset($context['plan_id'])) {
                $updateData['plan_id'] = $context['plan_id'];
            }

            $this->grantRepo->update($existing['id'], $updateData);

            // Also insert into junction table
            if ($sourceId) {
                $this->sourceRepo->addSource($existing['id'], $context['source_type'] ?? 'manual', $sourceId);
            }

            AuditLogger::logGrantChange($existing['id'], 'renewed', $existing, $updateData);

            do_action('fchub_memberships/grant_renewed', $existing, $updateData['renewal_count']);

            return ['action' => 'updated', 'grant_id' => $existing['id']];
        }

        // Create new grant
        $dripAvailableAt = $this->calculateDripDate($context['drip_rule'] ?? null);
        $isTrial = !empty($context['is_trial']);

        $grantData = [
            'user_id'          => $userId,
            'plan_id'          => $context['plan_id'] ?? null,
            'provider'         => $provider,
            'resource_type'    => $resourceType,
            'resource_id'      => $resourceId,
            'source_type'      => $isTrial ? 'trial' : ($context['source_type'] ?? 'manual'),
            'source_id'        => $sourceId,
            'feed_id'          => $context['feed_id'] ?? null,
            'grant_key'        => $grantKey,
            'status'           => 'active',
            'expires_at'       => $isTrial ? ($context['trial_ends_at'] ?? null) : ($context['expires_at'] ?? null),
            'trial_ends_at'    => $context['trial_ends_at'] ?? null,
            'drip_available_at' => $dripAvailableAt,
            'source_ids'       => $sourceId ? [$sourceId] : [],
            'meta'             => $context['meta'] ?? [],
        ];

        $grantId = $this->grantRepo->create($grantData);

        // Also insert into junction table
        if ($sourceId) {
            $this->sourceRepo->addSource($grantId, $grantData['source_type'], $sourceId);
        }

        AuditLogger::logGrantChange($grantId, 'created', [], $grantData);

        // Schedule drip notification if needed
        if ($dripAvailableAt && isset($context['drip_rule'])) {
            $this->dripRepo->schedule([
                'grant_id'     => $grantId,
                'plan_rule_id' => $context['drip_rule']['id'] ?? 0,
                'user_id'      => $userId,
                'notify_at'    => $dripAvailableAt,
            ]);
        }

        // Call adapter grant
        $this->callAdapterGrant($userId, $provider, $resourceType, $resourceId);

        return ['action' => 'created', 'grant_id' => $grantId];
    }

    /**
     * Revoke a plan's resources from a user.
     */
    public function revokePlan(int $userId, int $planId, array $context = []): array
    {
        $sourceId = (int) ($context['source_id'] ?? 0);
        $reason = $context['reason'] ?? '';
        $order = $context['order'] ?? null;

        // Load plan for grace period configuration
        $planRepo = new PlanRepository();
        $plan = $planRepo->find($planId);
        $gracePeriodDays = (int) ($context['grace_period_days'] ?? ($plan['grace_period_days'] ?? 0));

        $grants = $this->grantRepo->getByUserId($userId, ['plan_id' => $planId, 'status' => 'active']);
        $revoked = 0;
        $retained = 0;

        foreach ($grants as $grant) {
            // Validate status transition
            try {
                StatusTransitionValidator::assertTransition($grant['status'], 'revoked');
            } catch (\InvalidArgumentException $e) {
                continue;
            }

            if ($sourceId) {
                // Remove this source from source_ids
                $sourceIds = $grant['source_ids'];
                $sourceIds = array_values(array_filter($sourceIds, function ($id) use ($sourceId) {
                    return (int) $id !== $sourceId;
                }));

                // Also remove from junction table
                $this->sourceRepo->removeSource($grant['id'], $grant['source_type'], $sourceId);

                if (!empty($sourceIds)) {
                    // Other sources still provide this grant — keep active
                    $this->grantRepo->update($grant['id'], ['source_ids' => $sourceIds]);
                    $retained++;
                    continue;
                }
            }

            // Determine if grace period applies
            if ($gracePeriodDays > 0) {
                $this->grantRepo->update($grant['id'], [
                    'source_ids'                => [],
                    'cancellation_requested_at' => current_time('mysql'),
                    'cancellation_effective_at' => gmdate('Y-m-d H:i:s', strtotime("+{$gracePeriodDays} days")),
                    'cancellation_reason'       => $reason,
                    'meta'                      => array_merge($grant['meta'], ['revoke_reason' => $reason]),
                ]);
                $this->sourceRepo->removeAllByGrant($grant['id']);

                // Grace period: defer adapter revoke and drip cleanup to revokeExpiredGracePeriodGrants()
                AuditLogger::logGrantChange($grant['id'], 'grace_period_started', $grant, [
                    'cancellation_effective_at' => gmdate('Y-m-d H:i:s', strtotime("+{$gracePeriodDays} days")),
                ]);
            } else {
                // Revoke the grant immediately
                $this->grantRepo->update($grant['id'], [
                    'status'                    => 'revoked',
                    'source_ids'                => [],
                    'cancellation_requested_at' => current_time('mysql'),
                    'cancellation_reason'       => $reason,
                    'meta'                      => array_merge($grant['meta'], ['revoke_reason' => $reason]),
                ]);
                $this->sourceRepo->removeAllByGrant($grant['id']);

                // Delete pending drip notifications
                $this->dripRepo->deleteByGrantId($grant['id']);

                // Call adapter revoke
                $this->callAdapterRevoke($userId, $grant['provider'], $grant['resource_type'], $grant['resource_id']);

                AuditLogger::logGrantChange($grant['id'], 'revoked', $grant, ['status' => 'revoked']);
            }

            $revoked++;
        }

        do_action('fchub_memberships/grant_revoked', $grants, $planId, $userId, $reason);

        Logger::log(
            'Plan revoked',
            sprintf('User #%d plan #%d: %d revoked, %d retained', $userId, $planId, $revoked, $retained),
            ['module_id' => $sourceId, 'module_name' => 'Order']
        );

        if ($order) {
            Logger::orderLog(
                $order,
                __('Membership plan revoked', 'fchub-memberships'),
                sprintf(__('Plan #%d: %d resources revoked, %d retained', 'fchub-memberships'), $planId, $revoked, $retained)
            );
        }

        // Send revoked email
        if ($revoked > 0) {
            $this->sendRevokedEmail($userId, $planId, $reason);
        }

        return ['revoked' => $revoked, 'retained' => $retained];
    }

    /**
     * Revoke all grants from a specific source (order/subscription).
     */
    public function revokeBySource(int $sourceId, string $sourceType = 'order', array $context = []): array
    {
        $grants = $this->grantRepo->getBySourceId($sourceId, $sourceType);
        $revoked = 0;
        $retained = 0;

        foreach ($grants as $grant) {
            $sourceIds = $grant['source_ids'];
            $sourceIds = array_values(array_filter($sourceIds, function ($id) use ($sourceId) {
                return (int) $id !== $sourceId;
            }));

            // Remove from junction table
            $this->sourceRepo->removeSource($grant['id'], $sourceType, $sourceId);

            if (!empty($sourceIds)) {
                $this->grantRepo->update($grant['id'], ['source_ids' => $sourceIds]);
                $retained++;
                continue;
            }

            $this->grantRepo->update($grant['id'], [
                'status'     => 'revoked',
                'source_ids' => [],
            ]);
            $this->sourceRepo->removeAllByGrant($grant['id']);

            $this->dripRepo->deleteByGrantId($grant['id']);
            $this->callAdapterRevoke($grant['user_id'], $grant['provider'], $grant['resource_type'], $grant['resource_id']);
            $revoked++;
        }

        return ['revoked' => $revoked, 'retained' => $retained];
    }

    /**
     * Extend expiry for all active grants of a user's plan.
     */
    public function extendExpiry(int $userId, int $planId, string $newExpiresAt, ?int $renewalSourceId = null): int
    {
        $grants = $this->grantRepo->getByUserId($userId, ['plan_id' => $planId, 'status' => 'active']);
        $extended = 0;

        foreach ($grants as $grant) {
            $updateData = ['expires_at' => $newExpiresAt];

            if ($renewalSourceId) {
                $sourceIds = $grant['source_ids'];
                if (!in_array($renewalSourceId, $sourceIds, false)) {
                    $sourceIds[] = $renewalSourceId;
                    $updateData['source_ids'] = $sourceIds;
                }
                // Also add to junction table
                $this->sourceRepo->addSource($grant['id'], $grant['source_type'] ?: 'order', $renewalSourceId);
            }

            $this->grantRepo->update($grant['id'], $updateData);
            $extended++;
        }

        return $extended;
    }

    /**
     * Manual grant from admin panel.
     */
    public function manualGrant(int $userId, int $planId, ?string $expiresAt = null): array
    {
        return $this->grantPlan($userId, $planId, [
            'source_type' => 'manual',
            'source_id'   => 0,
            'expires_at'  => $expiresAt,
        ]);
    }

    /**
     * Check event idempotency lock before processing.
     */
    public function acquireEventLock(int $orderId, int $feedId, string $trigger, ?int $subscriptionId = null): bool
    {
        $hash = EventLockRepository::makeEventHash($orderId, $feedId, $trigger, $subscriptionId);
        return $this->lockRepo->acquire([
            'event_hash'      => $hash,
            'order_id'        => $orderId,
            'subscription_id' => $subscriptionId,
            'feed_id'         => $feedId,
            'trigger'         => $trigger,
        ]);
    }

    public function pauseGrant(int $grantId, string $reason = ''): array
    {
        $grant = $this->grantRepo->find($grantId);
        if (!$grant) {
            return ['error' => 'Grant not found'];
        }

        StatusTransitionValidator::assertTransition($grant['status'], 'paused');

        $this->grantRepo->update($grantId, [
            'status' => 'paused',
            'meta' => array_merge($grant['meta'], [
                'paused_at' => current_time('mysql'),
                'pause_reason' => $reason,
            ]),
        ]);

        AuditLogger::logGrantChange($grantId, 'paused', $grant, ['status' => 'paused'], $reason);
        do_action('fchub_memberships/grant_paused', $grant, $reason);

        $this->sendPausedEmail($grant);

        return ['success' => true, 'grant_id' => $grantId];
    }

    public function resumeGrant(int $grantId): array
    {
        $grant = $this->grantRepo->find($grantId);
        if (!$grant) {
            return ['error' => 'Grant not found'];
        }

        StatusTransitionValidator::assertTransition($grant['status'], 'active');

        $this->grantRepo->update($grantId, [
            'status' => 'active',
            'meta' => array_merge($grant['meta'], [
                'resumed_at' => current_time('mysql'),
            ]),
        ]);

        AuditLogger::logGrantChange($grantId, 'resumed', $grant, ['status' => 'active']);
        do_action('fchub_memberships/grant_resumed', $grant);

        $this->sendResumedEmail($grant);

        return ['success' => true, 'grant_id' => $grantId];
    }

    public function bulkGrant(array $userIds, int $planId, array $context = []): array
    {
        $results = ['granted' => 0, 'failed' => 0, 'errors' => []];
        foreach ($userIds as $userId) {
            try {
                $this->grantPlan((int) $userId, $planId, $context);
                $results['granted']++;
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = sprintf('User #%d: %s', $userId, $e->getMessage());
            }
        }
        return $results;
    }

    public function bulkRevoke(array $userIds, int $planId, array $context = []): array
    {
        $results = ['revoked' => 0, 'failed' => 0, 'errors' => []];
        foreach ($userIds as $userId) {
            try {
                $this->revokePlan((int) $userId, $planId, $context);
                $results['revoked']++;
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = sprintf('User #%d: %s', $userId, $e->getMessage());
            }
        }
        return $results;
    }

    /**
     * Expire overdue grants with hooks fired for each grant.
     *
     * Unlike GrantRepository::expireOverdueGrants() which does a bulk UPDATE,
     * this method SELECTs the grants first, fires the grant_expired hook for each,
     * then performs the bulk UPDATE.
     */
    public function expireOverdueGrantsWithHooks(): int
    {
        $overdueGrants = $this->grantRepo->getOverdueGrants();

        if (empty($overdueGrants)) {
            return 0;
        }

        // Fire hook for each grant before bulk update
        foreach ($overdueGrants as $grant) {
            do_action('fchub_memberships/grant_expired', $grant);

            AuditLogger::logGrantChange($grant['id'], 'expired', $grant, ['status' => 'expired']);
        }

        // Perform the bulk UPDATE
        return $this->grantRepo->expireOverdueGrants();
    }

    /**
     * Revoke grants that have passed their grace period (cancellation_effective_at).
     *
     * Called from the validity watcher cron. Finds active grants where
     * cancellation_effective_at is set and has passed, then properly revokes them.
     */
    public function revokeExpiredGracePeriodGrants(): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fchub_membership_grants';
        $now = current_time('mysql');

        $grants = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE status = 'active'
               AND cancellation_effective_at IS NOT NULL
               AND cancellation_effective_at <= %s
             LIMIT 100",
            $now
        ), ARRAY_A);

        if (empty($grants)) {
            return 0;
        }

        $revoked = 0;
        foreach ($grants as $grant) {
            $grant['source_ids'] = !empty($grant['source_ids']) ? json_decode($grant['source_ids'], true) : [];
            $grant['meta'] = !empty($grant['meta']) ? json_decode($grant['meta'], true) : [];

            $reason = $grant['cancellation_reason'] ?: 'Grace period expired';

            $this->grantRepo->update($grant['id'], [
                'status' => 'revoked',
                'meta'   => array_merge($grant['meta'], ['revoke_reason' => $reason]),
            ]);

            $this->dripRepo->deleteByGrantId($grant['id']);
            $this->callAdapterRevoke(
                (int) $grant['user_id'],
                $grant['provider'],
                $grant['resource_type'],
                $grant['resource_id']
            );

            AuditLogger::logGrantChange($grant['id'], 'revoked', $grant, ['status' => 'revoked']);
            do_action('fchub_memberships/grant_revoked', [$grant], (int) $grant['plan_id'], (int) $grant['user_id'], $reason);

            $revoked++;
        }

        if ($revoked > 0) {
            Logger::log('Grace period', sprintf('%d grants revoked after grace period', $revoked));
        }

        return $revoked;
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

    private function callAdapterGrant(int $userId, string $provider, string $resourceType, string $resourceId): void
    {
        $adapter = $this->getAdapter($provider);
        if ($adapter) {
            $adapter->grant($userId, $resourceType, $resourceId);
        }
    }

    private function callAdapterRevoke(int $userId, string $provider, string $resourceType, string $resourceId): void
    {
        $adapter = $this->getAdapter($provider);
        if ($adapter) {
            $adapter->revoke($userId, $resourceType, $resourceId);
        }
    }

    private function getAdapter(string $provider): ?object
    {
        $adapters = [
            'wordpress_core'   => \FChubMemberships\Adapters\WordPressContentAdapter::class,
            'learndash'        => \FChubMemberships\Adapters\LearnDashAdapter::class,
            'fluentcrm'        => \FChubMemberships\Adapters\FluentCrmAdapter::class,
            'fluent_community' => \FChubMemberships\Adapters\FluentCommunityAdapter::class,
        ];

        $class = $adapters[$provider] ?? null;
        if ($class && class_exists($class)) {
            return new $class();
        }

        return null;
    }

    private function sendGrantedEmail(int $userId, int $planId, array $rules): void
    {
        $settings = get_option('fchub_memberships_settings', []);
        if (($settings['email_access_granted'] ?? 'yes') !== 'yes') {
            return;
        }

        $planRepo = new \FChubMemberships\Storage\PlanRepository();
        $plan = $planRepo->find($planId);
        if (!$plan) {
            return;
        }

        $immediateResources = array_filter($rules, fn($r) => $r['drip_type'] === 'immediate');
        $dripItems = array_filter($rules, fn($r) => $r['drip_type'] !== 'immediate');

        (new \FChubMemberships\Email\AccessGrantedEmail())->send($userId, [
            'plan_id'    => $planId,
            'plan_title' => $plan['title'],
            'resources'  => array_values($immediateResources),
            'drip_items' => array_values($dripItems),
        ]);
    }

    private function sendRevokedEmail(int $userId, int $planId, string $reason): void
    {
        $settings = get_option('fchub_memberships_settings', []);
        if (($settings['email_access_revoked'] ?? 'yes') !== 'yes') {
            return;
        }

        $planRepo = new \FChubMemberships\Storage\PlanRepository();
        $plan = $planRepo->find($planId);
        if (!$plan) {
            return;
        }

        (new \FChubMemberships\Email\AccessRevokedEmail())->send($userId, [
            'plan_title' => $plan['title'],
            'reason'     => $reason,
        ]);
    }

    private function sendPausedEmail(array $grant): void
    {
        $planRepo = new PlanRepository();
        $plan = $planRepo->find($grant['plan_id']);
        if (!$plan) {
            return;
        }

        (new \FChubMemberships\Email\MembershipPausedEmail())->send($grant['user_id'], [
            'plan_title' => $plan['title'],
        ]);
    }

    private function sendResumedEmail(array $grant): void
    {
        $planRepo = new PlanRepository();
        $plan = $planRepo->find($grant['plan_id']);
        if (!$plan) {
            return;
        }

        (new \FChubMemberships\Email\MembershipResumedEmail())->send($grant['user_id'], [
            'plan_title' => $plan['title'],
            'expires_at' => $grant['expires_at'] ?? null,
        ]);
    }
}

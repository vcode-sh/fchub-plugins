<?php

namespace FChubMemberships\Domain\Grant;

use FChubMemberships\Domain\GrantNotificationService;
use FChubMemberships\Domain\GrantPlanContextService;
use FChubMemberships\Domain\MembershipModeService;
use FChubMemberships\Domain\MembershipPlanChangePublisher;
use FChubMemberships\Domain\Plan\PlanRuleResolver;
use FChubMemberships\Support\Logger;

defined('ABSPATH') || exit;

final class PlanGrantExecutionService
{
    public function __construct(
        private PlanRuleResolver $rules,
        private MembershipModeService $membershipModes,
        private GrantPlanContextService $planContext,
        private GrantCreationService $creation,
        private GrantRevocationService $revocation,
        private GrantNotificationService $notifications,
        private ?MembershipPlanChangePublisher $planChanges = null
    ) {
    }

    public function grantPlan(int $userId, int $planId, array $context = []): array
    {
        $rules = $this->rules->resolveUniqueRules($planId);
        $order = $context['order'] ?? null;

        ['plan' => $plan, 'context' => $context] = $this->planContext->resolve($userId, $planId, $context);

        $modeResult = $this->membershipModes->enforce(
            $userId,
            $planId,
            $plan,
            $context,
            fn(int $revokeUserId, int $revokePlanId, array $revokeContext): array => $this->revocation->revokePlan($revokeUserId, $revokePlanId, $revokeContext)
        );
        if (!empty($modeResult['blocked'])) {
            return $modeResult;
        }
        $modePlanChange = $modeResult['plan_change'] ?? null;
        $requestedPlanChange = $context['plan_change'] ?? null;
        $planChange = $modePlanChange ?? $requestedPlanChange;
        if ($planChange !== null) {
            $planChange['from_plan_ids'] = array_values(array_unique(array_merge(
                $modePlanChange['from_plan_ids'] ?? [],
                $requestedPlanChange['from_plan_ids'] ?? []
            )));
            $planChange['change_type'] = $requestedPlanChange['change_type']
                ?? (($context['source_type'] ?? null) === 'automation'
                    ? 'automation_change'
                    : ($modePlanChange['transition_type'] ?? 'automation_change'));
            $planChange['transition_type'] = $modePlanChange['transition_type'] ?? null;
        }

        $created = 0;
        $updated = 0;
        $failed = 0;
        $errors = [];

        foreach ($rules as $rule) {
            $result = $this->creation->grantResource(
                $userId,
                $rule['provider'],
                $rule['resource_type'],
                $rule['resource_id'],
                [
                    'plan_id' => $planId,
                    'source_type' => $context['source_type'] ?? 'manual',
                    'source_id' => (int) ($context['source_id'] ?? 0),
                    'feed_id' => $context['feed_id'] ?? null,
                    'expires_at' => $context['expires_at'] ?? null,
                    'preserve_expiry' => !empty($context['preserve_expiry']),
                    'drip_rule' => $rule,
                    'is_trial' => !empty($context['is_trial']),
                    'trial_ends_at' => $context['trial_ends_at'] ?? null,
                    'meta' => $context['meta'] ?? [],
                ]
            );

            if ($result['action'] === 'created') {
                $created++;
            } elseif ($result['action'] === 'updated') {
                $updated++;
            } elseif ($result['action'] === 'failed') {
                $failed++;
                $errors[] = $result;
            }
        }

        Logger::log(
            'Plan granted',
            sprintf('User #%d processed plan #%d: %d created, %d updated, %d failed', $userId, $planId, $created, $updated, $failed),
            ['module_id' => $context['source_id'] ?? 0, 'module_name' => 'Order']
        );

        if ($order && $failed === 0) {
            Logger::orderLog(
                $order,
                __('Membership plan granted', 'fchub-memberships'),
                sprintf(__('Plan #%d: %d resources granted', 'fchub-memberships'), $planId, $created + $updated)
            );
        }

        if ($failed === 0) {
            $this->notifications->sendGranted($userId, $planId, $rules);
            do_action('fchub_memberships/grant_created', $userId, $planId, $context);

            if (!empty($context['is_trial'])) {
                do_action('fchub_memberships/trial_started', $context, $planId, $userId);
            }

            if ($planChange !== null) {
                ($this->planChanges ?? new MembershipPlanChangePublisher())->publish(
                    $userId,
                    $planChange['from_plan_ids'],
                    $planId,
                    $planChange['change_type'],
                    $context
                );
                if ($planChange['transition_type'] === 'exclusive_replacement') {
                    do_action('fchub_memberships/plan_replaced', $userId, $planId, $planChange['from_plan_ids']);
                } elseif ($planChange['transition_type'] === 'level_upgrade') {
                    do_action('fchub_memberships/plan_upgraded', $userId, $planId, $planChange['from_plan_ids']);
                }
            }
        }

        $result = [
            'created' => $created,
            'updated' => $updated,
            'total' => count($rules),
        ];

        if ($failed > 0) {
            $result['failed'] = $failed;
            $result['errors'] = $errors;
        }

        return $result;
    }
}

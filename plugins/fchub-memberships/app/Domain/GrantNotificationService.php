<?php

namespace FChubMemberships\Domain;

use FChubMemberships\Domain\Plan\PlanRulePresenter;
use FChubMemberships\Storage\PlanRepository;

defined('ABSPATH') || exit;

final class GrantNotificationService
{
    private PlanRepository $plans;
    private PlanRulePresenter $rulePresenter;

    public function __construct(?PlanRepository $plans = null, ?PlanRulePresenter $rulePresenter = null)
    {
        $this->plans = $plans ?? new PlanRepository();
        $this->rulePresenter = $rulePresenter ?? new PlanRulePresenter();
    }

    /**
     * @param array<int, mixed> $rules Raw plan_rules rows for the granted plan.
     */
    public function sendGranted(int $userId, int $planId, array $rules): void
    {
        $settings = get_option('fchub_memberships_settings', []);
        if (($settings['email_access_granted'] ?? 'yes') !== 'yes') {
            return;
        }

        $plan = $this->plans->find($planId);
        if (!$plan) {
            return;
        }

        (new \FChubMemberships\Email\AccessGrantedEmail())->send($userId, [
            'plan_id'    => $planId,
            'plan_title' => $plan['title'],
            'resources'  => $this->rulePresenter->immediateResources($rules),
            'drip_items' => $this->rulePresenter->dripItems($rules),
        ]);
    }

    public function sendRevoked(int $userId, int $planId, string $reason): void
    {
        $settings = get_option('fchub_memberships_settings', []);
        if (($settings['email_access_revoked'] ?? 'yes') !== 'yes') {
            return;
        }

        $plan = $this->plans->find($planId);
        if (!$plan) {
            return;
        }

        (new \FChubMemberships\Email\AccessRevokedEmail())->send($userId, [
            'plan_title' => $plan['title'],
            'reason'     => $reason,
        ]);
    }

    public function sendPaused(array $grant): void
    {
        $plan = $this->plans->find((int) $grant['plan_id']);
        if (!$plan) {
            return;
        }

        (new \FChubMemberships\Email\MembershipPausedEmail())->send((int) $grant['user_id'], [
            'plan_title' => $plan['title'],
        ]);
    }

    public function sendResumed(array $grant): void
    {
        $plan = $this->plans->find((int) $grant['plan_id']);
        if (!$plan) {
            return;
        }

        (new \FChubMemberships\Email\MembershipResumedEmail())->send((int) $grant['user_id'], [
            'plan_title' => $plan['title'],
            'expires_at' => $grant['expires_at'] ?? null,
        ]);
    }
}

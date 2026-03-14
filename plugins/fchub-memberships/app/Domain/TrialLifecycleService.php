<?php

namespace FChubMemberships\Domain;

defined('ABSPATH') || exit;

use FChubMemberships\Email\TrialConvertedEmail;
use FChubMemberships\Email\TrialExpiringEmail;
use FChubMemberships\Domain\Trial\TrialGrantQueryService;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Support\Logger;

class TrialLifecycleService
{
    private GrantRepository $grantRepo;
    private PlanRepository $planRepo;
    private TrialGrantQueryService $queries;

    public function __construct()
    {
        $this->grantRepo = new GrantRepository();
        $this->planRepo = new PlanRepository();
        $this->queries = new TrialGrantQueryService();
    }

    /**
     * Daily cron: check trial expirations and convert or expire.
     */
    public function checkTrialExpirations(): void
    {
        $now = current_time('mysql');

        $grants = $this->queries->getDueTrialExpirations($now);

        if (empty($grants)) {
            return;
        }

        foreach ($grants as $row) {
            $grant = $this->hydrateRow($row);

            if ($this->hasSubscriptionPayment($grant)) {
                $this->convertTrial($grant);
            } else {
                $this->expireTrial($grant);
            }
        }
    }

    /**
     * Daily cron: send trial expiring soon notifications.
     */
    public function sendTrialExpiringNotifications(): void
    {
        $settings = get_option('fchub_memberships_settings', []);
        $noticeDays = (int) ($settings['trial_expiry_notice_days'] ?? 3);

        $now = current_time('mysql');
        $cutoff = date('Y-m-d H:i:s', strtotime("+{$noticeDays} days", current_time('timestamp')));

        $grants = $this->queries->getTrialExpiringSoon($now, $cutoff);

        if (empty($grants)) {
            return;
        }

        foreach ($grants as $row) {
            $meta = $row['meta'] ? json_decode($row['meta'], true) : [];
            if (!empty($meta['trial_expiry_notified'])) {
                continue;
            }

            $plan = $this->queries->findPlanSummary((int) $row['plan_id']);

            $planTitle = $plan ? $plan->title : __('Membership', 'fchub-memberships');
            $upgradeUrl = $plan && !empty($plan->slug)
                ? home_url('/membership/' . $plan->slug . '/')
                : home_url('/');

            // Calculate days left for the hook
            $daysLeft = max(0, (int) ceil((strtotime($row['trial_ends_at']) - time()) / DAY_IN_SECONDS));

            // Build grant array for hook
            $grantArray = [
                'id'            => (int) $row['id'],
                'user_id'       => (int) $row['user_id'],
                'plan_id'       => (int) $row['plan_id'],
                'trial_ends_at' => $row['trial_ends_at'],
                'meta'          => $meta,
            ];

            // Fire hook regardless of email enabled setting
            do_action('fchub_memberships/trial_expiring_soon', $grantArray, $daysLeft);

            (new TrialExpiringEmail())->send((int) $row['user_id'], [
                'plan_title'    => $planTitle,
                'trial_ends_at' => $row['trial_ends_at'],
                'upgrade_url'   => $upgradeUrl,
            ]);

            // Mark as notified to avoid duplicates
            $meta['trial_expiry_notified'] = current_time('mysql');
            $this->queries->markTrialExpiryNotified((int) $row['id'], $meta);
        }
    }

    /**
     * Convert a trial grant to a paid membership.
     */
    private function convertTrial(array $grant): void
    {
        $plan = $this->planRepo->find($grant['plan_id']);

        // Calculate proper expires_at from plan duration
        $expiresAt = null;
        if ($plan) {
            $durationType = $plan['duration_type'] ?? 'lifetime';
            if ($durationType === 'fixed_days' && ($plan['duration_days'] ?? 0) > 0) {
                $expiresAt = date('Y-m-d H:i:s', strtotime('+' . $plan['duration_days'] . ' days'));
            }
        }

        $this->grantRepo->update($grant['id'], [
            'source_type'   => 'subscription',
            'trial_ends_at' => null,
            'expires_at'    => $expiresAt,
        ]);

        $planTitle = $plan['title'] ?? __('Membership', 'fchub-memberships');

        AuditLogger::logGrantChange($grant['id'], 'trial_converted', $grant, [
            'source_type' => 'subscription',
            'expires_at'  => $expiresAt,
        ]);

        (new TrialConvertedEmail())->send($grant['user_id'], [
            'plan_title' => $planTitle,
            'expires_at' => $expiresAt,
        ]);

        do_action('fchub_memberships/trial_converted', $grant, $grant['plan_id'], $grant['user_id']);

        Logger::log(
            'Trial converted to paid',
            sprintf('Grant #%d, User #%d, Plan #%d', $grant['id'], $grant['user_id'], $grant['plan_id'])
        );
    }

    /**
     * Expire a trial grant that has no payment.
     */
    private function expireTrial(array $grant): void
    {
        $this->grantRepo->update($grant['id'], [
            'status' => 'expired',
        ]);

        AuditLogger::logGrantChange($grant['id'], 'trial_expired', $grant, ['status' => 'expired']);

        do_action('fchub_memberships/trial_expired', $grant);

        Logger::log(
            'Trial expired',
            sprintf('Grant #%d, User #%d, Plan #%d', $grant['id'], $grant['user_id'], $grant['plan_id'])
        );
    }

    /**
     * Check if a subscription payment exists for the grant's source.
     */
    private function hasSubscriptionPayment(array $grant): bool
    {
        if (empty($grant['source_ids'])) {
            return false;
        }

        if (!class_exists('\FluentCart\App\Models\Subscription')) {
            return false;
        }

        foreach ($grant['source_ids'] as $sourceId) {
            try {
                $subscription = \FluentCart\App\Models\Subscription::find($sourceId);
                if ($subscription && in_array($subscription->status, ['active', 'completed'], true)) {
                    return true;
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return false;
    }

    private function hydrateRow(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['user_id'] = (int) $row['user_id'];
        $row['plan_id'] = $row['plan_id'] !== null ? (int) $row['plan_id'] : null;
        $row['source_id'] = (int) ($row['source_id'] ?? 0);
        $row['source_ids'] = json_decode($row['source_ids'] ?? '[]', true) ?: [];
        $row['meta'] = json_decode($row['meta'] ?? '{}', true) ?: [];
        return $row;
    }
}

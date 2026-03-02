<?php

namespace FChubMemberships\Domain;

defined('ABSPATH') || exit;

use FChubMemberships\Support\Logger;

/**
 * Cron job that checks subscription validity and fires expiration events.
 *
 * FluentCart's `subscription_expired_validity` hook is registered as an IntegrationEventListener
 * target but no do_action() call exists in FluentCart core. This watcher implements the dispatch.
 */
class SubscriptionValidityWatcher
{
    public function registerHooks(): void
    {
        add_action('fluent_cart/subscription_status_changed', [$this, 'onSubscriptionStatusChanged'], 10, 2);
        add_action('fluent_cart/subscription_renewed', [$this, 'onSubscriptionRenewed'], 10, 1);
        add_action('fluent_cart/subscription_cancelled', [$this, 'onSubscriptionCancelled'], 10, 1);
        add_action('fluent_cart/subscription_paused', [$this, 'onSubscriptionPaused'], 10, 1);
        add_action('fluent_cart/subscription_resumed', [$this, 'onSubscriptionResumed'], 10, 1);

        // Payment failure hooks
        add_action('fluent_cart/order_payment_failed', [$this, 'onOrderPaymentFailed'], 10, 1);
        add_action('fluent_cart/payments/subscription_failing', [$this, 'onSubscriptionFailing'], 10, 1);
    }

    public function onSubscriptionStatusChanged($subscription, string $newStatus): void
    {
        $methodMap = [
            'paused'    => 'handleSubscriptionPaused',
            'active'    => 'handleSubscriptionResumed',
            'cancelled' => 'handleSubscriptionCancelled',
            'expired'   => 'handleSubscriptionExpired',
        ];
        $method = $methodMap[$newStatus] ?? null;
        if ($method && method_exists($this, $method)) {
            $this->$method($subscription);
        }
    }

    public function onSubscriptionRenewed($subscription): void
    {
        $this->handleSubscriptionRenewed($subscription);
    }

    public function onSubscriptionCancelled($subscription): void
    {
        $this->handleSubscriptionCancelled($subscription);
    }

    public function onSubscriptionPaused($subscription): void
    {
        $this->handleSubscriptionPaused($subscription);
    }

    public function onSubscriptionResumed($subscription): void
    {
        $this->handleSubscriptionResumed($subscription);
    }

    /**
     * Run the validity check. Called every 5 minutes via cron.
     */
    public function check(): void
    {
        global $wpdb;

        $validityTable = $wpdb->prefix . 'fchub_membership_validity_log';
        $grantsTable = $wpdb->prefix . 'fchub_membership_grants';

        // Find active grants linked to subscriptions
        $grants = $wpdb->get_results(
            "SELECT DISTINCT g.source_id, g.user_id, g.plan_id
             FROM {$grantsTable} g
             WHERE g.status = 'active'
               AND g.source_type = 'subscription'
               AND g.source_id > 0
             GROUP BY g.source_id",
            ARRAY_A
        );

        if (empty($grants)) {
            return;
        }

        // Check if Subscription model exists
        if (!class_exists('\FluentCart\App\Models\Subscription')) {
            return;
        }

        foreach ($grants as $grant) {
            $this->checkSubscription((int) $grant['source_id']);
        }

        $grantService = new \FChubMemberships\Domain\AccessGrantService();

        // Revoke grants that have passed their grace period
        $graceRevoked = $grantService->revokeExpiredGracePeriodGrants();

        // Also expire any overdue grants (with hooks fired for each)
        $expired = $grantService->expireOverdueGrantsWithHooks();

        if ($expired > 0) {
            Logger::log('Validity check', sprintf('%d overdue grants expired', $expired));
        }
    }

    private function checkSubscription(int $subscriptionId): void
    {
        global $wpdb;

        $subscription = \FluentCart\App\Models\Subscription::find($subscriptionId);
        if (!$subscription) {
            return;
        }

        $validityTable = $wpdb->prefix . 'fchub_membership_validity_log';

        // Check if subscription has access validity
        $hasValidity = method_exists($subscription, 'hasAccessValidity')
            ? $subscription->hasAccessValidity()
            : $this->manualValidityCheck($subscription);

        // Get or create validity log entry
        $log = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$validityTable} WHERE subscription_id = %d ORDER BY id DESC LIMIT 1",
            $subscriptionId
        ), ARRAY_A);

        if ($hasValidity) {
            // Subscription is still valid
            if (!$log) {
                $wpdb->insert($validityTable, [
                    'subscription_id' => $subscriptionId,
                    'last_valid_at'   => current_time('mysql'),
                ]);
            } else {
                $wpdb->update($validityTable, [
                    'last_valid_at' => current_time('mysql'),
                ], ['id' => $log['id']]);
            }
            return;
        }

        // Subscription validity has expired
        if ($log && !empty($log['dispatched_at'])) {
            // Already dispatched expiration event
            return;
        }

        // Fire the expiration event
        $this->dispatchExpiration($subscription);

        // Record dispatch
        if ($log) {
            $wpdb->update($validityTable, [
                'expired_at'    => current_time('mysql'),
                'dispatched_at' => current_time('mysql'),
            ], ['id' => $log['id']]);
        } else {
            $wpdb->insert($validityTable, [
                'subscription_id' => $subscriptionId,
                'last_valid_at'   => current_time('mysql'),
                'expired_at'      => current_time('mysql'),
                'dispatched_at'   => current_time('mysql'),
            ]);
        }
    }

    private function dispatchExpiration($subscription): void
    {
        $order = null;
        if (method_exists($subscription, 'order')) {
            $order = $subscription->order;
        } elseif (!empty($subscription->order_id)) {
            $order = \FluentCart\App\Models\Order::find($subscription->order_id);
        }

        $customer = null;
        if ($order && method_exists($order, 'customer')) {
            $customer = $order->customer;
        }

        $eventData = [
            'subscription' => $subscription,
            'order'        => $order,
            'customer'     => $customer,
        ];

        // Fire the event through FluentCart's integration system
        do_action('fluent_cart/subscription_expired_validity', $eventData);

        Logger::log(
            'Subscription validity expired',
            sprintf('Subscription #%d validity expired, event dispatched', $subscription->id),
            ['module_id' => $subscription->id, 'module_name' => 'Subscription']
        );
    }

    private function handleSubscriptionPaused($subscription): void
    {
        $grantService = new \FChubMemberships\Domain\AccessGrantService();
        $grantRepo = new \FChubMemberships\Storage\GrantRepository();
        $grants = $grantRepo->getBySourceId($subscription->id, 'subscription');
        foreach ($grants as $grant) {
            if ($grant['status'] === 'active') {
                $grantService->pauseGrant($grant['id'], 'Subscription paused');
            }
        }
    }

    private function handleSubscriptionResumed($subscription): void
    {
        $grantService = new \FChubMemberships\Domain\AccessGrantService();
        $grantRepo = new \FChubMemberships\Storage\GrantRepository();
        $grants = $grantRepo->getBySourceId($subscription->id, 'subscription');
        foreach ($grants as $grant) {
            if ($grant['status'] === 'paused') {
                $grantService->resumeGrant($grant['id']);
            }
        }
    }

    private function handleSubscriptionCancelled($subscription): void
    {
        $grantService = new \FChubMemberships\Domain\AccessGrantService();
        $grantRepo = new \FChubMemberships\Storage\GrantRepository();
        $grants = $grantRepo->getBySourceId($subscription->id, 'subscription');

        // Group grants by plan_id for proper revocation through the domain service
        $planIds = array_unique(array_column($grants, 'plan_id'));

        foreach ($planIds as $planId) {
            $planGrants = array_filter($grants, fn($g) => $g['plan_id'] == $planId);
            $userId = (int) reset($planGrants)['user_id'];

            $hasActiveOrPaused = array_filter(
                $planGrants,
                fn($g) => in_array($g['status'], ['active', 'paused'], true)
            );

            if (empty($hasActiveOrPaused)) {
                continue;
            }

            $grantService->revokePlan($userId, (int) $planId, [
                'source_id' => $subscription->id,
                'reason'    => 'Subscription cancelled',
            ]);
        }
    }

    private function handleSubscriptionRenewed($subscription): void
    {
        $grantService = new \FChubMemberships\Domain\AccessGrantService();
        $grantRepo = new \FChubMemberships\Storage\GrantRepository();
        $grants = $grantRepo->getBySourceId($subscription->id, 'subscription');

        $nextBilling = $subscription->next_billing_at ?? null;
        foreach ($grants as $grant) {
            if ($grant['status'] === 'active' && $nextBilling) {
                $grantService->extendExpiry($grant['user_id'], $grant['plan_id'], $nextBilling, $subscription->id);
            }
        }
    }

    private function handleSubscriptionExpired($subscription): void
    {
        $this->dispatchExpiration($subscription);
    }

    /**
     * Handle order payment failed event.
     *
     * The event fires with the full OrderPaymentFailed event object which includes
     * order, customer, transaction, old/new status, and reason.
     */
    public function onOrderPaymentFailed($eventData): void
    {
        $this->handlePaymentFailure($eventData, 'order_payment_failed');
    }

    /**
     * Handle subscription entering failing status.
     *
     * Fired via fluent_cart/payments/subscription_failing with event data array
     * containing subscription, order, customer, old_status, new_status.
     */
    public function onSubscriptionFailing(array $eventData): void
    {
        $this->handlePaymentFailure($eventData, 'subscription_failing');
    }

    /**
     * Common handler for payment failure events.
     * Finds membership grants linked to the subscription and fires the membership hook.
     */
    private function handlePaymentFailure($eventData, string $source): void
    {
        // Extract subscription from event data
        $subscription = null;

        if ($eventData instanceof \FluentCart\App\Events\Order\OrderPaymentFailed) {
            // OrderPaymentFailed event object — find subscriptions via order
            $order = $eventData->order;
            if (!$order) {
                return;
            }
            $subscriptions = \FluentCart\App\Models\Subscription::where('parent_order_id', $order->id)->get();
        } elseif (is_array($eventData) && isset($eventData['subscription'])) {
            // subscription_failing passes array with subscription key
            $subscriptions = collect([$eventData['subscription']]);
        } else {
            return;
        }

        if ($subscriptions->isEmpty()) {
            return;
        }

        $grantRepo = new \FChubMemberships\Storage\GrantRepository();

        foreach ($subscriptions as $subscription) {
            $grants = $grantRepo->getBySourceId($subscription->id, 'subscription');

            if (empty($grants)) {
                continue;
            }

            do_action('fchub_memberships/payment_failed', $grants, $subscription, $eventData);

            Logger::log(
                'Payment failed for membership',
                sprintf(
                    'Subscription #%d payment failed (%s), %d grant(s) affected',
                    $subscription->id,
                    $source,
                    count($grants)
                ),
                ['module_id' => $subscription->id, 'module_name' => 'Subscription']
            );
        }
    }

    /**
     * Manual validity check when hasAccessValidity() is not available.
     */
    private function manualValidityCheck($subscription): bool
    {
        $status = $subscription->status ?? '';

        // Active and trialing are always valid
        if (in_array($status, ['active', 'trialing'], true)) {
            return true;
        }

        // Pending and intended are not yet valid
        if (in_array($status, ['pending', 'intended'], true)) {
            return false;
        }

        // Expired is never valid
        if ($status === 'expired') {
            return false;
        }

        // For canceled, paused, expiring, failing - check next_billing_date
        $nextBilling = $subscription->next_billing_date ?? null;
        if ($nextBilling) {
            return strtotime($nextBilling) > time();
        }

        return false;
    }
}

<?php

namespace FChubMemberships\Domain;

defined('ABSPATH') || exit;

use FChubMemberships\Support\Logger;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\SubscriptionValidityLogRepository;

/**
 * Cron job that checks subscription validity and fires expiration events.
 *
 * FluentCart's `subscription_expired_validity` hook is registered as an IntegrationEventListener
 * target but no do_action() call exists in FluentCart core. This watcher implements the dispatch.
 */
class SubscriptionValidityWatcher
{
    private SubscriptionGrantLifecycleService $subscriptionGrants;
    private SubscriptionValidityCheckService $validityChecks;
    private SubscriptionPaymentFailureService $paymentFailures;

    public function __construct(
        ?SubscriptionGrantLifecycleService $subscriptionGrants = null,
        ?GrantRepository $grantRepo = null,
        ?SubscriptionValidityLogRepository $validityLogs = null,
        ?AccessGrantService $grantService = null
    )
    {
        $grantRepo = $grantRepo ?? new GrantRepository();
        $validityLogs = $validityLogs ?? new SubscriptionValidityLogRepository();
        $grantService = $grantService ?? new AccessGrantService();

        $this->subscriptionGrants = $subscriptionGrants ?? new SubscriptionGrantLifecycleService();
        $this->validityChecks = new SubscriptionValidityCheckService($grantRepo, $validityLogs, $grantService);
        $this->paymentFailures = new SubscriptionPaymentFailureService($grantRepo);
    }

    public function registerHooks(): void
    {
        // Status changed catches statuses without dedicated event hooks (expired).
        // Uses /payments/ prefix — that's the hook FluentCart actually fires.
        add_action('fluent_cart/payments/subscription_status_changed', [$this, 'onSubscriptionStatusChanged'], 10, 1);

        // Event-based hooks (fired via EventDispatcher, no /payments/ prefix).
        add_action('fluent_cart/subscription_renewed', [$this, 'onSubscriptionRenewed'], 10, 1);
        add_action('fluent_cart/subscription_canceled', [$this, 'onSubscriptionCancelled'], 10, 1);

        // Dynamic status hooks (fired via /payments/ prefix, no event class exists).
        add_action('fluent_cart/payments/subscription_paused', [$this, 'onSubscriptionPaused'], 10, 1);
        add_action('fluent_cart/payments/subscription_active', [$this, 'onSubscriptionResumed'], 10, 1);

        // Payment failure hooks
        add_action('fluent_cart/order_payment_failed', [$this, 'onOrderPaymentFailed'], 10, 1);
        add_action('fluent_cart/payments/subscription_failing', [$this, 'onSubscriptionFailing'], 10, 1);
    }

    public function onSubscriptionStatusChanged($data): void
    {
        $subscription = $data['subscription'] ?? null;
        $newStatus = $data['new_status'] ?? '';

        if (!$subscription) {
            return;
        }

        // Only handle statuses that don't have dedicated hooks to avoid double-firing.
        // canceled, paused, active (resumed) each have their own hooks registered above.
        $methodMap = [
            'expired' => 'handleSubscriptionExpired',
        ];

        $method = $methodMap[$newStatus] ?? null;
        if ($method && method_exists($this, $method)) {
            $this->$method($subscription);
        }
    }

    public function onSubscriptionRenewed($data): void
    {
        $subscription = is_array($data) ? ($data['subscription'] ?? null) : $data;
        if ($subscription) {
            $this->handleSubscriptionRenewed($subscription);
        }
    }

    public function onSubscriptionCancelled($data): void
    {
        $subscription = is_array($data) ? ($data['subscription'] ?? null) : $data;
        if ($subscription) {
            $this->handleSubscriptionCancelled($subscription);
        }
    }

    public function onSubscriptionPaused($data): void
    {
        $subscription = is_array($data) ? ($data['subscription'] ?? null) : $data;
        if ($subscription) {
            $this->handleSubscriptionPaused($subscription);
        }
    }

    public function onSubscriptionResumed($data): void
    {
        $subscription = is_array($data) ? ($data['subscription'] ?? null) : $data;
        if ($subscription) {
            $this->handleSubscriptionResumed($subscription);
        }
    }

    /**
     * Run the validity check. Called every 5 minutes via cron.
     */
    public function check(): void
    {
        $this->validityChecks->run();
    }

    private function checkSubscription(int $subscriptionId): void
    {
        $this->validityChecks->checkSubscription($subscriptionId);
    }

    private function dispatchExpiration($subscription): void
    {
        $this->validityChecks->dispatchExpiration($subscription);
    }

    private function handleSubscriptionPaused($subscription): void
    {
        $this->subscriptionGrants->pause($subscription);
    }

    private function handleSubscriptionResumed($subscription): void
    {
        $this->subscriptionGrants->resume($subscription);
    }

    private function handleSubscriptionCancelled($subscription): void
    {
        $this->subscriptionGrants->cancel($subscription);
    }

    private function handleSubscriptionRenewed($subscription): void
    {
        $this->subscriptionGrants->renew($subscription);
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
        $this->paymentFailures->handle($eventData, $source);
    }
}

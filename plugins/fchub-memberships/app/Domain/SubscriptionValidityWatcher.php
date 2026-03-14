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

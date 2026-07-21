<?php

namespace FChubMemberships\Domain;

defined('ABSPATH') || exit;

use FChubMemberships\Support\Logger;
use FChubMemberships\Storage\GrantRepository;

/**
 * Cron job that maintains membership grants while FluentCart owns subscription validity events.
 */
class SubscriptionValidityWatcher
{
    private SubscriptionGrantLifecycleService $subscriptionGrants;
    private SubscriptionValidityCheckService $validityChecks;
    private SubscriptionPaymentFailureService $paymentFailures;

    public function __construct(
        ?SubscriptionGrantLifecycleService $subscriptionGrants = null,
        ?GrantRepository $grantRepo = null,
        ?AccessGrantService $grantService = null
    )
    {
        $grantRepo = $grantRepo ?? new GrantRepository();
        $grantService = $grantService ?? new AccessGrantService();

        $this->subscriptionGrants = $subscriptionGrants ?? new SubscriptionGrantLifecycleService();
        $this->validityChecks = new SubscriptionValidityCheckService($grantService);
        $this->paymentFailures = new SubscriptionPaymentFailureService($grantRepo);
    }

    public function registerHooks(): void
    {
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

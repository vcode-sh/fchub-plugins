<?php

namespace FChubP24\Subscription;

defined('ABSPATH') || exit;

use FluentCart\App\Modules\PaymentMethods\Core\AbstractSubscriptionModule;

class Przelewy24SubscriptionModule extends AbstractSubscriptionModule
{
    /**
     * Cancel a subscription by vendor ID
     */
    public function cancel($vendorSubscriptionId, $args = [])
    {
        $subscriptionId = $this->extractSubscriptionId($vendorSubscriptionId);
        if ($subscriptionId) {
            Przelewy24RenewalHandler::unscheduleRenewal($subscriptionId);
        }

        return [
            'status'      => 'canceled',
            'canceled_at' => current_time('mysql'),
        ];
    }

    /**
     * Cancel subscription (full context version)
     */
    public function cancelSubscription($data, $order, $subscription)
    {
        Przelewy24RenewalHandler::unscheduleRenewal($subscription->id);

        return [
            'status'      => 'canceled',
            'canceled_at' => current_time('mysql'),
        ];
    }

    /**
     * Pause a subscription
     */
    public function pauseSubscription($data, $order, $subscription)
    {
        Przelewy24RenewalHandler::unscheduleRenewal($subscription->id);

        return [
            'status' => 'paused',
        ];
    }

    /**
     * Resume a paused subscription
     */
    public function resumeSubscription($data, $order, $subscription)
    {
        $nextBillingDate = $subscription->guessNextBillingDate(true);

        $timestamp = $nextBillingDate ? strtotime($nextBillingDate) : null;
        if ($timestamp && $timestamp > time()) {
            Przelewy24RenewalHandler::scheduleRenewal($subscription->id, $timestamp);
        }

        return [
            'status' => 'active',
        ];
    }

    /**
     * Cancel auto-renewal (keep subscription active until end of term)
     */
    public function cancelAutoRenew($subscription)
    {
        Przelewy24RenewalHandler::unscheduleRenewal($subscription->id);
    }

    /**
     * Cancel on plan change
     */
    public function cancelOnPlanChange($vendorSubscriptionId, $parentOrderId, $subscriptionId, $reason)
    {
        Przelewy24RenewalHandler::unscheduleRenewal($subscriptionId);
    }

    /**
     * Re-sync subscription from remote.
     * P24 has no remote subscription state, so just return the model unchanged.
     */
    public function reSyncSubscriptionFromRemote($subscriptionModel)
    {
        return $subscriptionModel;
    }

    /**
     * Card update is not supported in Phase 1.
     */
    public function cardUpdate($data, $subscriptionId)
    {
        throw new \Exception(
            esc_html__('Card update is not supported for Przelewy24 subscriptions. Please cancel and resubscribe with a new card.', 'fchub-p24'),
            404
        );
    }

    /**
     * Extract numeric subscription ID from vendor_subscription_id format "p24_sub_{id}"
     */
    private function extractSubscriptionId(string $vendorSubscriptionId): ?int
    {
        if (strpos($vendorSubscriptionId, 'p24_sub_') === 0) {
            return (int) substr($vendorSubscriptionId, 8);
        }

        return is_numeric($vendorSubscriptionId) ? (int) $vendorSubscriptionId : null;
    }
}

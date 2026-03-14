<?php

namespace FChubMemberships\Domain;

use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\SubscriptionValidityLogRepository;
use FChubMemberships\Support\Logger;

defined('ABSPATH') || exit;

final class SubscriptionValidityCheckService
{
    public function __construct(
        private GrantRepository $grants,
        private SubscriptionValidityLogRepository $validityLogs,
        private AccessGrantService $grantService
    ) {
    }

    public function run(): void
    {
        $subscriptionIds = $this->grants->getActiveSubscriptionSourceIds();
        if (empty($subscriptionIds)) {
            return;
        }

        if (!class_exists('\FluentCart\App\Models\Subscription')) {
            return;
        }

        foreach ($subscriptionIds as $subscriptionId) {
            $this->checkSubscription((int) $subscriptionId);
        }

        $this->grantService->revokeExpiredGracePeriodGrants();
        $expired = $this->grantService->expireOverdueGrantsWithHooks();

        if ($expired > 0) {
            Logger::log('Validity check', sprintf('%d overdue grants expired', $expired));
        }
    }

    public function checkSubscription(int $subscriptionId): void
    {
        $subscription = \FluentCart\App\Models\Subscription::find($subscriptionId);
        if (!$subscription) {
            return;
        }

        $hasValidity = method_exists($subscription, 'hasAccessValidity')
            ? $subscription->hasAccessValidity()
            : $this->manualValidityCheck($subscription);

        $log = $this->validityLogs->findLatestBySubscriptionId($subscriptionId);

        if ($hasValidity) {
            $this->validityLogs->touchValid($subscriptionId);
            return;
        }

        if ($log && !empty($log['dispatched_at'])) {
            return;
        }

        $this->dispatchExpiration($subscription);
        $this->validityLogs->markDispatched($subscriptionId);
    }

    public function dispatchExpiration(object $subscription): void
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
            'order' => $order,
            'customer' => $customer,
        ];

        do_action('fluent_cart/subscription_expired_validity', $eventData);

        Logger::log(
            'Subscription validity expired',
            sprintf('Subscription #%d validity expired, event dispatched', $subscription->id),
            ['module_id' => $subscription->id, 'module_name' => 'Subscription']
        );
    }

    private function manualValidityCheck(object $subscription): bool
    {
        $status = $subscription->status ?? '';

        if (in_array($status, ['active', 'trialing'], true)) {
            return true;
        }

        if (in_array($status, ['pending', 'intended'], true)) {
            return false;
        }

        if ($status === 'expired') {
            return false;
        }

        $nextBilling = $subscription->next_billing_date ?? $subscription->next_billing_at ?? null;
        if ($nextBilling) {
            return strtotime($nextBilling) > time();
        }

        return false;
    }
}

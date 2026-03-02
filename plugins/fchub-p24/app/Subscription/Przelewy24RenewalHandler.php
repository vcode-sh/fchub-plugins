<?php

namespace FChubP24\Subscription;

defined('ABSPATH') || exit;

use FChubP24\API\Przelewy24API;
use FChubP24\Gateway\Przelewy24Settings;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use FluentCart\App\Services\Payments\PaymentHelper;

class Przelewy24RenewalHandler
{
    const ACTION_HOOK = 'fchub_p24_process_renewal';
    const MAX_RETRIES = 3;
    const RETRY_DELAYS = [4 * HOUR_IN_SECONDS, 24 * HOUR_IN_SECONDS, 72 * HOUR_IN_SECONDS];

    /**
     * Schedule a single renewal action via Action Scheduler
     */
    public static function scheduleRenewal(int $subscriptionId, int $timestamp): void
    {
        // Unschedule any existing renewal first to avoid duplicates
        self::unscheduleRenewal($subscriptionId);

        as_schedule_single_action($timestamp, self::ACTION_HOOK, [$subscriptionId], 'fchub-p24');
    }

    /**
     * Unschedule all pending renewal actions for a subscription
     */
    public static function unscheduleRenewal(int $subscriptionId): void
    {
        as_unschedule_all_actions(self::ACTION_HOOK, [$subscriptionId], 'fchub-p24');
    }

    /**
     * Process a renewal charge for a subscription.
     * Called by Action Scheduler.
     */
    public static function processRenewal(int $subscriptionId): void
    {
        $subscription = Subscription::find($subscriptionId);

        if (!$subscription) {
            fluent_cart_error_log('P24 Renewal Error', 'Subscription not found: ' . $subscriptionId, [
                'module_name' => 'Subscription',
            ]);
            return;
        }

        $validStatuses = ['active', 'trialing', 'failing'];
        if (!in_array($subscription->status, $validStatuses, true)) {
            return;
        }

        // Check if subscription has remaining bill times
        $requiredBillTimes = $subscription->getRequiredBillTimes();
        if ($requiredBillTimes === -1) {
            // EOT reached - no more renewals needed
            return;
        }

        $refId = $subscription->getMeta('_p24_card_ref_id');
        if (!$refId) {
            self::handleRenewalFailure($subscription, 'No card refId stored for subscription');
            return;
        }

        $settings = new Przelewy24Settings();
        $api = new Przelewy24API($settings);
        $paymentHelper = new PaymentHelper('przelewy24');
        $listenerUrl = $paymentHelper->listenerUrl();

        $sessionId = wp_generate_uuid4();
        $amount = (int) $subscription->recurring_total;
        $currency = strtoupper($subscription->currency ?: 'PLN');

        // Store pending session for IPN routing
        $subscription->updateMeta('_p24_pending_renewal_session', $sessionId);

        // Register transaction with methodRefId for card-on-file
        $registerParams = [
            'sessionId'   => $sessionId,
            'amount'      => $amount,
            'currency'    => $currency,
            'description' => sprintf('Renewal for subscription #%d', $subscriptionId),
            'email'       => self::resolveSubscriptionEmail($subscription),
            'country'     => 'PL',
            'language'    => 'pl',
            'urlReturn'   => site_url(),
            'urlStatus'   => $listenerUrl['listener_url'],
            'methodRefId' => $refId,
            'channel'     => 1, // Cards only
            'regulationAccept' => false,
        ];

        $registerResponse = $api->registerTransaction($registerParams);

        if (isset($registerResponse['error'])) {
            $subscription->updateMeta('_p24_pending_renewal_session', '');
            self::handleRenewalFailure($subscription, 'Registration failed: ' . json_encode($registerResponse['error']));
            return;
        }

        $token = $registerResponse['data']['token'] ?? null;
        if (!$token) {
            $subscription->updateMeta('_p24_pending_renewal_session', '');
            self::handleRenewalFailure($subscription, 'No token in registration response');
            return;
        }

        // Charge the stored card
        $chargeResponse = $api->chargeCard($token);

        if (isset($chargeResponse['error'])) {
            $subscription->updateMeta('_p24_pending_renewal_session', '');
            self::handleRenewalFailure($subscription, 'Card charge failed: ' . json_encode($chargeResponse['error']));
            return;
        }

        // Charge accepted (async) - IPN will confirm. Nothing more to do here.
        fluent_cart_error_log('P24 Renewal Charge Submitted', sprintf(
            'Subscription #%d, session: %s, amount: %d %s',
            $subscriptionId, $sessionId, $amount, $currency
        ), [
            'module_id'   => $subscriptionId,
            'module_name' => 'Subscription',
        ]);
    }

    /**
     * Handle renewal failure with retry logic
     */
    public static function handleRenewalFailure(Subscription $subscription, string $reason): void
    {
        $retryCount = (int) $subscription->getMeta('_p24_retry_count', 0);

        fluent_cart_error_log('P24 Renewal Failure', sprintf(
            'Subscription #%d (attempt %d/%d): %s',
            $subscription->id, $retryCount + 1, self::MAX_RETRIES, $reason
        ), [
            'module_id'   => $subscription->id,
            'module_name' => 'Subscription',
        ]);

        if ($retryCount >= self::MAX_RETRIES) {
            // All retries exhausted - expire the subscription
            $subscription->status = 'expired';
            $subscription->save();
            $subscription->updateMeta('_p24_retry_count', 0);
            return;
        }

        // Set status to failing and schedule retry
        if ($subscription->status !== 'failing') {
            $subscription->status = 'failing';
            $subscription->save();
        }

        $subscription->updateMeta('_p24_retry_count', $retryCount + 1);

        $delay = self::RETRY_DELAYS[$retryCount] ?? end(self::RETRY_DELAYS);
        self::scheduleRenewal($subscription->id, time() + $delay);
    }

    /**
     * Schedule the next renewal after a successful payment
     */
    public static function scheduleNextRenewal(Subscription $subscription): void
    {
        // Reset retry counter on success
        $subscription->updateMeta('_p24_retry_count', 0);

        // Check if more renewals are needed
        $requiredBillTimes = $subscription->getRequiredBillTimes();
        if ($requiredBillTimes === -1) {
            return; // EOT reached
        }

        $nextBillingDate = $subscription->guessNextBillingDate(true);
        if (!$nextBillingDate) {
            return;
        }

        $timestamp = strtotime($nextBillingDate);
        if ($timestamp && $timestamp > time()) {
            self::scheduleRenewal($subscription->id, $timestamp);
        }
    }

    /**
     * Resolve the payer email for a subscription renewal
     */
    private static function resolveSubscriptionEmail(Subscription $subscription): string
    {
        $order = $subscription->order;
        if ($order && $order->customer) {
            $email = $order->customer->email ?? '';
            if ($email && is_email($email)) {
                return $email;
            }
        }

        return 'renewal@placeholder.local';
    }
}

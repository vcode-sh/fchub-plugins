<?php

namespace FChubMemberships\Domain;

use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Support\Logger;

defined('ABSPATH') || exit;

final class SubscriptionPaymentFailureService
{
    public function __construct(private GrantRepository $grants)
    {
    }

    public function handle(mixed $eventData, string $source): void
    {
        if ($eventData instanceof \FluentCart\App\Events\Order\OrderPaymentFailed) {
            $order = $eventData->order;
            if (!$order) {
                return;
            }
            $subscriptions = \FluentCart\App\Models\Subscription::where('parent_order_id', $order->id)->get();
        } elseif (is_array($eventData) && isset($eventData['subscription'])) {
            $subscriptions = collect([$eventData['subscription']]);
        } else {
            return;
        }

        if ($subscriptions->isEmpty()) {
            return;
        }

        foreach ($subscriptions as $subscription) {
            $grants = $this->grants->getBySourceId($subscription->id, 'subscription');
            if (empty($grants)) {
                continue;
            }

            $affectedGrants = [];
            foreach ($grants as $grant) {
                $meta = $grant['meta'] ?? [];
                $previous = $meta['payment_incident'] ?? [];
                $now = current_time('mysql');
                $meta['payment_incident'] = [
                    'subscription_id' => (int) $subscription->id,
                    'source' => $source,
                    'reference' => (string) $subscription->id,
                    'failure_count' => (int) ($previous['failure_count'] ?? 0) + 1,
                    'first_failed_at' => $previous['first_failed_at'] ?? $now,
                    'last_failed_at' => $now,
                    'recovered_at' => null,
                    'recovery_renewal_count' => null,
                ];
                if ($this->grants->update((int) $grant['id'], ['meta' => $meta])) {
                    $affectedGrants[] = array_replace($grant, ['meta' => $meta]);
                }
            }

            if (empty($affectedGrants)) {
                continue;
            }

            do_action('fchub_memberships/payment_failed', $affectedGrants, $subscription, $eventData);

            Logger::log(
                'Payment failed for membership',
                sprintf(
                    'Subscription #%d payment failed (%s), %d grant(s) affected',
                    $subscription->id,
                    $source,
                    count($affectedGrants)
                ),
                ['module_id' => $subscription->id, 'module_name' => 'Subscription']
            );
        }
    }
}

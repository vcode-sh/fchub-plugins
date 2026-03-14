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
}

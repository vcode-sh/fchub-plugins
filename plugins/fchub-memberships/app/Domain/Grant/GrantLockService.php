<?php

namespace FChubMemberships\Domain\Grant;

use FChubMemberships\Storage\EventLockRepository;

defined('ABSPATH') || exit;

final class GrantLockService
{
    public function __construct(private EventLockRepository $locks)
    {
    }

    public function acquireEventLock(int $orderId, int $feedId, string $trigger, ?int $subscriptionId = null): bool
    {
        $hash = EventLockRepository::makeEventHash($orderId, $feedId, $trigger, $subscriptionId);

        return $this->locks->acquire([
            'event_hash' => $hash,
            'order_id' => $orderId,
            'subscription_id' => $subscriptionId,
            'feed_id' => $feedId,
            'trigger' => $trigger,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Subscription;

defined('ABSPATH') || exit;

interface SubscriptionCutoverTargetGateway extends SubscriptionTargetGateway
{
    public function activateStatus(int $subscriptionId, string $expectedStatus, string $intendedStatus): void;
}

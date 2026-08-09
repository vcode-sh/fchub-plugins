<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription\Payment;

use CartShift\Domain\Subscription\SubscriptionRecord;

defined('ABSPATH') || exit;

/**
 * Everything the core knows about a payment gateway.
 *
 * Quoted verbatim from plan section 3's gateway extension contract, and kept
 * that narrow on purpose: a fourth gateway is one class implementing this, one
 * registry entry, and its tests. It is not another branch inside
 * `SubscriptionMapper`, which is how the current implementation ended up
 * marking all 564 Lapka subscriptions `automatic` and copying the raw Woo slug.
 *
 * Implementations are read-only. `assess()` may retrieve from a provider; it
 * may not create, confirm, charge, or subscribe. Nothing in this namespace has
 * a seam through which it could.
 */
interface SubscriptionPaymentStrategy
{
    public function assess(
        SubscriptionRecord $record,
        PaymentEnvironment $environment,
    ): PaymentMigrationDecision;
}

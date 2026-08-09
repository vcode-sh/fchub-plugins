<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription\Payment;

use CartShift\Domain\Subscription\SubscriptionRecord;

defined('ABSPATH') || exit;

/**
 * Turns a copied identifier into evidence, or into a reason it is not.
 *
 * A source row containing `cus_…` proves that somebody once wrote `cus_…` into
 * a WordPress meta table. It does not prove that a customer of that ID exists
 * under the target credentials, that the saved method belongs to them, that the
 * account and mode match, or that any of it can still be charged. That is what
 * an implementation of this interface establishes, and it establishes it with
 * retrievals only.
 *
 * The read-only rule is structural rather than aspirational: implementations in
 * this namespace take a single-argument retrieval closure, so there is no call
 * shape available to them that could create a PaymentIntent, a SetupIntent, a
 * charge, a subscription, or a webhook registration. Assessment must never move
 * money, and neither must anything else here, ever.
 */
interface ProviderReferenceVerifier
{
    public function verify(
        SubscriptionRecord $record,
        PaymentEnvironment $environment,
    ): ProviderVerification;
}

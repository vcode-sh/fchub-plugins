<?php

declare(strict_types=1);

namespace CartShift\Domain\Mapping;

defined('ABSPATH') || exit;

use CartShift\Domain\Subscription\Payment\PaymentMigrationDecision;
use CartShift\Domain\Subscription\SubscriptionAssessment;
use CartShift\Domain\Subscription\SubscriptionRecord;

/**
 * A decided subscription, as a `fct_subscriptions` payload. It decides nothing.
 *
 * That sentence is the entire change, and it is worth being precise about what
 * it replaces. The old mapper read a live `WC_Subscription` and, in one method,
 * committed six of the plan's confirmed defects: it inferred a setup fee from
 * `parent total - recurring total` (manufacturing a PLN 50 fee on Lapka plans
 * whose configured fee is zero), read the finite term off the *current*
 * product rather than the historical contract, hard-coded the quantity to 1,
 * assigned a PayPal subscription ID as the customer ID, passed `created_at`
 * through mass assignment that discards it, and marked every record
 * `collection_method = automatic` — which in FluentCart 1.6.0 means "a gateway
 * owns a remote schedule for this", when none of the 367 Lapka Stripe records
 * has a vendor subscription ID at all.
 *
 * Each of those was a decision the mapper had no business making. So it makes
 * none. The contract comes from `SubscriptionRecord`, which is the source's own
 * terms; the destination references, the payment ownership and the lifecycle
 * projection come from `SubscriptionAssessment`, which is what the gates
 * decided. This class copies.
 *
 * The consequence worth having: adding a fourth payment gateway needs a
 * strategy class, a registry entry and its tests. There is no branch here to
 * add it to, and a `grep` for a gateway slug in this file returns nothing.
 */
final class SubscriptionMapper
{
    /**
     * FluentCart's own key for the per-subscription record of the mode it was
     * born under.
     *
     * @see fluent-cart/app/Modules/Subscriptions/Services/SubscriptionManagementMode.php:30
     */
    public const string CONFIG_MANAGEMENT_MODE = 'management_mode';

    /** @see the same file, line 21. */
    public const string MODE_STORE_MANAGED = 'store_managed';

    /**
     * Everything `fct_subscriptions` needs, plus `created_at` for the writer.
     *
     * `created_at` is in the payload and is NOT written by mass assignment.
     * FluentCart excludes it from `Subscription::$fillable`, so
     * `Subscription::create()` drops it silently and stamps every migrated
     * subscription with the moment the migration ran. `SubscriptionWriter`
     * lifts it out of this array and sets it on the model instance before
     * `save()`. It travels here so the `cartshift/mapper/subscription` filter
     * can see and change it like any other field.
     *
     * @return array<string, mixed>
     */
    public function map(SubscriptionRecord $record, SubscriptionAssessment $assessment): array
    {
        $contract   = $record->contract;
        $references = $assessment->resolvedReferences;
        $lifecycle  = $assessment->lifecycle;
        $payment    = $assessment->payment;

        $mapped = [
            // The NOT NULL five, exactly as the gate resolved them.
            'customer_id'     => $references['customer_id'] ?? null,
            'parent_order_id' => $references['parent_order_id'] ?? null,
            'product_id'      => $references['product_id'] ?? null,
            'variation_id'    => $references['variation_id'] ?? null,
            'item_name'       => (string) ($references['item_name'] ?? ''),
            'quantity'        => (int) ($references['quantity'] ?? 0),

            // The contract, in the subscriber's own terms. 167 Lapka
            // subscribers pay PLN 24 for a product whose current price is
            // PLN 29; "correcting" them is not a migration.
            'billing_interval'    => $contract->targetInterval,
            'signup_fee'          => $contract->setupFee,
            'recurring_amount'    => $contract->recurringAmount,
            'recurring_tax_total' => $contract->recurringTax,
            'recurring_total'     => $contract->recurringTotal,

            // The lifecycle, projected explicitly. Every date here is the
            // source's or null; none is guessed.
            'status'            => (string) ($lifecycle['status'] ?? ''),
            'bill_times'        => (int) ($lifecycle['bill_times'] ?? 0),
            'bill_count'        => (int) ($lifecycle['bill_count'] ?? 0),
            'trial_days'        => (int) ($lifecycle['trial_days'] ?? 0),
            'trial_ends_at'     => $lifecycle['trial_ends_at'] ?? null,
            'next_billing_date' => $lifecycle['next_billing_date'] ?? null,
            'expire_at'         => $lifecycle['expire_at'] ?? null,
            'canceled_at'       => $lifecycle['canceled_at'] ?? null,
            'restored_at'       => null,

            // Payment ownership, copied from the decision whose constructor
            // already refused every incoherent combination.
            'collection_method'      => $payment->collectionMethod,
            'current_payment_method' => $payment->currentPaymentMethod,
            'vendor_customer_id'     => $payment->vendorCustomerId,
            'vendor_plan_id'         => $payment->vendorPlanId,
            'vendor_subscription_id' => $payment->vendorSubscriptionId,

            'original_plan'   => null,
            'vendor_response' => null,
            'config'          => $this->config($record, $assessment),

            // Lifted out by the writer. See the docblock.
            'created_at' => $lifecycle['created_at'] ?? $record->dates->startUtc,
        ];

        /** @see 'cartshift/mapper/subscription' */
        return apply_filters('cartshift/mapper/subscription', $mapped, $record, $assessment);
    }

    /**
     * What the receipt, the retry and the operator need to know later.
     *
     * Section 9.2's list, verbatim: source key, source subscription ID,
     * original gateway and status, contract fingerprint, intended final status,
     * next-action owner, and the strategy's reason codes. `wc_subscription_id`
     * and `migrated` are kept beside them because earlier CartShift releases
     * wrote those two and something may still be reading them.
     *
     * @return array<string, mixed>
     */
    private function config(SubscriptionRecord $record, SubscriptionAssessment $assessment): array
    {
        $payment = $assessment->payment;

        $config = [
            'migrated'                       => true,
            'currency'                       => $record->currency,
            'source_key'                     => $record->sourceKey,
            'source_subscription_id'         => $record->sourceSubscriptionId,
            'source_gateway'                 => $record->gateway,
            'source_status'                  => $record->status,
            'source_requires_manual_renewal' => $record->requiresManualRenewal,
            'contract_fingerprint'           => $record->fingerprint,
            'intended_status'                => (string) ($assessment->lifecycle['intended_status'] ?? ''),
            'next_action_owner'              => $payment->nextActionOwner,
            'payment_strategy'               => $payment->strategy,
            'payment_reason_codes'           => $payment->reasonCodes,
            'wc_subscription_id'             => $record->sourceSubscriptionId,
        ];

        // Section 9.2: a `system` subscription is stamped with FluentCart's own
        // store-managed marker, because that is the durable per-subscription
        // record of the mode it was born under — and CartShift changes no
        // store-wide setting to put it there.
        if ($payment->collectionMethod === PaymentMigrationDecision::COLLECTION_SYSTEM) {
            $config[self::CONFIG_MANAGEMENT_MODE] = self::MODE_STORE_MANAGED;
        }

        return $config;
    }
}

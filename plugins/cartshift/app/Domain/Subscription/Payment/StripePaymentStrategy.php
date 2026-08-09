<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription\Payment;

use CartShift\Domain\Subscription\SubscriptionRecord;

defined('ABSPATH') || exit;

/**
 * Stripe: FluentCart `system`, or deliberate manual. Never `automatic`.
 *
 * `automatic` means a gateway-owned remote schedule, and none of the 367 Lapka
 * Stripe subscriptions has a `_stripe_subscription_id`. Woo Stripe registers
 * WCS scheduled-payment hooks and charges renewals locally, so there is no
 * remote schedule to adopt. Marking these `automatic` — which is what
 * `SubscriptionMapper.php:160-164` does today — leaves no component responsible
 * for the next charge at all.
 *
 * The eligible destination is a FluentCart `system` subscription, and the bar
 * for it is exactly what `Stripe::chargeRenewal()` reads at fire time:
 * `vendor_customer_id` plus `active_payment_method.vendor_method_id`, absent
 * either of which it returns `missing_token`
 * (Stripe.php:213-221). So both are verified by retrieval before the decision
 * is made, and neither is ever a value merely copied out of a Woo meta table.
 *
 * The 246 `src_` records take the deliberate manual route with
 * `provider_method_unsupported`. A legacy source ID posted into FluentCart's
 * `payment_method` field is not "probably fine"; it is an experiment conducted
 * on somebody's card. The expected resolution is a customer payment-method
 * update that yields a modern `pm_`.
 */
final class StripePaymentStrategy implements SubscriptionPaymentStrategy
{
    public const string TARGET_GATEWAY = PaymentCapabilityProbe::GATEWAY_STRIPE;

    public function __construct(
        private readonly ManualPaymentStrategy $manual = new ManualPaymentStrategy(),
    ) {
    }

    public function assess(
        SubscriptionRecord $record,
        PaymentEnvironment $environment,
    ): PaymentMigrationDecision {
        $verification = $environment->verifierFor(PaymentMigrationDecision::STRATEGY_STRIPE)
            ?->verify($record, $environment)
            // No verifier configured is a target without Stripe credentials.
            // That is a legitimate state and it proves nothing about the
            // mandate, so it takes the same route as a failed verification.
            ?? ProviderVerification::nothing(['provider_customer_missing', 'provider_method_missing']);

        $capability = $environment->capabilities->diagnose(self::TARGET_GATEWAY);

        // Evaluated unconditionally, and merged rather than short-circuited.
        // Ordering these behind the other checks used to hide the fault
        // entirely: in the real Lapka run there is no approval hash yet, so
        // every Stripe record already carries `system_store_mode_not_approved`
        // and the two active/past-date records would have come back with no
        // mention of the date at all. Section 9.3's write gate would still stop
        // them; the operator would simply never learn why.
        $scheduleFault = $environment->liveScheduleFault($record->status, $record->dates->nextPaymentUtc) ?? [];

        $reasons = array_merge($verification->reasonCodes, $capability['reason_codes'], $scheduleFault);

        if (!$environment->systemSettingsApproved()) {
            // The operator reviews Task 0's redacted settings/census report and
            // binds approval to that exact hash. CartShift changes no FluentCart
            // setting and infers no approval from a store that happens to be
            // configured favourably today.
            $reasons[] = PaymentCapabilityProbe::REASON_STORE_MODE_NOT_APPROVED;
        }

        if ($reasons !== []) {
            // Manual with the reason attached, never `blocked`. An eligibility
            // miss is this layer's finding; emitting the block is section 9.3's
            // job and Task 8's write gate owns it.
            $decision = $this->manual
                ->assess($record, $environment, self::TARGET_GATEWAY)
                ->withReasonCodes($reasons)
                ->forStrategy(PaymentMigrationDecision::STRATEGY_STRIPE);

            return $scheduleFault === [] ? $decision : $decision->requiringConfirmation();
        }

        return new PaymentMigrationDecision(
            strategy: PaymentMigrationDecision::STRATEGY_STRIPE,
            outcome: PaymentMigrationDecision::OUTCOME_READY,
            collectionMethod: PaymentMigrationDecision::COLLECTION_SYSTEM,
            currentPaymentMethod: PaymentMigrationDecision::STRATEGY_STRIPE,
            nextActionOwner: PaymentMigrationDecision::OWNER_TARGET_SYSTEM,
            vendorCustomerId: $verification->customerId,
            vendorPlanId: null,
            // FluentCart bills this. A remote schedule ID here would mean two
            // components charging the same contract.
            vendorSubscriptionId: null,
            activePaymentMethod: $verification->activePaymentMethod(),
            reasonCodes: [],
        );
    }
}

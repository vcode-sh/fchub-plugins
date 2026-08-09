<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription\Payment;

use CartShift\Domain\Subscription\SubscriptionRecord;

defined('ABSPATH') || exit;

/**
 * PayPal's three ordered outcomes, in one strategy. Plan section 8.3.
 *
 * PayPal was previously under-modelled in the opposite direction from Stripe.
 * FluentCart 1.6.0's gateway really does advertise `system_subscription`
 * (PayPal.php:28), and `chargeRenewal()` really does delegate to
 * `Processor::chargeVaultedRenewal()` (PayPal.php:266), which reads the vault
 * ID from `active_payment_method.vendor_method_id` at fire time and charges it
 * off-session through Orders v2 (Processor.php:817-838). So a verified vault ID
 * can legitimately become a FluentCart `system` subscription, and treating
 * PayPal as remote-schedule-or-nothing would have been wrong.
 *
 * **A. Verified target-system PayPal** — the canonical probe returns `system`,
 * the exact source metadata adapter identifies a vault ID (not a Woo token-row
 * ID and not a billing-agreement ID), the target merchant can retrieve it, and
 * no provider-owned schedule is still running. Then `system`, with the vault in
 * `active_payment_method` and no vendor subscription ID.
 *
 * **B. Verified remote-schedule PayPal** — a real vendor subscription ID exists,
 * retrieves read-only under target credentials, matches on merchant and mode,
 * and the target's webhook routing resolves it. Then `automatic`, with the
 * schedule ID and *no* vault metadata: the remote schedule charges its own
 * stored mandate, and a vault sitting beside it invites a second charge.
 *
 * **C. Deliberate manual** — everything else, and the expected route for the
 * restored Lapka snapshot, whose PPCP plugin source is absent so no metadata
 * contract can be pinned. An empty reference set on a PPCP record reaches
 * `provider_method_missing`; it is never read as "no vault exists, therefore
 * manual is proven safe".
 *
 * A PayPal subscription or agreement ID never goes into `vendor_customer_id` or
 * into `active_payment_method`. `SubscriptionMapper.php:223-228` assigned the
 * subscription ID as the customer ID; that is the defect, and the decision's own
 * invariants now refuse to construct it.
 */
final class PayPalPaymentStrategy implements SubscriptionPaymentStrategy
{
    public const string TARGET_GATEWAY = PaymentCapabilityProbe::GATEWAY_PAYPAL;

    public function __construct(
        private readonly ManualPaymentStrategy $manual = new ManualPaymentStrategy(),
    ) {
    }

    public function assess(
        SubscriptionRecord $record,
        PaymentEnvironment $environment,
    ): PaymentMigrationDecision {
        $verification = $environment->verifierFor(PaymentMigrationDecision::STRATEGY_PAYPAL)
            ?->verify($record, $environment)
            ?? ProviderVerification::nothing(['provider_method_missing']);

        $capability = $environment->capabilities->diagnose(self::TARGET_GATEWAY);

        // Evaluated unconditionally, and merged rather than short-circuited, so
        // a date fault survives alongside whatever else objected instead of
        // being lost behind the first failing check.
        $scheduleFault = $environment->liveScheduleFault($record->status, $record->dates->nextPaymentUtc) ?? [];

        // A: a vault the target merchant can charge, and no remote schedule
        // still running against the same contract.
        $canSystem = $verification->isClean()
            && $verification->hasMethod()
            && !$verification->hasSchedule()
            && $capability['reason_codes'] === []
            && $environment->systemSettingsApproved();

        if ($canSystem && $scheduleFault === []) {
            return $this->outcomeA($verification);
        }

        // B: the provider keeps the schedule and FluentCart receives its events.
        $canRemote = $verification->isClean() && $verification->hasSchedule();

        $webhookFault = $canRemote
            && !$environment->webhookOwnershipVerified(PaymentMigrationDecision::STRATEGY_PAYPAL)
            // A remote schedule whose events the target cannot resolve is a
            // subscription that bills silently and reconciles nowhere.
            ? ['provider_webhook_unverified']
            : [];

        if ($canRemote && $webhookFault === [] && $scheduleFault === []) {
            return $this->outcomeB($verification);
        }

        return $this->outcomeC($record, $environment, $verification, array_merge(
            $webhookFault,
            $scheduleFault,
            // Capability reasons speak to the system destination. A record
            // headed for the remote schedule is not waiting on them.
            $verification->hasSchedule() ? [] : $capability['reason_codes'],
        ), $scheduleFault !== []);
    }

    private function outcomeA(ProviderVerification $verification): PaymentMigrationDecision
    {
        return new PaymentMigrationDecision(
            strategy: PaymentMigrationDecision::STRATEGY_PAYPAL,
            outcome: PaymentMigrationDecision::OUTCOME_READY,
            collectionMethod: PaymentMigrationDecision::COLLECTION_SYSTEM,
            currentPaymentMethod: PaymentMigrationDecision::STRATEGY_PAYPAL,
            nextActionOwner: PaymentMigrationDecision::OWNER_TARGET_SYSTEM,
            // A verified payer, or null when PayPal exposed none. Never the
            // vault ID and never a schedule ID.
            vendorCustomerId: $verification->customerId,
            vendorPlanId: null,
            vendorSubscriptionId: null,
            activePaymentMethod: $verification->activePaymentMethod(),
            reasonCodes: [],
            sourceMetadataAdapter: $verification->sourceMetadataAdapter,
        );
    }

    private function outcomeB(ProviderVerification $verification): PaymentMigrationDecision
    {
        return new PaymentMigrationDecision(
            strategy: PaymentMigrationDecision::STRATEGY_PAYPAL,
            outcome: PaymentMigrationDecision::OUTCOME_READY,
            collectionMethod: PaymentMigrationDecision::COLLECTION_AUTOMATIC,
            currentPaymentMethod: PaymentMigrationDecision::STRATEGY_PAYPAL,
            nextActionOwner: PaymentMigrationDecision::OWNER_REMOTE_SCHEDULE,
            vendorCustomerId: $verification->customerId,
            vendorPlanId: null,
            vendorSubscriptionId: $verification->subscriptionId,
            // The remote schedule charges its own stored mandate.
            activePaymentMethod: [],
            reasonCodes: [],
            sourceMetadataAdapter: $verification->sourceMetadataAdapter,
        );
    }

    /**
     * @param list<string> $extraReasons
     */
    private function outcomeC(
        SubscriptionRecord $record,
        PaymentEnvironment $environment,
        ProviderVerification $verification,
        array $extraReasons,
        bool $scheduleFaulted = false,
    ): PaymentMigrationDecision {
        $reasons = array_merge($verification->reasonCodes, $extraReasons);

        // Only when the store approval was actually a limiting input. A record
        // whose destination was the remote schedule is not waiting on
        // store-managed billing, and saying so would send the operator to
        // change a store-wide setting that would not have helped.
        if (!$verification->hasSchedule() && !$environment->systemSettingsApproved()) {
            $reasons[] = PaymentCapabilityProbe::REASON_STORE_MODE_NOT_APPROVED;
        }

        // Manual with the reasons attached, never `blocked`: an eligibility
        // miss is this layer's finding, and emitting the block is section 9.3's
        // job, which Task 8's write gate owns.
        $decision = $this->manual
            ->assess($record, $environment, self::TARGET_GATEWAY)
            ->withReasonCodes($reasons)
            ->forStrategy(PaymentMigrationDecision::STRATEGY_PAYPAL)
            ->withSourceMetadataAdapter($verification->sourceMetadataAdapter);

        return $scheduleFaulted ? $decision->requiringConfirmation() : $decision;
    }
}

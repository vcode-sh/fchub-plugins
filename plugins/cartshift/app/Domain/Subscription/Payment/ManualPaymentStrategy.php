<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription\Payment;

use CartShift\Domain\Subscription\SubscriptionRecord;

defined('ABSPATH') || exit;

/**
 * FluentCart raises the invoice; nobody charges anything off-session.
 *
 * The destination of plan section 8.4, and the safe end of every fallback:
 * `collection_method = manual`, no vendor customer, no plan, no subscription,
 * no vault metadata. It is `collection_method`, not the gateway slug, that
 * prevents automatic charging — the slug only decides which checkout path a
 * manual invoice offers.
 *
 * Which is why the slug is `''` for anything that is not a registered target
 * gateway. Three details make that the only correct value:
 *
 * `fct_subscriptions.current_payment_method` is `VARCHAR(45) NULL`
 * (SubscriptionsMigrator.php:45), so null looks permitted. But
 * `RenewalService::createRenewalOrders()` copies it straight into
 * `fct_orders.payment_method`, declared `VARCHAR(100) NOT NULL` with no default
 * (RenewalService.php:118, OrdersMigrator.php:25), and into
 * `fct_order_transactions.payment_method`, `NOT NULL DEFAULT ''`
 * (RenewalService.php:185, OrderTransactionsMigrator.php:24). A null reaches a
 * NOT NULL column at the first renewal, months after the migration was declared
 * a success.
 *
 * And the invented slug `manual` is not a FluentCart gateway. `App::gateway()`
 * would return null for it, which is a different and more confusing failure
 * than an empty string. Bank transfer, blank, P24 and BLIK all get `''`; only
 * a source whose gateway maps to a *registered* target Stripe or PayPal keeps
 * that slug, so a manual invoice can offer that checkout.
 */
final class ManualPaymentStrategy implements SubscriptionPaymentStrategy
{
    public const string REASON_CONFIRMATION_REQUIRED = 'manual_confirmation_required';

    public function assess(
        SubscriptionRecord $record,
        PaymentEnvironment $environment,
        string $fallbackTargetSlug = '',
    ): PaymentMigrationDecision {
        // A source that was already manual is not changing behaviour: WCS was
        // not charging it either. Anything that *was* automatic needs the
        // operator to accept that its customer now receives an invoice instead
        // of a silent renewal.
        $ready = $record->requiresManualRenewal || $environment->manualFallbackConfirmed;

        return $this->decide(
            $environment,
            $fallbackTargetSlug,
            $ready
                ? PaymentMigrationDecision::OUTCOME_READY
                : PaymentMigrationDecision::OUTCOME_CONFIRMATION_REQUIRED,
            $ready ? [] : [self::REASON_CONFIRMATION_REQUIRED],
        );
    }

    /**
     * A terminal record: 355 of the 564 Lapka subscriptions are cancelled.
     *
     * History cannot bill. It needs no verified customer, no token, and no
     * store that permits system collection — and holding it hostage to any of
     * those would block a migration over a mandate nothing will ever use.
     */
    public function assessHistorical(
        SubscriptionRecord $record,
        PaymentEnvironment $environment,
        string $fallbackTargetSlug = '',
    ): PaymentMigrationDecision {
        return $this->decide(
            $environment,
            $fallbackTargetSlug,
            PaymentMigrationDecision::OUTCOME_READY,
            [],
        );
    }

    /**
     * @param list<string> $reasonCodes
     */
    private function decide(
        PaymentEnvironment $environment,
        string $fallbackTargetSlug,
        string $outcome,
        array $reasonCodes,
    ): PaymentMigrationDecision {
        return new PaymentMigrationDecision(
            strategy: PaymentMigrationDecision::STRATEGY_MANUAL,
            outcome: $outcome,
            collectionMethod: PaymentMigrationDecision::COLLECTION_MANUAL,
            currentPaymentMethod: $this->targetSlug($environment, $fallbackTargetSlug),
            nextActionOwner: PaymentMigrationDecision::OWNER_TARGET_MANUAL,
            vendorCustomerId: null,
            vendorPlanId: null,
            vendorSubscriptionId: null,
            activePaymentMethod: [],
            reasonCodes: $reasonCodes,
        );
    }

    /**
     * The registered target gateway for this fallback, or the empty string.
     *
     * Registration is checked rather than assumed: naming a gateway FluentCart
     * does not have would offer a manual invoice a checkout that cannot load.
     */
    private function targetSlug(PaymentEnvironment $environment, string $fallbackTargetSlug): string
    {
        $allowed = [PaymentMigrationDecision::STRATEGY_STRIPE, PaymentMigrationDecision::STRATEGY_PAYPAL];

        if (!in_array($fallbackTargetSlug, $allowed, true)) {
            return '';
        }

        return $environment->capabilities->isRegistered($fallbackTargetSlug) ? $fallbackTargetSlug : '';
    }
}

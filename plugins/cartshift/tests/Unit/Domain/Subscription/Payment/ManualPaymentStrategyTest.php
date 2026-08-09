<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Subscription\Payment;

use CartShift\Domain\Subscription\Payment\ManualPaymentStrategy;
use CartShift\Domain\Subscription\Payment\PaymentMigrationDecision;
use CartShift\Domain\Subscription\Payment\PaymentStrategyRegistry;

/**
 * The manual destination, and the empty string that has to survive to the
 * database.
 *
 * `collection_method = manual` is what stops FluentCart charging anybody
 * off-session. The gateway slug only decides which checkout path a manual
 * invoice offers, which is why an invented slug is both useless and dangerous.
 */
final class ManualPaymentStrategyTest extends PaymentStrategyTestCase
{
    // ── the slug ───────────────────────────────────────────

    public function testAGatewayNeutralSourceGetsTheEmptyStringNotTheInventedSlugManual(): void
    {
        $decision = $this->registryDecision('bacs');

        $this->assertSame('', $decision->currentPaymentMethod);
        $this->assertNotSame(
            'manual',
            $decision->currentPaymentMethod,
            '`manual` is not a FluentCart gateway. App::gateway() would return null for it.',
        );
    }

    public function testAStripeSourceKeepsTheRegisteredStripeCheckoutPath(): void
    {
        $decision = $this->registryDecision('stripe', ['requires_manual_renewal' => true]);

        $this->assertSame('stripe', $decision->currentPaymentMethod);
    }

    public function testAPayPalSourceKeepsTheRegisteredPayPalCheckoutPath(): void
    {
        $decision = $this->registryDecision('ppcp-gateway', ['requires_manual_renewal' => true]);

        $this->assertSame('paypal', $decision->currentPaymentMethod);
    }

    /**
     * A gateway FluentCart does not have would offer a manual invoice a
     * checkout that cannot load, so registration is checked rather than
     * assumed.
     */
    public function testAnUnregisteredTargetGatewayFallsBackToTheNeutralSlug(): void
    {
        $this->seedGateways('paypal');

        $decision = $this->registryDecision('stripe', ['requires_manual_renewal' => true]);

        $this->assertSame('', $decision->currentPaymentMethod);
    }

    // ── the NOT NULL columns downstream ────────────────────

    /**
     * `fct_subscriptions.current_payment_method` is `VARCHAR(45) NULL`
     * (SubscriptionsMigrator.php:45), so a null looks permitted. It is not.
     *
     * `RenewalService::createRenewalOrders()` copies that value straight into
     * `fct_orders.payment_method`, declared `VARCHAR(100) NOT NULL` with no
     * default (RenewalService.php:118, OrdersMigrator.php:25), and into
     * `fct_order_transactions.payment_method`, `NOT NULL DEFAULT ''`
     * (RenewalService.php:185, OrderTransactionsMigrator.php:24). A null
     * therefore reaches a NOT NULL column at the first renewal, months after
     * the migration was declared a success.
     *
     * So the load-bearing assertion is that these slugs are `''`. That a null
     * can never be produced is a property of the type, asserted below rather
     * than staged with a fake column that would only be proving PHP's strict
     * types back to itself.
     */
    public function testEveryGatewayNeutralSourceProducesTheEmptyStringForThoseColumns(): void
    {
        foreach (['bacs', '', 'stripe_p24', 'ppcp-blik', 'cheque', 'cod'] as $slug) {
            $this->assertSame(
                '',
                $this->registryDecision($slug)->currentPaymentMethod,
                sprintf('Source slug "%s" must reach fct_orders.payment_method as the empty string.', $slug),
            );
        }
    }

    /**
     * Null is not merely avoided; it is untypeable. The property is declared
     * non-nullable `string`, and `guardVocabulary()` restricts it further to
     * `stripe`, `paypal` or `''` — so no code path, present or future, can put
     * a null or an invented slug into that NOT NULL column.
     */
    public function testTheSlugIsStructurallyIncapableOfBeingNull(): void
    {
        $property = new \ReflectionProperty(PaymentMigrationDecision::class, 'currentPaymentMethod');
        $type     = $property->getType();

        $this->assertInstanceOf(\ReflectionNamedType::class, $type);
        $this->assertSame('string', $type->getName());
        $this->assertFalse($type->allowsNull());
    }

    // ── confirmation ───────────────────────────────────────

    /**
     * A source that was already manual is not changing behaviour: WCS was not
     * charging it either. 127 Lapka records carry the flag.
     */
    public function testAnAlreadyManualSourceIsReadyWithoutFurtherConfirmation(): void
    {
        $decision = $this->registryDecision('bacs', ['requires_manual_renewal' => true]);

        $this->assertSame(PaymentMigrationDecision::OUTCOME_READY, $decision->outcome);
        $this->assertSame([], $decision->reasonCodes);
    }

    /**
     * A previously automatic source becoming manual is a change the customer
     * will notice — an invoice instead of a silent renewal — so the operator
     * accepts it explicitly.
     */
    public function testAPreviouslyAutomaticSourceNeedsExplicitConfirmation(): void
    {
        $decision = $this->registryDecision('bacs', ['requires_manual_renewal' => false]);

        $this->assertSame(PaymentMigrationDecision::OUTCOME_CONFIRMATION_REQUIRED, $decision->outcome);
        $this->assertSame(
            [ManualPaymentStrategy::REASON_CONFIRMATION_REQUIRED],
            $decision->reasonCodes,
        );
    }

    public function testAConfirmedCohortBecomesReady(): void
    {
        $decision = PaymentStrategyRegistry::withDefaults()->assess(
            $this->record('manualRenewal', ['payment_method' => 'bacs', 'requires_manual_renewal' => false]),
            $this->environment(['manualFallbackConfirmed' => true]),
        );

        $this->assertSame(PaymentMigrationDecision::OUTCOME_READY, $decision->outcome);
        $this->assertSame([], $decision->reasonCodes);
    }

    // ── no mandate, ever ───────────────────────────────────

    public function testAManualDecisionCarriesNoVendorMandateAtAll(): void
    {
        $decision = $this->registryDecision('bacs');

        $this->assertNull($decision->vendorCustomerId);
        $this->assertNull($decision->vendorPlanId);
        $this->assertNull($decision->vendorSubscriptionId);
        $this->assertSame([], $decision->activePaymentMethod);
        $this->assertSame(PaymentMigrationDecision::OWNER_TARGET_MANUAL, $decision->nextActionOwner);
    }

    // ── helpers ────────────────────────────────────────────

    /**
     * @param array<string, mixed> $overrides
     */
    private function registryDecision(string $slug, array $overrides = []): PaymentMigrationDecision
    {
        return PaymentStrategyRegistry::withDefaults()->assess(
            $this->record('manualRenewal', array_merge([
                'payment_method'          => $slug,
                'requires_manual_renewal' => false,
            ], $overrides)),
            $this->environment(),
        );
    }
}

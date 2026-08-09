<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Subscription\Payment;

use CartShift\Domain\Subscription\InvalidSourceRecord;
use CartShift\Domain\Subscription\Payment\PaymentEnvironment;
use CartShift\Domain\Subscription\Payment\PaymentMigrationDecision;
use CartShift\Domain\Subscription\Payment\PaymentStrategyRegistry;
use CartShift\Domain\Subscription\Payment\StripePaymentStrategy;
use CartShift\Domain\Subscription\Payment\SubscriptionPaymentStrategy;
use CartShift\Domain\Subscription\SubscriptionRecord;

/**
 * The decision table, whole.
 *
 * Section 8.1's precedence is six ordered steps and the order is the entire
 * point: the explicit `requires_manual_renewal` flag beats the gateway slug,
 * a terminal record needs no live mandate at all, and anything unrecognised is
 * blocked rather than guessed into one of the three buckets. Every Lapka
 * cohort below lands in exactly one strategy with exactly one next-action
 * owner, because a record with no owner has nothing responsible for its next
 * charge — which is the confirmed defect this task exists to fix.
 */
final class PaymentStrategyRegistryTest extends PaymentStrategyTestCase
{
    // ── step 1: terminal history needs no mandate ──────────

    /**
     * 355 of the 564 are cancelled. History cannot bill, so it does not need a
     * verified customer, a token, or a store that permits system collection —
     * and it must not be held hostage to any of them.
     */
    public function testATerminalRecordIsReadyWithoutAnyLiveMandate(): void
    {
        $this->seedGateways();
        $this->seedStoreSettings(mode: 'gateway_managed', systemCharge: 'no');

        $decision = $this->assess($this->record('cancelled'), $this->environment([
            'verifiers' => [],
        ]));

        $this->assertSame(PaymentMigrationDecision::STRATEGY_MANUAL, $decision->strategy);
        $this->assertSame(PaymentMigrationDecision::OUTCOME_READY, $decision->outcome);
        $this->assertSame(PaymentMigrationDecision::COLLECTION_MANUAL, $decision->collectionMethod);
        $this->assertSame(PaymentMigrationDecision::OWNER_TARGET_MANUAL, $decision->nextActionOwner);
        $this->assertSame([], $decision->reasonCodes);
    }

    public function testTerminalPrecedenceBeatsAnUnsupportedGateway(): void
    {
        $decision = $this->assess(
            $this->record('cancelled', ['payment_method' => 'klarna']),
            $this->environment(),
        );

        $this->assertSame(PaymentMigrationDecision::OUTCOME_READY, $decision->outcome);
        $this->assertSame([], $decision->reasonCodes);
    }

    // ── step 2: the explicit flag beats the slug ───────────

    /**
     * 127 records explicitly require manual renewal. The Stripe slug on such a
     * record is history, not an instruction: WCS was already not charging it.
     */
    public function testTheManualRenewalFlagBeatsTheStripeSlug(): void
    {
        $decision = $this->assess(
            $this->record('stripePaymentMethod', ['requires_manual_renewal' => true]),
            $this->environment(),
        );

        $this->assertSame(PaymentMigrationDecision::STRATEGY_MANUAL, $decision->strategy);
        $this->assertSame(PaymentMigrationDecision::COLLECTION_MANUAL, $decision->collectionMethod);
        $this->assertSame(PaymentMigrationDecision::OWNER_TARGET_MANUAL, $decision->nextActionOwner);
        $this->assertSame(
            PaymentMigrationDecision::OUTCOME_READY,
            $decision->outcome,
            'The source was already manual; nothing about the customer\'s experience changes.',
        );
        $this->assertNull($decision->vendorCustomerId);
        $this->assertSame([], $decision->activePaymentMethod);
    }

    public function testTheManualRenewalFlagBeatsThePayPalSlug(): void
    {
        $decision = $this->assess(
            $this->record('paypalGateway', ['requires_manual_renewal' => true]),
            $this->environment(),
        );

        $this->assertSame(PaymentMigrationDecision::STRATEGY_MANUAL, $decision->strategy);
        $this->assertSame('paypal', $decision->currentPaymentMethod);
        $this->assertNull($decision->vendorSubscriptionId);
    }

    // ── step 3: the manual slugs ───────────────────────────

    /**
     * @param non-empty-string $slug
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('manualSlugs')]
    public function testEveryManualSlugTakesTheManualStrategy(string $slug, string $expectedMethod): void
    {
        $decision = $this->assess(
            $this->record('manualRenewal', [
                'payment_method'          => $slug === '(blank)' ? '' : $slug,
                'requires_manual_renewal' => false,
            ]),
            $this->environment(),
        );

        $this->assertSame(PaymentMigrationDecision::STRATEGY_MANUAL, $decision->strategy);
        $this->assertSame(PaymentMigrationDecision::COLLECTION_MANUAL, $decision->collectionMethod);
        $this->assertSame(PaymentMigrationDecision::OWNER_TARGET_MANUAL, $decision->nextActionOwner);
        $this->assertSame($expectedMethod, $decision->currentPaymentMethod);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function manualSlugs(): array
    {
        return [
            'bank transfer'     => ['bacs', ''],
            'blank'             => ['(blank)', ''],
            // Not Stripe and not PayPal, whatever the prefixes imply. Routing
            // these to the registered target gateway would offer the customer a
            // checkout that cannot take their money.
            'Stripe P24'        => ['stripe_p24', ''],
            'PPCP BLIK'         => ['ppcp-blik', ''],
            'cheque'            => ['cheque', ''],
            'cash on delivery'  => ['cod', ''],
            'literal manual'    => ['manual', ''],
        ];
    }

    // ── steps 4 and 5: the two supported gateways ──────────

    public function testTheStripeSlugTakesTheStripeStrategy(): void
    {
        $decision = $this->assess($this->record('stripePaymentMethod'), $this->environment());

        $this->assertSame(PaymentMigrationDecision::STRATEGY_STRIPE, $decision->strategy);
    }

    public function testThePpcpSlugTakesThePayPalStrategy(): void
    {
        $decision = $this->assess($this->record('paypalGateway'), $this->environment());

        $this->assertSame(PaymentMigrationDecision::STRATEGY_PAYPAL, $decision->strategy);
    }

    // ── step 6: anything else is blocked ───────────────────

    public function testAnUnknownGatewayIsBlockedRatherThanGuessed(): void
    {
        $decision = $this->assess(
            $this->record('stripePaymentMethod', ['payment_method' => 'klarna']),
            $this->environment(),
        );

        $this->assertSame(PaymentMigrationDecision::OUTCOME_BLOCKED, $decision->outcome);
        $this->assertSame([PaymentStrategyRegistry::REASON_UNSUPPORTED_GATEWAY], $decision->reasonCodes);
        $this->assertSame(PaymentMigrationDecision::COLLECTION_MANUAL, $decision->collectionMethod);
        $this->assertSame(PaymentMigrationDecision::OWNER_TARGET_MANUAL, $decision->nextActionOwner);
    }

    /**
     * A gateway nobody registered must not inherit Stripe's answer just because
     * its slug happens to begin with the same six characters.
     */
    public function testALookalikeSlugIsNotTreatedAsStripe(): void
    {
        $decision = $this->assess(
            $this->record('stripePaymentMethod', ['payment_method' => 'stripe_sepa_debit']),
            $this->environment(),
        );

        $this->assertSame(PaymentMigrationDecision::OUTCOME_BLOCKED, $decision->outcome);
        $this->assertSame([PaymentStrategyRegistry::REASON_UNSUPPORTED_GATEWAY], $decision->reasonCodes);
    }

    // ── exactly one owner, every time ──────────────────────

    /**
     * Every Lapka cohort, and the invariant that matters most: one strategy,
     * one collection method, one next-action owner, and the owner agrees with
     * the collection method. A record whose next charge belongs to nobody is
     * the defect; a record claimed by two components would be worse.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('lapkaCohorts')]
    public function testEveryCohortSelectsExactlyOneStrategyAndOneOwner(string $shape): void
    {
        $decision = $this->assess($this->record($shape), $this->environment());

        $this->assertContains($decision->strategy, [
            PaymentMigrationDecision::STRATEGY_STRIPE,
            PaymentMigrationDecision::STRATEGY_PAYPAL,
            PaymentMigrationDecision::STRATEGY_MANUAL,
        ]);

        $this->assertSame(
            match ($decision->collectionMethod) {
                PaymentMigrationDecision::COLLECTION_SYSTEM    => PaymentMigrationDecision::OWNER_TARGET_SYSTEM,
                PaymentMigrationDecision::COLLECTION_AUTOMATIC => PaymentMigrationDecision::OWNER_REMOTE_SCHEDULE,
                default                                        => PaymentMigrationDecision::OWNER_TARGET_MANUAL,
            },
            $decision->nextActionOwner,
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function lapkaCohorts(): array
    {
        return [
            'Stripe pm_'      => ['stripePaymentMethod'],
            'Stripe src_'     => ['stripeLegacySource'],
            'PPCP'            => ['paypalGateway'],
            'bank transfer'   => ['manualRenewal'],
            'blank gateway'   => ['blankGateway'],
            'cancelled'       => ['cancelled'],
            'on hold'         => ['onHoldNoNextDate'],
            'active future'   => ['activeFutureDate'],
            'guest'           => ['guestCustomer'],
            'monthly PLN 24'  => ['monthlyPln24'],
            'yearly PLN 290'  => ['yearlyPln290'],
        ];
    }

    /**
     * The same invariant, swept across every subscription shape the fixture
     * file declares rather than a chosen eleven.
     *
     * A hand-picked list proves the cases somebody thought of. This one fails
     * the moment a shape is added that nothing routes, which is the whole point
     * of having written the Lapka population down.
     */
    public function testEverySubscriptionShapeInTheFixtureFileIsRouted(): void
    {
        $routed = 0;

        foreach ($this->shapes as $name => $factory) {
            $shape = $name === 'aggregates' ? null : $factory([]);

            if (!$shape instanceof \CartShiftLapkaSubscription) {
                continue;
            }

            $record = $this->factory->subscriptionFromWoo('lapka', $shape);

            if ($record instanceof InvalidSourceRecord) {
                // Malformed source rows are blocked before payment. That is the
                // dataset layer's answer and it is the right one.
                continue;
            }

            $decision = $this->assess($record, $this->environment());
            $routed++;

            $this->assertSame(
                match ($decision->collectionMethod) {
                    PaymentMigrationDecision::COLLECTION_SYSTEM    => PaymentMigrationDecision::OWNER_TARGET_SYSTEM,
                    PaymentMigrationDecision::COLLECTION_AUTOMATIC => PaymentMigrationDecision::OWNER_REMOTE_SCHEDULE,
                    default                                        => PaymentMigrationDecision::OWNER_TARGET_MANUAL,
                },
                $decision->nextActionOwner,
                sprintf('Fixture "%s" produced a decision nobody owns.', $name),
            );

            // A system or automatic decision without the identifier FluentCart
            // reads at fire time is a subscription that fails its first
            // renewal. The decision's own invariants refuse to construct one;
            // this asserts the sweep actually exercised them.
            if ($decision->collectionMethod === PaymentMigrationDecision::COLLECTION_SYSTEM) {
                $this->assertNotSame(
                    '',
                    (string) ($decision->activePaymentMethod[PaymentMigrationDecision::ACTIVE_METHOD_ID] ?? ''),
                    sprintf('Fixture "%s" would fail its first renewal with missing_token.', $name),
                );
            }
        }

        $this->assertGreaterThan(15, $routed, 'The sweep should be covering the whole fixture vocabulary.');
    }

    /**
     * The malformed record never reaches a payment strategy at all: it fails
     * the record contract first and arrives as an `InvalidSourceRecord`. That
     * is the correct answer to "which strategy does it select" — none, because
     * it is not a subscription yet.
     */
    public function testTheMalformedRecordNeverReachesAStrategy(): void
    {
        $decoded = $this->factory->subscriptionFromWoo('lapka', $this->shapes['malformedNoItemNoParent']([]));

        $this->assertInstanceOf(InvalidSourceRecord::class, $decoded);
    }

    // ── extensibility ──────────────────────────────────────

    /**
     * A fourth gateway is one class and one registry entry.
     *
     * The strategy below emits an `automatic` decision carrying a sentinel
     * vendor subscription ID — a shape no existing code produces for the slug
     * `mollie`, so seeing it back proves the registry actually dispatched to
     * this class rather than falling through to a manual answer that any code
     * could have given. Nothing in the mapper or the writer is involved.
     */
    public function testAFourthGatewayNeedsOneClassAndOneRegistryEntry(): void
    {
        $strategy = new class implements SubscriptionPaymentStrategy {
            public const string SENTINEL = 'MOLLIE-SENTINEL-SCHEDULE-0001';

            public function assess(
                SubscriptionRecord $record,
                PaymentEnvironment $environment,
            ): PaymentMigrationDecision {
                return new PaymentMigrationDecision(
                    strategy: PaymentMigrationDecision::STRATEGY_PAYPAL,
                    outcome: PaymentMigrationDecision::OUTCOME_READY,
                    collectionMethod: PaymentMigrationDecision::COLLECTION_AUTOMATIC,
                    currentPaymentMethod: 'paypal',
                    nextActionOwner: PaymentMigrationDecision::OWNER_REMOTE_SCHEDULE,
                    vendorCustomerId: null,
                    vendorPlanId: null,
                    vendorSubscriptionId: self::SENTINEL,
                    activePaymentMethod: [],
                    reasonCodes: [],
                );
            }
        };

        $registry = PaymentStrategyRegistry::withDefaults()->register('mollie', $strategy, 'mollie');

        $decision = $registry->assess(
            $this->record('stripePaymentMethod', ['payment_method' => 'mollie']),
            $this->environment(),
        );

        $this->assertSame($strategy::SENTINEL, $decision->vendorSubscriptionId);
        $this->assertSame(PaymentMigrationDecision::COLLECTION_AUTOMATIC, $decision->collectionMethod);
        $this->assertSame(PaymentMigrationDecision::OWNER_REMOTE_SCHEDULE, $decision->nextActionOwner);

        // And the same slug without the registration is blocked, so the
        // registry entry is what changed the answer.
        $this->assertSame(
            PaymentMigrationDecision::OUTCOME_BLOCKED,
            PaymentStrategyRegistry::withDefaults()->assess(
                $this->record('stripePaymentMethod', ['payment_method' => 'mollie']),
                $this->environment(),
            )->outcome,
        );
    }

    /**
     * Step 3 runs before the strategy map, so a strategy registered under a
     * manual slug would never run. A registry that accepts a registration it
     * will never honour is worse than one that refuses it — `supports()` would
     * answer true and the caller would spend an afternoon wondering why.
     */
    public function testRegisteringAStrategyForAManualSlugIsRefusedLoudly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('would never run');

        PaymentStrategyRegistry::withDefaults()->register(
            'bacs',
            new StripePaymentStrategy(),
            'stripe',
        );
    }

    // ── helper ─────────────────────────────────────────────

    private function assess(SubscriptionRecord $record, PaymentEnvironment $environment): PaymentMigrationDecision
    {
        return PaymentStrategyRegistry::withDefaults()->assess($record, $environment);
    }
}

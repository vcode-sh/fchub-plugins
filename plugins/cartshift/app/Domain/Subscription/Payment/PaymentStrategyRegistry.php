<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription\Payment;

use CartShift\Domain\Subscription\SubscriptionRecord;

defined('ABSPATH') || exit;

/**
 * Section 8.1's precedence, and the map from source slugs to the three
 * deliberately small strategies.
 *
 * The order is the whole point, and every step of it exists because the
 * alternative reading is wrong:
 *
 * 1. **Terminal history first.** 355 of the 564 Lapka subscriptions are
 *    cancelled. They cannot bill, so they need no live mandate — and blocking
 *    a migration because a cancelled subscription's Stripe token has expired
 *    would be absurd.
 * 2. **`requires_manual_renewal` beats the gateway slug.** 127 records carry
 *    the flag. WCS was already not charging them; the slug on such a record is
 *    history, not an instruction.
 * 3. **The manual slugs.** `bacs`, blank, `stripe_p24`, `ppcp-blik`, `manual`,
 *    `cheque`, `cod`. Note the third and fourth: a slug beginning `stripe` or
 *    `ppcp` is not thereby a Stripe or PayPal subscription, and routing P24 or
 *    BLIK to a registered target gateway would offer a customer a checkout that
 *    cannot take their money.
 * 4. **Standard Stripe.**
 * 5. **Standard PayPal/PPCP.**
 * 6. **Anything else is blocked** with `unsupported_gateway`. Not guessed into
 *    one of the three buckets, because a guess here bills a real person.
 *
 * Adding a fourth gateway is one strategy class, one `register()` call, and its
 * tests. It is not a new branch in `SubscriptionMapper`, and it changes nothing
 * in the mapper or the writer.
 */
final class PaymentStrategyRegistry
{
    public const string REASON_UNSUPPORTED_GATEWAY = 'unsupported_gateway';

    /**
     * WCS statuses that cannot bill again.
     *
     * `pending-cancel` is deliberately absent: it is still live until its term
     * ends, so it goes through the ordinary path rather than being written off
     * as history.
     *
     * @var list<string>
     */
    private const array TERMINAL_STATUSES = ['cancelled', 'canceled', 'expired', 'switched'];

    /**
     * Source slugs that are manual renewal by definition.
     *
     * The empty string is in here on purpose — 55 Lapka records have a blank
     * gateway, and blank is what the source says rather than a missing value to
     * be filled in with something plausible.
     *
     * @var list<string>
     */
    private const array MANUAL_SLUGS = ['', 'bacs', 'cheque', 'cod', 'manual', 'ppcp-blik', 'stripe_p24'];

    /** @var array<string, array{strategy: SubscriptionPaymentStrategy, target: string}> */
    private array $strategies = [];

    public function __construct(
        private readonly ManualPaymentStrategy $manual = new ManualPaymentStrategy(),
    ) {
    }

    /**
     * The plan's supported set: standard Stripe, standard PayPal, manual.
     */
    public static function withDefaults(): self
    {
        $manual = new ManualPaymentStrategy();

        return (new self($manual))
            ->register('stripe', new StripePaymentStrategy($manual), StripePaymentStrategy::TARGET_GATEWAY)
            ->register('ppcp-gateway', new PayPalPaymentStrategy($manual), PayPalPaymentStrategy::TARGET_GATEWAY)
            ->register('paypal', new PayPalPaymentStrategy($manual), PayPalPaymentStrategy::TARGET_GATEWAY);
    }

    /**
     * @param string $sourceSlug       The Woo gateway ID, exactly as the source stores it.
     * @param string $targetGatewaySlug The registered FluentCart gateway a manual invoice
     *                                  for this source may offer, or '' for none.
     */
    public function register(
        string $sourceSlug,
        SubscriptionPaymentStrategy $strategy,
        string $targetGatewaySlug = '',
    ): self {
        // Step 3 runs before the strategy map, so a strategy registered under a
        // manual slug would never be reached. A registry that silently accepts
        // a registration it will never honour is worse than one that refuses
        // it: `supports()` would answer true and the caller would spend an
        // afternoon wondering why their class never runs.
        if (in_array($sourceSlug, self::MANUAL_SLUGS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Source slug "%s" is manual renewal by definition (section 8.1 step 3) and is decided '
                . 'before the strategy map. A strategy registered for it would never run.',
                $sourceSlug,
            ));
        }

        $this->strategies[$sourceSlug] = ['strategy' => $strategy, 'target' => $targetGatewaySlug];

        return $this;
    }

    public function supports(string $sourceSlug): bool
    {
        return in_array($sourceSlug, self::MANUAL_SLUGS, true) || isset($this->strategies[$sourceSlug]);
    }

    public function assess(
        SubscriptionRecord $record,
        PaymentEnvironment $environment,
    ): PaymentMigrationDecision {
        $slug   = $record->gateway;
        $target = $this->strategies[$slug]['target'] ?? '';

        // 1. Terminal history needs no live mandate, whatever its slug says —
        //    including a slug nothing supports.
        if (in_array(strtolower($record->status), self::TERMINAL_STATUSES, true)) {
            return $this->manual->assessHistorical($record, $environment, $target);
        }

        // 2. The explicit flag beats the slug.
        if ($record->requiresManualRenewal) {
            return $this->manual->assess($record, $environment, $target);
        }

        // 3. The manual slugs. No target gateway: P24 and BLIK are not Stripe
        //    and PayPal however their prefixes read.
        if (in_array($slug, self::MANUAL_SLUGS, true)) {
            return $this->manual->assess($record, $environment, '');
        }

        // 4 and 5. The registered strategies.
        if (isset($this->strategies[$slug])) {
            return $this->strategies[$slug]['strategy']->assess($record, $environment);
        }

        // 6. Blocked, not guessed.
        return new PaymentMigrationDecision(
            strategy: PaymentMigrationDecision::STRATEGY_MANUAL,
            outcome: PaymentMigrationDecision::OUTCOME_BLOCKED,
            collectionMethod: PaymentMigrationDecision::COLLECTION_MANUAL,
            currentPaymentMethod: '',
            nextActionOwner: PaymentMigrationDecision::OWNER_TARGET_MANUAL,
            vendorCustomerId: null,
            vendorPlanId: null,
            vendorSubscriptionId: null,
            activePaymentMethod: [],
            reasonCodes: [self::REASON_UNSUPPORTED_GATEWAY],
        );
    }
}

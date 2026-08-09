<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription\Payment;

defined('ABSPATH') || exit;

/**
 * Who owns this subscription's next charge, and on what evidence.
 *
 * The constructor signature is plan section 3's, verbatim. What is added here
 * is that the invariants are enforced rather than documented, because the
 * confirmed P0 defect this task replaces was not a misunderstanding — it was
 * `SubscriptionMapper.php:160-164` marking every subscription `automatic` and
 * copying the raw Woo gateway slug, which in FluentCart 1.6.0 means "a gateway
 * owns a remote schedule for this". None of the 367 Lapka Stripe records has a
 * vendor subscription ID, so nothing would have been responsible for the next
 * charge at all.
 *
 * Three invariants, and each one is a bug that has already happened somewhere:
 *
 * **Exactly one next-action owner, and it agrees with the collection method.**
 * `system` means FluentCart bills and token-charges (`target_system`),
 * `automatic` means the provider's own schedule stays authoritative
 * (`remote_schedule`), `manual` means FluentCart raises invoices and charges
 * nobody off-session (`target_manual`). A decision that claims `automatic` with
 * `target_manual` is describing two different futures.
 *
 * **A system decision carries a token; an automatic one carries a schedule.**
 * FluentCart's installed charge path is explicit about which identifier it
 * reads: `Stripe::chargeRenewal()` (Stripe.php:200-221) needs
 * `vendor_customer_id` plus `active_payment_method.vendor_method_id` and
 * returns `missing_token` without them, and
 * `Processor::chargeVaultedRenewal()` (Processor.php:806-825) reads the same
 * meta key for PayPal. A `system` decision with an empty
 * `active_payment_method` is a subscription that will fail its first renewal.
 *
 * **Three identifiers, three fields.** A source customer reference, a vault ID,
 * and a remote subscription ID are different things however similar their
 * prefixes look. `SubscriptionMapper.php:223-228` assigned a PayPal
 * subscription ID as the customer ID; the equality guards below make the
 * cheapest version of that mistake impossible to construct.
 */
final readonly class PaymentMigrationDecision
{
    public const string STRATEGY_STRIPE = 'stripe';
    public const string STRATEGY_PAYPAL = 'paypal';
    public const string STRATEGY_MANUAL = 'manual';

    public const string OUTCOME_READY = 'ready';
    public const string OUTCOME_CONFIRMATION_REQUIRED = 'confirmation_required';
    public const string OUTCOME_BLOCKED = 'blocked';

    public const string COLLECTION_SYSTEM = 'system';
    public const string COLLECTION_AUTOMATIC = 'automatic';
    public const string COLLECTION_MANUAL = 'manual';

    public const string OWNER_TARGET_SYSTEM = 'target_system';
    public const string OWNER_REMOTE_SCHEDULE = 'remote_schedule';
    public const string OWNER_TARGET_MANUAL = 'target_manual';

    /**
     * The exact meta key FluentCart's charge path reads at fire time.
     *
     * @see fluent-cart/app/Modules/PaymentMethods/StripeGateway/Stripe.php:215-216
     * @see fluent-cart/app/Modules/PaymentMethods/PayPalGateway/Processor.php:817-818
     */
    public const string ACTIVE_METHOD_ID = 'vendor_method_id';

    /** @var array<string, string> The one owner each collection method implies. */
    private const array OWNER_FOR_COLLECTION = [
        self::COLLECTION_SYSTEM    => self::OWNER_TARGET_SYSTEM,
        self::COLLECTION_AUTOMATIC => self::OWNER_REMOTE_SCHEDULE,
        self::COLLECTION_MANUAL    => self::OWNER_TARGET_MANUAL,
    ];

    /**
     * The payment row of plan section 9.4, plus the two lifecycle codes a
     * payment decision may legitimately refuse a live mandate over.
     *
     * Section 9.4 is explicit that free-form strings do not control cutover:
     * commands, receipts, retry logic and operator copy all key off these, so
     * an unrecognised one is rejected at construction rather than discovered
     * three phases later when a retry stops matching its own blocker.
     *
     * @var list<string>
     */
    public const array REASON_CODES = [
        'active_next_date_missing',
        'active_next_date_past',
        'gateway_lacks_system_capability',
        'manual_confirmation_required',
        'provider_account_mismatch',
        'provider_customer_missing',
        'provider_metadata_contract_unknown',
        'provider_method_missing',
        'provider_method_unsupported',
        'provider_mode_mismatch',
        'provider_schedule_mismatch',
        'provider_subscription_missing',
        'provider_webhook_unverified',
        'system_collection_unavailable',
        'system_store_mode_not_approved',
        'unsupported_gateway',
    ];

    /** @var list<string> Sorted and de-duplicated, so two identical decisions read identically. */
    public array $reasonCodes;

    /**
     * @param array<string, mixed> $activePaymentMethod
     * @param list<string>         $reasonCodes
     */
    public function __construct(
        public string $strategy,
        public string $outcome,
        public string $collectionMethod,
        public string $currentPaymentMethod,
        public string $nextActionOwner,
        public ?string $vendorCustomerId,
        public ?string $vendorPlanId,
        public ?string $vendorSubscriptionId,
        public array $activePaymentMethod,
        array $reasonCodes,
        /**
         * The exact source metadata contract the references were read under.
         *
         * Appended after the plan's ten so every existing positional call is
         * unchanged. It exists because a reason code alone cannot tell an
         * operator whether a lookup ran and found nothing or was never
         * possible: null here beside `provider_metadata_contract_unknown` says
         * the source plugin's contract could not be identified, and matches
         * Task 0's `source_paypal_adapter_unknown` on the runtime report.
         */
        public ?string $sourceMetadataAdapter = null,
    ) {
        $this->guardVocabulary();
        $this->guardOwnership();
        $this->guardIdentifiers();

        $codes = array_values(array_unique($reasonCodes));
        sort($codes);

        foreach ($codes as $code) {
            if (!in_array($code, self::REASON_CODES, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Unknown reason code "%s". Section 9.4 codes control cutover; free-form strings do not.',
                    $code,
                ));
            }
        }

        $this->reasonCodes = $codes;
    }

    private function guardVocabulary(): void
    {
        $this->require(
            in_array($this->strategy, [self::STRATEGY_STRIPE, self::STRATEGY_PAYPAL, self::STRATEGY_MANUAL], true),
            sprintf('Unknown strategy "%s".', $this->strategy),
        );

        $this->require(
            in_array(
                $this->outcome,
                [self::OUTCOME_READY, self::OUTCOME_CONFIRMATION_REQUIRED, self::OUTCOME_BLOCKED],
                true,
            ),
            sprintf('Unknown outcome "%s".', $this->outcome),
        );

        $this->require(
            isset(self::OWNER_FOR_COLLECTION[$this->collectionMethod]),
            sprintf('Unknown collection method "%s".', $this->collectionMethod),
        );

        // Section 8.4: the invented slug `manual` is not a FluentCart gateway.
        // The gateway-neutral value is the empty string, which is what the
        // renewal invoice copies into `fct_orders.payment_method` — declared
        // VARCHAR(100) NOT NULL with no default.
        $this->require(
            in_array($this->currentPaymentMethod, [self::STRATEGY_STRIPE, self::STRATEGY_PAYPAL, ''], true),
            sprintf('"%s" is not a registered target gateway slug.', $this->currentPaymentMethod),
        );
    }

    private function guardOwnership(): void
    {
        $this->require(
            $this->nextActionOwner === self::OWNER_FOR_COLLECTION[$this->collectionMethod],
            sprintf(
                'collection_method "%s" implies owner "%s", not "%s". A decision has exactly one owner.',
                $this->collectionMethod,
                self::OWNER_FOR_COLLECTION[$this->collectionMethod],
                $this->nextActionOwner,
            ),
        );
    }

    /**
     * The identifiers FluentCart's installed charge path actually requires,
     * and the ones it must never be handed.
     */
    private function guardIdentifiers(): void
    {
        $methodId = (string) ($this->activePaymentMethod[self::ACTIVE_METHOD_ID] ?? '');

        if ($this->collectionMethod === self::COLLECTION_SYSTEM) {
            $this->require(
                $methodId !== '',
                'A system subscription with no active_payment_method.vendor_method_id fails its first renewal '
                . 'with missing_token.',
            );
            $this->require(
                $this->vendorSubscriptionId === null,
                'A system subscription is billed by FluentCart. A remote schedule ID here means two things '
                . 'would charge the same contract.',
            );
            $this->require(
                $this->vendorCustomerId !== $methodId,
                'A customer reference and a payment method are different identifiers.',
            );
        }

        if ($this->collectionMethod === self::COLLECTION_AUTOMATIC) {
            $this->require(
                $this->vendorSubscriptionId !== null && $this->vendorSubscriptionId !== '',
                'An automatic subscription is owned by a remote schedule, so it needs that schedule\'s ID.',
            );
            $this->require(
                $this->activePaymentMethod === [],
                'A remote schedule charges its own stored mandate. Vault metadata here invites a second charge.',
            );
            $this->require(
                $this->vendorCustomerId !== $this->vendorSubscriptionId,
                'A subscription ID is not a customer ID. That substitution is the confirmed PayPal defect.',
            );
        }

        if ($this->collectionMethod === self::COLLECTION_MANUAL) {
            $this->require(
                $this->vendorCustomerId === null
                && $this->vendorPlanId === null
                && $this->vendorSubscriptionId === null
                && $this->activePaymentMethod === [],
                'A manual subscription carries no vendor mandate. Section 8.4.',
            );
        }
    }

    private function require(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \InvalidArgumentException($message);
        }
    }

    /**
     * The same decision with more to say about itself.
     *
     * Used where a strategy falls back to the manual decision and needs to keep
     * the provider reasons that caused the fallback. The outcome is the manual
     * strategy's to decide; the evidence is not.
     *
     * @param list<string> $reasonCodes
     */
    public function withReasonCodes(array $reasonCodes): self
    {
        return new self(
            $this->strategy,
            $this->outcome,
            $this->collectionMethod,
            $this->currentPaymentMethod,
            $this->nextActionOwner,
            $this->vendorCustomerId,
            $this->vendorPlanId,
            $this->vendorSubscriptionId,
            $this->activePaymentMethod,
            array_merge($this->reasonCodes, $reasonCodes),
            $this->sourceMetadataAdapter,
        );
    }

    /**
     * The same decision, but never `ready`.
     *
     * Used where a lifecycle fault was found that the operator has confirmed
     * nothing about. A cohort-wide manual confirmation covers "this customer
     * will now receive an invoice"; it does not cover "and by the way its next
     * billing date is in the past", so a receipt must not read `ready` with
     * that sitting in its reason codes.
     */
    public function requiringConfirmation(): self
    {
        if ($this->outcome !== self::OUTCOME_READY) {
            return $this;
        }

        return new self(
            $this->strategy,
            self::OUTCOME_CONFIRMATION_REQUIRED,
            $this->collectionMethod,
            $this->currentPaymentMethod,
            $this->nextActionOwner,
            $this->vendorCustomerId,
            $this->vendorPlanId,
            $this->vendorSubscriptionId,
            $this->activePaymentMethod,
            $this->reasonCodes,
            $this->sourceMetadataAdapter,
        );
    }

    /**
     * The same decision, told which source metadata contract produced it.
     */
    public function withSourceMetadataAdapter(?string $adapter): self
    {
        return new self(
            $this->strategy,
            $this->outcome,
            $this->collectionMethod,
            $this->currentPaymentMethod,
            $this->nextActionOwner,
            $this->vendorCustomerId,
            $this->vendorPlanId,
            $this->vendorSubscriptionId,
            $this->activePaymentMethod,
            $this->reasonCodes,
            $adapter,
        );
    }

    /**
     * The same decision attributed to a different strategy.
     *
     * A Stripe record that falls back to deliberate manual is still a Stripe
     * record; hiding that behind `strategy = manual` would lose the cohort a
     * report needs to group by.
     */
    public function forStrategy(string $strategy): self
    {
        return new self(
            $strategy,
            $this->outcome,
            $this->collectionMethod,
            $this->currentPaymentMethod,
            $this->nextActionOwner,
            $this->vendorCustomerId,
            $this->vendorPlanId,
            $this->vendorSubscriptionId,
            $this->activePaymentMethod,
            $this->reasonCodes,
            $this->sourceMetadataAdapter,
        );
    }

    public function isReady(): bool
    {
        return $this->outcome === self::OUTCOME_READY;
    }

    public function isBlocked(): bool
    {
        return $this->outcome === self::OUTCOME_BLOCKED;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'active_payment_method'   => $this->activePaymentMethod,
            'collection_method'       => $this->collectionMethod,
            'current_payment_method'  => $this->currentPaymentMethod,
            'next_action_owner'       => $this->nextActionOwner,
            'outcome'                 => $this->outcome,
            'reason_codes'            => $this->reasonCodes,
            'source_metadata_adapter' => $this->sourceMetadataAdapter,
            'strategy'                => $this->strategy,
            'vendor_customer_id'      => $this->vendorCustomerId,
            'vendor_plan_id'          => $this->vendorPlanId,
            'vendor_subscription_id'  => $this->vendorSubscriptionId,
        ];
    }
}

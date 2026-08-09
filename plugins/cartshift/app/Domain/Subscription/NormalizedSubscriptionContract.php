<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription;

defined('ABSPATH') || exit;

use CartShift\Support\Enums\FcBillingInterval;

/**
 * What a thing bills, how often, after what trial, for how many cycles —
 * reduced to a form both ends of a mapping decision can be compared in.
 *
 * A WooCommerce source variation arrives through the exact cadence table; a
 * FluentCart target variation arrives through its stored `repeat_interval`.
 * Once both are one of these, "may this source claim that target" is an
 * equality test rather than six ad-hoc reads spread across a resolver, a
 * controller and a Vue component.
 *
 * Deliberately built from primitives and nothing else. It holds no WooCommerce
 * object, no FluentCart model and no source record, so the mapping layer can
 * use it before the dataset layer exists and the two need never agree on
 * anything but four scalars.
 *
 * **Price is not in here, and that is the point.** Lapka's monthly cohort is
 * split PLN 29 / PLN 24 and its yearly cohort PLN 290 / PLN 240, against a
 * single catalogue price each. Those are the same contract as far as choosing
 * an entitlement variation goes; the subscriber's own amount is preserved on
 * the subscription row. A price that gated variation choice would either split
 * one plan into four or silently reprice 243 customers.
 */
final readonly class NormalizedSubscriptionContract
{
    /**
     * The source cadence has no exact FluentCart equivalent.
     *
     * One of the plan's stable contract/mapping codes (section 9.4). Blocking,
     * never a warning: `week/2` collapsed to `weekly` is a customer billed twice
     * as often as they agreed to.
     */
    public const string ERROR_UNSUPPORTED_CADENCE = 'unsupported_billing_cadence';

    /**
     * @param string $sourceCadence What the source actually said, e.g. `week/2`
     *                              or `monthly`. Only ever used to keep two
     *                              unrepresentable contracts distinct.
     */
    private function __construct(
        private ?FcBillingInterval $interval,
        private int $trialDays,
        private int $finiteCycles,
        private string $sourceCadence,
    ) {
    }

    /**
     * From WooCommerce Subscriptions' own primitives.
     *
     * @param int $trialDays    Trial length already converted to days.
     * @param int $finiteCycles Billing cycles the contract runs for; 0 is open-ended.
     */
    public static function fromWooCommerce(
        string $period,
        int $multiplier,
        int $trialDays = 0,
        int $finiteCycles = 0,
    ): self {
        return new self(
            FcBillingInterval::tryFromWooCommerce($period, $multiplier),
            max(0, $trialDays),
            max(0, $finiteCycles),
            strtolower(trim($period)) . '/' . $multiplier,
        );
    }

    /**
     * From a FluentCart product variation's stored subscription configuration.
     *
     * An interval outside the six is unrepresentable rather than rounded: a
     * variation CartShift cannot describe is one it must not silently map to.
     */
    public static function fromFluentCart(
        string $repeatInterval,
        int $trialDays = 0,
        int $finiteCycles = 0,
    ): self {
        $repeatInterval = strtolower(trim($repeatInterval));

        return new self(
            FcBillingInterval::tryFrom($repeatInterval),
            max(0, $trialDays),
            max(0, $finiteCycles),
            $repeatInterval,
        );
    }

    public function interval(): ?FcBillingInterval
    {
        return $this->interval;
    }

    public function trialDays(): int
    {
        return $this->trialDays;
    }

    public function finiteCycles(): int
    {
        return $this->finiteCycles;
    }

    public function isRepresentable(): bool
    {
        return $this->interval !== null;
    }

    /**
     * @return list<string> Empty when this contract may proceed.
     */
    public function reasonCodes(): array
    {
        return $this->isRepresentable() ? [] : [self::ERROR_UNSUPPORTED_CADENCE];
    }

    /**
     * The per-row hard gate: same billing rhythm.
     *
     * Trial and term are deliberately excluded. Section 7.2's second gate is
     * cadence alone — a target plan offering a trial the source never had is a
     * difference the operator is shown and may accept, not a mismatch. What may
     * never differ is how often the customer is charged.
     *
     * An unrepresentable contract matches nothing, including another
     * unrepresentable one: two cadences CartShift cannot express are not
     * thereby the same cadence.
     */
    public function cadenceMatches(self $other): bool
    {
        return $this->interval !== null && $this->interval === $other->interval;
    }

    /**
     * The stricter key that governs sharing one target variation.
     *
     * Section 7.3 rule 3: several source variations may claim one target only
     * when their whole normalised contract agrees — cadence, trial and finite
     * term. An unrepresentable contract keys on the raw source cadence so
     * `week/2` and `year/2` do not converge into one shareable "unsupported".
     */
    public function key(): string
    {
        return sprintf(
            '%s|trial:%d|cycles:%d',
            $this->interval?->value ?? 'unsupported:' . $this->sourceCadence,
            $this->trialDays,
            $this->finiteCycles,
        );
    }

    /**
     * @return array{interval: string|null, trial_days: int, finite_cycles: int, representable: bool, key: string}
     */
    public function toArray(): array
    {
        return [
            'interval'      => $this->interval?->value,
            'trial_days'    => $this->trialDays,
            'finite_cycles' => $this->finiteCycles,
            'representable' => $this->isRepresentable(),
            'key'           => $this->key(),
        ];
    }
}

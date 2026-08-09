<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription;

defined('ABSPATH') || exit;

/**
 * What this subscriber actually agreed to, in the source's own terms.
 *
 * `period` and `multiplier` are the exact WooCommerce Subscriptions pair.
 * `targetInterval` is the FluentCart interval it maps to under section 7.2's
 * finite cadence table, and it is nullable because that table has no fallback
 * arm: a two-month contract is not "roughly monthly", and collapsing it would
 * quietly bill somebody twice as often as they agreed to.
 *
 * The money is integer minor units, and it is the subscriber's amount rather
 * than the catalogue's. 167 Lapka subscribers pay PLN 24 for a product whose
 * current price is PLN 29; "correcting" them is not a migration.
 */
final readonly class SubscriptionContract
{
    public function __construct(
        public string $period,
        public int $multiplier,
        public string|null $targetInterval,
        public int $recurringAmount,
        public int $recurringTax,
        public int $recurringTotal,
        public int|null $finiteCycles,
        public int $trialLength,
        public string $trialPeriod,
        public int $setupFee,
        public array $sourcePlan,
    ) {}

    public function isRepresentable(): bool
    {
        return $this->targetInterval !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'finite_cycles'    => $this->finiteCycles,
            'multiplier'       => $this->multiplier,
            'period'           => $this->period,
            'recurring_amount' => $this->recurringAmount,
            'recurring_tax'    => $this->recurringTax,
            'recurring_total'  => $this->recurringTotal,
            'setup_fee'        => $this->setupFee,
            'source_plan'      => $this->sourcePlan,
            'target_interval'  => $this->targetInterval,
            'trial_length'     => $this->trialLength,
            'trial_period'     => $this->trialPeriod,
        ];
    }
}

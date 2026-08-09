<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription;

defined('ABSPATH') || exit;

/**
 * What the three counts said, and what was done about it.
 *
 * Every number is carried rather than reduced to a verdict, because the verdict
 * is the least useful part when they disagree. An operator repairing a
 * mismatched subscription needs to know that WCS counted seven, the package
 * carries six paid orders and FluentCart computed six — the difference names
 * the missing order, and "reconciliation failed" does not.
 *
 * Two of the counts describe orders and one describes billed cycles, which is
 * why there are four numbers rather than three. `includedPaidOrderCount` is the
 * raw evidence — how many parent and renewal orders came across carrying a
 * succeeded positive charge — and `correctedPaidCycleCount` is that number with
 * FluentCart's own two initial-cycle corrections applied, which is what is
 * actually comparable with a payment count. On Lapka both corrections are zero
 * on all 564 records and the two numbers are the same; on a subscription whose
 * first cycle was a free trial they differ by exactly the offset, and comparing
 * the uncorrected number would block every trial subscriber for ever.
 *
 * `generatedDates` exists to be zero. It counts next-billing dates that appeared
 * during reconciliation and were not in the source, and the only way it can
 * become non-zero is if something invoked FluentCart's lifecycle machinery —
 * `syncSubscriptionStates()` calls `guessNextBillingDate()` for any subscription
 * whose next date is empty, and 360 of the 564 preserved Lapka subscriptions
 * have an empty one. A migration that manufactures 360 billing dates has
 * manufactured 360 future card charges.
 */
final readonly class ReconciliationResult
{
    /** Section 9.4's History/cutover code. */
    public const string REASON_HISTORY_COUNT_MISMATCH = 'history_count_mismatch';

    /** The staged subscription this result is about could not be read back. */
    public const string REASON_SUBSCRIPTION_MISSING = 'required_reference_missing';

    /**
     * @param list<int>    $relatedOrderIds Every source order this history names.
     * @param list<int>    $paidOrderIds    Those carrying a succeeded positive charge.
     * @param list<string> $reasonCodes
     */
    public function __construct(
        public bool $reconciled,
        public int $subscriptionId,
        public int $sourcePaymentCount,
        public int $includedPaidOrderCount,
        public int $correctedPaidCycleCount,
        public int $fluentCartCount,
        public int $billedCyclesOffset,
        public int $billedCyclesDeduction,
        public array $relatedOrderIds,
        public array $paidOrderIds,
        public string $lifecycleStatus,
        public string|null $nextBillingDate,
        public int $generatedDates,
        public array $reasonCodes,
        public string $message,
    ) {
    }

    public function isMismatch(): bool
    {
        return in_array(self::REASON_HISTORY_COUNT_MISMATCH, $this->reasonCodes, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'billed_cycles_deduction'   => $this->billedCyclesDeduction,
            'billed_cycles_offset'      => $this->billedCyclesOffset,
            'corrected_paid_cycle_count' => $this->correctedPaidCycleCount,
            'fluent_cart_count'         => $this->fluentCartCount,
            'generated_dates'           => $this->generatedDates,
            'included_paid_order_count' => $this->includedPaidOrderCount,
            'lifecycle_status'          => $this->lifecycleStatus,
            'message'                   => $this->message,
            'next_billing_date'         => $this->nextBillingDate,
            'paid_order_ids'            => $this->paidOrderIds,
            'reason_codes'              => $this->reasonCodes,
            'reconciled'                => $this->reconciled,
            'related_order_ids'         => $this->relatedOrderIds,
            'source_payment_count'      => $this->sourcePaymentCount,
            'subscription_id'           => $this->subscriptionId,
        ];
    }
}

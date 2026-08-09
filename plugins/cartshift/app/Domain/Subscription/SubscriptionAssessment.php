<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription;

defined('ABSPATH') || exit;

use CartShift\Domain\Subscription\Payment\PaymentMigrationDecision;

/**
 * Whether this subscription may be written, and on what evidence — plan
 * section 6.3.
 *
 * Business readiness, deliberately separate from execution progress. A record
 * is `ready`, `confirmation_required`, or `blocked`; `staged` and `activated`
 * are states of the destination row and belong to the receipt, not here.
 *
 * The important property is negative and it is the reason this class exists:
 * an assessment that is not `ready` cannot be turned into a database row.
 * `SubscriptionWriter::stage()` refuses one, loudly. CartShift used to answer a
 * missing product reference by changing the status to `paused` and calling
 * `Subscription::create()` anyway — which produced a row FluentCart bills
 * against a blank line the moment somebody presses resume. Paused is a
 * lifecycle state; it has never satisfied a NOT NULL column.
 *
 * `errors` and `warnings` are lists of `{code, message}`. The code is section
 * 9.4's stable vocabulary — commands, receipts, retry logic and tests key off
 * it — and the message carries the specifics a fixed vocabulary never can:
 * which item, which reference, which subscription ID.
 *
 * The constructor is section 6.3's five parameters in order, plus `lifecycle`
 * appended. Appending rather than inserting keeps every positional call in the
 * plan's own shape valid, the same way `PaymentMigrationDecision` grew its
 * source-adapter field.
 */
final readonly class SubscriptionAssessment
{
    public const string OUTCOME_READY = 'ready';
    public const string OUTCOME_CONFIRMATION_REQUIRED = 'confirmation_required';
    public const string OUTCOME_BLOCKED = 'blocked';

    // Section 9.4's contract/mapping row, plus the one identity code CartShift
    // has always used for a subscription whose buyer never arrived.
    public const string REASON_REQUIRED_REFERENCE_MISSING = 'required_reference_missing';
    public const string REASON_MULTI_ITEM = 'multi_item_subscription';
    public const string REASON_UNSUPPORTED_CADENCE = 'unsupported_billing_cadence';
    public const string REASON_CUSTOMER_NOT_FOUND = 'customer_not_found';

    /**
     * The resolved variation is not on the resolved product.
     *
     * Section 9.3 requires this before `Subscription::save()`, in those words,
     * and it is not the same claim as "both resolved". `fct_product_variations.id`
     * is a global auto-increment with no foreign key back from
     * `fct_order_items.object_id`, the ID map has two writers
     * (`MappingPromoter` and `ProductMigrator`), and a stale mapping decision
     * or a hand-made POST can pair a product with somebody else's variant.
     * `MappingPromoter` refuses that pairing at promotion time; asserting the
     * invariant and enforcing it are different things, and the writer is where
     * section 9.3 asks for the second.
     */
    public const string REASON_VARIATION_NOT_ON_PRODUCT = 'target_variation_not_on_product';
    public const string REASON_ACTIVE_NEXT_DATE_MISSING = 'active_next_date_missing';
    public const string REASON_ACTIVE_NEXT_DATE_PAST = 'active_next_date_past';
    public const string REASON_FINITE_TERM_STATE_CONFLICT = 'finite_term_state_conflict';

    /**
     * The behaviour change was accepted, and this record took it.
     *
     * A note about a subscription that WAS written, and deliberately NOT the
     * same code as the refusal below — they were one string for a round, which
     * meant a record that staged successfully logged under "Manual renewal has
     * not been accepted" with a hint reading "Nothing was migrated", at
     * blocking severity. It also emptied the refusal's own group of its
     * meaning: a UI grouping by code got the cohort-stopping block and the
     * routine note in one pile.
     */
    public const string REASON_MANUAL_RENEWAL_ADOPTED = 'manual_renewal_adopted';

    /**
     * Ratified into section 9.4 at block severity for this implementation.
     *
     * `SubscriptionContract::$finiteCycles === null` means both "explicitly
     * unlimited" and "the subscription's own meta never said". Answering the
     * second with `bill_times = 0` tells FluentCart to bill forever, which is a
     * contract the source never expressed — and it also disarms
     * `finite_term_state_conflict`, whose check is guarded on a positive term.
     * One unanswered question deciding two gates is precisely what section 9.3
     * exists to stop, so it is refused instead.
     */
    public const string REASON_FINITE_TERM_UNDECLARED = 'finite_term_undeclared';

    /**
     * Section 9.2's fallback, taken and declared.
     *
     * "Current product metadata is fallback evidence only and must raise a
     * warning." The current product describes today's catalogue rather than
     * what a subscriber agreed to years ago, so using it is allowed and using
     * it silently is not.
     */
    public const string REASON_FINITE_TERM_FROM_PRODUCT = 'finite_term_from_product';

    /**
     * The manual behaviour change has not been accepted, so nothing was written.
     *
     * Section 9.4's payment row, and section 8.4's condition: manual output
     * "remains `confirmation_required` until the operator accepts the behaviour
     * change and the cutover receipt proves source auto-renewal was disabled".
     * Distinct from `REASON_MANUAL_RENEWAL_ADOPTED` above, which is the same
     * situation after somebody said yes.
     */
    public const string REASON_MANUAL_NOT_ACCEPTED = 'manual_confirmation_required';

    /**
     * @param list<array{code: string, message: string}> $errors
     * @param list<array{code: string, message: string}> $warnings
     * @param array<string, mixed>                       $resolvedReferences
     * @param array<string, mixed>                       $lifecycle
     */
    public function __construct(
        public string $outcome,
        public array $errors,
        public array $warnings,
        public array $resolvedReferences,
        public PaymentMigrationDecision $payment,
        public array $lifecycle = [],
    ) {
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
     * The one question the writer asks.
     *
     * `ready` and nothing else. A `confirmation_required` record is one whose
     * operator has not yet accepted something — usually that a customer WCS was
     * charging silently will now receive an invoice — and staging it anyway
     * would make the confirmation decorative.
     */
    public function isStageable(): bool
    {
        // The empty-errors clause is not redundant with the outcome. This is a
        // plain DTO, so nothing stops a caller — or a future assessor with a
        // bug in its verdict — from handing over `ready` with a failed gate
        // still listed. Reading both means the answer can only be yes when the
        // two agree.
        return $this->isReady() && $this->errors === [];
    }

    /**
     * @return list<string>
     */
    public function errorCodes(): array
    {
        return self::codes($this->errors);
    }

    /**
     * @return list<string>
     */
    public function warningCodes(): array
    {
        return self::codes($this->warnings);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'errors'              => $this->errors,
            'lifecycle'           => $this->lifecycle,
            'outcome'             => $this->outcome,
            'payment'             => $this->payment->toArray(),
            'resolved_references' => $this->resolvedReferences,
            'warnings'            => $this->warnings,
        ];
    }

    /**
     * @param list<array{code: string, message: string}> $entries
     * @return list<string>
     */
    private static function codes(array $entries): array
    {
        return array_values(array_map(
            static fn (array $entry): string => (string) ($entry['code'] ?? ''),
            $entries,
        ));
    }
}

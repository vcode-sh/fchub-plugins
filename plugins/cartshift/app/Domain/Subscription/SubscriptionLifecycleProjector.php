<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription;

defined('ABSPATH') || exit;

use CartShift\Domain\Subscription\Payment\PaymentEnvironment;
use CartShift\Support\Enums\FcSubscriptionStatus;

/**
 * What status and which dates a destination subscription is written with —
 * plan section 9.3, spelled out rather than delegated.
 *
 * FluentCart has a service that would do this: `SubscriptionService
 * ::syncSubscriptionStates()`. It must never be used here, and the reason is
 * specific rather than stylistic. It completes a finite subscription when
 * `bill_count >= bill_times`, clears its next date, and — when the next date is
 * empty — calls `guessNextBillingDate()` and writes whatever comes back. The
 * preserved Lapka source has 360 subscriptions with no next-payment date. Run
 * that service over them and CartShift reports 564 subscriptions with a
 * schedule, 360 of which it made up, each one a date some real person's card
 * would be charged on.
 *
 * So every field below is either copied from the source or left null, and the
 * five projection rules are the plan's, in the plan's order:
 *
 *  - terminal historical record with no next date: preserve status, null date;
 *  - paused/on-hold: preserve the source date exactly, including its absence;
 *  - live record with a future date: stage paused, intended status in config;
 *  - live record with a missing or past date: blocked;
 *  - finite record whose paid cycles reach its term while the source still
 *    calls it live: blocked with `finite_term_state_conflict`.
 *
 * The first three of those were pinned by Task 1 as CHARACTERISATION — "this is
 * what CartShift does today, and the plan intends to change it". This class is
 * where they stop being descriptions and become rules.
 */
final class SubscriptionLifecycleProjector
{
    public const string REASON_ACTIVE_NEXT_DATE_MISSING = SubscriptionAssessment::REASON_ACTIVE_NEXT_DATE_MISSING;
    public const string REASON_ACTIVE_NEXT_DATE_PAST = SubscriptionAssessment::REASON_ACTIVE_NEXT_DATE_PAST;
    public const string REASON_FINITE_TERM_STATE_CONFLICT = SubscriptionAssessment::REASON_FINITE_TERM_STATE_CONFLICT;
    public const string REASON_FINITE_TERM_UNDECLARED = SubscriptionAssessment::REASON_FINITE_TERM_UNDECLARED;
    public const string WARNING_FINITE_TERM_FROM_PRODUCT = SubscriptionAssessment::REASON_FINITE_TERM_FROM_PRODUCT;

    /**
     * Destination statuses that cannot bill again.
     *
     * `expiring` — WooCommerce `pending-cancel` — is deliberately absent: the
     * subscriber has cancelled but the term they paid for is still running, so
     * the record is live until it ends.
     *
     * @var list<string>
     */
    private const array TERMINAL_STATUSES = ['canceled', 'expired'];

    /**
     * Project one record's lifecycle.
     *
     * `$environment` is nullable so the projection can be taken without a
     * payment context — the mapper's own tests do exactly that. Absent one, the
     * schedule gate is simply not applied here; the assessor always supplies it,
     * and it is the assessor that decides whether a record may be written.
     *
     * @return array{
     *     status: string,
     *     intended_status: string,
     *     staged_paused: bool,
     *     terminal: bool,
     *     next_billing_date: string|null,
     *     trial_ends_at: string|null,
     *     canceled_at: string|null,
     *     expire_at: string|null,
     *     trial_days: int,
     *     bill_times: int,
     *     bill_count: int,
     *     created_at: string|null,
     *     errors: list<string>,
     *     warnings: list<string>,
     * }
     */
    public function project(SubscriptionRecord $record, ?PaymentEnvironment $environment): array
    {
        $intended = FcSubscriptionStatus::fromWooCommerce($record->status)->value;
        $terminal = in_array($intended, self::TERMINAL_STATUSES, true);

        $term      = $this->finiteTerm($record);
        $billTimes = $term['bill_times'];
        $billCount = $record->sourcePaymentCount;

        $errors   = [];
        $warnings = [];

        // Section 9.3, live-record rule. Asked of PaymentEnvironment rather
        // than answered here, because the Stripe and PayPal strategies ask the
        // same helper and two definitions of "future and plausible" would
        // eventually disagree about somebody's renewal. The manual strategy
        // attaches no schedule reason of its own — it has no opinion about
        // schedules — so without this call a manual live record with a next
        // date in 2024 would walk straight past the gate.
        if ($environment !== null && !$terminal) {
            $errors = array_merge(
                $errors,
                $environment->liveScheduleFault($record->status, $record->dates->nextPaymentUtc) ?? [],
            );
        }

        // Section 9.3, finite-term rule. A twelve-month plan with twelve
        // payments taken and a source that still calls it active is a
        // disagreement between the status and the count, and picking a winner
        // is how somebody gets billed a thirteenth time.
        if (!$terminal && $billTimes > 0 && $billCount >= $billTimes) {
            $errors[] = self::REASON_FINITE_TERM_STATE_CONFLICT;
        }

        // Section 9.2's three states, in its own order of preference.
        if ($term['source'] === SubscriptionRecordFactory::FINITE_FROM_PRODUCT) {
            $warnings[] = self::WARNING_FINITE_TERM_FROM_PRODUCT;
        }

        // And a term nobody wrote down anywhere is refused rather than answered.
        //
        // Answering it with `bill_times = 0` would be wrong twice over:
        // FluentCart reads zero as "bill forever" — a contract the source never
        // expressed — and the conflict rule immediately above is guarded on
        // `$billTimes > 0`, so the records whose term is unknown would be
        // exactly the records it could never examine. One unanswered question
        // quietly deciding two section 9.3 gates is the shape this refuses.
        if ($term['source'] === SubscriptionRecordFactory::FINITE_FROM_NOWHERE) {
            $errors[] = self::REASON_FINITE_TERM_UNDECLARED;
        }

        return [
            // Phase B stages every live record paused and Phase D activates it
            // once the source has released ownership. A terminal record has no
            // ownership to release, so it is written as what it is.
            'status'          => $terminal ? $intended : FcSubscriptionStatus::Paused->value,
            'intended_status' => $intended,
            'staged_paused'   => !$terminal,
            'terminal'        => $terminal,

            // Copied. Every one of them, nulls included.
            'next_billing_date' => $record->dates->nextPaymentUtc,
            'trial_ends_at'     => $record->dates->trialEndUtc,
            'canceled_at'       => $record->dates->cancelledUtc,
            'expire_at'         => $record->dates->endUtc,
            'created_at'        => $record->dates->startUtc,

            'trial_days' => $this->trialDays($record),
            'bill_times' => $billTimes,
            'bill_count' => $billCount,

            'errors'   => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * How many charges the destination expects, and on whose authority — plan
     * section 9.2, in its own words:
     *
     *   "Use the historical subscription's own finite length/cycle data where
     *    available; current product metadata is fallback evidence only and must
     *    raise a warning."
     *
     * Three states, in that order of preference:
     *
     *  - the subscription declared its own term, including WCS's `0` meaning
     *    unlimited — believed without comment;
     *  - the subscription is silent and its product declared one — used, and
     *    warned about, because a 2026 catalogue describes today's offer rather
     *    than what a 2021 subscriber agreed to;
     *  - nobody declared anything — refused, because `bill_times = 0` would be
     *    an answer rather than an admission.
     *
     * The last of those is not hypothetical and is in fact the Lapka shape:
     * `_subscription_length` occurs four times in the whole preserved dump,
     * on the two source products and on none of the 564 subscriptions.
     *
     * WCS stores the term in billing PERIODS, not in charges — a length of
     * twelve on a `month/3` contract is twelve months, which is four payments —
     * so `bill_times`, which counts charges, is the quotient.
     *
     * A record whose provenance is missing altogether counts as undeclared. It
     * cannot arise from either factory, but "the key was absent" must not be
     * the one path that falls through to billing for ever.
     *
     * @return array{bill_times: int, source: string}
     */
    private function finiteTerm(SubscriptionRecord $record): array
    {
        $plan   = $record->contract->sourcePlan;
        $source = (string) ($plan[SubscriptionRecordFactory::FINITE_CYCLES_SOURCE]
            ?? SubscriptionRecordFactory::FINITE_FROM_NOWHERE);

        $cycles = match ($source) {
            SubscriptionRecordFactory::FINITE_FROM_SUBSCRIPTION => $record->contract->finiteCycles,
            SubscriptionRecordFactory::FINITE_FROM_PRODUCT      => (int) ($plan[SubscriptionRecordFactory::PLAN_PRODUCT_LENGTH] ?? 0),
            default                                             => null,
        };

        if (!in_array(
            $source,
            [
                SubscriptionRecordFactory::FINITE_FROM_SUBSCRIPTION,
                SubscriptionRecordFactory::FINITE_FROM_PRODUCT,
            ],
            true,
        )) {
            $source = SubscriptionRecordFactory::FINITE_FROM_NOWHERE;
        }

        if ($cycles === null || $cycles <= 0) {
            return ['bill_times' => 0, 'source' => $source];
        }

        return [
            'bill_times' => (int) ceil($cycles / max(1, $record->contract->multiplier)),
            'source'     => $source,
        ];
    }

    /**
     * The trial length in days, from the source's own two dates.
     *
     * Derived rather than copied because FluentCart stores a day count and WCS
     * stores an end date, and derived from `start` and `trial_end` rather than
     * from the plan's trial meta because the plan describes the product and
     * these two describe this subscriber.
     */
    private function trialDays(SubscriptionRecord $record): int
    {
        $start    = $record->dates->startUtc;
        $trialEnd = $record->dates->trialEndUtc;

        if ($start === null || $trialEnd === null) {
            return 0;
        }

        $startTs = strtotime($start . ' UTC');
        $endTs   = strtotime($trialEnd . ' UTC');

        if ($startTs === false || $endTs === false) {
            return 0;
        }

        return max(0, (int) floor(($endTs - $startTs) / 86_400));
    }
}

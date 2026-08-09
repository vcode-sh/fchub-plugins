<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription;

defined('ABSPATH') || exit;

use CartShift\Storage\IdMapRepository;
use CartShift\Support\Constants;
use FluentCart\App\Models\Subscription;

/**
 * Plan section 10's algorithm, steps four to seven — and the one FluentCart
 * service it must never reach for.
 *
 * `SubscriptionService::syncSubscriptionStates()` does everything this class
 * does and three things it must not. It completes a finite subscription when
 * `bill_count >= bill_times` and clears its next date; it writes
 * `guessNextBillingDate()` into any subscription whose next date is empty; and
 * it dispatches the ordinary lifecycle events, which is right for a renewal
 * that just happened and wrong for a five-year history being replayed in an
 * afternoon. The preserved Lapka source has 360 subscriptions with no
 * next-payment date. Run that service over them and CartShift reports 564
 * schedules, 360 of which it invented, each one a date a real card would be
 * charged on. So the count comes from `Subscription::calculateBillCount()`
 * (Subscription.php:1090) directly, and the status and the dates stay exactly
 * as `SubscriptionLifecycleProjector` decided and `SubscriptionWriter` wrote
 * them.
 *
 * THREE NUMBERS ARE COMPARED, ONE IS WRITTEN. FluentCart's recomputed count,
 * WCS's own `get_payment_count()`, and the paid orders the dataset actually
 * carries. All three agreeing is the only condition under which `bill_count` is
 * set. Anything else keeps the record paused and reports both sides with the
 * order IDs, because a number chosen to make a mismatch go away is a number
 * FluentCart contradicts at the next recompute — and the difference is
 * somebody's billing history.
 *
 * IT IS RERUNNABLE. Nothing here creates a row, and `calculateBillCount()` is a
 * query. A second reconciliation of an unchanged history reaches the same
 * verdict and writes the same value, which is the point of reconciliation
 * rather than of a one-shot fixup.
 */
final class SubscriptionReconciler
{
    public function __construct(
        private readonly SubscriptionHistoryIndex $history,
        private readonly IdMapRepository $idMap,
    ) {
    }

    /**
     * Reconcile one staged subscription against its own history.
     */
    public function reconcile(
        SubscriptionRecord $source,
        int $targetSubscriptionId,
    ): ReconciliationResult {
        $relatedOrderIds = $this->history->relatedOrderIds($source);
        $paidOrderIds    = $this->history->paidOrderIds($source);
        $corrections     = SubscriptionHistoryLinker::corrections($source, $this->history);

        // The evidence, in the same units as a payment count. FluentCart's two
        // initial-cycle corrections translate "orders carrying a charge" into
        // "billed cycles": a free trial cycle is a cycle with no charge behind
        // it, and a signup-fee-only charge is a charge that is not a cycle.
        // Comparing the uncorrected number would block every trial subscriber
        // by construction, and Lapka's corrections are zero, so this changes
        // nothing there and makes the arithmetic honest elsewhere.
        $corrected = count($paidOrderIds)
            + $corrections[SubscriptionHistoryLinker::META_BILLED_CYCLES_OFFSET]
            - $corrections[SubscriptionHistoryLinker::META_BILLED_CYCLES_DEDUCTION];

        $subscription = Subscription::query()->find($targetSubscriptionId);

        if ($subscription === null) {
            return new ReconciliationResult(
                reconciled: false,
                subscriptionId: $targetSubscriptionId,
                sourcePaymentCount: $source->sourcePaymentCount,
                includedPaidOrderCount: count($paidOrderIds),
                correctedPaidCycleCount: $corrected,
                fluentCartCount: 0,
                billedCyclesOffset: $corrections[SubscriptionHistoryLinker::META_BILLED_CYCLES_OFFSET],
                billedCyclesDeduction: $corrections[SubscriptionHistoryLinker::META_BILLED_CYCLES_DEDUCTION],
                relatedOrderIds: $relatedOrderIds,
                paidOrderIds: $paidOrderIds,
                lifecycleStatus: '',
                nextBillingDate: null,
                generatedDates: 0,
                reasonCodes: [ReconciliationResult::REASON_SUBSCRIPTION_MISSING],
                message: sprintf(
                    'Subscription WC-#%d has no FluentCart row #%d to reconcile against, so nothing was '
                    . 'counted. Stage it first.',
                    $source->sourceSubscriptionId,
                    $targetSubscriptionId,
                ),
            );
        }

        // Read before, compared after. This is the whole zero-generated-dates
        // proof: if any code path below reached FluentCart's lifecycle
        // machinery, an empty date would come back populated and the count
        // would be one rather than zero.
        $dateBefore = self::dateOf($subscription);

        // Step 4. Directly, and never through syncSubscriptionStates().
        $fluentCartCount = (int) $subscription->calculateBillCount();

        // Step 5.
        $sourceCount = $source->sourcePaymentCount;
        $agree       = $fluentCartCount === $sourceCount && $fluentCartCount === $corrected;

        if ($agree) {
            // Step 6: only `bill_count`. The status and the five dates are the
            // ones the projector decided and the writer wrote; re-deriving them
            // here would be a second opinion about a question already answered,
            // and the second opinion is where invented dates come from.
            if ((int) ($subscription->bill_count ?? 0) !== $fluentCartCount) {
                $subscription->bill_count = $fluentCartCount;
                $subscription->save();
            }
        }

        $dateAfter = self::dateOf($subscription);

        return new ReconciliationResult(
            reconciled: $agree,
            subscriptionId: $targetSubscriptionId,
            sourcePaymentCount: $sourceCount,
            includedPaidOrderCount: count($paidOrderIds),
            correctedPaidCycleCount: $corrected,
            fluentCartCount: $fluentCartCount,
            billedCyclesOffset: $corrections[SubscriptionHistoryLinker::META_BILLED_CYCLES_OFFSET],
            billedCyclesDeduction: $corrections[SubscriptionHistoryLinker::META_BILLED_CYCLES_DEDUCTION],
            relatedOrderIds: $relatedOrderIds,
            paidOrderIds: $paidOrderIds,
            lifecycleStatus: (string) ($subscription->status ?? ''),
            nextBillingDate: $dateAfter,
            generatedDates: $dateBefore === null && $dateAfter !== null ? 1 : 0,
            reasonCodes: $agree ? [] : [ReconciliationResult::REASON_HISTORY_COUNT_MISMATCH],
            message: $agree
                ? $this->agreementMessage($source, $fluentCartCount)
                : $this->mismatchMessage($source, $sourceCount, $corrected, $fluentCartCount, $relatedOrderIds),
        );
    }

    /**
     * The destination rows behind a list of source order IDs, for an operator
     * who has to go and look at them.
     *
     * A mismatch is repaired by comparing two databases, and "source order
     * 880501" is only half an address when the other half is a `fct_orders` row
     * with a different number on it.
     *
     * @param list<int> $sourceOrderIds
     * @return list<string>
     */
    private function destinationOrders(array $sourceOrderIds): array
    {
        $pairs = [];

        foreach ($sourceOrderIds as $sourceOrderId) {
            $fcId = $this->idMap->getFcId(Constants::ENTITY_ORDER, (string) $sourceOrderId);

            $pairs[] = $fcId === null
                ? sprintf('%d (not imported)', $sourceOrderId)
                : sprintf('%d -> FC #%d', $sourceOrderId, $fcId);
        }

        return $pairs;
    }

    private function agreementMessage(SubscriptionRecord $source, int $count): string
    {
        return sprintf(
            'Subscription WC-#%d reconciled at %d paid cycle(s): the source, the imported history and '
            . 'FluentCart all agree.',
            $source->sourceSubscriptionId,
            $count,
        );
    }

    /**
     * @param list<int> $relatedOrderIds
     */
    private function mismatchMessage(
        SubscriptionRecord $source,
        int $sourceCount,
        int $paidCount,
        int $fluentCartCount,
        array $relatedOrderIds,
    ): string {
        return sprintf(
            'Subscription WC-#%d was left paused: WooCommerce Subscriptions counted %d payment(s), the '
            . 'imported history carries %d paid order(s), and FluentCart computed %d. Nothing was written '
            . 'to bill_count — a number picked here would be overwritten the next time FluentCart '
            . 'recounted. Related source orders: %s.',
            $source->sourceSubscriptionId,
            $sourceCount,
            $paidCount,
            $fluentCartCount,
            $relatedOrderIds === []
                ? 'none'
                : implode(', ', $this->destinationOrders($relatedOrderIds)),
        );
    }

    /**
     * The stored next-billing date as a string, or null.
     *
     * An empty string is treated as null on purpose: `fct_subscriptions
     * .next_billing_date` is nullable, but a source row that arrived through a
     * different path may hold `''`, and "no date" is one condition however it
     * is spelled.
     */
    private static function dateOf(object $subscription): string|null
    {
        $date = $subscription->next_billing_date ?? null;

        if (!is_string($date) || trim($date) === '') {
            return null;
        }

        return $date;
    }
}

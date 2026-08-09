<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription;

defined('ABSPATH') || exit;

use CartShift\Domain\Migration\OrderIdentity;
use CartShift\Storage\IdMapRepository;
use CartShift\Support\Constants;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;

/**
 * The column FluentCart actually counts, filled in after the subscription
 * exists.
 *
 * `Subscription::calculateBillCount()` (FluentCart 1.6.0, Subscription.php:1090)
 * counts `fct_order_transactions` rows where `subscription_id` is this
 * subscription, `transaction_type` is `charge`, `status` is `succeeded` and
 * `total > 0`. CartShift has never set that column, so every migrated
 * subscription computed zero and the copied `bill_count` was overwritten the
 * first time FluentCart recomputed — the plan's P1, and the reason a shop owner
 * sees a five-year subscriber with no payment history.
 *
 * The link cannot happen at import time. Section 6.2 fixes the order — orders,
 * then paused subscriptions, then the links — because a transaction cannot
 * reference an ID the subscription does not have yet. So this runs third.
 *
 * IT ALSO STAMPS THE TWO CORRECTIONS, AND ONLY FROM SOURCE EVIDENCE.
 * `calculateBillCount()` reads `billed_cycles_offset` and
 * `billed_cycles_deduction` from subscription meta, and FluentCart writes them
 * exactly once, at checkout, in `CheckoutProcessor::syncInitialCycleCounting()`.
 * A migration has to reproduce that decision from what the source recorded,
 * because there was no checkout. Both default to zero, both require explicit
 * evidence, and neither is ever reached for to make a mismatched count agree —
 * that is what section 10 step 7 is for. Lapka has no trials and no setup fees,
 * so the deduction is zero on all 564 records; the offset is 1 on the 230 whose
 * parent order settled for nothing, which is a fact about those orders and not
 * a concession to the count.
 */
final class SubscriptionHistoryLinker
{
    /** @see \FluentCart\App\Helpers\CheckoutProcessor::syncInitialCycleCounting() */
    public const string META_BILLED_CYCLES_OFFSET = 'billed_cycles_offset';
    public const string META_BILLED_CYCLES_DEDUCTION = 'billed_cycles_deduction';

    public function __construct(
        private readonly IdMapRepository $idMap,
    ) {
    }

    /**
     * Attach this subscription's succeeded positive charges to it.
     *
     * Refunded and failed history keeps its own status and is not touched: a
     * failed renewal is part of what happened, and a transaction that never
     * succeeded is not a paid cycle whatever column it carries.
     *
     * @param array<int, int> $importedOrders Source order ID => FluentCart order ID.
     *
     * @return array{
     *     linked: list<int>,
     *     corrections: array{billed_cycles_offset: int, billed_cycles_deduction: int},
     * }
     */
    public function link(
        SubscriptionRecord $record,
        SubscriptionHistoryIndex $history,
        int $subscriptionId,
        array $importedOrders,
    ): array {
        $linked = [];

        foreach ($history->history($record) as $entry) {
            $order        = $entry['order'];
            $orderType    = $history->fluentCartOrderType($order->sourceOrderId);
            $fcOrderId    = $importedOrders[$order->sourceOrderId] ?? null;

            // Only parent and renewal charges are paid cycles of THIS
            // subscription. A switch or resubscribe order is a real purchase
            // with a real charge, and linking it would add a cycle the
            // subscriber never renewed.
            if ($orderType === null || $fcOrderId === null) {
                continue;
            }

            foreach ($order->transactions as $index => $transaction) {
                if (!self::isCountableCharge($transaction)) {
                    continue;
                }

                $fcTransactionId = $this->idMap->getFcId(
                    Constants::ENTITY_ORDER_TRANSACTION,
                    OrderIdentity::transactionKey($order->sourceOrderId, (int) $index),
                );

                if ($fcTransactionId === null) {
                    continue;
                }

                if ($this->attach($fcTransactionId, $subscriptionId, $orderType)) {
                    $linked[] = $fcTransactionId;
                }
            }
        }

        $corrections = self::corrections($record, $history);

        $this->stamp($subscriptionId, $corrections);

        return ['linked' => $linked, 'corrections' => $corrections];
    }

    /**
     * The two count corrections, derived from the source and nothing else.
     *
     * FluentCart's own conditions, from `syncInitialCycleCounting()`, restated
     * against what a WooCommerce Subscriptions record actually carries:
     *
     *  - OFFSET — a free first cycle. The parent order settled and took no
     *    money. That cycle was consumed and produced no `total > 0` transaction,
     *    so without the offset the count is one short for the whole life of the
     *    subscription.
     *  - DEDUCTION — a signup-fee-only charge. The source declares a trial AND a
     *    setup fee, and the parent order's charge is exactly that fee. It is a
     *    `total > 0` transaction linked to the subscription and it is not a
     *    billed cycle, so without the deduction the count is one long.
     *
     * TWO ROUTES TO THE OFFSET, ONE MEANING. The first is FluentCart's own
     * trial metadata — `syncInitialCycleCounting()` reads a declared trial, a
     * zero setup fee and a finite term, and that arm is unchanged. The second
     * is the evidence itself: a paid parent order with a zero total IS a
     * consumed free cycle whether or not WCS meta says the word "trial", and
     * `OrderRecord::isConsumedFreeCycle()` is where that is decided. The Lapka
     * source has 230 of them and no trial metadata anywhere, which is precisely
     * the case the metadata route cannot see.
     *
     * The evidence route is deliberately NOT gated on a finite term. The term
     * gate exists because a correction that changes no decision is noise, and
     * on an open-ended subscription `bill_times` is zero so nothing compares the
     * count against a term. That was true when the only consumer was FluentCart;
     * it is not true now. `DatasetClosureValidator::checkPaymentCount()` and
     * `SubscriptionReconciler` both compare the corrected count against WCS's
     * `get_payment_count()` on every subscription, finite or not, and on the
     * open-ended ones the correction is the difference between a reconciled
     * record and a blocked one.
     *
     * WHAT NEITHER ROUTE WILL DO is fire on a parent order that was never paid.
     * No settlement, no consumed cycle, and an offset there would invent a
     * billing period that never happened — which is the exact forgery section 10
     * spends a page forbidding, and the reason a mismatch is allowed to stand
     * with diagnostics rather than be closed with a number.
     *
     * @return array{billed_cycles_offset: int, billed_cycles_deduction: int}
     */
    public static function corrections(
        SubscriptionRecord $record,
        SubscriptionHistoryIndex $history,
    ): array {
        $contract = $record->contract;
        $parent   = $history->order($record->parentOrderId);

        if ($parent === null) {
            return [self::META_BILLED_CYCLES_OFFSET => 0, self::META_BILLED_CYCLES_DEDUCTION => 0];
        }

        // Route two, from evidence alone: the parent settled and took nothing.
        // This subsumes route one's offer arm — a declared trial with a zero
        // setup fee produces exactly this order — so the trial metadata is only
        // still load-bearing for the deduction below.
        if ($parent->isConsumedFreeCycle()) {
            return [self::META_BILLED_CYCLES_OFFSET => 1, self::META_BILLED_CYCLES_DEDUCTION => 0];
        }

        $hasTrial   = $contract->trialLength > 0 && $record->dates->trialEndUtc !== null;
        $finiteTerm = $contract->finiteCycles !== null && $contract->finiteCycles > 0;

        if (!$hasTrial || !$finiteTerm) {
            return [self::META_BILLED_CYCLES_OFFSET => 0, self::META_BILLED_CYCLES_DEDUCTION => 0];
        }

        // A signup fee charged on its own: the parent's charge is the fee, and
        // the recurring item has not been billed yet.
        if ($contract->setupFee > 0 && self::chargeTotal($parent) === $contract->setupFee) {
            return [self::META_BILLED_CYCLES_OFFSET => 0, self::META_BILLED_CYCLES_DEDUCTION => 1];
        }

        return [self::META_BILLED_CYCLES_OFFSET => 0, self::META_BILLED_CYCLES_DEDUCTION => 0];
    }

    /**
     * Assert the computed correction in BOTH directions.
     *
     * Writing only the positives was a one-way ratchet, and a reachable one.
     * Section 9.3 explicitly contemplates an operator correcting the source and
     * re-exporting: a `billed_cycles_offset` written by an earlier run against
     * bad trial data would then survive the correction for ever, and FluentCart
     * adds it to every `calculateBillCount()` from then on — a permanent extra
     * paid cycle, and on a finite plan a customer who stops being billed a month
     * early. So a correction that is now zero is deleted rather than skipped.
     *
     * Deleted rather than written as an explicit zero: zero is FluentCart's own
     * default (`getMeta('billed_cycles_offset', 0)`), so a stored zero would be
     * a meta row per migrated subscription saying exactly what its absence says.
     * `Subscription::deleteMeta()` is FluentCart 1.6.0's own — Subscription.php:590
     * — and is what `CheckoutProcessor::syncInitialCycleCounting()` calls in the
     * same situation.
     *
     * @param array{billed_cycles_offset: int, billed_cycles_deduction: int} $corrections
     */
    private function stamp(int $subscriptionId, array $corrections): void
    {
        $subscription = Subscription::query()->find($subscriptionId);

        if ($subscription === null) {
            return;
        }

        foreach ($corrections as $key => $value) {
            if ($value > 0) {
                $subscription->updateMeta($key, $value);

                continue;
            }

            if ((int) $subscription->getMeta($key, 0) !== 0) {
                $subscription->deleteMeta($key);
            }
        }
    }

    /**
     * Set the two columns, and say whether this charge is attached.
     *
     * It answers "is it linked", not "did I change it" — true for a transaction
     * that was already correct as well as for one this call fixed, false only
     * when the row could not be found. That is the useful question for the
     * caller: `link()` returns the linked charges so an operator can be told how
     * many of a subscription's history are attached, and a rerun that changed
     * nothing must not report zero.
     *
     * The write itself is a set-if-different on two columns of one row, guarded
     * by an identity check, so a second run over an unchanged history issues no
     * UPDATE at all. That is what makes reconciliation rerunnable rather than
     * merely repeatable — and it is a property of the comparison below, not of
     * this return value.
     */
    private function attach(int $fcTransactionId, int $subscriptionId, string $orderType): bool
    {
        $transaction = OrderTransaction::query()->find($fcTransactionId);

        if ($transaction === null) {
            return false;
        }

        $changed = false;

        if ((int) ($transaction->subscription_id ?? 0) !== $subscriptionId) {
            $transaction->subscription_id = $subscriptionId;
            $changed                      = true;
        }

        if ((string) ($transaction->order_type ?? '') !== $orderType) {
            $transaction->order_type = $orderType;
            $changed                 = true;
        }

        if ($changed) {
            $transaction->save();
        }

        return true;
    }

    /**
     * The three conditions `calculateBillCount()` applies, in one place.
     *
     * @param array<string, mixed> $transaction
     */
    private static function isCountableCharge(array $transaction): bool
    {
        return (string) ($transaction['type'] ?? '') === 'charge'
            && (string) ($transaction['status'] ?? '') === 'succeeded'
            && (int) ($transaction['total'] ?? 0) > 0;
    }

    private static function chargeTotal(OrderRecord $order): int
    {
        $total = 0;

        foreach ($order->transactions as $transaction) {
            if (self::isCountableCharge($transaction)) {
                $total += (int) $transaction['total'];
            }
        }

        return $total;
    }
}

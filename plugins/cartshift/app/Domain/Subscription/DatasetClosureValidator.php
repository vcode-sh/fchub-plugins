<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription;

defined('ABSPATH') || exit;

/**
 * Does this dataset actually contain everything it needs to be imported?
 *
 * Section 6.2's rules, one method each. The bar is deliberately high because
 * the alternative has already been tried: the original package carried
 * subscription lines with numeric parent and renewal references and nothing
 * behind them, which cannot execute the import phase at all. A reference is not
 * an order. A phase that promises to find the missing payloads later is
 * promising clairvoyance.
 *
 * The validator materialises the stream — it indexes records by reference and
 * needs to see all of them before it can answer. A source may still stream into
 * it; what it may not do is stream a dataset so large that it cannot be
 * checked, and then call the absence of a check a pass.
 */
final class DatasetClosureValidator
{
    /**
     * @param iterable<CustomerRecord|ProductRecord|OrderRecord|SubscriptionRecord|InvalidSourceRecord> $records
     */
    public function validate(DatasetManifest $manifest, iterable $records): ClosureReport
    {
        $customers     = [];
        $products      = [];
        $orders        = [];
        $subscriptions = [];
        $invalid       = [];

        $counts      = array_fill_keys(DatasetManifest::KINDS, 0);
        $fingerprints = [];
        $failures    = [];

        foreach ($records as $record) {
            // A record type this validator does not know cannot be checked, and
            // must not be waved through as whatever the last arm happened to be.
            // The previous `default => $subscriptions[] = $record` meant any
            // future record class silently became a subscription, and a foreign
            // object would have fatalled on the property reads below.
            if (!self::isKnownRecord($record)) {
                $failures[] = self::failure(
                    ClosureReport::CODE_INVALID_SOURCE_RECORD,
                    $manifest->sourceKey,
                    'unknown',
                    'unknown',
                    ['record_type' => get_debug_type($record)],
                );

                continue;
            }

            // An invalid record counts under the kind it failed to become. Drop
            // it from the total and 564 quietly becomes 563, and a run that
            // skipped a live subscription looks complete.
            $kind = $record instanceof InvalidSourceRecord ? $record->entityKind : $record->kind();

            if (array_key_exists($kind, $counts)) {
                $counts[$kind]++;
            }

            // A dataset carrying records from a source other than the one its
            // manifest names is not the dataset it claims to be. Flagged rather
            // than discarded, and still indexed under its own source key, so the
            // result is one failure per foreign record instead of a cascade of
            // consequential missing-dependency ones.
            if ($record->sourceKey !== $manifest->sourceKey) {
                $failures[] = self::failure(
                    ClosureReport::CODE_FOREIGN_SOURCE_KEY,
                    $record->sourceKey,
                    $kind,
                    $record->sourceRef,
                    ['manifest_source_key' => $manifest->sourceKey],
                );
            }

            $referenceKey = self::scoped($record->sourceKey, $kind . '|' . $record->sourceRef);

            if (isset($fingerprints[$referenceKey]) && $fingerprints[$referenceKey] !== $record->fingerprint) {
                $failures[] = self::failure(
                    ClosureReport::CODE_DUPLICATE_REFERENCE,
                    $record->sourceKey,
                    $kind,
                    $record->sourceRef,
                    [
                        'first_fingerprint'  => $fingerprints[$referenceKey],
                        'second_fingerprint' => $record->fingerprint,
                    ],
                );
            }

            $fingerprints[$referenceKey] ??= $record->fingerprint;

            // Every index is keyed by (sourceKey, id). Section 6.1: "References
            // use (sourceKey, kind, sourceRef), not bare integers." Keyed on the
            // integer alone, a `lapka-klub` subscription whose parent order is
            // 42 resolves against `local`'s order 42 and passes closure — which
            // is the exact cross-site collision the source_key column was added
            // to the schema to prevent, reintroduced one layer up.
            match (true) {
                $record instanceof InvalidSourceRecord => $invalid[] = $record,
                $record instanceof CustomerRecord
                    => $customers[self::scoped($record->sourceKey, $record->sourceRef)] ??= $record,
                $record instanceof ProductRecord
                    => $products[self::scoped($record->sourceKey, $record->sourceProductId)] ??= $record,
                $record instanceof OrderRecord
                    => $orders[self::scoped($record->sourceKey, $record->sourceOrderId)] ??= $record,
                $record instanceof SubscriptionRecord => $subscriptions[] = $record,
                // Unreachable: isKnownRecord() above is the exhaustiveness check,
                // and match(true) needs an arm rather than an UnhandledMatchError.
                default                               => null,
            };
        }

        foreach ($invalid as $record) {
            $failures[] = self::failure(
                ClosureReport::CODE_INVALID_SOURCE_RECORD,
                $record->sourceKey,
                $record->entityKind,
                $record->sourceRef,
                ['reason_codes' => $record->reasonCodes],
            );
        }

        $failures = array_merge(
            $failures,
            self::checkCounts($manifest, $counts),
            self::checkOrders($orders, $products),
            self::checkSubscriptions($subscriptions, $customers, $products, $orders),
        );

        return new ClosureReport($failures, $counts);
    }

    /**
     * @param array<string, int> $counts
     * @return list<array<string, mixed>>
     */
    private static function checkCounts(DatasetManifest $manifest, array $counts): array
    {
        $failures = [];

        foreach ($counts as $kind => $decoded) {
            if ($manifest->countFor($kind) === $decoded) {
                continue;
            }

            $failures[] = self::failure(
                ClosureReport::CODE_COUNT_MISMATCH,
                $manifest->sourceKey,
                $kind,
                'manifest',
                ['declared' => $manifest->countFor($kind), 'decoded' => $decoded],
            );
        }

        return $failures;
    }

    /**
     * @param array<string, OrderRecord>   $orders   Keyed by (sourceKey, order ID).
     * @param array<string, ProductRecord> $products Keyed by (sourceKey, product ID).
     * @return list<array<string, mixed>>
     */
    private static function checkOrders(array $orders, array $products): array
    {
        $failures = [];

        foreach ($orders as $order) {
            foreach ($order->productClaims() as $claim) {
                $product = $products[self::scoped($order->sourceKey, $claim['source_product_id'])] ?? null;

                if ($product !== null && $product->hasVariation($claim['pseudo_variation_key'])) {
                    continue;
                }

                $failures[] = self::failure(
                    ClosureReport::CODE_MISSING_PRODUCT,
                    $order->sourceKey,
                    OrderRecord::KIND,
                    $order->sourceRef,
                    $claim,
                );
            }

            // FluentCart recomputes bill_count from succeeded positive charge
            // transactions. A paid order with a positive total and none of them
            // contributes nothing, so the count silently disagrees with the
            // source for ever.
            if ($order->isPaid() && $order->total() > 0 && $order->succeededChargeCount() === 0) {
                $failures[] = self::failure(
                    ClosureReport::CODE_MISSING_TRANSACTION,
                    $order->sourceKey,
                    OrderRecord::KIND,
                    $order->sourceRef,
                    ['source_order_id' => $order->sourceOrderId, 'total' => $order->total()],
                );
            }
        }

        return $failures;
    }

    /**
     * @param list<SubscriptionRecord>     $subscriptions
     * @param array<string, CustomerRecord> $customers Keyed by (sourceKey, customer ref).
     * @param array<string, ProductRecord>  $products  Keyed by (sourceKey, product ID).
     * @param array<string, OrderRecord>    $orders    Keyed by (sourceKey, order ID).
     * @return list<array<string, mixed>>
     */
    private static function checkSubscriptions(
        array $subscriptions,
        array $customers,
        array $products,
        array $orders,
    ): array {
        $failures       = [];
        $parentOrderUse = [];

        foreach ($subscriptions as $subscription) {
            $ref = $subscription->sourceRef;

            if (!isset($customers[self::scoped($subscription->sourceKey, $subscription->sourceCustomerRef)])) {
                $failures[] = self::failure(
                    ClosureReport::CODE_MISSING_CUSTOMER,
                    $subscription->sourceKey,
                    SubscriptionRecord::KIND,
                    $ref,
                    ['source_customer_ref' => $subscription->sourceCustomerRef],
                );
            }

            foreach ($subscription->items as $item) {
                $product = $products[self::scoped(
                    $subscription->sourceKey,
                    (int) $item['source_product_id'],
                )] ?? null;

                if ($product !== null && $product->hasVariation((string) $item['pseudo_variation_key'])) {
                    continue;
                }

                $failures[] = self::failure(
                    ClosureReport::CODE_MISSING_PRODUCT,
                    $subscription->sourceKey,
                    SubscriptionRecord::KIND,
                    $ref,
                    [
                        'source_product_id'    => (int) $item['source_product_id'],
                        'pseudo_variation_key' => (string) $item['pseudo_variation_key'],
                    ],
                );
            }

            if (!isset($orders[self::scoped($subscription->sourceKey, $subscription->parentOrderId)])) {
                $failures[] = self::failure(
                    ClosureReport::CODE_MISSING_PARENT_ORDER,
                    $subscription->sourceKey,
                    SubscriptionRecord::KIND,
                    $ref,
                    ['source_order_id' => $subscription->parentOrderId],
                );
            }

            // Scoped, like every other index here. Order 42 on the club site and
            // order 42 on the shop site are two orders, and blocking both
            // subscriptions for "sharing" one would be the same bare-integer
            // mistake in the opposite direction.
            $parentOrderUse[self::scoped($subscription->sourceKey, $subscription->parentOrderId)][]
                = [$subscription->sourceKey, $ref, $subscription->parentOrderId];

            $failures = array_merge($failures, self::checkRelatedOrders($subscription, $orders));
            $failures = array_merge($failures, self::checkPaymentCount($subscription, $orders));
        }

        foreach ($parentOrderUse as $claimants) {
            if (count($claimants) < 2) {
                continue;
            }

            sort($claimants);
            $claimedBy    = array_column($claimants, 1);
            $parentOrderId = $claimants[0][2];

            // FluentCart's renewal service assumes one subscription per parent
            // order. Allocating a shared one is outside this implementation, and
            // picking a winner silently is worse than stopping. Every claimant
            // is named, on both sides: an operator repairing this needs to know
            // which subscriptions are arguing, not just that two of them are.
            foreach ($claimants as [$sourceKey, $claimant]) {
                $failures[] = self::failure(
                    ClosureReport::CODE_SHARED_PARENT_ORDER,
                    $sourceKey,
                    SubscriptionRecord::KIND,
                    $claimant,
                    ['source_order_id' => $parentOrderId, 'claimed_by' => $claimedBy],
                );
            }
        }

        return $failures;
    }

    /**
     * @param array<string, OrderRecord> $orders Keyed by (sourceKey, order ID).
     * @return list<array<string, mixed>>
     */
    private static function checkRelatedOrders(SubscriptionRecord $subscription, array $orders): array
    {
        $failures         = [];
        $relationshipsById = [];

        foreach ($subscription->relatedOrders as $reference) {
            $relationshipsById[$reference->sourceOrderId][] = $reference->relationship;

            if (isset($orders[self::scoped($subscription->sourceKey, $reference->sourceOrderId)])) {
                continue;
            }

            $failures[] = self::failure(
                ClosureReport::CODE_MISSING_RELATED_ORDER,
                $subscription->sourceKey,
                SubscriptionRecord::KIND,
                $subscription->sourceRef,
                ['source_order_id' => $reference->sourceOrderId, 'relationship' => $reference->relationship],
            );
        }

        foreach ($relationshipsById as $orderId => $relationships) {
            $distinct = array_values(array_unique($relationships));

            if (count($distinct) < 2) {
                continue;
            }

            sort($distinct);

            $failures[] = self::failure(
                ClosureReport::CODE_AMBIGUOUS_ORDER_RELATIONSHIP,
                $subscription->sourceKey,
                SubscriptionRecord::KIND,
                $subscription->sourceRef,
                ['source_order_id' => $orderId, 'relationships' => $distinct],
            );
        }

        return $failures;
    }

    /**
     * WCS says how many payments it took; the included history says how many
     * CartShift can prove. Section 6.2 requires any disagreement to be explicit,
     * because the alternative is writing whichever number is handier into
     * `bill_count` and letting FluentCart contradict it at the next sync.
     *
     * The evidence is corrected before it is compared, exactly as
     * `SubscriptionReconciler` corrects it: a parent order that settled for
     * nothing is a cycle WCS counts and FluentCart structurally cannot see, and
     * `billed_cycles_offset` is the translation between the two questions. One
     * definition, on `OrderRecord::isConsumedFreeCycle()`, used by both — two
     * definitions of "a paid cycle" would eventually disagree about somebody's
     * bill count and surface months later as a subscription that stopped
     * billing early.
     *
     * The correction is bounded at one per subscription and comes from the
     * parent order alone, so it can close an off-by-one and cannot close an
     * off-by-three. That is the point rather than a limitation: a residual gap
     * keeps its `history_count_mismatch` and its diagnostics, which is what
     * section 10 step 7 asks for.
     *
     * @param array<string, OrderRecord> $orders Keyed by (sourceKey, order ID).
     * @return list<array<string, mixed>>
     */
    private static function checkPaymentCount(SubscriptionRecord $subscription, array $orders): array
    {
        $chargeable = array_unique(array_merge(
            [$subscription->parentOrderId],
            $subscription->relatedOrderIds(SubscriptionOrderReference::PARENT),
            $subscription->relatedOrderIds(SubscriptionOrderReference::RENEWAL),
        ));

        $evidence = 0;

        foreach ($chargeable as $orderId) {
            $order = $orders[self::scoped($subscription->sourceKey, $orderId)] ?? null;

            if ($order !== null && $order->succeededChargeCount() > 0) {
                $evidence++;
            }
        }

        $parent = $orders[self::scoped($subscription->sourceKey, $subscription->parentOrderId)] ?? null;
        $offset = $parent !== null && $parent->isConsumedFreeCycle() ? 1 : 0;

        if ($evidence + $offset === $subscription->sourcePaymentCount) {
            return [];
        }

        // `included_paid_orders` keeps meaning what it has always meant — the
        // orders that actually carried a charge — and the correction is reported
        // beside it rather than folded into it. An operator repairing a mismatch
        // needs to see both numbers and the arithmetic between them.
        return [self::failure(
            ClosureReport::CODE_HISTORY_COUNT_MISMATCH,
            $subscription->sourceKey,
            SubscriptionRecord::KIND,
            $subscription->sourceRef,
            [
                'source_payment_count' => $subscription->sourcePaymentCount,
                'included_paid_orders' => $evidence,
                'billed_cycles_offset' => $offset,
            ],
        )];
    }

    /**
     * The composite key every index in this class is built on.
     *
     * Section 6.1: references are `(sourceKey, kind, sourceRef)`, never a bare
     * integer. The `|` is safe as a separator because a source key is an
     * operator-supplied slug.
     */
    private static function scoped(string $sourceKey, int|string $reference): string
    {
        return $sourceKey . '|' . $reference;
    }

    private static function isKnownRecord(mixed $record): bool
    {
        return $record instanceof CustomerRecord
            || $record instanceof ProductRecord
            || $record instanceof OrderRecord
            || $record instanceof SubscriptionRecord
            || $record instanceof InvalidSourceRecord;
    }

    /**
     * A failure, with its context canonicalised.
     *
     * `canonicalise()` rather than `sortDeep()`: the report is JSON-encoded for
     * `--format=json` and fingerprinted, and a context carrying bytes copied out
     * of a mangled source field would otherwise make the whole report
     * unencodable — or, before the fix, hash to a constant.
     *
     * @param array<string, mixed> $context
     * @return array{code: string, source_key: string, kind: string, source_ref: string, context: array<string, mixed>}
     */
    private static function failure(
        string $code,
        string $sourceKey,
        string $kind,
        string $sourceRef,
        array $context,
    ): array {
        return [
            'code'       => $code,
            'source_key' => SubscriptionRecordFactory::textOrMarker($sourceKey),
            'kind'       => SubscriptionRecordFactory::textOrMarker($kind),
            'source_ref' => SubscriptionRecordFactory::textOrMarker($sourceRef),
            'context'    => SubscriptionRecordFactory::canonicalise($context),
        ];
    }
}

<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription;

defined('ABSPATH') || exit;

/**
 * One dataset's order history, indexed by the two questions the import phase
 * asks of it: what payload does this reference have, and what relationship does
 * it hold to the subscription that named it.
 *
 * Both answers come from the records themselves, which is the whole point.
 *
 * THE RELATIONSHIP IS NEVER `post_parent`. WooCommerce Subscriptions renewal
 * orders carry no useful parent link — plan section 4.8 — so a reader that
 * infers the relationship from one finds nothing and maps 4,702 renewals as
 * ordinary checkouts. `SubscriptionOrderReference` already carries the type,
 * because `WooDatasetRecordFactory` takes it from four separate typed
 * `get_related_orders()` calls rather than from the one flattened call that
 * throws the label away.
 *
 * A REFERENCE IS NOT AN ORDER. Section 6.2 requires the actual `OrderRecord`,
 * with its items and its transactions, for every typed reference. So
 * `missingReferences()` exists and is asked BEFORE anything is written: a
 * package that names order 880501 without carrying it fails closure, rather
 * than importing half a subscriber's history and promising to discover the
 * rest later by clairvoyance.
 *
 * Every index is keyed by `(sourceKey, orderId)` for the same reason
 * `DatasetClosureValidator`'s are: order 42 on the club site and order 42 on
 * the shop site are two different orders, and a bare integer cannot tell them
 * apart.
 */
final class SubscriptionHistoryIndex
{
    /** Section 9.4's dataset codes, as this class reports them. */
    public const string CODE_MISSING_PARENT_ORDER = 'dataset_missing_parent_order';
    public const string CODE_MISSING_RELATED_ORDER = 'dataset_missing_related_order';

    /** Why a reference could not be satisfied. */
    public const string REASON_ABSENT = 'absent';
    public const string REASON_NO_LINE_ITEMS = 'no_line_items';

    /**
     * An order two relationships both claim.
     *
     * Not resolved to whichever was iterated first, and not typed at all: the
     * choice would decide whether the order becomes a FluentCart `renewal` or
     * an ordinary purchase, and the answer would depend on the order of a
     * foreach. `DatasetClosureValidator` reports it as
     * `dataset_ambiguous_order_relationship` and blocks; until an operator
     * settles it, the safe type is the one that claims nothing.
     */
    private const string RELATIONSHIP_AMBIGUOUS = '__ambiguous__';

    /**
     * The FluentCart order type each WCS relationship becomes.
     *
     * `switch` and `resubscribe` are deliberately absent rather than mapped to
     * something plausible. A switch order is a real purchase and a real part of
     * the subscriber's history, but it is not a renewal charge of this
     * subscription's cycle — calling it one would inflate the paid-cycle
     * evidence by however often somebody changed plan, and FluentCart's
     * `calculateBillCount()` would then bill a finite term short.
     *
     * @see \FluentCart\App\Helpers\Status::ORDER_TYPE_SUBSCRIPTION, ::ORDER_TYPE_RENEWAL
     * @var array<string, string>
     */
    private const array FLUENT_CART_ORDER_TYPES = [
        SubscriptionOrderReference::PARENT  => 'subscription',
        SubscriptionOrderReference::RENEWAL => 'renewal',
    ];

    /**
     * The relationships whose paid orders are evidence of a billed cycle.
     *
     * The same two `DatasetClosureValidator::checkPaymentCount()` uses, and
     * that is not a coincidence: two definitions of "a paid cycle" would
     * eventually disagree about somebody's bill count, and the disagreement
     * would surface months later as a subscription that stopped billing early.
     *
     * @var list<string>
     */
    private const array CHARGEABLE_RELATIONSHIPS = [
        SubscriptionOrderReference::PARENT,
        SubscriptionOrderReference::RENEWAL,
    ];

    /** @var array<int, OrderRecord>|null Null until a deferred index is resolved. */
    private ?array $orders;

    /** @var array<int, array{relationship: string, parent_source_order_id: int}>|null */
    private ?array $relationships;

    /**
     * Builds the two indexes on first use. Null once they exist, or from the
     * start for an index that was handed its contents.
     *
     * @var (\Closure(): array{orders: array<int, OrderRecord>, relationships: array<int, array{relationship: string, parent_source_order_id: int}>})|null
     */
    private ?\Closure $provider = null;

    /**
     * @param array<int, OrderRecord>                                          $orders        Keyed by source order ID.
     * @param array<int, array{relationship: string, parent_source_order_id: int}> $relationships Keyed by source order ID.
     */
    public function __construct(
        public readonly string $sourceKey,
        array $orders = [],
        array $relationships = [],
    ) {
        $this->orders        = $orders;
        $this->relationships = $relationships;
    }

    /**
     * An index that costs nothing until something asks it a question.
     *
     * The reason is a read-only endpoint. `MigrationOrchestratorFactory::
     * migratorsForCounting()` builds every migrator including the order one, and
     * `PreviewController` calls it inside a REST request before any entity-type
     * filter is applied — so an eagerly-built index made a products-only preview
     * page `wcs_get_subscriptions()` in full and hydrate one `WC_Subscription`
     * per row. 564 hydrations on the reference dataset, for a preview that never
     * maps an order; a timeout on a large store.
     *
     * Laziness lives on the type everyone already passes rather than in a
     * parallel "provider" argument threaded through two migrators and a mapper,
     * so every existing signature and call site is unchanged and both the
     * counting path and the products-only path are fixed by the same object.
     *
     * The build runs at most once. A migrator that maps no order never triggers
     * it at all.
     *
     * @param callable(): array{orders: array<int, OrderRecord>, relationships: array<int, array{relationship: string, parent_source_order_id: int}>} $build
     */
    public static function deferred(string $sourceKey, callable $build): self
    {
        $index = new self($sourceKey);

        $index->orders        = null;
        $index->relationships = null;
        $index->provider      = $build(...);

        return $index;
    }

    /**
     * Resolve the deferred build, once.
     *
     * @return array{orders: array<int, OrderRecord>, relationships: array<int, array{relationship: string, parent_source_order_id: int}>}
     */
    private function resolved(): array
    {
        if ($this->provider !== null) {
            $built = ($this->provider)();

            $this->orders        = $built['orders'] ?? [];
            $this->relationships = $built['relationships'] ?? [];
            $this->provider      = null;
        }

        return ['orders' => $this->orders ?? [], 'relationships' => $this->relationships ?? []];
    }

    /**
     * Index whatever the dataset yields. Everything that is not an order or a
     * subscription is ignored rather than refused — closure is
     * `DatasetClosureValidator`'s job, and duplicating its verdict here would
     * give an operator two answers to one question.
     *
     * @param iterable<object> $records
     */
    public static function fromRecords(string $sourceKey, iterable $records): self
    {
        $orders        = [];
        $relationships = [];

        foreach ($records as $record) {
            if ($record instanceof OrderRecord && $record->sourceKey === $sourceKey) {
                $orders[$record->sourceOrderId] ??= $record;

                continue;
            }

            if (!($record instanceof SubscriptionRecord) || $record->sourceKey !== $sourceKey) {
                continue;
            }

            // The RAW reference list, not the de-duplicated one `referencesOf()`
            // returns. De-duplication is right for iterating a history and
            // wrong here: the second claim on an order is precisely the
            // ambiguity, and dropping it before `claim()` sees it would type the
            // order on whichever relationship happened to come first.
            self::claim($relationships, $record->parentOrderId, SubscriptionOrderReference::PARENT, $record->parentOrderId);

            foreach ($record->relatedOrders as $reference) {
                self::claim($relationships, $reference->sourceOrderId, $reference->relationship, $record->parentOrderId);
            }
        }

        return new self($sourceKey, $orders, $relationships);
    }

    /**
     * The relationships, read straight off live WooCommerce.
     *
     * Returns the payload `deferred()` expects rather than a finished index, so
     * the ordinary order run pays for this read only if it maps an order. See
     * `deferred()` for why that matters — an eager version made a read-only
     * preview page every subscription in the store.
     *
     * This is what the ordinary order run needs and all it needs: `OrderMapper`
     * asks which FluentCart type an order takes and which order a renewal hangs
     * off, and never asks for a payload. Deliberately NOT a dependency closure —
     * `order()` answers null for everything and `missingReferences()` would
     * report the lot. The full closure is the dataset source's job, and section
     * 6.2's import order is the staging command's.
     *
     * All four relationship types are read, one typed call each, even though
     * only two produce a FluentCart type. `get_related_orders()` flattens its
     * grouped result and discards the label, so a single call cannot tell a
     * renewal from a switch; and reading only the two that matter would hide an
     * order claimed by both, which would then be typed `renewal` on the strength
     * of a coin toss.
     *
     * @param iterable<object> $subscriptions Live `WC_Subscription` objects.
     */
    public static function liveRelationships(
        iterable $subscriptions,
        ?Source\WooDatasetRecordFactory $factory = null,
    ): array {
        $factory ??= new Source\WooDatasetRecordFactory();

        $relationships = [];

        foreach ($subscriptions as $subscription) {
            if (!is_object($subscription) || !method_exists($subscription, 'get_id')) {
                continue;
            }

            $parentOrderId = method_exists($subscription, 'get_parent_id')
                ? (int) $subscription->get_parent_id()
                : 0;

            if ($parentOrderId > 0) {
                self::claim($relationships, $parentOrderId, SubscriptionOrderReference::PARENT, $parentOrderId);
            }

            foreach ($factory->relatedOrdersByType($subscription) as $relationship => $orderIds) {
                foreach ($orderIds as $orderId) {
                    self::claim($relationships, (int) $orderId, (string) $relationship, $parentOrderId);
                }
            }
        }

        return ['orders' => [], 'relationships' => $relationships];
    }

    /**
     * Record one order's relationship, or mark it disputed.
     *
     * A repeat of the SAME relationship is the ordinary case — a subscription's
     * `parentOrderId` and its `parent` reference are two statements of one fact.
     * A second, DIFFERENT relationship is not resolved; see
     * RELATIONSHIP_AMBIGUOUS.
     *
     * FIRST CLAIM KEEPS THE PARENT POINTER. Only the first write sets
     * `parent_source_order_id`, and the omission matters in a case the
     * relationship check does not cover: two subscriptions naming the same order
     * as `renewal` agree about the relationship, so nothing is disputed — but
     * they disagree about which parent order it hangs off, and an unconditional
     * rewrite would silently hand it to whichever subscription the loop reached
     * last. `DatasetClosureValidator` blocks a shared parent order
     * (`shared_parent_order_requires_projection`) and a duplicated renewal is
     * caught by the same closure rules; this keeps the value deterministic in
     * the meantime rather than dependent on iteration order.
     *
     * @param array<int, array{relationship: string, parent_source_order_id: int}> $relationships
     */
    private static function claim(
        array &$relationships,
        int $sourceOrderId,
        string $relationship,
        int $parentSourceOrderId,
    ): void {
        if ($sourceOrderId <= 0) {
            return;
        }

        $existing = $relationships[$sourceOrderId]['relationship'] ?? null;

        if ($existing === null) {
            $relationships[$sourceOrderId] = [
                'relationship'           => $relationship,
                'parent_source_order_id' => $parentSourceOrderId,
            ];

            return;
        }

        if ($existing !== $relationship && $existing !== self::RELATIONSHIP_AMBIGUOUS) {
            $relationships[$sourceOrderId]['relationship'] = self::RELATIONSHIP_AMBIGUOUS;
        }
    }

    public function order(int $sourceOrderId): ?OrderRecord
    {
        return $this->resolved()['orders'][$sourceOrderId] ?? null;
    }

    /**
     * Null for an order the dataset says nothing about, and null for one two
     * relationships dispute.
     *
     * The two are NOT the same condition and a caller that has to tell them
     * apart asks `isAmbiguous()`. Flattening both to null is right for deciding
     * a type — neither can be typed — and wrong for deciding whether to say
     * something, which is the distinction this pair exists to keep.
     */
    public function relationship(int $sourceOrderId): ?string
    {
        $relationship = $this->resolved()['relationships'][$sourceOrderId]['relationship'] ?? null;

        return $relationship === self::RELATIONSHIP_AMBIGUOUS ? null : $relationship;
    }

    /**
     * Whether two relationships claim this order and neither wins.
     *
     * Fail-closed is only half of the answer. On the dataset path
     * `DatasetClosureValidator` detects the same dispute independently of this
     * index and reports `dataset_ambiguous_order_relationship`, so a null type
     * suppresses nothing. On the live order path there is no validator at all —
     * a run migrating orders without subscriptions would otherwise write the
     * disputed order as a plain `checkout`, correctly and completely silently,
     * and no caller could tell "disputed" from "not a subscription order".
     * Safe-but-silent is the outcome this plan keeps refusing, so the condition
     * is askable and `OrderMapper` warns on it.
     */
    public function isAmbiguous(int $sourceOrderId): bool
    {
        return ($this->resolved()['relationships'][$sourceOrderId]['relationship'] ?? null)
            === self::RELATIONSHIP_AMBIGUOUS;
    }

    /**
     * The source order this one hangs off, for a renewal.
     *
     * FluentCart parents a renewal order on the SUBSCRIPTION'S parent order
     * (`SubscriptionService::createRenewalOrders()`, line 211) and both
     * `guessNextBillingDate()` and `SystemChargeService` read the history back
     * through `where('parent_id', $subscription->parent_order_id)`. A renewal
     * with the wrong parent is invisible to every one of them.
     */
    public function parentSourceOrderId(int $sourceOrderId): ?int
    {
        if ($this->relationship($sourceOrderId) !== SubscriptionOrderReference::RENEWAL) {
            return null;
        }

        $parent = (int) ($this->resolved()['relationships'][$sourceOrderId]['parent_source_order_id'] ?? 0);

        return $parent > 0 ? $parent : null;
    }

    /**
     * The FluentCart `type` this order takes, or null when the dataset holds no
     * opinion — in which case the caller keeps whatever it was going to write.
     */
    public function fluentCartOrderType(int $sourceOrderId): ?string
    {
        $relationship = $this->relationship($sourceOrderId);

        return $relationship === null ? null : self::fluentCartOrderTypeForRelationship($relationship);
    }

    /**
     * Type one order in the context of the subscription currently importing it.
     *
     * A WCS resubscribe order is both history of the old subscription and the
     * parent of the new one. The global index quite properly calls those roles
     * ambiguous; the new subscription's own typed reference is not ambiguous.
     */
    public static function fluentCartOrderTypeForRelationship(string $relationship): ?string
    {
        return self::FLUENT_CART_ORDER_TYPES[$relationship] ?? null;
    }

    /**
     * Every typed reference this subscription makes that the dataset cannot
     * satisfy, with the section 9.4 code that names the hole.
     *
     * Two ways to fail, and the second is the one that looks like success.
     *
     *  - `absent` — the reference names an order and no `OrderRecord` carries
     *    it. A reference is not an order.
     *  - `no_line_items` — an `OrderRecord` exists and carries no lines. Section
     *    6.2 requires "all line items and transactions required to reproduce its
     *    FluentCart order", and an order imported without them produces a
     *    FluentCart order with no products on it: the money is right, the
     *    invoice is blank, and per-product reporting loses the sale. That is
     *    strictly worse than refusing, because nothing about it looks wrong.
     *
     * Transactions are deliberately NOT required. A failed or pending renewal
     * legitimately carries none — it is part of the subscriber's history and
     * contributes no paid cycle — and demanding one would block every
     * subscription that ever had a card decline. `DatasetClosureValidator` makes
     * the narrower demand that fits: an order that is PAID with a positive total
     * and no succeeded charge is `dataset_missing_transaction`.
     *
     * @return list<array{code: string, source_order_id: int, relationship: string, reason: string}>
     */
    public function missingReferences(SubscriptionRecord $record): array
    {
        $orders  = $this->resolved()['orders'];
        $missing = [];

        foreach (self::referencesOf($record) as $reference) {
            $order = $orders[$reference->sourceOrderId] ?? null;

            $reason = match (true) {
                $order === null    => self::REASON_ABSENT,
                $order->items === [] => self::REASON_NO_LINE_ITEMS,
                default            => null,
            };

            if ($reason === null) {
                continue;
            }

            $missing[] = [
                'code' => $reference->relationship === SubscriptionOrderReference::PARENT
                    ? self::CODE_MISSING_PARENT_ORDER
                    : self::CODE_MISSING_RELATED_ORDER,
                'source_order_id' => $reference->sourceOrderId,
                'relationship'    => $reference->relationship,
                'reason'          => $reason,
            ];
        }

        return $missing;
    }

    /**
     * This subscription's whole order history, in source-ID order, each with
     * the relationship it holds.
     *
     * @return list<array{order: OrderRecord, relationship: string}>
     */
    public function history(SubscriptionRecord $record): array
    {
        $orders  = $this->resolved()['orders'];
        $history = [];

        foreach (self::referencesOf($record) as $reference) {
            $order = $orders[$reference->sourceOrderId] ?? null;

            if ($order !== null) {
                $history[] = ['order' => $order, 'relationship' => $reference->relationship];
            }
        }

        return $history;
    }

    /**
     * The parent and renewal orders carrying a succeeded positive charge.
     *
     * This is the "included paid-order count" of section 10 step 5, and the
     * one the reconciler compares against WCS's own payment count.
     *
     * @return list<int>
     */
    public function paidOrderIds(SubscriptionRecord $record): array
    {
        $ids = [];

        foreach ($this->history($record) as $entry) {
            if (!in_array($entry['relationship'], self::CHARGEABLE_RELATIONSHIPS, true)) {
                continue;
            }

            if ($entry['order']->succeededChargeCount() > 0) {
                $ids[] = $entry['order']->sourceOrderId;
            }
        }

        return $ids;
    }

    public function paidOrderCount(SubscriptionRecord $record): int
    {
        return count($this->paidOrderIds($record));
    }

    /**
     * Chargeable cycles WCS counted but FluentCart cannot see as transactions.
     *
     * Each result is evidenced by an included parent/renewal order with a paid
     * date and zero total. Failed or merely open zero-total orders do not count.
     */
    public function consumedFreeCycleCount(SubscriptionRecord $record): int
    {
        $count = 0;

        foreach ($this->history($record) as $entry) {
            if (!in_array($entry['relationship'], self::CHARGEABLE_RELATIONSHIPS, true)) {
                continue;
            }

            if ($entry['order']->isConsumedFreeCycle()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Every source order ID this subscription's history names, in order.
     *
     * @return list<int>
     */
    public function relatedOrderIds(SubscriptionRecord $record): array
    {
        return array_map(
            static fn (SubscriptionOrderReference $reference): int => $reference->sourceOrderId,
            self::referencesOf($record),
        );
    }

    /**
     * The subscription's typed references, with its own `parentOrderId`
     * guaranteed present.
     *
     * `SubscriptionRecord::$parentOrderId` and a `parent` relationship
     * reference are two statements of the same fact, and a source is free to
     * make only one of them: `WooDatasetRecordFactory` fills `relatedOrders`
     * from `get_related_orders()`, which a WCS installation may answer for
     * `parent` or may not. Missing the parent order because two fields
     * disagreed about whose job it was would lose the one order FluentCart's
     * `fct_subscriptions.parent_order_id` cannot be NULL without.
     *
     * @return list<SubscriptionOrderReference>
     */
    private static function referencesOf(SubscriptionRecord $record): array
    {
        $seen       = [];
        $references = [];

        if ($record->parentOrderId > 0) {
            $seen[$record->parentOrderId] = true;
            $references[] = new SubscriptionOrderReference(
                $record->parentOrderId,
                SubscriptionOrderReference::PARENT,
            );
        }

        foreach ($record->relatedOrders as $reference) {
            if ($reference->sourceOrderId <= 0 || isset($seen[$reference->sourceOrderId])) {
                continue;
            }

            $seen[$reference->sourceOrderId] = true;
            $references[] = $reference;
        }

        usort(
            $references,
            static fn (SubscriptionOrderReference $a, SubscriptionOrderReference $b): int
                => $a->sourceOrderId <=> $b->sourceOrderId,
        );

        return $references;
    }
}

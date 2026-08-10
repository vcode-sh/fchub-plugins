<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription;

defined('ABSPATH') || exit;

use CartShift\Domain\Migration\OrderIdentity;
use CartShift\Storage\IdMapRepository;
use CartShift\Support\Constants;
use CartShift\Support\Enums\FcOrderStatus;
use CartShift\Support\Enums\FcPaymentStatus;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderAddress;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\OrderTransaction;

/**
 * One subscription's order history, imported from payloads rather than from a
 * live WooCommerce.
 *
 * That distinction is the reason this exists beside `OrderMigrator` instead of
 * inside it. The Lapka cutover exports in one WordPress and imports in another,
 * so by the time the history is needed there is no `WC_Order` to ask — only the
 * `OrderRecord` the package carries. Section 6.2 requires that record, its line
 * items and its transactions for every typed reference, and this class refuses
 * to write anything at all until it has them: `missingReferences()` is asked
 * first, and a package that names order 880501 without carrying it fails
 * closure rather than importing half a subscriber's history.
 *
 * IT IS IDEMPOTENT IN THREE DIRECTIONS. An order CartShift already imported is
 * found in the ID map. An order FluentCart's own WooCommerce importer created
 * is adopted by `invoice_no` — the same convention `OrderMigrator` uses. And an
 * order that arrived through the ordinary order path as a plain `checkout` is
 * RETYPED rather than duplicated, because otherwise FluentCart never lists it
 * under the subscription and never counts it.
 *
 * The type it writes is FluentCart's own vocabulary: a parent order is
 * `subscription`, a renewal is `renewal`, and a renewal's `parent_id` is the
 * SUBSCRIPTION'S parent order, which is what `Subscription::guessNextBillingDate()`
 * and `SystemChargeService` both read the history back through.
 */
final class SubscriptionOrderImporter
{
    public function __construct(
        private readonly IdMapRepository $idMap,
    ) {
    }

    /**
     * Import — or find — every order this subscription's history names.
     *
     * Nothing is written when a reference has no payload. Not "the ones that
     * are present", not "and the rest next time": a partial history produces a
     * bill count that is wrong in a way nobody can see, which is worse than a
     * run that stopped and said why.
     *
     * @return array{
     *     orders: array<int, int>,
     *     created: list<int>,
     *     adopted: list<int>,
     *     retyped: list<int>,
     *     relinked: list<int>,
     *     failures: list<array{code: string, source_order_id: int, relationship: string}>,
     * }
     */
    public function import(
        SubscriptionRecord $record,
        SubscriptionHistoryIndex $history,
        string $migrationId = '',
    ): array {
        $missing = $history->missingReferences($record);

        if ($missing !== []) {
            return [
                'orders'   => [],
                'created'  => [],
                'adopted'  => [],
                'retyped'  => [],
                'relinked' => [],
                'failures' => $missing,
            ];
        }

        $orders   = [];
        $created  = [];
        $adopted  = [];
        $retyped  = [];
        $relinked = [];

        // Parents first, so a renewal's `parent_id` can point at a destination
        // ID that already exists. The index sorts by source ID, which on a WCS
        // store puts the parent first anyway — but "anyway" is not a guarantee,
        // and a renewal parented on null is a renewal FluentCart cannot find.
        foreach ($this->parentFirst($history->history($record)) as $entry) {
            $order        = $entry['order'];
            $relationship = $entry['relationship'];
            $sourceId     = $order->sourceOrderId;

            $parentFcId = $this->parentFcIdFor($record, $relationship, $orders);
            $type       = SubscriptionHistoryIndex::fluentCartOrderTypeForRelationship($relationship);

            $existing = $this->idMap->getFcId(Constants::ENTITY_ORDER, (string) $sourceId);

            if ($existing === null) {
                $existing = OrderIdentity::findImportedOrderId($sourceId);

                if ($existing !== null) {
                    $this->idMap->store(
                        Constants::ENTITY_ORDER,
                        (string) $sourceId,
                        $existing,
                        $migrationId,
                        false,
                    );
                    $adopted[] = $sourceId;
                }
            } else {
                $adopted[] = $sourceId;
            }

            if ($existing !== null) {
                if ($this->correctType($existing, $type, $parentFcId)) {
                    $retyped[] = $sourceId;
                }

                if ($this->correctItemReferences($order)) {
                    $relinked[] = $sourceId;
                }

                $orders[$sourceId] = $existing;

                continue;
            }

            $orders[$sourceId] = $this->create($order, $relationship, $type, $parentFcId, $migrationId);
            $created[]         = $sourceId;
        }

        return [
            'orders'   => $orders,
            'created'  => $created,
            'adopted'  => $adopted,
            'retyped'  => $retyped,
            'relinked' => $relinked,
            'failures' => [],
        ];
    }

    // ──────────────────────────────────────────────
    // Writing
    // ──────────────────────────────────────────────

    private function create(
        OrderRecord $order,
        string $relationship,
        ?string $type,
        ?int $parentFcId,
        string $migrationId,
    ): int {
        $sourceId = $order->sourceOrderId;
        $created  = $order->dates['created_utc'] ?? gmdate('Y-m-d H:i:s');

        $fcOrder = Order::query()->create([
            'customer_id'           => $this->resolveCustomerId($order),
            'parent_id'             => $parentFcId,
            'type'                  => $type ?? 'checkout',
            'status'                => FcOrderStatus::fromWooCommerce($order->status)->value,
            'payment_status'        => FcPaymentStatus::fromWooCommerce($order->status)->value,
            'payment_method'        => $this->gatewayOf($order),
            'payment_method_title'  => 'WooCommerce (migrated)',
            'currency'              => $order->currency,
            'subtotal'              => (int) ($order->totals['subtotal'] ?? 0),
            'discount_tax'          => 0,
            'manual_discount_total' => 0,
            'coupon_discount_total' => (int) ($order->totals['discount'] ?? 0),
            'shipping_tax'          => 0,
            'shipping_total'        => (int) ($order->totals['shipping'] ?? 0),
            'tax_total'             => (int) ($order->totals['tax'] ?? 0),
            'tax_behavior'          => ((int) ($order->totals['tax'] ?? 0)) > 0 ? 1 : 0,
            'total_amount'          => $order->total(),
            'total_paid'            => $order->isPaid() ? $order->total() : 0,
            'total_refund'          => (int) ($order->totals['refunded'] ?? 0),
            'rate'                  => '1',
            'note'                  => '',
            'ip_address'            => '',
            'mode'                  => 'live',
            'fulfillment_type'      => 'digital',
            'shipping_status'       => '',
            'invoice_no'            => OrderIdentity::invoiceNo($sourceId),
            'uuid'                  => wp_generate_uuid4(),
            'config'                => [
                'migrated'                  => true,
                'wc_order_id'               => $sourceId,
                'source_key'                => $order->sourceKey,
                'subscription_relationship' => $relationship,
            ],
            'created_at'            => $created,
            'completed_at'          => $order->dates['completed_utc'] ?? null,
        ]);

        $fcOrderId = (int) $fcOrder->id;

        $this->idMap->store(Constants::ENTITY_ORDER, (string) $sourceId, $fcOrderId, $migrationId, true);

        $this->createItems($order, $fcOrderId, $created, $migrationId);
        $this->createAddresses($order, $fcOrderId, $migrationId);
        $this->createTransactions($order, $fcOrderId, $type, $created, $migrationId);

        return $fcOrderId;
    }

    private function createItems(OrderRecord $order, int $fcOrderId, string $created, string $migrationId): void
    {
        foreach ($order->items as $index => $item) {
            $sourceProductId = (int) ($item['source_product_id'] ?? 0);
            $variationKey    = (string) ($item['pseudo_variation_key'] ?? '');

            $productId = $sourceProductId > 0
                ? $this->idMap->getFcId(Constants::ENTITY_PRODUCT, (string) $sourceProductId)
                : null;
            $variationId = $variationKey !== ''
                ? $this->idMap->getFcId(Constants::ENTITY_VARIATION, $variationKey)
                : null;

            $quantity  = max(1, (int) ($item['quantity'] ?? 1));
            $lineTotal = (int) ($item['line_total'] ?? 0);

            $fcItem = OrderItem::query()->create([
                'order_id'         => $fcOrderId,
                'post_id'          => $productId ?: 0,
                'object_id'        => $variationId ?: 0,
                'post_title'       => (string) ($item['name'] ?? ''),
                'title'            => (string) ($item['name'] ?? ''),
                'fulfillment_type' => 'digital',
                'quantity'         => $quantity,
                'unit_price'       => intdiv($lineTotal, $quantity),
                'cost'             => 0,
                'subtotal'         => $lineTotal,
                'tax_amount'       => (int) ($item['line_tax'] ?? 0),
                'discount_total'   => 0,
                'refund_total'     => 0,
                'line_total'       => $lineTotal,
                'rate'             => '1',
                'payment_type'     => 'subscription',
                'other_info'       => [],
                'line_meta'        => [],
                'created_at'       => $created,
            ]);

            $this->idMap->store(
                Constants::ENTITY_ORDER_ITEM,
                OrderIdentity::itemKey($order->sourceOrderId, (int) $index),
                (int) $fcItem->id,
                $migrationId,
                true,
            );
        }
    }

    private function createAddresses(OrderRecord $order, int $fcOrderId, string $migrationId): void
    {
        foreach ($order->addresses as $type => $fields) {
            if (!is_array($fields) || $fields === []) {
                continue;
            }

            $name = trim(
                (string) ($fields['first_name'] ?? '') . ' ' . (string) ($fields['last_name'] ?? ''),
            );

            $fcAddress = OrderAddress::query()->create([
                'order_id'  => $fcOrderId,
                'type'      => (string) $type,
                'name'      => $name,
                'address_1' => (string) ($fields['address_1'] ?? ''),
                'address_2' => (string) ($fields['address_2'] ?? ''),
                'city'      => (string) ($fields['city'] ?? ''),
                'state'     => (string) ($fields['state'] ?? ''),
                'postcode'  => (string) ($fields['postcode'] ?? ''),
                'country'   => (string) ($fields['country'] ?? ''),
                'meta'      => [],
            ]);

            $this->idMap->store(
                Constants::ENTITY_ORDER_ADDRESS,
                OrderIdentity::addressKey($order->sourceOrderId, (string) $type),
                (int) $fcAddress->id,
                $migrationId,
                true,
            );
        }
    }

    /**
     * The order's transactions, with `subscription_id` deliberately absent.
     *
     * Section 6.2 fixes the import order — orders, then paused subscriptions,
     * then transaction-to-subscription links — because the subscription does
     * not have an ID yet. `SubscriptionHistoryLinker` is the second half.
     */
    private function createTransactions(
        OrderRecord $order,
        int $fcOrderId,
        ?string $type,
        string $created,
        string $migrationId,
    ): void {
        foreach ($order->transactions as $index => $transaction) {
            $fcTransaction = OrderTransaction::query()->create([
                'order_id'            => $fcOrderId,
                'order_type'          => $type ?? 'order',
                'vendor_charge_id'    => (string) ($transaction['source_transaction_id'] ?? ''),
                'payment_method'      => (string) ($transaction['gateway'] ?? ''),
                'payment_mode'        => 'live',
                'payment_method_type' => 'wc_migrated',
                'currency'            => (string) ($transaction['currency'] ?? $order->currency),
                'transaction_type'    => (string) ($transaction['type'] ?? 'charge'),
                'status'              => (string) ($transaction['status'] ?? 'pending'),
                'total'               => (int) ($transaction['total'] ?? 0),
                'rate'                => '1',
                'meta'                => ['wc_order_id' => $order->sourceOrderId],
                'created_at'          => (string) ($transaction['paid_at_utc'] ?? $created),
            ]);

            $this->idMap->store(
                Constants::ENTITY_ORDER_TRANSACTION,
                OrderIdentity::transactionKey($order->sourceOrderId, (int) $index),
                (int) $fcTransaction->id,
                $migrationId,
                true,
            );
        }
    }

    // ──────────────────────────────────────────────
    // Adoption
    // ──────────────────────────────────────────────

    /**
     * Correct an adopted order's type and parent, and say whether anything
     * changed.
     *
     * The ordinary order path maps every order `type = checkout`, which is not
     * in FluentCart's vocabulary at all and certainly not `renewal`. Leaving it
     * means `RenewalController`, `CustomerOrderController` and
     * `Subscription::renewalOrders()` all filter it out — the subscriber's own
     * invoice list loses every renewal they ever paid.
     */
    /**
     * Attach an already-imported order's line items to the product they name,
     * once the mapping that resolves it finally exists.
     *
     * WHY AN ADOPTED ORDER CAN NEED THIS AT ALL. `createItems()` resolves
     * `post_id` and `object_id` through the ID map and writes 0 when the map has
     * no answer, because a NOT NULL column has to be given something and an
     * invented ID would be worse than a visible zero. A run that imported orders
     * before the operator's mapping decisions had been promoted therefore left
     * every line item pointing at nothing — and the ORDER is in the ID map, so
     * the next run adopts it and never reaches `createItems()` again. Without
     * this the damage is permanent short of a full reset: 5,277 order lines in
     * the Lapka rehearsal, each showing a subscriber a product FluentCart cannot
     * resolve on their order page, their receipt and their emails.
     *
     * ONLY ZEROS ARE FILLED. A line already carrying a reference is left exactly
     * as it is, whoever put it there — the owner correcting a line by hand, or
     * FluentCart's own importer — because this method's warrant is "the map now
     * has an answer where nothing had one", not "the map is more right than you".
     * That also makes it a no-op on every ordinary re-run, which is what keeps
     * `relinked` meaningful: a number above zero is a repair, not a heartbeat.
     *
     * The item is found through the ID map by the same `itemKey` that recorded
     * it, so nothing is matched by title, position in the table, or price —
     * a line CartShift did not write is a line it does not touch.
     */
    private function correctItemReferences(OrderRecord $order): bool
    {
        $changed = false;

        foreach ($order->items as $index => $item) {
            $fcItemId = $this->idMap->getFcId(
                Constants::ENTITY_ORDER_ITEM,
                OrderIdentity::itemKey($order->sourceOrderId, (int) $index),
            );

            if ($fcItemId === null) {
                continue;
            }

            $fcItem = OrderItem::query()->find($fcItemId);

            if ($fcItem === null) {
                continue;
            }

            $sourceProductId = (int) ($item['source_product_id'] ?? 0);
            $variationKey    = (string) ($item['pseudo_variation_key'] ?? '');

            $productId = $sourceProductId > 0
                ? $this->idMap->getFcId(Constants::ENTITY_PRODUCT, (string) $sourceProductId)
                : null;
            $variationId = $variationKey !== ''
                ? $this->idMap->getFcId(Constants::ENTITY_VARIATION, $variationKey)
                : null;

            $repaired = false;

            if ((int) ($fcItem->post_id ?? 0) <= 0 && $productId !== null && $productId > 0) {
                $fcItem->post_id = $productId;
                $repaired        = true;
            }

            if ((int) ($fcItem->object_id ?? 0) <= 0 && $variationId !== null && $variationId > 0) {
                $fcItem->object_id = $variationId;
                $repaired          = true;
            }

            if ($repaired) {
                $fcItem->save();
                $changed = true;
            }
        }

        return $changed;
    }

    private function correctType(int $fcOrderId, ?string $type, ?int $parentFcId): bool
    {
        if ($type === null) {
            return false;
        }

        $existing = Order::query()->find($fcOrderId);

        if ($existing === null) {
            return false;
        }

        $changed = false;

        if ((string) ($existing->type ?? '') !== $type) {
            $existing->type = $type;
            $changed        = true;
        }

        if ($parentFcId !== null && (int) ($existing->parent_id ?? 0) !== $parentFcId) {
            $existing->parent_id = $parentFcId;
            $changed             = true;
        }

        if ($changed) {
            $existing->save();
        }

        return $changed;
    }

    // ──────────────────────────────────────────────
    // References
    // ──────────────────────────────────────────────

    /**
     * @param array<int, int> $imported Source order ID => FluentCart order ID.
     */
    private function parentFcIdFor(
        SubscriptionRecord $record,
        string $relationship,
        array $imported,
    ): ?int
    {
        if ($relationship !== SubscriptionOrderReference::RENEWAL || $record->parentOrderId <= 0) {
            return null;
        }

        return $imported[$record->parentOrderId]
            ?? $this->idMap->getFcId(Constants::ENTITY_ORDER, (string) $record->parentOrderId);
    }

    private function resolveCustomerId(OrderRecord $order): ?int
    {
        if (str_starts_with($order->sourceCustomerRef, 'customer:')) {
            $sourceUserId = (int) substr($order->sourceCustomerRef, strlen('customer:'));

            $registered = $sourceUserId > 0
                ? $this->idMap->getFcId(Constants::ENTITY_CUSTOMER, (string) $sourceUserId)
                : null;

            if ($registered !== null) {
                return $registered;
            }
        }

        if ($order->billingEmail === '') {
            return null;
        }

        return $this->idMap->getFcId(Constants::ENTITY_GUEST_CUSTOMER, $order->billingEmail)
            ?? $this->idMap->getFcId(Constants::ENTITY_GUEST_CUSTOMER, $order->sourceCustomerRef);
    }

    private function gatewayOf(OrderRecord $order): string
    {
        foreach ($order->transactions as $transaction) {
            $gateway = trim((string) ($transaction['gateway'] ?? ''));

            if ($gateway !== '') {
                return $gateway;
            }
        }

        return 'wc_migrated';
    }

    /**
     * Parent relationships first, everything else in the order it arrived.
     *
     * @param list<array{order: OrderRecord, relationship: string}> $history
     * @return list<array{order: OrderRecord, relationship: string}>
     */
    private function parentFirst(array $history): array
    {
        $parents = [];
        $rest    = [];

        foreach ($history as $entry) {
            if ($entry['relationship'] === SubscriptionOrderReference::PARENT) {
                $parents[] = $entry;

                continue;
            }

            $rest[] = $entry;
        }

        return array_merge($parents, $rest);
    }
}

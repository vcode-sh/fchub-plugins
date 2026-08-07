<?php

declare(strict_types=1);

namespace CartShift\Migrator;

defined('ABSPATH') || exit;

use CartShift\Domain\Mapping\OrderMapper;
use CartShift\Domain\Migration\GuestCustomerFactory;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\Constants;
use CartShift\Support\Enums\MigrationErrorCode;
use CartShift\Support\MoneyHelper;
use CartShift\Support\WooStorage;
use FluentCart\App\Models\AppliedCoupon;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderAddress;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\OrderMeta;
use FluentCart\App\Models\OrderTransaction;

final class OrderMigrator extends AbstractMigrator
{
    private readonly OrderMapper $orderMapper;

    private readonly GuestCustomerFactory $guestCustomers;

    /** @var int|null Highest order ID covered by the ID page fetchBatch() last read. */
    private ?int $pageEndCursor = null;

    public function __construct(
        IdMapRepository $idMap,
        MigrationLogRepository $log,
        MigrationState $migrationState,
        int $batchSize = Constants::DEFAULT_BATCH_SIZE,
    ) {
        parent::__construct($idMap, $log, $migrationState, $batchSize);

        $currency = get_woocommerce_currency();
        $this->orderMapper = new OrderMapper($idMap, $currency);
        $this->guestCustomers = new GuestCustomerFactory($idMap);
    }

    #[\Override]
    protected function getEntityType(): string
    {
        return Constants::ENTITY_ORDER;
    }

    /**
     * Count exactly the rows fetchBatch() will hand back.
     *
     * An unfiltered COUNT(*) also sweeps up checkout-drafts and trashed orders,
     * neither of which wc_get_orders(['status' => 'any']) ever returns — so the
     * progress bar could never reach 100%.
     */
    #[\Override]
    protected function countTotal(): int
    {
        global $wpdb;

        $scope = WooStorage::orderScopeSql();
        $table = WooStorage::ordersTable();

        return (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$table}
             WHERE {$scope}",
        );
    }

    /**
     * Keyset pagination over HPOS order IDs.
     *
     * `wc_get_orders(['offset' => 50000])` makes MySQL walk and discard fifty
     * thousand rows before it hands back one, which is why the tail of a large
     * store crawls. The ID page instead seeks straight into the primary key,
     * reusing WooStorage::orderScopeParts() so the status/type set stays
     * byte-identical to wc_get_orders(['status' => 'any']).
     *
     * Hydration still goes through wc_get_orders() — post__in is the HPOS query
     * layer's own alias for `id`, so one query fetches the page.
     *
     * @see woocommerce/src/Internal/DataStores/Orders/OrdersTableQuery.php (v11.0.0, line 278: 'post__in' => 'id')
     */
    #[\Override]
    public function fetchBatch(string|int|null $cursor, int $limit): array
    {
        $after = max(0, (int) $cursor);

        // Loop only when an entire ID page fails to hydrate; returning [] there
        // would end the entity early and silently truncate the migration.
        while (true) {
            $ids = $this->fetchOrderIdPage($after, $limit);

            if ($ids === []) {
                return [];
            }

            $after = (int) end($ids);
            $this->pageEndCursor = $after;

            $orders = wc_get_orders([
                'limit'    => count($ids),
                'post__in' => $ids,
                'status'   => 'any',
                'type'     => WooStorage::TYPE_ORDER,
                'orderby'  => 'ID',
                'order'    => 'ASC',
            ]);

            $orders = array_values(array_filter(
                (array) $orders,
                static fn (mixed $order): bool => is_object($order),
            ));

            if ($orders !== []) {
                return $orders;
            }
        }
    }

    /**
     * Hydrate exactly these order IDs, for a retry run.
     *
     * Same wc_get_orders() call fetchBatch() hydrates its ID page with, and the
     * same WooStorage type scoping — `post__in` is the HPOS query layer's alias
     * for `id`, so one query covers the page. An order that has been deleted or
     * moved out of scope since the run that failed on it simply does not come
     * back.
     *
     * The page cursor is left untouched: a retry paginates an ID list, not the
     * orders table.
     *
     * @param array<int, string|int> $wcIds
     *
     * @return list<\WC_Order>
     */
    #[\Override]
    public function fetchByIds(array $wcIds): array
    {
        $ids = self::normalizeIntIds($wcIds);

        if ($ids === []) {
            return [];
        }

        $orders = wc_get_orders([
            'limit'    => count($ids),
            'post__in' => $ids,
            'status'   => 'any',
            'type'     => WooStorage::TYPE_ORDER,
            'orderby'  => 'ID',
            'order'    => 'ASC',
        ]);

        return array_values(array_filter(
            (array) $orders,
            static fn (mixed $order): bool => is_object($order),
        ));
    }

    /**
     * The cursor is the end of the ID page, not the last hydrated order — a
     * trailing ID that wc_get_orders() declines to return would otherwise be
     * re-read for ever.
     */
    #[\Override]
    public function cursorFor(mixed $record): string|int
    {
        return $this->pageEndCursor ?? parent::cursorFor($record);
    }

    /**
     * The next page of order IDs strictly after $afterId, in the same scope
     * countTotal() counts.
     *
     * @return list<int>
     */
    private function fetchOrderIdPage(int $afterId, int $limit): array
    {
        global $wpdb;

        $table = WooStorage::ordersTable();

        // Placeholder form, so the scope and the pagination go through a single
        // prepare() rather than nesting one prepared string inside another.
        [$scope, $scopeValues] = WooStorage::orderScopeParts();

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id
             FROM {$table}
             WHERE {$scope}
               AND id > %d
             ORDER BY id ASC
             LIMIT %d",
            ...[...$scopeValues, $afterId, $limit],
        ));

        return array_map(intval(...), $ids);
    }

    /**
     * Validate an order without creating any FC records.
     * Skips FK validation in dry-run — we validate data mapping quality only.
     *
     * @param \WC_Order $wcOrder
     */
    #[\Override]
    public function validateRecord(mixed $wcOrder): bool
    {
        $wcId = $wcOrder->get_id();

        if ($this->idMap->getFcId(Constants::ENTITY_ORDER, (string) $wcId)) {
            $this->writeLog($wcId, 'dry-run', 'dry-run: already migrated, would skip.', MigrationErrorCode::AlreadyMigrated);
            return false;
        }

        $mapped = $this->orderMapper->map($wcOrder);

        $itemCount = count($mapped['items']);
        if ($itemCount === 0) {
            $this->writeLog($wcId, 'dry-run', 'dry-run: order has no items, would fail.', MigrationErrorCode::OrderHasNoItems);
            return false;
        }

        $this->writeLog($wcId, 'dry-run', sprintf(
            'dry-run: would create order WC-#%d with %d item(s).',
            $wcId,
            $itemCount,
        ));

        return true;
    }

    /**
     * @param \WC_Order $wcOrder
     */
    #[\Override]
    public function processRecord(mixed $wcOrder): int|false
    {
        $wcId = $wcOrder->get_id();

        if ($this->idMap->getFcId(Constants::ENTITY_ORDER, (string) $wcId)) {
            $this->writeLog($wcId, 'skipped', 'Already migrated.', MigrationErrorCode::AlreadyMigrated);
            return false;
        }

        // FluentCart's own WooCommerce migrator may have imported this order first.
        // Adopt it rather than creating a duplicate — same convention as the
        // FIX C9 blocks in CustomerMigrator and CouponMigrator.
        $adopted = $this->findFluentCartImportedOrder($wcId);
        if ($adopted !== null) {
            $this->idMap->store(Constants::ENTITY_ORDER, (string) $wcId, $adopted, $this->migrationId(), false);
            $this->writeLog($wcId, 'skipped', sprintf(
                'Order already imported into FluentCart as #%d (invoice_no "WC-%d"). Adopted into the ID map; rollback will leave it alone.',
                $adopted,
                $wcId,
            ), MigrationErrorCode::AlreadyExistsInFluentCart);

            return false;
        }

        // An order is a record of something that already happened, so it
        // migrates whatever else is missing. Resolve the buyer — rebuilding one
        // from the order's own billing details if the customer never came
        // across — before mapping, so the mapper's own lookup finds it.
        $this->resolveBuyer($wcOrder, $wcId);

        $mapped = $this->orderMapper->map($wcOrder);

        // One entry per order, not per line item. A shop with five thousand
        // orders full of retired products would otherwise write a log nobody can
        // read, to say one thing five times per order.
        foreach ($this->orderMapper->getCodedWarnings() as $warning) {
            $this->writeLog($wcId, 'warning', $warning['message'], $warning['code']);
        }

        // 1. Create the FC order.
        $fcOrder = Order::query()->create($mapped['order']);
        $this->idMap->store(Constants::ENTITY_ORDER, (string) $wcId, $fcOrder->id, $this->migrationId(), true);

        // 2. Create order items with compound keys (FIX C7).
        $totalQuantity = 0;
        foreach ($mapped['items'] as $index => $itemData) {
            $itemData['order_id'] = $fcOrder->id;
            $fcItem = OrderItem::query()->create($itemData);
            $totalQuantity += (int) ($itemData['quantity'] ?? 1);
            $itemKey = "{$wcId}_{$index}";
            $this->idMap->store(Constants::ENTITY_ORDER_ITEM, $itemKey, $fcItem->id, $this->migrationId(), true);
        }

        // 2b. Update item_count on the FC order (sum of all item quantities).
        if ($totalQuantity > 0) {
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'fct_orders',
                ['item_count' => $totalQuantity],
                ['id' => $fcOrder->id],
                ['%d'],
                ['%d'],
            );
        }

        // 3. Create order addresses with compound keys (FIX C7).
        foreach ($mapped['addresses'] as $addressData) {
            $addressData['order_id'] = $fcOrder->id;
            $fcAddress = OrderAddress::query()->create($addressData);
            $addressKey = "{$wcId}_{$addressData['type']}";
            $this->idMap->store(Constants::ENTITY_ORDER_ADDRESS, $addressKey, $fcAddress->id, $this->migrationId(), true);
        }

        // 4. Create order transaction with compound key (FIX C7).
        if ($mapped['transaction']) {
            $transactionData = $mapped['transaction'];
            $transactionData['order_id'] = $fcOrder->id;
            $fcTransaction = OrderTransaction::query()->create($transactionData);
            $transactionKey = "{$wcId}_charge";
            $this->idMap->store(Constants::ENTITY_ORDER_TRANSACTION, $transactionKey, $fcTransaction->id, $this->migrationId(), true);
        }

        // 5. Handle refund transactions (FIX C1: no json_encode on meta).
        $refunds = $wcOrder->get_refunds();
        foreach ($refunds as $refund) {
            $this->processRefund($refund, $fcOrder->id, $wcId);
        }

        // 5b. Store per-item refund amounts from WC refunds.
        $this->applyPerItemRefunds($wcOrder, $fcOrder->id, $wcId);

        // 6. FIX M16: Detect partial refunds and update payment_status.
        $this->updatePartialRefundStatus($wcOrder, $fcOrder->id);

        // 7. FIX M7: Migrate WC order notes to FC order meta.
        $this->migrateOrderNotes($wcId, $fcOrder->id);

        // 8. Migrate applied coupons to FC applied_coupons table.
        $this->migrateAppliedCoupons($wcOrder, $fcOrder->id);

        // 9. Migrate key WC order meta to fct_order_meta.
        $this->migrateKeyOrderMeta($wcOrder, $fcOrder->id);

        $this->writeLog($wcId, 'success', sprintf(
            'Migrated order #%d (FC ID: %d) - Status: %s.',
            $wcId,
            $fcOrder->id,
            $wcOrder->get_status(),
        ));

        return $fcOrder->id;
    }

    /**
     * Make sure the order has a buyer to point at, and say so when one had to be
     * invented.
     *
     * Until 1.2.1 an order whose customer was not in the ID map was skipped
     * outright, which is the worst thing on the list: revenue disappears from the
     * migration and the only trace is one line in a log nobody reads. A past
     * order is a record — the money has to add up, and a missing link to a
     * customer profile does not make the order untrue.
     *
     * So the buyer is rebuilt from the billing details already on the order, by
     * the same GuestCustomerFactory that handles guest checkouts, and stored
     * under ENTITY_GUEST_CUSTOMER keyed by email. That key is what makes the
     * buyer's second order reuse the first rebuild instead of creating a
     * duplicate customer per order.
     *
     * An order with no billing email has nothing to rebuild from, and
     * fct_orders.customer_id is nullable, so it migrates with no customer.
     * Never a skip.
     *
     * @return int|null The FluentCart customer ID, if there is one.
     */
    private function resolveBuyer(\WC_Order $wcOrder, int $wcId): ?int
    {
        $wcCustomerId = $wcOrder->get_customer_id();

        if ($wcCustomerId > 0) {
            $fcCustomerId = $this->idMap->getFcId(Constants::ENTITY_CUSTOMER, (string) $wcCustomerId);

            if ($fcCustomerId) {
                return $fcCustomerId;
            }
        }

        $built = $this->guestCustomers->fromOrder($wcOrder, $this->migrationId());

        if ($built === null) {
            $this->writeLog(
                $wcId,
                'warning',
                $wcCustomerId > 0
                    ? sprintf(
                        'Customer ID %d was not migrated and the order carries no billing email to rebuild a '
                        . 'buyer from. Migrated with no customer so the order and its revenue survive.',
                        $wcCustomerId,
                    )
                    : 'The order carries no billing email, so there is no buyer to attach. Migrated with no '
                        . 'customer so the order and its revenue survive.',
                MigrationErrorCode::CustomerNotFound,
            );

            return null;
        }

        if ($built['outcome'] === GuestCustomerFactory::OUTCOME_ALREADY_MAPPED) {
            return $built['id'];
        }

        $this->writeLog(
            $wcId,
            'warning',
            $wcCustomerId > 0
                ? sprintf(
                    'Customer ID %d was not migrated. The buyer was rebuilt from the order\'s own billing '
                    . 'details as "%s" (FC ID: %d), so the order keeps its revenue.',
                    $wcCustomerId,
                    $built['email'],
                    $built['id'],
                )
                : sprintf(
                    'The buyer was rebuilt from the order\'s own billing details as "%s" (FC ID: %d).',
                    $built['email'],
                    $built['id'],
                ),
            MigrationErrorCode::CustomerRebuiltFromOrder,
        );

        return $built['id'];
    }

    /**
     * Find a FluentCart order that was already imported from this WC order.
     *
     * FluentCart's own WooCommerce migrator stamps imported orders with
     * `invoice_no LIKE 'WC-%'`, and OrderMapper writes the identical
     * `'WC-' . $wcOrderId` marker — so an exact match on that column identifies
     * the same order regardless of which tool did the importing.
     *
     * fct_orders.invoice_no is VARCHAR(192) and indexed, so this is a single
     * index lookup per order.
     *
     * @see fluent-cart/database/Migrations/OrdersMigrator.php (lines 19, 51)
     * @see \CartShift\Domain\Mapping\OrderMapper
     */
    private function findFluentCartImportedOrder(int $wcOrderId): ?int
    {
        global $wpdb;

        $fcId = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fct_orders WHERE invoice_no = %s LIMIT 1",
            'WC-' . $wcOrderId,
        ));

        return $fcId !== null && (int) $fcId > 0 ? (int) $fcId : null;
    }

    /**
     * Process a refund as a transaction.
     * FIX C1: meta is an array, not json_encode'd.
     * FIX C7: compound key for refund transactions.
     */
    private function processRefund(\WC_Order_Refund $refund, int $parentFcOrderId, int $wcOrderId): void
    {
        $refundAmount = abs(floatval($refund->get_amount()));
        if ($refundAmount <= 0) {
            return;
        }

        $currency = $refund->get_currency();

        $transactionData = [
            'order_id'            => $parentFcOrderId,
            'order_type'          => 'order',
            'vendor_charge_id'    => '',
            'payment_method'      => 'wc_migrated',
            'payment_mode'        => 'live',
            'payment_method_type' => 'wc_migrated',
            'currency'            => $currency,
            'transaction_type'    => 'refund',
            'status'              => 'refunded',
            'total'               => MoneyHelper::toCents($refundAmount, $currency),
            'rate'                => 1,
            'meta'                => [
                'wc_refund_id' => $refund->get_id(),
                'reason'       => $refund->get_reason(),
            ],
            'created_at'          => $refund->get_date_created()
                ? $refund->get_date_created()->date('Y-m-d H:i:s')
                : gmdate('Y-m-d H:i:s'),
        ];

        $fcTransaction = OrderTransaction::query()->create($transactionData);
        $transactionKey = "{$wcOrderId}_refund_{$refund->get_id()}";
        $this->idMap->store(Constants::ENTITY_ORDER_TRANSACTION, $transactionKey, $fcTransaction->id, $this->migrationId(), true);
    }

    /**
     * FIX M16: If the order has partial refunds (sum < total, sum > 0),
     * update the FC order payment_status to 'partially_refunded'.
     */
    private function updatePartialRefundStatus(\WC_Order $wcOrder, int $fcOrderId): void
    {
        $currency     = $wcOrder->get_currency();
        $totalRefund  = MoneyHelper::toCents($wcOrder->get_total_refunded(), $currency);
        $orderTotal   = MoneyHelper::toCents($wcOrder->get_total(), $currency);

        if ($totalRefund > 0 && $totalRefund < $orderTotal) {
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'fct_orders',
                ['payment_status' => 'partially_refunded'],
                ['id' => $fcOrderId],
                ['%s'],
                ['%d'],
            );
        }
    }

    /**
     * Migrate applied WC coupons to FC's fct_applied_coupons table.
     */
    private function migrateAppliedCoupons(\WC_Order $wcOrder, int $fcOrderId): void
    {
        $currency = $wcOrder->get_currency();

        /** @var \WC_Order_Item_Coupon $couponItem */
        foreach ($wcOrder->get_items('coupon') as $couponItem) {
            $code     = $couponItem->get_code();
            $discount = MoneyHelper::toCents($couponItem->get_discount(), $currency);

            // Try to resolve the FC coupon ID from the WC coupon.
            $wcCouponId = $couponItem->get_meta('coupon_id') ?: 0;
            if (!$wcCouponId) {
                // Fallback: look up by code via wc_get_coupon_id_by_code.
                $wcCouponId = wc_get_coupon_id_by_code($code) ?: 0;
            }

            $fcCouponId = $wcCouponId
                ? $this->idMap->getFcId(Constants::ENTITY_COUPON, (string) $wcCouponId)
                : null;

            AppliedCoupon::query()->create([
                'order_id'  => $fcOrderId,
                'coupon_id' => $fcCouponId ?: 0,
                'code'      => $code,
                'amount'    => $discount,
            ]);
        }
    }

    /**
     * Apply per-item refund amounts from WC refunds to FC order items.
     * Aggregates refund amounts across all refunds per product/variation.
     */
    private function applyPerItemRefunds(\WC_Order $wcOrder, int $fcOrderId, int $wcOrderId): void
    {
        $refunds = $wcOrder->get_refunds();

        if (empty($refunds)) {
            return;
        }

        $currency = $wcOrder->get_currency();

        // Aggregate refund amounts per order item index.
        // We match by iterating the parent order items in the same order as mapItems().
        $parentItems = [];
        $index = 0;
        foreach ($wcOrder->get_items() as $item) {
            if (!($item instanceof \WC_Order_Item_Product)) {
                continue;
            }
            $parentItems[$item->get_id()] = $index;
            $index++;
        }

        // Accumulate refunded cents per item index.
        $refundByIndex = [];

        foreach ($refunds as $refund) {
            foreach ($refund->get_items() as $refundItem) {
                if (!($refundItem instanceof \WC_Order_Item_Product)) {
                    continue;
                }

                // WC refund items reference parent item via get_meta('_refunded_item_id')
                // but more reliably, the refund item shares the same product/variation mapping.
                $refundedItemId = (int) $refundItem->get_meta('_refunded_item_id');

                if (!$refundedItemId || !isset($parentItems[$refundedItemId])) {
                    continue;
                }

                $itemIndex = $parentItems[$refundedItemId];
                $refundAmount = abs(floatval($refundItem->get_total()));

                if ($refundAmount <= 0) {
                    continue;
                }

                $refundCents = MoneyHelper::toCents($refundAmount, $currency);

                if (!isset($refundByIndex[$itemIndex])) {
                    $refundByIndex[$itemIndex] = 0;
                }
                $refundByIndex[$itemIndex] += $refundCents;
            }
        }

        // Update each FC order item with accumulated refund_total.
        if (empty($refundByIndex)) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'fct_order_items';

        foreach ($refundByIndex as $itemIndex => $refundCents) {
            $itemKey = "{$wcOrderId}_{$itemIndex}";
            $fcItemId = $this->idMap->getFcId(Constants::ENTITY_ORDER_ITEM, $itemKey);

            if (!$fcItemId) {
                continue;
            }

            $wpdb->update(
                $table,
                ['refund_total' => $refundCents],
                ['id' => $fcItemId],
                ['%d'],
                ['%d'],
            );
        }
    }

    /**
     * Migrate key WC order meta fields to FC order meta.
     * Stores transaction_id, customer_note, billing_phone, shipping_phone, and order_key.
     */
    private function migrateKeyOrderMeta(\WC_Order $wcOrder, int $fcOrderId): void
    {
        $metaEntries = [];

        $transactionId = $wcOrder->get_transaction_id();
        if ($transactionId) {
            $metaEntries['_transaction_id'] = $transactionId;
        }

        $customerNote = $wcOrder->get_customer_note();
        if ($customerNote) {
            $metaEntries['_customer_note'] = $customerNote;
        }

        $billingPhone = $wcOrder->get_billing_phone();
        if ($billingPhone) {
            $metaEntries['_billing_phone'] = $billingPhone;
        }

        $shippingPhone = $wcOrder->get_shipping_phone();
        if ($shippingPhone) {
            $metaEntries['_shipping_phone'] = $shippingPhone;
        }

        $orderKey = $wcOrder->get_order_key();
        if ($orderKey) {
            $metaEntries['_order_key'] = $orderKey;
        }

        foreach ($metaEntries as $key => $value) {
            OrderMeta::query()->create([
                'order_id'   => $fcOrderId,
                'meta_key'   => $key,
                'meta_value' => $value,
            ]);
        }
    }

    /**
     * FIX M7: Migrate WooCommerce order notes to FC order meta.
     * Each note is stored as a separate 'wc_note' meta entry.
     *
     * Notes live in wp_comments keyed by order ID under both order backends.
     * Two things matter here:
     *
     * 1. Only comment_type = 'order_note' counts. Matching comment_type = ''
     *    as well used to drag in ordinary blog comments, because under HPOS
     *    order IDs and post IDs come from different sequences and can collide.
     * 2. The customer-visible flag is real data, not a guess. WooCommerce
     *    writes it to commentmeta under 'is_customer_note', and only when the
     *    note is a customer note — so an absent row means "private note".
     *    A correlated subquery reads it without an N+1.
     *
     * @see woocommerce/includes/class-wc-order.php::add_order_note() (v11.0.0, lines 2104, 2110, 2117)
     */
    private function migrateOrderNotes(int $wcOrderId, int $fcOrderId): void
    {
        global $wpdb;

        $notes = $wpdb->get_results($wpdb->prepare(
            "SELECT c.comment_content,
                    c.comment_date_gmt,
                    c.comment_author,
                    (SELECT cm.meta_value
                       FROM {$wpdb->commentmeta} AS cm
                      WHERE cm.comment_id = c.comment_ID
                        AND cm.meta_key = %s
                      LIMIT 1) AS is_customer_note
             FROM {$wpdb->comments} AS c
             WHERE c.comment_post_ID = %d
               AND c.comment_type = %s
               AND c.comment_approved = '1'
             ORDER BY c.comment_date_gmt ASC",
            'is_customer_note',
            $wcOrderId,
            'order_note',
        ));

        if (empty($notes)) {
            return;
        }

        foreach ($notes as $note) {
            OrderMeta::query()->create([
                'order_id'   => $fcOrderId,
                'meta_key'   => 'wc_note',
                'meta_value' => [
                    'content'       => $note->comment_content,
                    'added_by'      => $note->comment_author ?: 'system',
                    'customer_note' => self::isCustomerNote($note->is_customer_note ?? null),
                    'date'          => $note->comment_date_gmt,
                ],
            ]);
        }
    }

    /**
     * Interpret the raw 'is_customer_note' commentmeta value.
     *
     * WooCommerce writes the integer 1 and never writes a falsey row, but read
     * it the way wc_string_to_bool() would so hand-edited data behaves.
     */
    private static function isCustomerNote(mixed $rawMetaValue): bool
    {
        if ($rawMetaValue === null || $rawMetaValue === false) {
            return false;
        }

        if (is_bool($rawMetaValue)) {
            return $rawMetaValue;
        }

        return in_array(
            strtolower(trim((string) $rawMetaValue)),
            ['1', 'yes', 'true', 'on'],
            true,
        );
    }
}

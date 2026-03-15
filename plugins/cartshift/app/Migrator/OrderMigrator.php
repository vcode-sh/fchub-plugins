<?php

declare(strict_types=1);

namespace CartShift\Migrator;

defined('ABSPATH') || exit;

use CartShift\Domain\Mapping\OrderMapper;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\Constants;
use CartShift\Support\MoneyHelper;
use FluentCart\App\Models\AppliedCoupon;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderAddress;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\OrderMeta;
use FluentCart\App\Models\OrderTransaction;

final class OrderMigrator extends AbstractMigrator
{
    private readonly OrderMapper $orderMapper;

    public function __construct(
        IdMapRepository $idMap,
        MigrationLogRepository $log,
        MigrationState $migrationState,
        string $migrationId,
        int $batchSize = Constants::DEFAULT_BATCH_SIZE,
    ) {
        parent::__construct($idMap, $log, $migrationState, $migrationId, $batchSize);

        $currency = get_woocommerce_currency();
        $this->orderMapper = new OrderMapper($idMap, $currency);
    }

    #[\Override]
    protected function getEntityType(): string
    {
        return Constants::ENTITY_ORDER;
    }

    #[\Override]
    protected function countTotal(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$wpdb->prefix}wc_orders
             WHERE type = 'shop_order'",
        );
    }

    #[\Override]
    public function fetchBatch(int $offset, int $limit): array
    {
        return wc_get_orders([
            'limit'   => $limit,
            'offset'  => $offset,
            'status'  => 'any',
            'type'    => 'shop_order',
            'orderby' => 'ID',
            'order'   => 'ASC',
        ]);
    }

    /**
     * @param \WC_Order $wcOrder
     */
    #[\Override]
    public function processRecord(mixed $wcOrder): int|false
    {
        $wcId = $wcOrder->get_id();

        if ($this->idMap->getFcId(Constants::ENTITY_ORDER, (string) $wcId)) {
            $this->writeLog($wcId, 'skipped', 'Already migrated.');
            return false;
        }

        // FIX C4: validate customer_id FK before creating order.
        $wcCustomerId = $wcOrder->get_customer_id();
        if ($wcCustomerId > 0) {
            $fcCustomerId = $this->idMap->getFcId(Constants::ENTITY_CUSTOMER, (string) $wcCustomerId);
            if (!$fcCustomerId) {
                // Try guest lookup by email.
                $email = $wcOrder->get_billing_email();
                $fcCustomerId = $email
                    ? $this->idMap->getFcId(Constants::ENTITY_GUEST_CUSTOMER, $email)
                    : null;

                if (!$fcCustomerId) {
                    $this->writeLog(
                        $wcId,
                        'warning',
                        sprintf('Customer ID %d not found in ID map. Skipping order.', $wcCustomerId),
                    );
                    return false;
                }
            }
        }

        $mapped = $this->orderMapper->map($wcOrder);

        // 1. Create the FC order.
        $fcOrder = Order::query()->create($mapped['order']);
        $this->idMap->store(Constants::ENTITY_ORDER, (string) $wcId, $fcOrder->id, $this->migrationId, true);

        // 2. Create order items with compound keys (FIX C7).
        $totalQuantity = 0;
        foreach ($mapped['items'] as $index => $itemData) {
            $itemData['order_id'] = $fcOrder->id;
            $fcItem = OrderItem::query()->create($itemData);
            $totalQuantity += (int) ($itemData['quantity'] ?? 1);
            $itemKey = "{$wcId}_{$index}";
            $this->idMap->store(Constants::ENTITY_ORDER_ITEM, $itemKey, $fcItem->id, $this->migrationId, true);
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
            $this->idMap->store(Constants::ENTITY_ORDER_ADDRESS, $addressKey, $fcAddress->id, $this->migrationId, true);
        }

        // 4. Create order transaction with compound key (FIX C7).
        if ($mapped['transaction']) {
            $transactionData = $mapped['transaction'];
            $transactionData['order_id'] = $fcOrder->id;
            $fcTransaction = OrderTransaction::query()->create($transactionData);
            $transactionKey = "{$wcId}_charge";
            $this->idMap->store(Constants::ENTITY_ORDER_TRANSACTION, $transactionKey, $fcTransaction->id, $this->migrationId, true);
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
        $this->idMap->store(Constants::ENTITY_ORDER_TRANSACTION, $transactionKey, $fcTransaction->id, $this->migrationId, true);
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
     */
    private function migrateOrderNotes(int $wcOrderId, int $fcOrderId): void
    {
        global $wpdb;

        // Query WC order notes directly from wp_comments (works regardless of HPOS).
        $notes = $wpdb->get_results($wpdb->prepare(
            "SELECT comment_content, comment_date_gmt, comment_author, comment_author_email
             FROM {$wpdb->comments}
             WHERE comment_post_ID = %d
               AND comment_type IN ('order_note', '')
               AND comment_approved = '1'
             ORDER BY comment_date_gmt ASC",
            $wcOrderId,
        ));

        if (empty($notes)) {
            return;
        }

        foreach ($notes as $note) {
            $isCustomerNote = ($note->comment_author_email === '' || $note->comment_author === __('WooCommerce', 'woocommerce'));

            OrderMeta::query()->create([
                'order_id'   => $fcOrderId,
                'meta_key'   => 'wc_note',
                'meta_value' => [
                    'content'       => $note->comment_content,
                    'added_by'      => $note->comment_author ?: 'system',
                    'customer_note' => !$isCustomerNote,
                    'date'          => $note->comment_date_gmt,
                ],
            ]);
        }
    }
}

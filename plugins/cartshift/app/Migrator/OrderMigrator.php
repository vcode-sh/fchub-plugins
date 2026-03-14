<?php

namespace CartShift\Migrator;

defined('ABSPATH') or die;

use CartShift\Mapper\OrderMapper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\OrderAddress;
use FluentCart\App\Models\OrderTransaction;

class OrderMigrator extends AbstractMigrator
{
    protected string $entityType = 'orders';

    protected function countTotal(): int
    {
        return count(wc_get_orders([
            'limit'  => -1,
            'return' => 'ids',
            'status' => 'any',
            'type'   => 'shop_order',
        ]));
    }

    protected function fetchBatch(int $page): array
    {
        return wc_get_orders([
            'limit'   => $this->batchSize,
            'page'    => $page,
            'status'  => 'any',
            'type'    => 'shop_order',
            'orderby' => 'ID',
            'order'   => 'ASC',
        ]);
    }

    /**
     * @param \WC_Order $wcOrder
     */
    protected function processRecord($wcOrder)
    {
        $wcId = $wcOrder->get_id();

        // Skip if already migrated.
        if ($this->idMap->getFcId('order', $wcId)) {
            $this->log($wcId, 'skipped', 'Already migrated.');
            return false;
        }

        $mapped = OrderMapper::map($wcOrder, $this->idMap);

        if ($this->dryRun) {
            $this->log($wcId, 'success', sprintf(
                '[DRY RUN] Would migrate order #%d - Status: %s, Total: %s, Items: %d.',
                $wcId,
                $wcOrder->get_status(),
                $wcOrder->get_formatted_order_total(),
                count($mapped['items'])
            ));
            return 0;
        }

        // 1. Create the FC order.
        $fcOrder = Order::query()->create($mapped['order']);
        $this->idMap->store('order', $wcId, $fcOrder->id);

        // 2. Create order items.
        foreach ($mapped['items'] as $itemData) {
            $itemData['order_id'] = $fcOrder->id;
            $fcItem = OrderItem::query()->create($itemData);
            $this->idMap->store('order_item', $wcId, $fcItem->id);
        }

        // 3. Create order addresses.
        foreach ($mapped['addresses'] as $addressData) {
            $addressData['order_id'] = $fcOrder->id;
            $fcAddress = OrderAddress::query()->create($addressData);
            $this->idMap->store('order_address', $wcId, $fcAddress->id);
        }

        // 4. Create order transaction.
        if ($mapped['transaction']) {
            $transactionData = $mapped['transaction'];
            $transactionData['order_id'] = $fcOrder->id;
            $fcTransaction = OrderTransaction::query()->create($transactionData);
            $this->idMap->store('order_transaction', $wcId, $fcTransaction->id);
        }

        // 5. Handle refund child orders.
        $refunds = $wcOrder->get_refunds();
        foreach ($refunds as $refund) {
            $this->processRefund($refund, $fcOrder->id);
        }

        $this->log($wcId, 'success', sprintf(
            'Migrated order #%d (FC ID: %d) - Status: %s, Total: %s.',
            $wcId,
            $fcOrder->id,
            $wcOrder->get_status(),
            $wcOrder->get_formatted_order_total()
        ));

        return $fcOrder->id;
    }

    /**
     * Process a refund as a child order transaction.
     */
    private function processRefund(\WC_Order_Refund $refund, int $parentFcOrderId): void
    {
        $refundAmount = abs(floatval($refund->get_amount()));
        if ($refundAmount <= 0) {
            return;
        }

        $transactionData = [
            'order_id'            => $parentFcOrderId,
            'order_type'          => 'order',
            'vendor_charge_id'    => '',
            'payment_method'      => 'wc_migrated',
            'payment_mode'        => 'live',
            'payment_method_type' => 'wc_migrated',
            'currency'            => $refund->get_currency(),
            'transaction_type'    => 'refund',
            'status'              => 'refunded',
            'total'               => intval(round($refundAmount * 100)),
            'rate'                => 1,
            'meta'                => json_encode([
                'wc_refund_id' => $refund->get_id(),
                'reason'       => $refund->get_reason(),
            ]),
            'created_at'          => $refund->get_date_created()
                ? $refund->get_date_created()->date('Y-m-d H:i:s')
                : gmdate('Y-m-d H:i:s'),
        ];

        OrderTransaction::query()->create($transactionData);
    }
}

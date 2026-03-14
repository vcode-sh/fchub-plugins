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
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderAddress;
use FluentCart\App\Models\OrderItem;
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
    protected function fetchBatch(int $offset, int $limit): array
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
    protected function processRecord(mixed $wcOrder): int|false
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
        foreach ($mapped['items'] as $index => $itemData) {
            $itemData['order_id'] = $fcOrder->id;
            $fcItem = OrderItem::query()->create($itemData);
            $itemKey = "{$wcId}_{$index}";
            $this->idMap->store(Constants::ENTITY_ORDER_ITEM, $itemKey, $fcItem->id, $this->migrationId, true);
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
}

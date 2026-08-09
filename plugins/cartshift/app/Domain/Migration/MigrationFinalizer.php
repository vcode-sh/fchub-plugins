<?php

declare(strict_types=1);

namespace CartShift\Domain\Migration;

defined('ABSPATH') || exit;

use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\Constants;

final class MigrationFinalizer
{
    private const int BATCH_SIZE = 100;

    /**
     * Order payment statuses that count towards customer stats.
     *
     * Mirrors FluentCart's Status::getOrderPaymentSuccessStatuses()
     * (fluent-cart/app/Helpers/Status.php:328-335), which is what
     * Customer::recountStat() filters on.
     *
     * @var string[]
     */
    private const array SUCCESS_PAYMENT_STATUSES = [
        'paid',
        'partially_refunded',
        'partially_paid',
    ];

    public function __construct(
        private readonly IdMapRepository $idMap,
        private readonly MigrationLogRepository $log,
    ) {
    }

    /**
     * Run all post-migration finalization steps.
     *
     * @return array{customers_updated: int, caches_cleared: bool}
     */
    public function finalize(string $migrationId): array
    {
        $customersUpdated = $this->recalculateCustomerStats($migrationId);

        $this->clearCaches();

        /** @see 'cartshift/migration/finalized' */
        do_action('cartshift/migration/finalized', $migrationId);

        return [
            'customers_updated' => $customersUpdated,
            'caches_cleared'    => true,
        ];
    }

    /**
     * Recalculate purchase stats for every migrated customer.
     *
     * Processes in batches to avoid memory exhaustion on large datasets.
     *
     * Column semantics, taken from FluentCart itself rather than guessed:
     *
     * - `ltv` is net lifetime value in cents. FluentCart stores `total_paid`
     *   ALREADY net of refunds — Order::recountTotalPaid() writes
     *   `max(0, paid - refunded)` (app/Models/Order.php:898-906) and every refund
     *   path decrements it in place (OrderTransaction.php:245-247,
     *   StripeGateway/Webhook/IPN.php:303-305). `total_refund` holds the refunded
     *   amount separately (Order.php:818, 834). CartShift writes the same invariant:
     *   OrderMapper::getTotalPaid() returns `total - refunded`. So LTV is
     *   SUM(total_paid) and nothing else — subtracting total_refund again would
     *   deduct every refund twice. Per-order flooring at zero mirrors the
     *   `if ($netPaid > 0)` guard in Customer::recountStat() (Customer.php:210-215).
     *
     * - `purchase_value` is GROSS per-currency turnover, JSON keyed by currency,
     *   e.g. {"USD": 12345}. FluentCart's own WooCommerce importer builds it from
     *   SUM(_order_total) — gross order totals, no refunds deducted
     *   (WooCommerceMigrator/Services/CustomerMigrationService.php:254, 281, 303).
     *   `total_amount` is CartShift's equivalent of that column. It is deliberately
     *   NOT the same figure as `ltv`; net vs gross is the intended difference.
     *
     * - `aov` is ltv / purchase_count, matching Customer::recountStat()
     *   (Customer.php:221), rounded because the column is BIGINT cents.
     *
     * Note: FluentCart's Customer::recountStat() computes
     * `total_paid - total_refund`, which double-counts refunds against its own
     * storage invariant. That is a FluentCart bug, not a format to copy — running
     * it would understate LTV for FluentCart-native orders too.
     */
    public function recalculateCustomerStats(string $migrationId): int
    {
        global $wpdb;

        $mappings = $this->idMap->getAllByEntityType(Constants::ENTITY_CUSTOMER, $migrationId);
        $guestMappings = $this->idMap->getAllByEntityType(Constants::ENTITY_GUEST_CUSTOMER, $migrationId);
        $allMappings = array_merge($mappings, $guestMappings);

        if (empty($allMappings)) {
            return 0;
        }

        $fcIds = array_map(static fn (object $m): int => (int) $m->fc_id, $allMappings);
        $fcIds = array_unique($fcIds);

        // Kept so a refused write below can be reported against the WooCommerce
        // customer the owner recognises rather than the FluentCart id they have
        // never seen. First mapping wins, matching the ID map's own read order.
        $wcIdByFcId = [];

        foreach ($allMappings as $mapping) {
            $wcIdByFcId[(int) $mapping->fc_id] ??= (string) $mapping->wc_id;
        }

        $ordersTable = $wpdb->prefix . 'fct_orders';
        $customersTable = $wpdb->prefix . 'fct_customers';
        $updated = 0;

        $statusPlaceholders = implode(',', array_fill(0, count(self::SUCCESS_PAYMENT_STATUSES), '%s'));

        foreach (array_chunk($fcIds, self::BATCH_SIZE) as $batch) {
            $placeholders = implode(',', array_fill(0, count($batch), '%d'));
            $queryArgs = [...$batch, ...self::SUCCESS_PAYMENT_STATUSES];

            // Fetch order-level stats grouped by customer.
            // total_paid is already net of refunds — see the docblock above.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $stats = $wpdb->get_results($wpdb->prepare(
                "SELECT
                    customer_id,
                    COUNT(*) AS order_count,
                    COALESCE(SUM(GREATEST(total_paid, 0)), 0) AS ltv,
                    MAX(created_at) AS last_order,
                    MIN(created_at) AS first_order
                FROM {$ordersTable}
                WHERE customer_id IN ({$placeholders})
                  AND payment_status IN ({$statusPlaceholders})
                GROUP BY customer_id",
                ...$queryArgs,
            ));

            $statsMap = [];
            foreach ($stats as $row) {
                $statsMap[(int) $row->customer_id] = $row;
            }

            // Fetch per-currency totals for purchase_value JSON. Gross by design:
            // purchase_value is turnover, ltv is net. See the docblock above.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $currencyStats = $wpdb->get_results($wpdb->prepare(
                "SELECT
                    customer_id,
                    currency,
                    COALESCE(SUM(total_amount), 0) AS currency_total
                FROM {$ordersTable}
                WHERE customer_id IN ({$placeholders})
                  AND payment_status IN ({$statusPlaceholders})
                GROUP BY customer_id, currency",
                ...$queryArgs,
            ));

            $currencyMap = [];
            foreach ($currencyStats as $row) {
                $cid = (int) $row->customer_id;
                $currencyMap[$cid][$row->currency] = (int) $row->currency_total;
            }

            foreach ($batch as $customerId) {
                $row = $statsMap[$customerId] ?? null;

                $count = $row ? (int) $row->order_count : 0;
                $ltv = $row ? (int) $row->ltv : 0;
                $lastOrder = $row ? $row->last_order : null;
                $firstOrder = $row ? $row->first_order : null;
                $aov = $count > 0 ? (int) round($ltv / $count) : 0;

                $purchaseValue = $currencyMap[$customerId] ?? [];

                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->update(
                    $customersTable,
                    [
                        'purchase_count'      => $count,
                        'purchase_value'      => wp_json_encode($purchaseValue),
                        'ltv'                 => $ltv,
                        'aov'                 => $aov,
                        'first_purchase_date' => $firstOrder,
                        'last_purchase_date'  => $lastOrder,
                    ],
                    ['id' => $customerId],
                    ['%d', '%s', '%d', '%d', '%s', '%s'],
                    ['%d'],
                );

                // Lifetime value, average order value and purchase count drive
                // the customer list, the segments and the reports. A row that
                // silently kept its zeroes reads as a customer who has never
                // bought anything, which is exactly the sort of wrong number
                // nobody thinks to question.
                if ($this->log->recordWriteFailure(
                    $migrationId,
                    Constants::ENTITY_CUSTOMER,
                    $wcIdByFcId[$customerId] ?? 0,
                    'the recalculated purchase stats',
                )) {
                    continue;
                }

                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Flush WordPress object cache and FC-specific transients.
     *
     * A full flush once, at the end of a migration, is defensible: the store's
     * cached product/order data is now wrong wholesale. It is still a blunt
     * instrument on a shared Redis or Memcached, so it is opt-out via
     * 'cartshift/finalize/flush_object_cache'. Declining it still clears the
     * in-process cache, which costs nobody anything.
     */
    public function clearCaches(): void
    {
        /** @see 'cartshift/finalize/flush_object_cache' */
        $fullFlush = (bool) apply_filters('cartshift/finalize/flush_object_cache', true);

        if ($fullFlush) {
            wp_cache_flush();
        } else {
            MigrationOrchestrator::flushRuntimeCache();
        }

        global $wpdb;

        // Clear any CartShift/FluentCart transients.
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_fct_%'
                OR option_name LIKE '_transient_timeout_fct_%'
                OR option_name LIKE '_transient_cartshift_%'
                OR option_name LIKE '_transient_timeout_cartshift_%'",
        );
    }
}

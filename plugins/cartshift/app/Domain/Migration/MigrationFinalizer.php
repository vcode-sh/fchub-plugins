<?php

declare(strict_types=1);

namespace CartShift\Domain\Migration;

defined('ABSPATH') || exit;

use CartShift\Storage\IdMapRepository;
use CartShift\Support\Constants;

final class MigrationFinalizer
{
    private const int BATCH_SIZE = 100;

    public function __construct(
        private readonly IdMapRepository $idMap,
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
     * Matches FluentCart's CustomerMigrationService::calculateCustomerStats() format:
     * - purchase_value is JSON keyed by currency, e.g. {"USD": 12345}
     * - aov is average order value in cents
     * - ltv is lifetime value (total_paid - total_refund)
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

        $ordersTable = $wpdb->prefix . 'fct_orders';
        $customersTable = $wpdb->prefix . 'fct_customers';
        $updated = 0;

        foreach (array_chunk($fcIds, self::BATCH_SIZE) as $batch) {
            $placeholders = implode(',', array_fill(0, count($batch), '%d'));

            // Fetch order-level stats grouped by customer.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $stats = $wpdb->get_results($wpdb->prepare(
                "SELECT
                    customer_id,
                    COUNT(*) AS order_count,
                    COALESCE(SUM(total_paid - total_refund), 0) AS ltv,
                    MAX(created_at) AS last_order,
                    MIN(created_at) AS first_order
                FROM {$ordersTable}
                WHERE customer_id IN ({$placeholders})
                  AND payment_status IN ('paid', 'partially_refunded')
                GROUP BY customer_id",
                ...$batch,
            ));

            $statsMap = [];
            foreach ($stats as $row) {
                $statsMap[(int) $row->customer_id] = $row;
            }

            // Fetch per-currency totals for purchase_value JSON.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $currencyStats = $wpdb->get_results($wpdb->prepare(
                "SELECT
                    customer_id,
                    currency,
                    COALESCE(SUM(total_amount), 0) AS currency_total
                FROM {$ordersTable}
                WHERE customer_id IN ({$placeholders})
                  AND payment_status IN ('paid', 'partially_refunded')
                GROUP BY customer_id, currency",
                ...$batch,
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
                $lastOrder = $row->last_order ?? null;
                $firstOrder = $row->first_order ?? null;
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

                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Flush WordPress object cache and FC-specific transients.
     */
    public function clearCaches(): void
    {
        wp_cache_flush();

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

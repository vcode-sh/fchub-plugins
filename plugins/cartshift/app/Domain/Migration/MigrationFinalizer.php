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
     * Uses the same stat columns as FluentCart's Customer::recountStat().
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

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $stats = $wpdb->get_results($wpdb->prepare(
                "SELECT
                    customer_id,
                    COUNT(*) AS order_count,
                    COALESCE(SUM(total_amount), 0) AS total_value,
                    MAX(created_at) AS last_order,
                    MIN(created_at) AS first_order
                FROM {$ordersTable}
                WHERE customer_id IN ({$placeholders})
                GROUP BY customer_id",
                ...$batch,
            ));

            $statsMap = [];
            foreach ($stats as $row) {
                $statsMap[(int) $row->customer_id] = $row;
            }

            foreach ($batch as $customerId) {
                $row = $statsMap[$customerId] ?? null;

                $count = $row ? (int) $row->order_count : 0;
                $total = $row ? (float) $row->total_value : 0.0;
                $lastOrder = $row->last_order ?? null;
                $firstOrder = $row->first_order ?? null;
                $aov = $count > 0 ? round($total / $count, 2) : 0;

                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->update(
                    $customersTable,
                    [
                        'purchase_count'      => $count,
                        'ltv'                 => (int) $total,
                        'first_purchase_date' => $firstOrder,
                        'last_purchase_date'  => $lastOrder,
                        'aov'                 => $aov,
                    ],
                    ['id' => $customerId],
                    ['%d', '%d', '%s', '%s', '%s'],
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

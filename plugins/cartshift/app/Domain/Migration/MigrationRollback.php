<?php

declare(strict_types=1);

namespace CartShift\Domain\Migration;

defined('ABSPATH') || exit;

use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\Constants;

final class MigrationRollback
{
    public function __construct(
        private readonly IdMapRepository $idMap,
        private readonly MigrationLogRepository $log,
    ) {
    }

    /**
     * Roll back all records created by a specific migration.
     *
     * @return array<string, int> Counts of deleted records per entity type.
     */
    public function rollback(string $migrationId): array
    {
        $stats = [];

        foreach (Constants::ROLLBACK_ORDER as $entityType) {
            $mappings = $this->idMap->getCreatedByMigration($entityType, $migrationId);
            $count = 0;

            foreach ($mappings as $mapping) {
                $this->deleteRecord($entityType, (int) $mapping->fc_id);
                $count++;
            }

            if ($count > 0) {
                $stats[$entityType] = $count;

                $this->log->write(
                    $migrationId,
                    $entityType,
                    0,
                    'rollback',
                    sprintf('Rolled back %d %s record(s).', $count, $entityType),
                );
            }
        }

        $this->idMap->deleteCreatedByMigration($migrationId);

        /** @see 'cartshift/migration/rolled_back' */
        do_action('cartshift/migration/rolled_back', $migrationId, $stats);

        return $stats;
    }

    /**
     * Delete a single FluentCart record using the appropriate method.
     */
    private function deleteRecord(string $entityType, int $fcId): void
    {
        match ($entityType) {
            Constants::ENTITY_PRODUCT => wp_delete_post($fcId, true),
            Constants::ENTITY_CATEGORY => wp_delete_term($fcId, 'product-categories'),
            default => $this->deleteFromTable($entityType, $fcId),
        };
    }

    /**
     * Delete a record from a FluentCart database table.
     */
    private function deleteFromTable(string $entityType, int $fcId): void
    {
        global $wpdb;

        $table = $this->resolveTable($entityType);

        if ($table === null) {
            return;
        }

        $wpdb->delete($table, ['id' => $fcId], ['%d']);
    }

    /**
     * Map entity type to its FluentCart database table.
     */
    private function resolveTable(string $entityType): string|null
    {
        global $wpdb;

        return match ($entityType) {
            Constants::ENTITY_VARIATION => $wpdb->prefix . 'fct_product_variations',
            Constants::ENTITY_PRODUCT_DETAIL => $wpdb->prefix . 'fct_product_details',
            Constants::ENTITY_CUSTOMER,
            Constants::ENTITY_GUEST_CUSTOMER => $wpdb->prefix . 'fct_customers',
            Constants::ENTITY_CUSTOMER_ADDRESS => $wpdb->prefix . 'fct_customer_addresses',
            Constants::ENTITY_ORDER => $wpdb->prefix . 'fct_orders',
            Constants::ENTITY_ORDER_ITEM => $wpdb->prefix . 'fct_order_items',
            Constants::ENTITY_ORDER_ADDRESS => $wpdb->prefix . 'fct_order_addresses',
            Constants::ENTITY_ORDER_TRANSACTION => $wpdb->prefix . 'fct_order_transactions',
            Constants::ENTITY_COUPON => $wpdb->prefix . 'fct_coupons',
            Constants::ENTITY_SUBSCRIPTION => $wpdb->prefix . 'fct_subscriptions',
            Constants::ENTITY_SHIPPING_CLASS => $wpdb->prefix . 'fct_shipping_classes',
            default => null,
        };
    }
}

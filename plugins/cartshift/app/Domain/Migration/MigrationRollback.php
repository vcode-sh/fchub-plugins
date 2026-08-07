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

        // Read every mapping up front. The child-table cleanup below needs parent ids
        // that the deletion loop is about to destroy, and reading once beats reading
        // the same rows twice.
        $mappingsByType = [];

        foreach (Constants::ROLLBACK_ORDER as $entityType) {
            $mappingsByType[$entityType] = $this->idMap->getCreatedByMigration($entityType, $migrationId);
        }

        // Children first, while their parents' ids are still meaningful.
        $stats += $this->deleteOrphanChildren($mappingsByType, $migrationId);

        foreach (Constants::ROLLBACK_ORDER as $entityType) {
            $count = 0;

            foreach ($mappingsByType[$entityType] as $mapping) {
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
     * Delete rows the migrators wrote but never mapped, keyed off their parent ids.
     *
     * Products, variations and orders reach the id-map; the download records,
     * variant thumbnails, applied coupons, order meta and attribute relations hung
     * off them do not. Without this they survive their parents as orphans, and on a
     * re-run they accumulate. See Constants::ROLLBACK_CHILD_TABLES.
     *
     * @param array<string, array<int, object>> $mappingsByType
     * @return array<string, int> Deleted row counts, keyed by table name.
     */
    private function deleteOrphanChildren(array $mappingsByType, string $migrationId): array
    {
        global $wpdb;

        $stats = [];

        foreach (Constants::ROLLBACK_CHILD_TABLES as $spec) {
            $parentIds = [];

            foreach ($mappingsByType[$spec['parent']] ?? [] as $mapping) {
                $fcId = (int) $mapping->fc_id;

                if ($fcId > 0) {
                    $parentIds[$fcId] = true;
                }
            }

            if (empty($parentIds)) {
                continue;
            }

            $table = $wpdb->prefix . $spec['table'];
            $column = $spec['column'];
            $objectType = $spec['object_type'] ?? null;
            $deleted = 0;

            foreach (array_chunk(array_keys($parentIds), Constants::ROLLBACK_DELETE_CHUNK) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
                $args = $chunk;

                $sql = "DELETE FROM {$table} WHERE {$column} IN ({$placeholders})";

                if ($objectType !== null) {
                    $sql .= ' AND object_type = %s';
                    $args[] = $objectType;
                }

                // Table and column come from a hardcoded constant, ids and object_type
                // are prepared. phpcs cannot see through the interpolation.
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $result = $wpdb->query($wpdb->prepare($sql, ...$args));

                if (is_int($result) && $result > 0) {
                    $deleted += $result;
                }
            }

            if ($deleted > 0) {
                $stats[$spec['table']] = $deleted;

                $this->log->write(
                    $migrationId,
                    $spec['table'],
                    0,
                    'rollback',
                    sprintf('Rolled back %d orphan row(s) from %s.', $deleted, $spec['table']),
                );
            }
        }

        return $stats;
    }

    /**
     * Delete a single FluentCart record using the appropriate method.
     *
     * Products go through wp_delete_post($id, true), which is doing more work than it
     * looks: WordPress deletes the post's meta and its term relationships as part of
     * the same call. So `fluent-products-gallery-image` and `_wc_product_tags`, both
     * stored as post meta, and every category/brand/tag assignment go with the post.
     * Only rows in FluentCart's own tables need the explicit cleanup above.
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

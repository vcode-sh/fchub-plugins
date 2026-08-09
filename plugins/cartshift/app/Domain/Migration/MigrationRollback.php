<?php

declare(strict_types=1);

namespace CartShift\Domain\Migration;

defined('ABSPATH') || exit;

use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\Constants;
use CartShift\Support\Enums\MigrationErrorCode;
use CartShift\Support\Logger;

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

        // Asked now because the answer is stored on the rows the loop below is
        // about to delete. See survivingVariantParents().
        $survivors = $this->survivingVariantParents($mappingsByType);

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

        // Last, because it reads what is left rather than what there was.
        $this->refreshSurvivingProducts($survivors, $migrationId);

        $this->idMap->deleteCreatedByMigration($migrationId);

        /** @see 'cartshift/migration/rolled_back' */
        do_action('cartshift/migration/rolled_back', $migrationId, $stats);

        return $stats;
    }

    /**
     * The products that lose variants to this rollback but survive it.
     *
     * These are the owner's own products, linked on the mapping screen rather
     * than created by CartShift, which had orphan variants added inside them.
     * MappingPromoter flags those variants created_by_migration = 1 precisely so
     * rollback takes them back out — and taking them out is where the damage
     * used to happen. Adding one recomputes `fct_product_details.min_price` and
     * `max_price` (MigrationOrchestratorFactory::createOrphanVariant); removing
     * one did not, so the widened range outlived the variants that justified it.
     * A live rollback left a hand-built product quoting "up to £79.00" with a
     * dearest surviving variant of £12.30, and nothing would ever have moved it
     * back.
     *
     * Products CartShift created are excluded, and that exclusion is the whole
     * subtlety: their `fct_product_details` row and their post are deleted a few
     * lines below, so recomputing a range for them would be work done on a
     * corpse. The ID map already separates the two — a created product has a
     * created_by_migration = 1 row and a linked one does not, which is exactly
     * what getCreatedByMigration() selects on.
     *
     * Read before anything is deleted: the parent is `post_id` on the variation
     * row itself, and after the deletion loop there is nothing left to ask.
     *
     * @param  array<string, array<int, object>> $mappingsByType
     * @return list<int>                         FluentCart product post IDs.
     */
    private function survivingVariantParents(array $mappingsByType): array
    {
        global $wpdb;

        $variationIds = [];

        foreach ($mappingsByType[Constants::ENTITY_VARIATION] ?? [] as $mapping) {
            $fcId = (int) $mapping->fc_id;

            if ($fcId > 0) {
                $variationIds[$fcId] = true;
            }
        }

        if ($variationIds === []) {
            return [];
        }

        $doomed = [];

        foreach ($mappingsByType[Constants::ENTITY_PRODUCT] ?? [] as $mapping) {
            $doomed[(int) $mapping->fc_id] = true;
        }

        $table = $wpdb->prefix . 'fct_product_variations';
        $survivors = [];

        foreach (array_chunk(array_keys($variationIds), Constants::ROLLBACK_DELETE_CHUNK) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));

            // Table name is derived from the prefix, ids are prepared. phpcs
            // cannot see through the interpolation.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $parents = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$table} WHERE id IN ({$placeholders})",
                ...$chunk,
            ));

            foreach ($parents as $parent) {
                $postId = (int) $parent;

                if ($postId > 0 && !isset($doomed[$postId])) {
                    $survivors[$postId] = true;
                }
            }
        }

        return array_map('intval', array_keys($survivors));
    }

    /**
     * Put each survivor's price range and stock availability back in agreement
     * with the variants it still has.
     *
     * Shares MigrationOrchestratorFactory's implementation rather than carrying
     * a second one: add and remove disagreeing about how a range is derived is
     * the class of bug this exists to close, not one worth reintroducing.
     *
     * A throw is caught per product. Rollback is what an owner reaches for when
     * a migration has already gone wrong, and aborting it halfway over one
     * product's detail row would leave them with the worse half of both
     * outcomes. The failure is logged as an error because the consequence is
     * real and silent — the storefront keeps advertising a price nobody can buy.
     *
     * @param list<int> $postIds
     */
    private function refreshSurvivingProducts(array $postIds, string $migrationId): void
    {
        if ($postIds === []) {
            return;
        }

        $refreshed = 0;

        foreach ($postIds as $postId) {
            try {
                MigrationOrchestratorFactory::refreshProductRange($postId);
                $refreshed++;
            } catch (\Throwable $exception) {
                Logger::error('Could not recompute the price range after rollback.', [
                    'fc_post_id' => $postId,
                    'error'      => $exception->getMessage(),
                ]);

                $this->log->write(
                    $migrationId,
                    Constants::ENTITY_PRODUCT,
                    $postId,
                    'error',
                    sprintf(
                        'Removed migrated variants from FluentCart product #%d but could not recompute its price range: %s. '
                        . 'Open the product and save it to correct the range.',
                        $postId,
                        $exception->getMessage(),
                    ),
                    null,
                    MigrationErrorCode::UnexpectedException,
                );
            }
        }

        if ($refreshed > 0) {
            $this->log->write(
                $migrationId,
                Constants::ENTITY_PRODUCT,
                0,
                'rollback',
                sprintf('Recomputed the price range on %d product(s) that survived the rollback.', $refreshed),
            );
        }
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

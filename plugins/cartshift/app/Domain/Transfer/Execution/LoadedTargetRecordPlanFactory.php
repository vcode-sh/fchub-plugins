<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\Identity\CheckedMappingStore;
use CartShift\Domain\Transfer\RecordEnvelope;

defined('ABSPATH') || exit;

final class LoadedTargetRecordPlanFactory
{
    /** @param list<RecordEnvelope> $records */
    public static function create(
        TransferDecisionSet $decisions,
        CheckedMappingStore $maps,
        string $packageDirectory,
        array $records,
        string $evaluationUtc,
    ): TargetRecordPlanFactory {
        return new TargetRecordPlanFactory(
            $decisions,
            $maps,
            $packageDirectory,
            $records,
            self::targetTerms($decisions, $records),
            self::taxClasses(),
            self::capabilities(),
            self::shippingClasses(),
            self::paymentMode(),
            get_option('woocommerce_tax_round_at_subtotal', 'no') === 'yes',
            evaluationUtc: $evaluationUtc,
        );
    }

    /** @return array<string,bool> */
    private static function capabilities(): array
    {
        return ['shared_parent_stock' => false] + array_fill_keys([
            'asset_hash_roundtrip',
            'backorders_notify',
            'backorders_yes',
            'catalogue_fields_roundtrip',
            'custom_variation_attributes',
            'exact_price_x100',
            'exact_sale_scheduler',
            'extension_metadata_adapter',
            'global_unique_id_roundtrip',
            'provenance_readback',
            'review_provenance_roundtrip',
            'sales_provenance_roundtrip',
            'simple_variations',
            'stock_purchase_path',
            'subscription_finite_cycles',
            'subscription_setup_fee',
            'subscription_trial_days',
        ], true);
    }

    /** @return list<string> */
    private static function taxClasses(): array
    {
        global $wpdb;
        $rows = self::column("SELECT slug FROM {$wpdb->prefix}fct_tax_classes WHERE slug IS NOT NULL ORDER BY slug ASC");
        $classes = array_values(array_unique(array_filter(array_map('strval', $rows), static fn (string $slug): bool => $slug !== '')));
        $classes[] = 'none';
        $classes = array_values(array_unique($classes));
        sort($classes, SORT_STRING);
        return $classes;
    }

    /** @return array<string,int> */
    private static function shippingClasses(): array
    {
        global $wpdb;
        $rows = self::rows("SELECT id, name FROM {$wpdb->prefix}fct_shipping_classes WHERE name IS NOT NULL ORDER BY name ASC, id ASC");
        $classes = ['none' => 0];
        foreach ($rows as $row) {
            $slug = (string) ($row['name'] ?? '');
            $id = (int) ($row['id'] ?? 0);
            if ($slug === '' || $id <= 0 || isset($classes[$slug])) {
                throw new \RuntimeException('target_shipping_class_state_invalid');
            }
            if (preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $slug) !== 1) {
                throw new \RuntimeException('target_shipping_class_mapping_unavailable');
            }
            $classes[$slug] = $id;
        }
        ksort($classes, SORT_STRING);
        return $classes;
    }

    /**
     * @param list<RecordEnvelope> $records
     * @return list<array{taxonomy:string,slug:string,name:string,parent_source:?string,target_id:int}>
     */
    private static function targetTerms(TransferDecisionSet $decisions, array $records): array
    {
        global $wpdb;
        $sourceKey = $records[0]->identity->sourceKey ?? '';
        $mappedRows = self::rows($wpdb->prepare(
            "SELECT wc_id, fc_id FROM {$wpdb->prefix}cartshift_id_map
             WHERE source_key = %s AND entity_type = 'taxonomy_term' AND is_simulated = 0
               AND record_state <> 'rolled_back' ORDER BY wc_id ASC",
            $sourceKey,
        ));
        $sourceByTarget = [];
        foreach ($mappedRows as $row) {
            $sourceByTarget[(int) $row['fc_id']] = $sourceKey . ':taxonomy_term:' . (string) $row['wc_id'];
        }
        $rows = self::rows(
            "SELECT t.term_id AS target_id, t.slug, t.name, tt.taxonomy, tt.parent
             FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
             WHERE tt.taxonomy IN ('product-categories','product-brands') ORDER BY tt.taxonomy, t.slug, t.term_id",
        );
        $terms = [];
        foreach ($rows as $row) {
            $targetId = (int) ($row['target_id'] ?? 0);
            $parent = (int) ($row['parent'] ?? 0);
            if ($targetId <= 0 || (string) ($row['slug'] ?? '') === '' || (string) ($row['name'] ?? '') === '') {
                throw new \RuntimeException('target_taxonomy_state_invalid');
            }
            $terms[] = [
                'taxonomy' => (string) $row['taxonomy'],
                'slug' => (string) $row['slug'],
                'name' => (string) $row['name'],
                'parent_source' => $parent === 0 ? null : ($sourceByTarget[$parent] ?? '__unowned_target_parent__'),
                'target_id' => $targetId,
            ];
        }
        return $terms;
    }

    private static function paymentMode(): string
    {
        $settings = get_option('fluent_cart_store_settings', []);
        $mode = is_array($settings) ? ($settings['payment_mode'] ?? $settings['mode'] ?? null) : null;
        return $mode === 'test' ? 'test' : 'live';
    }

    /** @return list<array<string,mixed>> */
    private static function rows(string $sql): array
    {
        global $wpdb;
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException('target_projection_state_read_failed');
        }
        return is_array($rows) ? array_values(array_map(static fn ($row): array => (array) $row, $rows)) : [];
    }

    /** @return list<mixed> */
    private static function column(string $sql): array
    {
        global $wpdb;
        $wpdb->last_error = '';
        $rows = $wpdb->get_col($sql);
        if (trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException('target_projection_state_read_failed');
        }
        return is_array($rows) ? array_values($rows) : [];
    }
}

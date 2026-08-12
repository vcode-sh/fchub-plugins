<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Product;

use CartShift\Domain\Transfer\SourceRecordException;

defined('ABSPATH') || exit;

final class ProductFieldRegistry
{
    public const int VERSION = 1;

    /** @var array<string, array{disposition: string, projection: string, reason_code: string, evidence: string}> */
    private const array FIELDS = [
        'attributes' => ['disposition' => 'migrate', 'projection' => 'attributes', 'reason_code' => 'attribute_contract', 'evidence' => 'source_api'],
        'attribute_summary' => ['disposition' => 'inventory', 'projection' => 'attributes', 'reason_code' => 'derived_attribute_summary_rebuilt', 'evidence' => 'source_api'],
        'average_rating' => ['disposition' => 'inventory', 'projection' => 'review_provenance', 'reason_code' => 'review_decision_required', 'evidence' => 'source_api'],
        'backorders' => ['disposition' => 'migrate', 'projection' => 'stock', 'reason_code' => 'backorder_contract', 'evidence' => 'purchase_path'],
        'brand_ids' => ['disposition' => 'migrate', 'projection' => 'taxonomies', 'reason_code' => 'taxonomy_contract', 'evidence' => 'source_api'],
        'button_text' => ['disposition' => 'adapter', 'projection' => 'external', 'reason_code' => 'external_product_adapter_required', 'evidence' => 'adapter'],
        'catalog_visibility' => ['disposition' => 'inventory', 'projection' => 'catalog_visibility', 'reason_code' => 'catalogue_field_decision_required', 'evidence' => 'target_readback'],
        'category_ids' => ['disposition' => 'migrate', 'projection' => 'taxonomies', 'reason_code' => 'taxonomy_contract', 'evidence' => 'source_api'],
        'children' => ['disposition' => 'adapter', 'projection' => 'grouped_children', 'reason_code' => 'grouped_product_adapter_required', 'evidence' => 'adapter'],
        'cogs_value' => ['disposition' => 'inventory', 'projection' => 'cost', 'reason_code' => 'cost_contract_required', 'evidence' => 'installed_schema'],
        'cogs_value_is_additive' => ['disposition' => 'migrate', 'projection' => 'cost', 'reason_code' => 'cost_contract_required', 'evidence' => 'source_api'],
        'cross_sell_ids' => ['disposition' => 'migrate', 'projection' => 'cross_sells', 'reason_code' => 'relation_contract', 'evidence' => 'target_relation'],
        'date_created' => ['disposition' => 'migrate', 'projection' => 'created_utc', 'reason_code' => 'date_contract', 'evidence' => 'source_api'],
        'date_modified' => ['disposition' => 'migrate', 'projection' => 'modified_utc', 'reason_code' => 'date_contract', 'evidence' => 'source_api'],
        'date_on_sale_from' => ['disposition' => 'migrate', 'projection' => 'sale_start', 'reason_code' => 'sale_schedule_contract', 'evidence' => 'target_scheduler'],
        'date_on_sale_to' => ['disposition' => 'migrate', 'projection' => 'sale_end', 'reason_code' => 'sale_schedule_contract', 'evidence' => 'target_scheduler'],
        'default_attributes' => ['disposition' => 'migrate', 'projection' => 'attribute_defaults', 'reason_code' => 'attribute_contract', 'evidence' => 'source_api'],
        'description' => ['disposition' => 'migrate', 'projection' => 'description', 'reason_code' => 'content_contract', 'evidence' => 'target_readback'],
        'download_expiry' => ['disposition' => 'migrate', 'projection' => 'download_policy', 'reason_code' => 'download_contract', 'evidence' => 'target_readback'],
        'download_limit' => ['disposition' => 'migrate', 'projection' => 'download_policy', 'reason_code' => 'download_contract', 'evidence' => 'target_readback'],
        'downloadable' => ['disposition' => 'migrate', 'projection' => 'fulfilment', 'reason_code' => 'fulfilment_contract', 'evidence' => 'target_readback'],
        'downloads' => ['disposition' => 'migrate', 'projection' => 'downloads', 'reason_code' => 'download_contract', 'evidence' => 'asset_hash'],
        'product_url' => ['disposition' => 'adapter', 'projection' => 'external', 'reason_code' => 'external_product_adapter_required', 'evidence' => 'adapter'],
        'featured' => ['disposition' => 'inventory', 'projection' => 'featured', 'reason_code' => 'catalogue_field_decision_required', 'evidence' => 'target_readback'],
        'gallery_image_ids' => ['disposition' => 'migrate', 'projection' => 'media', 'reason_code' => 'media_contract', 'evidence' => 'asset_hash'],
        'global_unique_id' => ['disposition' => 'inventory', 'projection' => 'global_unique_id', 'reason_code' => 'gtin_contract_required', 'evidence' => 'target_readback'],
        'height' => ['disposition' => 'migrate', 'projection' => 'dimensions', 'reason_code' => 'dimension_contract', 'evidence' => 'target_readback'],
        'id' => ['disposition' => 'migrate', 'projection' => 'source_identity', 'reason_code' => 'identity_contract', 'evidence' => 'source_api'],
        'image_id' => ['disposition' => 'migrate', 'projection' => 'media', 'reason_code' => 'media_contract', 'evidence' => 'asset_hash'],
        'length' => ['disposition' => 'migrate', 'projection' => 'dimensions', 'reason_code' => 'dimension_contract', 'evidence' => 'target_readback'],
        'low_stock_amount' => ['disposition' => 'migrate', 'projection' => 'stock', 'reason_code' => 'stock_contract', 'evidence' => 'purchase_path'],
        'manage_stock' => ['disposition' => 'migrate', 'projection' => 'stock', 'reason_code' => 'stock_contract', 'evidence' => 'purchase_path'],
        'menu_order' => ['disposition' => 'inventory', 'projection' => 'menu_order', 'reason_code' => 'catalogue_field_decision_required', 'evidence' => 'target_readback'],
        'meta_data' => ['disposition' => 'inventory', 'projection' => 'approved_meta', 'reason_code' => 'raw_meta_requires_approval', 'evidence' => 'source_api'],
        'name' => ['disposition' => 'migrate', 'projection' => 'name', 'reason_code' => 'content_contract', 'evidence' => 'target_readback'],
        'parent_id' => ['disposition' => 'migrate', 'projection' => 'parent_identity', 'reason_code' => 'identity_contract', 'evidence' => 'source_api'],
        'post_password' => ['disposition' => 'inventory', 'projection' => 'access_provenance', 'reason_code' => 'password_protection_unsupported', 'evidence' => 'source_api'],
        'price' => ['disposition' => 'migrate', 'projection' => 'active_price', 'reason_code' => 'price_contract', 'evidence' => 'target_readback'],
        'purchase_note' => ['disposition' => 'inventory', 'projection' => 'purchase_note', 'reason_code' => 'catalogue_field_decision_required', 'evidence' => 'target_readback'],
        'rating_counts' => ['disposition' => 'inventory', 'projection' => 'review_provenance', 'reason_code' => 'review_decision_required', 'evidence' => 'source_api'],
        'regular_price' => ['disposition' => 'migrate', 'projection' => 'regular_price', 'reason_code' => 'price_contract', 'evidence' => 'target_readback'],
        'review_count' => ['disposition' => 'inventory', 'projection' => 'review_provenance', 'reason_code' => 'review_decision_required', 'evidence' => 'source_api'],
        'reviews_allowed' => ['disposition' => 'inventory', 'projection' => 'reviews_allowed', 'reason_code' => 'review_decision_required', 'evidence' => 'target_readback'],
        'sale_price' => ['disposition' => 'migrate', 'projection' => 'sale_price', 'reason_code' => 'price_contract', 'evidence' => 'target_readback'],
        'shipping_class_id' => ['disposition' => 'migrate', 'projection' => 'shipping_class', 'reason_code' => 'shipping_contract', 'evidence' => 'target_readback'],
        'short_description' => ['disposition' => 'migrate', 'projection' => 'short_description', 'reason_code' => 'content_contract', 'evidence' => 'target_readback'],
        'sku' => ['disposition' => 'migrate', 'projection' => 'sku', 'reason_code' => 'sku_contract', 'evidence' => 'target_readback'],
        'slug' => ['disposition' => 'migrate', 'projection' => 'slug', 'reason_code' => 'content_contract', 'evidence' => 'target_readback'],
        'sold_individually' => ['disposition' => 'migrate', 'projection' => 'stock', 'reason_code' => 'stock_contract', 'evidence' => 'purchase_path'],
        'status' => ['disposition' => 'migrate', 'projection' => 'status', 'reason_code' => 'status_contract', 'evidence' => 'target_readback'],
        'stock_quantity' => ['disposition' => 'migrate', 'projection' => 'stock', 'reason_code' => 'stock_contract', 'evidence' => 'purchase_path'],
        'stock_status' => ['disposition' => 'migrate', 'projection' => 'stock', 'reason_code' => 'stock_contract', 'evidence' => 'purchase_path'],
        'subscription_length' => ['disposition' => 'adapter', 'projection' => 'subscription', 'reason_code' => 'subscription_contract', 'evidence' => 'wcs_adapter'],
        'subscription_period' => ['disposition' => 'adapter', 'projection' => 'subscription', 'reason_code' => 'subscription_contract', 'evidence' => 'wcs_adapter'],
        'subscription_period_interval' => ['disposition' => 'adapter', 'projection' => 'subscription', 'reason_code' => 'subscription_contract', 'evidence' => 'wcs_adapter'],
        'subscription_price' => ['disposition' => 'adapter', 'projection' => 'subscription', 'reason_code' => 'subscription_contract', 'evidence' => 'wcs_adapter'],
        'subscription_sign_up_fee' => ['disposition' => 'adapter', 'projection' => 'subscription', 'reason_code' => 'subscription_contract', 'evidence' => 'wcs_adapter'],
        'subscription_trial_length' => ['disposition' => 'adapter', 'projection' => 'subscription', 'reason_code' => 'subscription_contract', 'evidence' => 'wcs_adapter'],
        'subscription_trial_period' => ['disposition' => 'adapter', 'projection' => 'subscription', 'reason_code' => 'subscription_contract', 'evidence' => 'wcs_adapter'],
        'tag_ids' => ['disposition' => 'migrate', 'projection' => 'taxonomies', 'reason_code' => 'taxonomy_contract', 'evidence' => 'source_api'],
        'tax_class' => ['disposition' => 'migrate', 'projection' => 'tax', 'reason_code' => 'tax_contract', 'evidence' => 'target_tax_class'],
        'tax_status' => ['disposition' => 'migrate', 'projection' => 'tax', 'reason_code' => 'tax_contract', 'evidence' => 'target_tax_class'],
        'total_sales' => ['disposition' => 'inventory', 'projection' => 'sales_provenance', 'reason_code' => 'sales_count_decision_required', 'evidence' => 'source_api'],
        'upsell_ids' => ['disposition' => 'migrate', 'projection' => 'upsells', 'reason_code' => 'relation_contract', 'evidence' => 'target_relation'],
        'virtual' => ['disposition' => 'migrate', 'projection' => 'fulfilment', 'reason_code' => 'fulfilment_contract', 'evidence' => 'target_readback'],
        'weight' => ['disposition' => 'migrate', 'projection' => 'dimensions', 'reason_code' => 'dimension_contract', 'evidence' => 'target_readback'],
        'width' => ['disposition' => 'migrate', 'projection' => 'dimensions', 'reason_code' => 'dimension_contract', 'evidence' => 'target_readback'],
    ];

    /** @return list<string> */
    public function recognizedKeysWithoutDuplicates(): array
    {
        $keys = array_keys(self::FIELDS);
        sort($keys, SORT_STRING);
        return $keys;
    }

    /** @return array<string, string> */
    public function allowedLossLedger(): array
    {
        $ledger = [];

        foreach (self::FIELDS as $field => $contract) {
            if ($contract['disposition'] !== 'migrate') {
                $ledger[$field] = $contract['reason_code'];
            }
        }

        ksort($ledger);
        return $ledger;
    }

    /** @param list<string> $installedKeys */
    public function assertCovers(array $installedKeys): void
    {
        if (!array_is_list($installedKeys) || count($installedKeys) !== count(array_unique($installedKeys))) {
            throw new SourceRecordException('product_field_registry_duplicate', 'Installed WooCommerce data keys are not a unique list.');
        }

        sort($installedKeys, SORT_STRING);
        $unknown = array_values(array_diff($installedKeys, $this->recognizedKeysWithoutDuplicates()));

        if ($unknown !== []) {
            throw new SourceRecordException('product_field_unrecognized', 'Installed WooCommerce exposes an unregistered product field.');
        }
    }
}

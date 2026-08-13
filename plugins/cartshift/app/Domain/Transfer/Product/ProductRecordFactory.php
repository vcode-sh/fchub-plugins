<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Product;

use CartShift\Domain\Transfer\RecordKind;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\SourceRecordException;

defined('ABSPATH') || exit;

final class ProductRecordFactory
{
    /** @var (\Closure(object): list<int>)|null */
    private readonly ?\Closure $variationIds;

    /** @var (\Closure(int,string,list<int>): array<int,int>)|null */
    private readonly ?\Closure $taxonomyOrders;

    /**
     * @param (callable(object): list<int>)|null $variationIds
     * @param (callable(int,string,list<int>): array<int,int>)|null $taxonomyOrders
     */
    public function __construct(
        private readonly ProductFieldRegistry $fieldRegistry = new ProductFieldRegistry(),
        ?callable $variationIds = null,
        ?callable $taxonomyOrders = null,
    ) {
        $this->variationIds = $variationIds !== null ? $variationIds(...) : null;
        $this->taxonomyOrders = $taxonomyOrders !== null ? $taxonomyOrders(...) : null;
    }

    public static function forLoadedWoo(): self
    {
        return new self(
            new ProductFieldRegistry(),
            static function (object $product): array {
                if (!function_exists('get_posts') || !is_callable([$product, 'get_id'])) {
                    throw new SourceRecordException(
                        'woocommerce_product_api_unavailable',
                        'WooCommerce variation identities cannot be read without writes.',
                    );
                }

                $ids = get_posts(apply_filters('woocommerce_variable_children_args', [
                    'post_parent' => (int) $product->get_id(),
                    'post_type' => 'product_variation',
                    'orderby' => ['menu_order' => 'ASC', 'ID' => 'ASC'],
                    'fields' => 'ids',
                    'post_status' => ['publish', 'private'],
                    'numberposts' => -1,
                    'no_found_rows' => true,
                    'cache_results' => false,
                    'update_post_meta_cache' => false,
                    'update_post_term_cache' => false,
                ], $product, false));

                return array_values(array_map('intval', (array) $ids));
            },
            static function (int $productId, string $taxonomy, array $assignedIds): array {
                global $wpdb;
                if (!is_object($wpdb)) {
                    throw new SourceRecordException(
                        'product_taxonomy_order_read_failed',
                        'The WordPress taxonomy relationship store is unavailable.',
                    );
                }
                $wpdb->last_error = '';
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT tt.term_id, tr.term_order
                     FROM {$wpdb->term_relationships} tr
                     INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                     WHERE tr.object_id = %d AND tt.taxonomy = %s
                     ORDER BY tr.term_order ASC, tt.term_id ASC",
                    $productId,
                    $taxonomy,
                ), ARRAY_A);
                if (trim((string) ($wpdb->last_error ?? '')) !== '' || !is_array($rows)) {
                    throw new SourceRecordException(
                        'product_taxonomy_order_read_failed',
                        'Source taxonomy relationship order could not be read.',
                    );
                }
                $orders = [];
                foreach ($rows as $row) {
                    $orders[(int) ($row['term_id'] ?? 0)] = (int) ($row['term_order'] ?? -1);
                }
                return $orders;
            },
        );
    }

    public function fromWooProduct(object $product, string $sourceKey): ProductRecord
    {
        SourceIdentity::assertValidSourceKey($sourceKey);
        $data = $this->data($product);

        if ($data !== []) {
            $keys = array_map('strval', array_keys($data));
            $this->fieldRegistry->assertCovers(array_values($keys));
        }

        $id = $this->positiveInt($this->read($product, 'get_id', $data['id'] ?? null), 'product_identity_invalid');
        $identity = new SourceIdentity($sourceKey, RecordKind::Product->value, (string) $id);
        $currency = $this->currency();
        $attributes = $this->attributes($product, $data);
        $tax = $this->tax($product, $data);
        $stock = $this->stock($product, $identity, null, $data);
        $shippingClassSlug = $this->shippingClassSlug($product, $data);
        $media = $this->media($product, $identity, 'product');
        $downloads = $this->downloads($product, $identity, $data);
        $variations = $this->variations(
            $product,
            $identity,
            $currency,
            $tax,
            $stock,
            $shippingClassSlug,
            $attributes,
            $data,
        );
        $created = $this->utc($this->read($product, 'get_date_created', $data['date_created'] ?? null));

        if ($created === null) {
            throw new SourceRecordException('product_created_date_missing', 'Product creation time is required.');
        }

        return new ProductRecord(
            $identity,
            $this->scalarString($this->read($product, 'get_type', 'simple'), 'product_type_invalid'),
            $this->scalarString($this->read($product, 'get_status', $data['status'] ?? ''), 'product_status_invalid'),
            $this->scalarString($this->read($product, 'get_name', $data['name'] ?? ''), 'product_name_invalid'),
            $this->scalarString($this->read($product, 'get_slug', $data['slug'] ?? ''), 'product_slug_invalid'),
            $this->scalarString($this->read($product, 'get_description', $data['description'] ?? ''), 'product_description_invalid'),
            $this->scalarString($this->read($product, 'get_short_description', $data['short_description'] ?? ''), 'product_short_description_invalid'),
            $this->scalarString($this->read($product, 'get_sku', $data['sku'] ?? ''), 'product_sku_invalid'),
            $created,
            $this->utc($this->read($product, 'get_date_modified', $data['date_modified'] ?? null)),
            (int) $this->scalar($this->read($product, 'get_menu_order', $data['menu_order'] ?? 0), 'product_menu_order_invalid'),
            (bool) $this->scalar($this->read($product, 'is_featured', $data['featured'] ?? false), 'product_featured_invalid'),
            $this->scalarString($this->read($product, 'get_catalog_visibility', $data['catalog_visibility'] ?? ''), 'product_visibility_invalid'),
            $this->scalarString($this->read($product, 'get_purchase_note', $data['purchase_note'] ?? ''), 'product_purchase_note_invalid'),
            (bool) $this->scalar($this->read($product, 'get_reviews_allowed', $data['reviews_allowed'] ?? false), 'product_reviews_allowed_invalid'),
            (int) $this->scalar($this->read($product, 'get_review_count', $data['review_count'] ?? 0), 'product_review_count_invalid'),
            $this->scalarString($this->read($product, 'get_average_rating', $data['average_rating'] ?? '0'), 'product_average_rating_invalid'),
            $this->ratingDistribution($this->read($product, 'get_rating_counts', $data['rating_counts'] ?? [])),
            (int) $this->scalar($this->read($product, 'get_total_sales', $data['total_sales'] ?? 0), 'product_total_sales_invalid'),
            $this->scalarString($this->read($product, 'get_global_unique_id', $data['global_unique_id'] ?? ''), 'product_global_id_invalid'),
            $this->fulfilment($product, $data),
            $this->scalarString($this->read($product, 'get_post_password', $data['post_password'] ?? ''), 'product_password_invalid') !== '',
            $shippingClassSlug,
            $this->typeConfiguration($product, $data),
            $tax,
            $stock,
            $variations,
            $attributes,
            $this->taxonomies($product, $sourceKey, $data),
            $media,
            $downloads,
            $this->productIdentities($this->read($product, 'get_upsell_ids', $data['upsell_ids'] ?? []), $sourceKey),
            $this->productIdentities($this->read($product, 'get_cross_sell_ids', $data['cross_sell_ids'] ?? []), $sourceKey),
            $this->approvedMeta($product),
            ProductFieldRegistry::VERSION,
            $this->fieldRegistry->allowedLossLedger(),
        );
    }

    /** @return list<VariationRecord> */
    private function variations(
        object $product,
        SourceIdentity $parent,
        string $currency,
        TaxProfile $parentTax,
        StockProfile $parentStock,
        string $parentShippingClassSlug,
        array $attributes,
        array $data,
    ): array {
        $type = (string) $this->read($product, 'get_type', 'simple');
        $children = $this->variationIds !== null
            ? ($this->variationIds)($product)
            : $this->read($product, 'get_children', $data['children'] ?? []);

        if (!is_array($children)) {
            throw new SourceRecordException('product_variation_list_invalid', 'Product variation IDs must be a list.');
        }

        if (!in_array($type, ['variable', 'variable-subscription'], true) || $children === []) {
            $synthetic = new SourceIdentity(
                $parent->sourceKey,
                RecordKind::Product->value,
                $parent->sourceId . ':variation:' . $parent->sourceId,
            );
            return [$this->variation(
                $product,
                $synthetic,
                $parent,
                $currency,
                $parentTax,
                $parentStock,
                $parentShippingClassSlug,
                $attributes,
                true,
                $data,
            )];
        }

        $records = [];
        $seen = [];

        foreach ($children as $childId) {
            $childId = $this->positiveInt($childId, 'product_variation_identity_invalid');

            if (isset($seen[$childId])) {
                throw new SourceRecordException('product_variation_duplicate', 'A source variation identity occurs more than once.');
            }

            $seen[$childId] = true;
            $variation = function_exists('wc_get_product') ? wc_get_product($childId) : null;

            if (!is_object($variation)) {
                throw new SourceRecordException('product_variation_hydration_failed', 'A referenced source variation could not be loaded.');
            }

            $variationData = $this->data($variation);
            $actualParent = (int) $this->read($variation, 'get_parent_id', $variationData['parent_id'] ?? 0);

            if ($actualParent !== (int) $parent->sourceId) {
                throw new SourceRecordException('product_variation_parent_mismatch', 'A variation does not belong to the selected parent product.');
            }

            $identity = new SourceIdentity($parent->sourceKey, RecordKind::Product->value, $parent->sourceId . ':variation:' . $childId);
            $records[] = $this->variation(
                $variation,
                $identity,
                $parent,
                $currency,
                $parentTax,
                $parentStock,
                $parentShippingClassSlug,
                $attributes,
                false,
                $variationData,
            );
        }

        usort($records, static fn (VariationRecord $left, VariationRecord $right): int =>
            $left->identity->sourceId <=> $right->identity->sourceId
        );

        return $records;
    }

    private function variation(
        object $variation,
        SourceIdentity $identity,
        SourceIdentity $parent,
        string $currency,
        TaxProfile $parentTax,
        StockProfile $parentStock,
        string $parentShippingClassSlug,
        array $attributes,
        bool $synthetic,
        array $data,
    ): VariationRecord {
        $tax = $synthetic ? $parentTax : $this->tax($variation, $data, $parentTax);
        $stock = $synthetic ? $parentStock : $this->stock($variation, $identity, [$parent, $parentStock], $data);
        $assignments = [];
        $rawAssignments = $synthetic ? [] : $this->read($variation, 'get_attributes', $data['attributes'] ?? []);

        if (!is_array($rawAssignments)) {
            throw new SourceRecordException('variation_attributes_invalid', 'Variation attributes must be a scalar map.');
        }

        $declaredAttributes = [];
        foreach ($attributes as $attribute) {
            $declaredAttributes[$attribute->sourceKey] = $attribute;
        }

        foreach ($rawAssignments as $key => $value) {
            if (!is_scalar($value) && $value !== null) {
                throw new SourceRecordException('variation_attribute_value_invalid', 'Variation attribute values must be scalar.');
            }

            $key = $this->attributeKey((string) $key);
            $value = trim((string) $value);
            $declared = $declaredAttributes[$key] ?? null;
            $assignments[] = [
                'attribute_key' => $key,
                'value' => $declared instanceof AttributeRecord
                    ? $this->declaredVariationValue($value, $declared)
                    : $value,
                'kind' => $declared instanceof AttributeRecord
                    ? $declared->kind
                    : (str_starts_with($key, 'pa_') ? 'taxonomy' : 'custom'),
                'wildcard' => $value === '',
            ];
        }

        usort($assignments, static fn (array $left, array $right): int => $left['attribute_key'] <=> $right['attribute_key']);
        $costRaw = $this->read($variation, 'get_cogs_value', null);
        $effectiveCostRaw = $this->read($variation, 'get_cogs_total_value', $costRaw);
        $costIsAdditive = (bool) $this->scalar(
            $this->read($variation, 'get_cogs_value_is_additive', $data['cogs_value_is_additive'] ?? false),
            'variation_cost_additive_invalid',
        );

        return new VariationRecord(
            $identity,
            $parent,
            $this->scalarString($this->read($variation, 'get_status', $data['status'] ?? 'publish'), 'variation_status_invalid'),
            $this->utc($this->read($variation, 'get_date_created', $data['date_created'] ?? null)),
            $this->utc($this->read($variation, 'get_date_modified', $data['date_modified'] ?? null)),
            (int) $this->scalar($this->read($variation, 'get_menu_order', $data['menu_order'] ?? 0), 'variation_menu_order_invalid'),
            $this->scalarString($this->read($variation, 'get_sku', $data['sku'] ?? ''), 'variation_sku_invalid'),
            $this->scalarString($this->read($variation, 'get_global_unique_id', $data['global_unique_id'] ?? ''), 'variation_global_id_invalid'),
            $assignments,
            $this->price($variation, $currency, $data),
            $tax,
            $stock,
            $effectiveCostRaw === null || $effectiveCostRaw === '' ? null : $this->money($effectiveCostRaw),
            [
                'weight' => $this->nullableString($this->read($variation, 'get_weight', $data['weight'] ?? null)),
                'length' => $this->nullableString($this->read($variation, 'get_length', $data['length'] ?? null)),
                'width' => $this->nullableString($this->read($variation, 'get_width', $data['width'] ?? null)),
                'height' => $this->nullableString($this->read($variation, 'get_height', $data['height'] ?? null)),
                'weight_unit' => function_exists('get_option') ? (string) get_option('woocommerce_weight_unit', 'kg') : 'kg',
                'dimension_unit' => function_exists('get_option') ? (string) get_option('woocommerce_dimension_unit', 'cm') : 'cm',
            ],
            $this->fulfilment($variation, $data),
            $this->scalarString($this->read($variation, 'get_description', $data['description'] ?? ''), 'variation_description_invalid'),
            $synthetic ? [] : $this->media($variation, $identity, 'variation', $parent, $data),
            $this->downloads($variation, $identity, $data),
            $this->variationTypeConfiguration($variation, $data),
            $synthetic ? $parentShippingClassSlug : $this->shippingClassSlug($variation, $data),
            $costRaw === null || $costRaw === '' ? null : $this->money($costRaw),
            $costIsAdditive,
        );
    }

    private function price(object $product, string $currency, array $data): PriceRecord
    {
        return new PriceRecord(
            $this->nullableMoney($this->read($product, 'get_price', $data['price'] ?? null)),
            $this->nullableMoney($this->read($product, 'get_regular_price', $data['regular_price'] ?? null)),
            $this->nullableMoney($this->read($product, 'get_sale_price', $data['sale_price'] ?? null)),
            $this->utc($this->read($product, 'get_date_on_sale_from', $data['date_on_sale_from'] ?? null)),
            $this->utc($this->read($product, 'get_date_on_sale_to', $data['date_on_sale_to'] ?? null)),
            $currency,
        );
    }

    private function tax(object $product, array $data, ?TaxProfile $parent = null): TaxProfile
    {
        $status = (string) $this->read($product, 'get_tax_status', $data['tax_status'] ?? $parent?->status ?? 'taxable');
        $class = (string) $this->read($product, 'get_tax_class', $data['tax_class'] ?? $parent?->classSlug ?? 'standard');
        $class = $class === '' ? 'standard' : $this->attributeKey($class);
        $includeTax = function_exists('wc_prices_include_tax') ? (bool) wc_prices_include_tax() : false;
        return new TaxProfile($status, $class, $includeTax);
    }

    /** @param array{0: SourceIdentity, 1: StockProfile}|null $parent */
    private function stock(object $product, SourceIdentity $identity, ?array $parent, array $data): StockProfile
    {
        $manage = $this->read($product, 'get_manage_stock', $data['manage_stock'] ?? false);

        if (!is_bool($manage) && $manage !== 'parent') {
            throw new SourceRecordException('stock_ownership_invalid', 'Stock ownership must be false, true or parent.');
        }

        if ($manage === 'parent') {
            if ($parent === null) {
                throw new SourceRecordException('stock_parent_missing', 'Parent-owned stock requires a parent product.');
            }

            return new StockProfile(
                StockOwnership::Parent,
                $parent[0],
                $parent[1]->quantity,
                $parent[1]->status,
                (string) $this->read($product, 'get_backorders', $data['backorders'] ?? $parent[1]->backorders),
                (bool) $this->read($product, 'is_sold_individually', $data['sold_individually'] ?? false),
                $this->nullableInt($this->read($product, 'get_low_stock_amount', $data['low_stock_amount'] ?? null)),
            );
        }

        $ownership = $manage ? StockOwnership::Self : StockOwnership::None;
        $status = (string) $this->read(
            $product,
            'get_stock_status',
            $data['stock_status'] ?? ((bool) $this->read($product, 'is_in_stock', true) ? 'instock' : 'outofstock'),
        );

        return new StockProfile(
            $ownership,
            $manage ? $identity : null,
            $manage ? $this->nullableInt($this->read($product, 'get_stock_quantity', $data['stock_quantity'] ?? null)) : null,
            $status,
            (string) $this->read($product, 'get_backorders', $data['backorders'] ?? 'no'),
            (bool) $this->read($product, 'is_sold_individually', $data['sold_individually'] ?? false),
            $this->nullableInt($this->read($product, 'get_low_stock_amount', $data['low_stock_amount'] ?? null)),
        );
    }

    /** @return list<AttributeRecord> */
    private function attributes(object $product, array $data): array
    {
        $attributes = $this->read($product, 'get_attributes', $data['attributes'] ?? []);
        $defaults = $this->read($product, 'get_default_attributes', $data['default_attributes'] ?? []);

        if (!is_array($attributes) || !is_array($defaults)) {
            throw new SourceRecordException('product_attributes_invalid', 'Product attributes and defaults must be arrays.');
        }

        $records = [];
        $seen = [];

        foreach ($attributes as $index => $attribute) {
            if (is_object($attribute)) {
                $name = (string) $this->read($attribute, 'get_name', '');
                $taxonomy = (bool) $this->read($attribute, 'is_taxonomy', false);
                $values = $this->read($attribute, 'get_options', []);
                $position = (int) $this->read($attribute, 'get_position', $index);
                $visible = (bool) $this->read($attribute, 'get_visible', false);
                $variation = (bool) $this->read($attribute, 'get_variation', false);
            } elseif (is_array($attribute)) {
                $name = (string) ($attribute['name'] ?? $index);
                $taxonomy = (bool) ($attribute['taxonomy'] ?? str_starts_with($name, 'pa_'));
                $values = $attribute['options'] ?? [];
                $position = (int) ($attribute['position'] ?? $index);
                $visible = (bool) ($attribute['visible'] ?? false);
                $variation = (bool) ($attribute['variation'] ?? false);
            } else {
                throw new SourceRecordException('product_attribute_shape_invalid', 'A product attribute has an unsupported shape.');
            }

            if (!is_array($values)) {
                throw new SourceRecordException('product_attribute_values_invalid', 'Product attribute values must be a list.');
            }

            $key = $this->attributeKey($name);

            if (isset($seen[$key])) {
                throw new SourceRecordException('product_attribute_ambiguous', 'Two source attributes normalize to the same identity.');
            }

            $seen[$key] = true;
            $normalized = [];
            $valueLabels = [];
            foreach ($values as $value) {
                [$storedValue, $displayLabel] = $this->attributeValueAndLabel($value, $taxonomy, $name);
                if (!in_array($storedValue, $normalized, true)) {
                    $normalized[] = $storedValue;
                }
                $valueLabels[$storedValue] = $displayLabel;
            }
            $default = $defaults[$name] ?? $defaults[$key] ?? null;
            $records[] = new AttributeRecord(
                $key,
                $name,
                $taxonomy ? 'taxonomy' : 'custom',
                $variation,
                $visible,
                $position,
                $default === null || $default === '' ? null : (string) $default,
                $normalized,
                $valueLabels,
            );
        }

        usort($records, static fn (AttributeRecord $left, AttributeRecord $right): int =>
            [$left->position, $left->sourceKey] <=> [$right->position, $right->sourceKey]
        );
        return $records;
    }

    /** @return list<AssetReference> */
    private function media(
        object $product,
        SourceIdentity $owner,
        string $context,
        ?SourceIdentity $parent = null,
        array $data = [],
    ): array
    {
        $ids = [];
        $featured = (int) $this->read($product, 'get_image_id', 0);
        $ownFeatured = array_key_exists('image_id', $data) ? (int) $data['image_id'] : $featured;

        if ($featured > 0) {
            $ids[] = [$featured, $context === 'variation' ? 'variation' : 'featured'];
        }

        if ($context !== 'variation') {
            $gallery = $this->read($product, 'get_gallery_image_ids', []);

            if (!is_array($gallery)) {
                throw new SourceRecordException('product_gallery_invalid', 'Gallery attachment IDs must be a list.');
            }

            foreach ($gallery as $id) {
                $ids[] = [$this->positiveInt($id, 'product_attachment_identity_invalid'), 'gallery'];
            }
        }

        $assets = [];

        foreach ($ids as [$id, $role]) {
            $url = function_exists('wp_get_attachment_url') ? wp_get_attachment_url($id) : false;

            if (!is_string($url) || $url === '') {
                throw new SourceRecordException('product_attachment_unresolvable', 'A referenced product attachment has no source locator.');
            }

            $path = function_exists('get_attached_file') ? get_attached_file($id) : false;
            $size = is_string($path) && is_file($path) ? filesize($path) : null;
            $hash = is_string($path) && is_file($path) ? hash_file('sha256', $path) : null;
            $assets[] = new AssetReference(
                new SourceIdentity($owner->sourceKey, RecordKind::MediaAsset->value, (string) $id),
                $url,
                $role,
                function_exists('get_post_mime_type') ? ((string) get_post_mime_type($id) ?: 'application/octet-stream') : 'application/octet-stream',
                is_int($size) ? $size : null,
                $owner,
                $context === 'variation' && $ownFeatured === 0 && $parent !== null ? 'inherited' : 'own',
                is_string($hash) ? $hash : null,
            );
        }

        return $assets;
    }

    /** @return list<DownloadReference> */
    private function downloads(object $product, SourceIdentity $owner, array $data): array
    {
        $downloads = array_key_exists('downloads', $data)
            ? $data['downloads']
            : $this->read($product, 'get_downloads', []);

        if (!is_array($downloads)) {
            throw new SourceRecordException('product_downloads_invalid', 'Product downloads must be a list or map.');
        }

        $limit = (int) $this->scalar($this->read($product, 'get_download_limit', $data['download_limit'] ?? -1), 'download_limit_invalid');
        $expiry = (int) $this->scalar($this->read($product, 'get_download_expiry', $data['download_expiry'] ?? -1), 'download_expiry_invalid');
        $records = [];

        foreach ($downloads as $key => $download) {
            $name = is_object($download) ? $this->read($download, 'get_name', '') : ($download['name'] ?? '');
            $locator = is_object($download) ? $this->read($download, 'get_file', '') : ($download['file'] ?? '');
            $downloadId = is_object($download) ? $this->read($download, 'get_id', $key) : ($download['id'] ?? $key);
            $name = $this->scalarString($name, 'download_name_invalid');
            $locator = $this->scalarString($locator, 'download_locator_invalid');

            if ($locator === '') {
                throw new SourceRecordException('product_download_unresolvable', 'A referenced download has no source locator.');
            }

            $segment = strtolower(preg_replace('/[^a-z0-9._-]+/', '-', (string) $downloadId) ?? '');
            $segment = trim($segment, '-');
            $segment = $segment !== '' ? substr($segment, 0, 64) : substr(hash('sha256', $locator . $name), 0, 32);
            $path = str_starts_with($locator, '/') && is_file($locator) ? $locator : null;
            $records[] = new DownloadReference(
                new SourceIdentity($owner->sourceKey, RecordKind::DownloadAsset->value, $owner->sourceId . ':download:' . $segment),
                $locator,
                $path !== null ? hash_file('sha256', $path) : null,
                $owner,
                $name,
                $limit,
                $expiry,
            );
        }

        return $records;
    }

    /** @return list<TaxonomyAssignment> */
    private function taxonomies(object $product, string $sourceKey, array $data): array
    {
        $sets = [
            'product_cat' => $data['category_ids'] ?? $this->read($product, 'get_category_ids', []),
            'product_tag' => $data['tag_ids'] ?? $this->read($product, 'get_tag_ids', []),
            'product_brand' => $data['brand_ids'] ?? $this->read($product, 'get_brand_ids', []),
        ];
        $taxonomies = apply_filters('cartshift/transfer/product_taxonomies', ['product_cat', 'product_tag', 'product_brand']);

        if (!is_array($taxonomies) || !array_is_list($taxonomies)) {
            throw new SourceRecordException('product_taxonomy_registry_invalid', 'Product taxonomy registry must be a list.');
        }

        foreach (array_values(array_unique($taxonomies)) as $taxonomy) {
            if (!is_string($taxonomy) || $taxonomy === '') {
                throw new SourceRecordException('product_taxonomy_registry_invalid', 'Product taxonomy registry contains an invalid name.');
            }

            if (isset($sets[$taxonomy])) {
                continue;
            }

            $method = $taxonomy === 'product_brand' ? 'get_brand_ids' : '';

            if ($method !== '' && method_exists($product, $method)) {
                $sets[$taxonomy] = $product->{$method}();
            } elseif (function_exists('wc_get_product_terms')) {
                $sets[$taxonomy] = wc_get_product_terms((int) $product->get_id(), $taxonomy, ['fields' => 'ids']);
            } else {
                $sets[$taxonomy] = [];
            }
        }
        $records = [];

        foreach ($sets as $taxonomy => $ids) {
            if (!is_array($ids)) {
                throw new SourceRecordException('product_taxonomy_ids_invalid', 'Product taxonomy IDs must be lists.');
            }

            $assignedIds = array_map(
                fn (mixed $id): int => $this->positiveInt($id, 'product_term_identity_invalid'),
                $ids,
            );
            $relationshipOrders = $this->taxonomyRelationshipOrders(
                (int) $this->read($product, 'get_id', $data['id'] ?? 0),
                $taxonomy,
                $assignedIds,
            );

            foreach ($this->taxonomyIdentitiesWithAncestors($ids, $taxonomy) as [$id, $assigned]) {
                $id = $this->positiveInt($id, 'product_term_identity_invalid');
                $term = function_exists('get_term') ? get_term($id, $taxonomy) : null;
                $name = is_object($term) ? (string) ($term->name ?? '') : $taxonomy . '-' . $id;
                $slug = is_object($term) ? (string) ($term->slug ?? '') : (string) $id;
                $description = is_object($term) ? (string) ($term->description ?? '') : '';
                $parentId = is_object($term) ? (int) ($term->parent ?? 0) : 0;
                $records[] = new TaxonomyAssignment(
                    $taxonomy,
                    new SourceIdentity($sourceKey, RecordKind::TaxonomyTerm->value, $id . ':' . str_replace('_', '-', $taxonomy)),
                    $name,
                    $slug,
                    $description,
                    $parentId > 0 ? new SourceIdentity($sourceKey, RecordKind::TaxonomyTerm->value, $parentId . ':' . str_replace('_', '-', $taxonomy)) : null,
                    $assigned ? $relationshipOrders[$id] : 0,
                    'assign',
                    $assigned,
                );
            }
        }

        usort($records, static fn (TaxonomyAssignment $left, TaxonomyAssignment $right): int =>
            $left->termIdentity->canonical() <=> $right->termIdentity->canonical()
        );
        return $records;
    }

    /**
     * WordPress term objects do not expose the numeric relationship term_order.
     * WooCommerce does expose the relationship ordering through its public term
     * query API, so preserve that semantic order as stable zero-based ranks.
     * Private-table numeric gaps are not part of the source API contract.
     *
     * @param list<int> $assignedIds
     * @return array<int, int>
     */
    private function taxonomyRelationshipOrders(int $productId, string $taxonomy, array $assignedIds): array
    {
        if ($assignedIds === []) {
            return [];
        }

        if ($this->taxonomyOrders !== null) {
            $orders = ($this->taxonomyOrders)($productId, $taxonomy, $assignedIds);
        } else {
            if (!function_exists('wc_get_product_terms')) {
                throw new SourceRecordException('product_taxonomy_order_read_failed', 'The WooCommerce taxonomy relationship API is unavailable.');
            }
            $orderedIds = wc_get_product_terms(
                $productId,
                $taxonomy,
                ['fields' => 'ids', 'orderby' => 'term_order', 'order' => 'ASC'],
            );
            if (is_wp_error($orderedIds) || !is_array($orderedIds) || !array_is_list($orderedIds)) {
                throw new SourceRecordException('product_taxonomy_order_read_failed', 'Source taxonomy relationship order could not be read.');
            }
            $orders = [];
            foreach ($orderedIds as $rank => $rawTermId) {
                if ((!is_int($rawTermId) && !(is_string($rawTermId) && preg_match('/\A[1-9][0-9]*\z/D', $rawTermId) === 1))) {
                    throw new SourceRecordException('product_taxonomy_order_invalid', 'Source taxonomy relationship order is malformed.');
                }
                $termId = (int) $rawTermId;
                if (isset($orders[$termId])) {
                    throw new SourceRecordException('product_taxonomy_order_duplicate', 'A source taxonomy relationship occurs more than once.');
                }
                $orders[$termId] = $rank;
            }
        }
        foreach ($orders as $termId => $order) {
            if (!is_int($termId) || $termId <= 0 || !is_int($order) || $order < 0) {
                throw new SourceRecordException('product_taxonomy_order_invalid', 'Source taxonomy relationship order is malformed.');
            }
        }

        $expected = array_values(array_unique($assignedIds));
        sort($expected, SORT_NUMERIC);
        $actual = array_keys($orders);
        sort($actual, SORT_NUMERIC);
        if ($actual !== $expected) {
            throw new SourceRecordException('product_taxonomy_order_mismatch', 'The ordered taxonomy relationship set differs from the selected source terms.');
        }
        foreach ($expected as $termId) {
            if (!array_key_exists($termId, $orders)) {
                throw new SourceRecordException('product_taxonomy_order_missing', 'A selected source taxonomy term has no relationship row.');
            }
        }

        return $orders;
    }

    /** @param list<mixed> $ids @return list<array{int, bool}> */
    private function taxonomyIdentitiesWithAncestors(array $ids, string $taxonomy): array
    {
        $entries = [];
        foreach ($ids as $rawId) {
            $id = $this->positiveInt($rawId, 'product_term_identity_invalid');
            $entries[$id] = true;
            $seen = [];
            $current = $id;

            while (function_exists('get_term')) {
                if (isset($seen[$current])) {
                    throw new SourceRecordException('product_taxonomy_parent_cycle', 'A source taxonomy parent chain contains a cycle.');
                }
                $seen[$current] = true;
                $term = get_term($current, $taxonomy);
                $parent = is_object($term) ? (int) ($term->parent ?? 0) : 0;
                if ($parent <= 0) {
                    break;
                }
                $entries[$parent] ??= false;
                $current = $parent;
            }
        }

        ksort($entries, SORT_NUMERIC);
        return array_map(
            static fn (int $id, bool $assigned): array => [$id, $assigned],
            array_keys($entries),
            array_values($entries),
        );
    }

    /** @return list<SourceIdentity> */
    private function productIdentities(mixed $ids, string $sourceKey): array
    {
        if (!is_array($ids)) {
            throw new SourceRecordException('product_relation_ids_invalid', 'Related product IDs must be lists.');
        }

        $identities = array_map(fn (mixed $id): SourceIdentity => new SourceIdentity(
            $sourceKey,
            RecordKind::Product->value,
            (string) $this->positiveInt($id, 'product_relation_identity_invalid'),
        ), $ids);
        usort($identities, static fn (SourceIdentity $left, SourceIdentity $right): int => $left->sourceId <=> $right->sourceId);
        return $identities;
    }

    /** @return array<string, scalar|null> */
    private function approvedMeta(object $product): array
    {
        $keys = apply_filters('cartshift/transfer/approved_product_meta_keys', []);

        if (!is_array($keys) || !array_is_list($keys)) {
            throw new SourceRecordException('approved_product_meta_registry_invalid', 'Approved product metadata keys must be a list.');
        }

        $keys = array_values(array_unique($keys));
        sort($keys, SORT_STRING);
        $meta = [];

        foreach ($keys as $key) {
            if (!is_string($key) || $key === '' || !is_callable([$product, 'get_meta'])) {
                throw new SourceRecordException('approved_product_meta_key_invalid', 'An approved product metadata key is invalid.');
            }

            $value = $product->get_meta($key, true);

            if (!is_scalar($value) && $value !== null) {
                throw new SourceRecordException('approved_product_meta_value_invalid', 'Approved product metadata must be scalar.');
            }

            $meta[$key] = $value;
        }

        return $meta;
    }

    /** @return array<string, mixed> */
    private function data(object $product): array
    {
        if (!is_callable([$product, 'get_data'])) {
            return [];
        }

        $data = $product->get_data();

        if (!is_array($data)) {
            throw new SourceRecordException('product_data_shape_invalid', 'WooCommerce product data must be an array.');
        }

        return $data;
    }

    private function read(object $object, string $method, mixed $default): mixed
    {
        return is_callable([$object, $method]) ? $object->{$method}() : $default;
    }

    private function scalar(mixed $value, string $reason): string|int|float|bool|null
    {
        if (!is_scalar($value) && $value !== null) {
            throw new SourceRecordException($reason, 'WooCommerce returned a non-scalar value for a scalar product field.');
        }

        return $value;
    }

    private function scalarString(mixed $value, string $reason): string
    {
        return (string) ($this->scalar($value, $reason) ?? '');
    }

    private function nullableString(mixed $value): ?string
    {
        $value = $this->scalar($value, 'product_dimension_invalid');
        return $value === null || $value === '' ? null : (string) $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        $value = $this->scalar($value, 'product_integer_field_invalid');
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function positiveInt(mixed $value, string $reason): int
    {
        $value = $this->scalar($value, $reason);

        if (!is_int($value) && !(is_string($value) && preg_match('/\A[1-9][0-9]*\z/D', $value) === 1)) {
            throw new SourceRecordException($reason, 'Source identity must be a positive integer.');
        }

        $id = (int) $value;

        if ($id <= 0) {
            throw new SourceRecordException($reason, 'Source identity must be positive.');
        }

        return $id;
    }

    private function nullableMoney(mixed $value): ?int
    {
        $value = $this->scalar($value, 'product_price_invalid');
        return $value === null || $value === '' ? null : $this->money($value);
    }

    private function money(mixed $value): int
    {
        $value = trim((string) $value);

        if (preg_match('/\A(-?)([0-9]+)(?:\.([0-9]{1,2}))?\z/D', $value, $matches) !== 1) {
            throw new SourceRecordException('product_price_not_exact', 'A source price cannot be represented exactly in target hundredths.');
        }

        $minor = ((int) $matches[2] * 100) + (int) str_pad($matches[3] ?? '', 2, '0');
        return ($matches[1] ?? '') === '-' ? -$minor : $minor;
    }

    private function utc(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return gmdate('Y-m-d\TH:i:s\Z', $value->getTimestamp());
        }

        if (is_object($value) && is_callable([$value, 'getTimestamp'])) {
            return gmdate('Y-m-d\TH:i:s\Z', (int) $value->getTimestamp());
        }

        if (is_string($value)) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));

            if ($date !== false && $date->format('Y-m-d\TH:i:s\Z') === $value) {
                return $value;
            }
        }

        throw new SourceRecordException('product_date_invalid', 'A source product date is not canonical or convertible to UTC.');
    }

    private function attributeKey(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_-]+/', '-', $value) ?? '';
        $value = trim($value, '-');

        if ($value === '') {
            throw new SourceRecordException('product_attribute_identifier_ambiguous', 'An attribute identifier normalizes to blank.');
        }

        return $value;
    }

    /** @return array{string, string} */
    private function attributeValueAndLabel(mixed $value, bool $taxonomy, string $taxonomyName): array
    {
        $value = $this->scalar($value, 'product_attribute_value_invalid');

        if ($taxonomy && (is_int($value) || (is_string($value) && ctype_digit($value)))) {
            $term = function_exists('get_term') ? get_term((int) $value, $taxonomyName) : null;

            if (is_object($term) && (string) ($term->slug ?? '') !== '') {
                return [(string) $term->slug, (string) ($term->name ?? $term->slug)];
            }
        }

        $value = trim((string) $value);

        if ($value === '') {
            throw new SourceRecordException('product_attribute_value_invalid', 'Product attribute values cannot be blank.');
        }

        return [$value, $value];
    }

    private function declaredVariationValue(string $value, AttributeRecord $attribute): string
    {
        if ($value === '' || $attribute->kind === 'taxonomy' || in_array($value, $attribute->values, true)) {
            return $value;
        }

        $matches = array_values(array_filter(
            $attribute->values,
            fn (string $candidate): bool => $this->variationValueSlug($candidate) === $value,
        ));

        return count($matches) === 1 ? $matches[0] : $value;
    }

    private function variationValueSlug(string $value): string
    {
        if (function_exists('sanitize_title')) {
            return sanitize_title($value);
        }

        return $this->attributeKey($value);
    }

    private function fulfilment(object $product, array $data): string
    {
        $virtual = (bool) $this->scalar($this->read($product, 'is_virtual', $data['virtual'] ?? false), 'product_virtual_invalid');
        $downloadable = (bool) $this->scalar($this->read($product, 'is_downloadable', $data['downloadable'] ?? false), 'product_downloadable_invalid');
        return $virtual || $downloadable ? 'digital' : 'physical';
    }

    /** @return array<string, int> */
    private function ratingDistribution(mixed $counts): array
    {
        if (!is_array($counts)) {
            throw new SourceRecordException('product_rating_distribution_invalid', 'Rating distribution must be an integer map.');
        }

        $normalized = [];

        foreach ($counts as $rating => $count) {
            if ((!is_int($rating) && !ctype_digit((string) $rating)) || !is_int($count) || $count < 0) {
                throw new SourceRecordException('product_rating_distribution_invalid', 'Rating distribution must be an integer map.');
            }

            $normalized[(string) $rating] = $count;
        }

        ksort($normalized, SORT_NUMERIC);
        return $normalized;
    }

    private function shippingClassSlug(object $product, array $data): string
    {
        $slug = $this->read($product, 'get_shipping_class', null);

        if (is_scalar($slug) && (string) $slug !== '') {
            return $this->attributeKey((string) $slug);
        }

        $id = (int) $this->read($product, 'get_shipping_class_id', $data['shipping_class_id'] ?? 0);

        if ($id <= 0) {
            return 'none';
        }

        $term = function_exists('get_term') ? get_term($id, 'product_shipping_class') : null;
        return is_object($term) && (string) ($term->slug ?? '') !== ''
            ? $this->attributeKey((string) $term->slug)
            : 'term-' . $id;
    }

    /** @return array<string, scalar|list<int>|null> */
    private function typeConfiguration(object $product, array $data): array
    {
        $type = (string) $this->read($product, 'get_type', 'simple');
        $configuration = [];

        if ($type === 'grouped') {
            $children = $this->read($product, 'get_children', $data['children'] ?? []);

            if (!is_array($children)) {
                throw new SourceRecordException('grouped_product_children_invalid', 'Grouped product children must be a list.');
            }

            $configuration['grouped_children'] = array_map(
                fn (mixed $id): int => $this->positiveInt($id, 'grouped_product_child_invalid'),
                array_values($children),
            );
        }

        if ($type === 'external') {
            $configuration['external_url'] = $this->scalarString(
                $this->read($product, 'get_product_url', $data['product_url'] ?? ''),
                'external_product_url_invalid',
            );
            $configuration['button_text'] = $this->scalarString(
                $this->read($product, 'get_button_text', $data['button_text'] ?? ''),
                'external_product_button_invalid',
            );
        }

        if ($this->isSubscriptionProduct($product, $type)) {
            $configuration = [...$configuration, ...$this->subscriptionConfiguration($product, $data)];
        }

        ksort($configuration);
        return $configuration;
    }

    private function isSubscriptionProduct(object $product, string $type): bool
    {
        if (in_array($type, ['subscription', 'variable-subscription', 'subscription_variation'], true)) {
            return true;
        }

        return is_callable(['WC_Subscriptions_Product', 'is_subscription'])
            && \WC_Subscriptions_Product::is_subscription($product);
    }

    /** @return array<string, scalar|null> */
    private function subscriptionConfiguration(object $product, array $data): array
    {
        $apiMethods = [
            'subscription_price' => 'get_price',
            'subscription_period' => 'get_period',
            'subscription_period_interval' => 'get_interval',
            'subscription_length' => 'get_length',
            'subscription_sign_up_fee' => 'get_sign_up_fee',
            'subscription_trial_length' => 'get_trial_length',
            'subscription_trial_period' => 'get_trial_period',
        ];
        $configuration = [];

        foreach ($apiMethods as $field => $method) {
            if (array_key_exists($field, $data)) {
                $configuration[$field] = $this->scalar($data[$field], 'subscription_product_field_invalid');
                continue;
            }

            if (is_callable(['WC_Subscriptions_Product', $method])) {
                $configuration[$field] = $this->scalar(
                    \WC_Subscriptions_Product::$method($product),
                    'subscription_product_field_invalid',
                );
                continue;
            }

            $value = is_callable([$product, 'get_meta']) ? $product->get_meta('_' . $field, true) : '';
            if ($value !== '') {
                $configuration[$field] = $this->scalar($value, 'subscription_product_field_invalid');
            }
        }

        return $configuration;
    }

    /** @return array<string, scalar|null> */
    private function variationTypeConfiguration(object $product, array $data): array
    {
        return array_filter(
            $this->typeConfiguration($product, $data),
            static fn (mixed $value): bool => is_scalar($value) || $value === null,
        );
    }

    private function currency(): string
    {
        $currency = function_exists('get_woocommerce_currency') ? strtoupper((string) get_woocommerce_currency()) : 'USD';

        if (preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1) {
            throw new SourceRecordException('product_currency_invalid', 'WooCommerce currency is invalid.');
        }

        return $currency;
    }

}

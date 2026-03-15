<?php

declare(strict_types=1);

namespace CartShift\Domain\Mapping;

defined('ABSPATH') || exit;

use CartShift\Support\MoneyHelper;

final class ProductMapper
{
    /**
     * @param array<int, int> $shippingClassMap WC shipping class term_id => FC shipping class ID.
     */
    public function __construct(
        private readonly string $currency,
        private readonly array $shippingClassMap = [],
    ) {}

    /**
     * Map a WC_Product to FluentCart product data arrays.
     *
     * @return array{product: array, detail: array, variations: array}|null Null if product type is unsupported.
     */
    public function map(\WC_Product $product): ?array
    {
        $type = $product->get_type();

        if (in_array($type, ['grouped', 'external'], true)) {
            return null;
        }

        $isVariable = $type === 'variable';
        $fulfillmentType = self::getFulfillmentType($product);

        $postData = [
            'post_title'    => $product->get_name(),
            'post_content'  => $product->get_description(),
            'post_excerpt'  => $product->get_short_description(),
            'post_status'   => self::resolvePostStatus($product),
            'post_type'     => 'fluent-products',
            'post_name'     => $product->get_slug(),
            'post_date'     => $product->get_date_created()
                ? $product->get_date_created()->date('Y-m-d H:i:s')
                : current_time('mysql'),
            'post_date_gmt' => $product->get_date_created()
                ? $product->get_date_created()->date('Y-m-d H:i:s')
                : current_time('mysql', true),
        ];

        $variationType = $isVariable ? 'advanced_variations' : 'simple';

        $detailData = [
            'fulfillment_type'    => $fulfillmentType,
            'variation_type'      => $variationType,
            'stock_availability'  => $product->is_in_stock() ? 'in-stock' : 'out-of-stock',
            'manage_stock'        => $product->get_manage_stock() ? 1 : 0,
            'manage_downloadable' => $product->is_downloadable() ? 1 : 0,
            'other_info'          => self::buildDetailOtherInfo($product),
        ];

        $variationMapper = new VariationMapper($this->currency, $this->shippingClassMap);
        $variations = [];

        if ($isVariable) {
            $variationIds = $product->get_children();
            $index = 0;
            foreach ($variationIds as $varId) {
                $wcVariation = wc_get_product($varId);
                if (!$wcVariation || !$wcVariation instanceof \WC_Product_Variation) {
                    continue;
                }
                $variations[] = $variationMapper->mapVariation($wcVariation, $index);
                $index++;
            }
        } else {
            $variations[] = $variationMapper->mapSimple($product);
        }

        $mapped = [
            'product'    => $postData,
            'detail'     => $detailData,
            'variations' => $variations,
        ];

        /** @see 'cartshift/mapper/product' */
        return apply_filters('cartshift/mapper/product', $mapped, $product);
    }

    /**
     * Map product detail data for an existing FC product.
     */
    public function mapDetail(\WC_Product $product, int $fcProductId): array
    {
        $fulfillmentType = self::getFulfillmentType($product);
        $isVariable = $product->get_type() === 'variable';

        return [
            'product_id'          => $fcProductId,
            'fulfillment_type'    => $fulfillmentType,
            'variation_type'      => $isVariable ? 'advanced_variations' : 'simple',
            'stock_availability'  => $product->is_in_stock() ? 'in-stock' : 'out-of-stock',
            'manage_stock'        => $product->get_manage_stock() ? 1 : 0,
            'manage_downloadable' => $product->is_downloadable() ? 1 : 0,
            'other_info'          => self::buildDetailOtherInfo($product),
        ];
    }

    /**
     * Build the other_info array for product detail, including weight/dimensions when present.
     */
    private static function buildDetailOtherInfo(\WC_Product $product): array
    {
        $info = [
            'sold_individually' => $product->is_sold_individually() ? 'yes' : 'no',
        ];

        $weight = $product->get_weight();
        $length = $product->get_length();
        $width  = $product->get_width();
        $height = $product->get_height();

        if ($weight) {
            $info['weight'] = $weight;
        }
        if ($length) {
            $info['length'] = $length;
        }
        if ($width) {
            $info['width'] = $width;
        }
        if ($height) {
            $info['height'] = $height;
        }

        if ($weight || $length || $width || $height) {
            $info['weight_unit']    = get_option('woocommerce_weight_unit', 'kg');
            $info['dimension_unit'] = get_option('woocommerce_dimension_unit', 'cm');
        }

        return $info;
    }

    /**
     * Resolve the FC post_status from WC status + catalog visibility.
     *
     * WC separates post_status (publish/draft/private/pending) from catalog visibility
     * (visible/catalog/search/hidden). FC only has publish/draft/private.
     *
     * Products that are published but hidden from both catalog and search are mapped to draft.
     * Products with partial visibility (catalog-only or search-only) stay published but
     * generate a warning via the 'cartshift/mapper/product/warnings' filter.
     */
    private static function resolvePostStatus(\WC_Product $product): string
    {
        $wcStatus = $product->get_status();
        $visibility = method_exists($product, 'get_catalog_visibility')
            ? $product->get_catalog_visibility()
            : 'visible';

        $fcStatus = match (true) {
            $wcStatus === 'private' => 'private',
            $wcStatus !== 'publish' => 'draft',
            $visibility === 'hidden' => 'draft',
            default => 'publish',
        };

        if ($wcStatus === 'publish' && in_array($visibility, ['catalog', 'search'], true)) {
            /** @see 'cartshift/mapper/product/warnings' */
            apply_filters('cartshift/mapper/product/warnings', [
                sprintf(
                    'Product #%d has partial visibility "%s" — mapped as published. FC does not support partial catalog visibility.',
                    $product->get_id(),
                    $visibility,
                ),
            ], $product);
        }

        return $fcStatus;
    }

    /**
     * Determine FC fulfillment type from WC product.
     */
    public static function getFulfillmentType(\WC_Product $product): string
    {
        if ($product->is_downloadable()) {
            return 'digital';
        }

        if ($product->is_virtual()) {
            return 'service';
        }

        return 'physical';
    }
}

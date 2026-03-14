<?php

namespace CartShift\Mapper;

defined('ABSPATH') or die;

class ProductMapper
{
    /**
     * Map a WC_Product to FluentCart product data arrays.
     *
     * @param \WC_Product $product
     * @return array{product: array, detail: array, variations: array}|null Null if product type is unsupported.
     */
    public static function map(\WC_Product $product): ?array
    {
        $type = $product->get_type();

        // Skip unsupported product types.
        if (in_array($type, ['grouped', 'external'], true)) {
            return null;
        }

        $isVariable = ($type === 'variable');

        // Determine fulfillment type.
        $fulfillmentType = self::getFulfillmentType($product);

        // Product post data (for wp_posts via FluentCart's Product model which uses CPT).
        $postData = [
            'post_title'   => $product->get_name(),
            'post_content' => $product->get_description(),
            'post_excerpt' => $product->get_short_description(),
            'post_status'  => StatusMapper::productStatus($product->get_status()),
            'post_type'    => 'fluent-products',
            'post_name'    => $product->get_slug(),
            'post_date'    => $product->get_date_created()
                ? $product->get_date_created()->date('Y-m-d H:i:s')
                : current_time('mysql'),
            'post_date_gmt' => $product->get_date_created()
                ? $product->get_date_created()->date('Y-m-d H:i:s')
                : current_time('mysql', true),
        ];

        // Product detail data (for fct_product_details).
        $variationType = $isVariable ? 'advanced_variations' : 'simple';

        $detailData = [
            'fulfillment_type'    => $fulfillmentType,
            'variation_type'      => $variationType,
            'stock_availability'  => $product->is_in_stock() ? 'in-stock' : 'out-of-stock',
            'manage_stock'        => $product->get_manage_stock() ? 1 : 0,
            'manage_downloadable' => $product->is_downloadable() ? 1 : 0,
            'other_info'          => json_encode([
                'sold_individually' => $product->is_sold_individually() ? 'yes' : 'no',
            ]),
        ];

        // Build variations.
        $variations = [];

        if ($isVariable) {
            $variationIds = $product->get_children();
            $index = 0;
            foreach ($variationIds as $varId) {
                $wcVariation = wc_get_product($varId);
                if (!$wcVariation || !$wcVariation instanceof \WC_Product_Variation) {
                    continue;
                }
                $variations[] = VariationMapper::mapVariation($wcVariation, $index);
                $index++;
            }
        } else {
            // Simple product: create a single default variation.
            $variations[] = VariationMapper::mapSimple($product);
        }

        return [
            'product'    => $postData,
            'detail'     => $detailData,
            'variations' => $variations,
        ];
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

    /**
     * Convert a WC price to FC cents (BIGINT).
     */
    public static function toCents($price): int
    {
        return intval(round(floatval($price) * 100));
    }
}

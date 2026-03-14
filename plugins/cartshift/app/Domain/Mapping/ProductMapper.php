<?php

declare(strict_types=1);

namespace CartShift\Domain\Mapping;

defined('ABSPATH') or die;

use CartShift\Support\MoneyHelper;

final class ProductMapper
{
    public function __construct(
        private readonly string $currency,
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
            'post_status'   => $product->get_status() === 'publish' ? 'publish' : 'draft',
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
            'other_info'          => [
                'sold_individually' => $product->is_sold_individually() ? 'yes' : 'no',
            ],
        ];

        $variationMapper = new VariationMapper($this->currency);
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
            'other_info'          => [
                'sold_individually' => $product->is_sold_individually() ? 'yes' : 'no',
            ],
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
}

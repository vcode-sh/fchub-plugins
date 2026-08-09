<?php

declare(strict_types=1);

namespace CartShift\Domain\Mapping;

defined('ABSPATH') || exit;

use CartShift\Support\Enums\MigrationErrorCode;
use CartShift\Support\MoneyHelper;
use CartShift\Support\ProductTypes;

final class ProductMapper
{
    /**
     * Shared across map() calls so VariationMapper's attribute-term memo cache survives
     * the whole run instead of being thrown away after every product.
     */
    private ?VariationMapper $variationMapper = null;

    /**
     * Warnings raised by the last map() call, each with its reason code.
     *
     * @var list<array{message: string, code: MigrationErrorCode}>
     */
    private array $warnings = [];

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
        $this->warnings = [];

        $type = $product->get_type();

        if (in_array($type, ['grouped', 'external'], true)) {
            return null;
        }

        $isVariable = ProductTypes::isVariable($type);
        $fulfillmentType = self::getFulfillmentType($product);

        $dateCreated = $product->get_date_created();

        $postData = [
            'post_title'    => $product->get_name(),
            'post_content'  => $product->get_description(),
            'post_excerpt'  => $product->get_short_description(),
            'post_status'   => $this->resolvePostStatus($product),
            'post_type'     => 'fluent-products',
            'post_name'     => $product->get_slug(),
            // wp_posts keeps the two apart: post_date is site-local, post_date_gmt is UTC.
            // WC_DateTime::date() formats against getOffsetTimestamp() — site-local — so it is
            // right for post_date and wrong for post_date_gmt. getTimestamp() is the plain UTC
            // epoch, which is what post_date_gmt wants.
            'post_date'     => $dateCreated
                ? $dateCreated->date('Y-m-d H:i:s')
                : current_time('mysql'),
            'post_date_gmt' => self::toUtcString($dateCreated) ?? current_time('mysql', true),
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

        $variationMapper = $this->variationMapper();
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
        $isVariable = ProductTypes::isVariable($product->get_type());

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
     * The shared VariationMapper for this run.
     */
    private function variationMapper(): VariationMapper
    {
        return $this->variationMapper ??= new VariationMapper($this->currency, $this->shippingClassMap);
    }

    /**
     * Render a WC_DateTime as a UTC 'Y-m-d H:i:s' string.
     *
     * WC_DateTime::date() formats against getOffsetTimestamp() (site-local); getTimestamp()
     * is the plain UTC epoch, so gmdate() over it is the UTC rendering.
     */
    private static function toUtcString(?object $date): ?string
    {
        if (!$date || !method_exists($date, 'getTimestamp')) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $date->getTimestamp());
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
     * Products with partial visibility (catalog-only or search-only) stay published, and the
     * fact that the distinction was lost is collected as a coded warning — see getCodedWarnings().
     * The 'cartshift/mapper/product/warnings' filter still fires for anything already hooked to it.
     */
    private function resolvePostStatus(\WC_Product $product): string
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
            $message = sprintf(
                'Product #%d has partial visibility "%s" — mapped as published. FC does not support partial catalog visibility.',
                $product->get_id(),
                $visibility,
            );

            $this->warnings[] = [
                'message' => $message,
                'code'    => MigrationErrorCode::PartialCatalogVisibility,
            ];

            /** @see 'cartshift/mapper/product/warnings' */
            apply_filters('cartshift/mapper/product/warnings', [$message], $product);
        }

        return $fcStatus;
    }

    /**
     * Warnings collected during the last map() call.
     *
     * Plain sentences, matching CouponMapper and SubscriptionMapper.
     *
     * @return list<string>
     */
    public function getWarnings(): array
    {
        return array_map(
            static fn (array $warning): string => $warning['message'],
            $this->warnings,
        );
    }

    /**
     * The same warnings, each paired with the reason code it stands for.
     *
     * @return list<array{message: string, code: MigrationErrorCode}>
     */
    public function getCodedWarnings(): array
    {
        return $this->warnings;
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

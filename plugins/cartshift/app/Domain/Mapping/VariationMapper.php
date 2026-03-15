<?php

declare(strict_types=1);

namespace CartShift\Domain\Mapping;

defined('ABSPATH') || exit;

use CartShift\Support\Enums\FcBillingInterval;
use CartShift\Support\MoneyHelper;

final class VariationMapper
{
    /**
     * @param array<int, int> $shippingClassMap WC shipping class term_id => FC shipping class ID.
     */
    public function __construct(
        private readonly string $currency,
        private readonly array $shippingClassMap = [],
    ) {}

    /**
     * Map a WC_Product_Variation to a FluentCart variation data array.
     */
    public function mapVariation(\WC_Product_Variation $variation, int $index = 0): array
    {
        $regularPrice = $variation->get_regular_price();
        $salePrice    = $variation->get_sale_price();
        $price        = $variation->get_price();

        $itemPrice    = MoneyHelper::toCents($price ?: $regularPrice, $this->currency);
        $comparePrice = ($salePrice !== '' && $salePrice !== null && floatval($salePrice) < floatval($regularPrice))
            ? MoneyHelper::toCents($regularPrice, $this->currency)
            : 0;

        $paymentType = 'onetime';
        $otherInfo   = [];

        if (class_exists('WC_Subscriptions_Product') && \WC_Subscriptions_Product::is_subscription($variation)) {
            $paymentType = 'subscription';
            $period      = $variation->get_meta('_subscription_period') ?: 'month';
            $interval    = (int) ($variation->get_meta('_subscription_period_interval') ?: 1);
            $length      = (int) ($variation->get_meta('_subscription_length') ?: 0);
            $trialLength = (int) ($variation->get_meta('_subscription_trial_length') ?: 0);
            $trialPeriod = $variation->get_meta('_subscription_trial_period') ?: 'day';
            $signupFee   = MoneyHelper::toCents(
                $variation->get_meta('_subscription_sign_up_fee') ?: 0,
                $this->currency,
            );

            $trialDays = self::convertToDays($trialLength, $trialPeriod);
            $billTimes = $length > 0 ? (int) ceil($length / max($interval, 1)) : 0;

            $otherInfo = [
                'payment_type'     => 'subscription',
                'repeat_interval'  => FcBillingInterval::fromWooCommerce($period, $interval)->value,
                'times'            => $billTimes,
                'trial_days'       => $trialDays,
                'manage_setup_fee' => $signupFee > 0 ? 'yes' : 'no',
                'signup_fee'       => $signupFee,
            ];
        }

        $fulfillmentType = match (true) {
            $variation->is_downloadable() => 'digital',
            $variation->is_virtual()      => 'service',
            default                       => 'physical',
        };

        $sku = $variation->get_sku();

        $attributes = $variation->get_attributes();
        $titleParts = [];
        foreach ($attributes as $attrName => $attrValue) {
            if ($attrValue) {
                $taxonomy = str_replace('attribute_', '', $attrName);
                $term = get_term_by('slug', $attrValue, $taxonomy);
                $titleParts[] = $term ? $term->name : $attrValue;
            }
        }
        $variationTitle = !empty($titleParts) ? implode(' / ', $titleParts) : 'Default';

        $otherInfo = self::mergeWeightDimensions($otherInfo, $variation);

        return [
            'serial_index'         => $index,
            'variation_title'      => $variationTitle,
            'variation_identifier' => sanitize_title($variationTitle),
            'sku'                  => $sku ?: null,
            'payment_type'         => $paymentType,
            'fulfillment_type'     => $fulfillmentType,
            'item_price'           => $itemPrice,
            'compare_price'        => $comparePrice,
            'item_cost'            => 0,
            'manage_cost'          => 'false',
            'manage_stock'         => $variation->get_manage_stock() ? 1 : 0,
            'stock_status'         => $variation->is_in_stock() ? 'in-stock' : 'out-of-stock',
            'total_stock'          => (int) $variation->get_stock_quantity(),
            'available'            => max(0, (int) $variation->get_stock_quantity()),
            'committed'            => 0,
            'on_hold'              => 0,
            'backorders'           => in_array($variation->get_backorders(), ['yes', 'notify'], true) ? 1 : 0,
            'downloadable'         => $variation->is_downloadable() ? 'true' : 'false',
            'item_status'          => $variation->get_status() === 'publish' ? 'active' : 'inactive',
            'sold_individually'    => 0,
            'shipping_class'       => $this->resolveShippingClass($variation),
            'media_id'             => self::getMediaId($variation),
            'other_info'           => !empty($otherInfo) ? $otherInfo : null,
        ];
    }

    /**
     * Map a simple WC_Product to a single default FluentCart variation.
     */
    public function mapSimple(\WC_Product $product): array
    {
        $regularPrice = $product->get_regular_price();
        $salePrice    = $product->get_sale_price();
        $price        = $product->get_price();

        $itemPrice    = MoneyHelper::toCents($price ?: $regularPrice, $this->currency);
        $comparePrice = ($salePrice !== '' && $salePrice !== null && floatval($salePrice) < floatval($regularPrice))
            ? MoneyHelper::toCents($regularPrice, $this->currency)
            : 0;

        $paymentType = 'onetime';
        $otherInfo   = [];

        if (class_exists('WC_Subscriptions_Product') && \WC_Subscriptions_Product::is_subscription($product)) {
            $paymentType = 'subscription';
            $period      = $product->get_meta('_subscription_period') ?: 'month';
            $interval    = (int) ($product->get_meta('_subscription_period_interval') ?: 1);
            $length      = (int) ($product->get_meta('_subscription_length') ?: 0);
            $trialLength = (int) ($product->get_meta('_subscription_trial_length') ?: 0);
            $trialPeriod = $product->get_meta('_subscription_trial_period') ?: 'day';
            $signupFee   = MoneyHelper::toCents(
                $product->get_meta('_subscription_sign_up_fee') ?: 0,
                $this->currency,
            );

            $trialDays = self::convertToDays($trialLength, $trialPeriod);
            $billTimes = $length > 0 ? (int) ceil($length / max($interval, 1)) : 0;

            $otherInfo = [
                'payment_type'     => 'subscription',
                'repeat_interval'  => FcBillingInterval::fromWooCommerce($period, $interval)->value,
                'times'            => $billTimes,
                'trial_days'       => $trialDays,
                'manage_setup_fee' => $signupFee > 0 ? 'yes' : 'no',
                'signup_fee'       => $signupFee,
            ];
        }

        $fulfillmentType = ProductMapper::getFulfillmentType($product);
        $sku = $product->get_sku();
        $otherInfo = self::mergeWeightDimensions($otherInfo, $product);

        return [
            'serial_index'         => 0,
            'variation_title'      => 'Default',
            'variation_identifier' => 'default',
            'sku'                  => $sku ?: null,
            'payment_type'         => $paymentType,
            'fulfillment_type'     => $fulfillmentType,
            'item_price'           => $itemPrice,
            'compare_price'        => $comparePrice,
            'item_cost'            => 0,
            'manage_cost'          => 'false',
            'manage_stock'         => $product->get_manage_stock() ? 1 : 0,
            'stock_status'         => $product->is_in_stock() ? 'in-stock' : 'out-of-stock',
            'total_stock'          => (int) $product->get_stock_quantity(),
            'available'            => max(0, (int) $product->get_stock_quantity()),
            'committed'            => 0,
            'on_hold'              => 0,
            'backorders'           => in_array($product->get_backorders(), ['yes', 'notify'], true) ? 1 : 0,
            'downloadable'         => $product->is_downloadable() ? 'true' : 'false',
            'item_status'          => 'active',
            'sold_individually'    => $product->is_sold_individually() ? 1 : 0,
            'shipping_class'       => $this->resolveShippingClass($product),
            'media_id'             => self::getMediaId($product),
            'other_info'           => !empty($otherInfo) ? $otherInfo : null,
        ];
    }

    /**
     * Merge weight and dimension data into the other_info array.
     */
    private static function mergeWeightDimensions(array $otherInfo, \WC_Product $product): array
    {
        $weight = $product->get_weight();
        $length = $product->get_length();
        $width  = $product->get_width();
        $height = $product->get_height();

        if ($weight) {
            $otherInfo['weight'] = $weight;
        }
        if ($length) {
            $otherInfo['length'] = $length;
        }
        if ($width) {
            $otherInfo['width'] = $width;
        }
        if ($height) {
            $otherInfo['height'] = $height;
        }

        if ($weight || $length || $width || $height) {
            $otherInfo['weight_unit']    = get_option('woocommerce_weight_unit', 'kg');
            $otherInfo['dimension_unit'] = get_option('woocommerce_dimension_unit', 'cm');
        }

        return $otherInfo;
    }

    /**
     * Convert a trial length + period to number of days.
     */
    private static function convertToDays(int $length, string $period): int
    {
        if ($length <= 0) {
            return 0;
        }

        return $length * match ($period) {
            'day'   => 1,
            'week'  => 7,
            'month' => 30,
            'year'  => 365,
            default => 1,
        };
    }

    /**
     * Get the featured image attachment ID for media_id field.
     */
    private static function getMediaId(\WC_Product $product): ?int
    {
        $imageId = $product->get_image_id();

        return $imageId ? (int) $imageId : null;
    }

    /**
     * Resolve the FC shipping class ID from the WC product's shipping class term.
     */
    private function resolveShippingClass(\WC_Product $product): ?int
    {
        $wcShippingClassId = $product->get_shipping_class_id();

        if ($wcShippingClassId <= 0) {
            return null;
        }

        return $this->shippingClassMap[$wcShippingClassId] ?? null;
    }
}

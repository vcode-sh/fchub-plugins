<?php

namespace WcFc\Mapper;

defined('ABSPATH') or die;

class VariationMapper
{
    /**
     * Map a WC_Product_Variation to a FluentCart variation data array.
     *
     * @param \WC_Product_Variation $variation WC variation object.
     * @param int                   $index     Serial index for ordering.
     * @return array
     */
    public static function mapVariation(\WC_Product_Variation $variation, int $index = 0): array
    {
        $regularPrice = $variation->get_regular_price();
        $salePrice    = $variation->get_sale_price();
        $price        = $variation->get_price();

        $itemPrice    = ProductMapper::toCents($price ?: $regularPrice);
        $comparePrice = ($salePrice !== '' && $salePrice !== null && floatval($salePrice) < floatval($regularPrice))
            ? ProductMapper::toCents($regularPrice)
            : 0;

        $paymentType = 'onetime';
        $otherInfo   = [];

        // Check if this is a WC Subscriptions product.
        if (class_exists('WC_Subscriptions_Product') && \WC_Subscriptions_Product::is_subscription($variation)) {
            $paymentType = 'subscription';
            $period      = $variation->get_meta('_subscription_period') ?: 'month';
            $interval    = (int) ($variation->get_meta('_subscription_period_interval') ?: 1);
            $length      = (int) ($variation->get_meta('_subscription_length') ?: 0);
            $trialLength = (int) ($variation->get_meta('_subscription_trial_length') ?: 0);
            $trialPeriod = $variation->get_meta('_subscription_trial_period') ?: 'day';
            $signupFee   = ProductMapper::toCents($variation->get_meta('_subscription_sign_up_fee') ?: 0);

            $trialDays = self::convertToDays($trialLength, $trialPeriod);
            $billTimes = $length > 0 ? (int) ceil($length / max($interval, 1)) : 0;

            $otherInfo = [
                'payment_type'    => 'subscription',
                'repeat_interval' => StatusMapper::billingInterval($period),
                'times'           => $billTimes,
                'trial_days'      => $trialDays,
                'manage_setup_fee'=> $signupFee > 0 ? 'yes' : 'no',
                'signup_fee'      => $signupFee,
            ];
        }

        $fulfillmentType = 'physical';
        if ($variation->is_downloadable()) {
            $fulfillmentType = 'digital';
        } elseif ($variation->is_virtual()) {
            $fulfillmentType = 'service';
        }

        $sku = $variation->get_sku();

        // Build variation title from attributes.
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

        return [
            'serial_index'        => $index,
            'variation_title'     => $variationTitle,
            'variation_identifier'=> sanitize_title($variationTitle),
            'sku'                 => $sku ?: null,
            'payment_type'        => $paymentType,
            'fulfillment_type'    => $fulfillmentType,
            'item_price'          => $itemPrice,
            'compare_price'       => $comparePrice,
            'item_cost'           => 0,
            'manage_cost'         => 'false',
            'manage_stock'        => $variation->get_manage_stock() ? 1 : 0,
            'stock_status'        => $variation->is_in_stock() ? 'in-stock' : 'out-of-stock',
            'total_stock'         => (int) $variation->get_stock_quantity(),
            'available'           => max(0, (int) $variation->get_stock_quantity()),
            'committed'           => 0,
            'on_hold'             => 0,
            'backorders'          => $variation->get_backorders() === 'yes' ? 1 : 0,
            'downloadable'        => $variation->is_downloadable() ? 'true' : 'false',
            'item_status'         => $variation->get_status() === 'publish' ? 'active' : 'inactive',
            'sold_individually'   => 0,
            'shipping_class'      => null,
            'media_id'            => self::getMediaId($variation),
            'other_info'          => !empty($otherInfo) ? json_encode($otherInfo) : null,
        ];
    }

    /**
     * Map a simple WC_Product to a single default FluentCart variation.
     *
     * @param \WC_Product $product
     * @return array
     */
    public static function mapSimple(\WC_Product $product): array
    {
        $regularPrice = $product->get_regular_price();
        $salePrice    = $product->get_sale_price();
        $price        = $product->get_price();

        $itemPrice    = ProductMapper::toCents($price ?: $regularPrice);
        $comparePrice = ($salePrice !== '' && $salePrice !== null && floatval($salePrice) < floatval($regularPrice))
            ? ProductMapper::toCents($regularPrice)
            : 0;

        $paymentType = 'onetime';
        $otherInfo   = [];

        // Check if this is a WC Subscriptions simple product.
        if (class_exists('WC_Subscriptions_Product') && \WC_Subscriptions_Product::is_subscription($product)) {
            $paymentType = 'subscription';
            $period      = $product->get_meta('_subscription_period') ?: 'month';
            $interval    = (int) ($product->get_meta('_subscription_period_interval') ?: 1);
            $length      = (int) ($product->get_meta('_subscription_length') ?: 0);
            $trialLength = (int) ($product->get_meta('_subscription_trial_length') ?: 0);
            $trialPeriod = $product->get_meta('_subscription_trial_period') ?: 'day';
            $signupFee   = ProductMapper::toCents($product->get_meta('_subscription_sign_up_fee') ?: 0);

            $trialDays = self::convertToDays($trialLength, $trialPeriod);
            $billTimes = $length > 0 ? (int) ceil($length / max($interval, 1)) : 0;

            $otherInfo = [
                'payment_type'    => 'subscription',
                'repeat_interval' => StatusMapper::billingInterval($period),
                'times'           => $billTimes,
                'trial_days'      => $trialDays,
                'manage_setup_fee'=> $signupFee > 0 ? 'yes' : 'no',
                'signup_fee'      => $signupFee,
            ];
        }

        $fulfillmentType = ProductMapper::getFulfillmentType($product);
        $sku = $product->get_sku();

        return [
            'serial_index'        => 0,
            'variation_title'     => 'Default',
            'variation_identifier'=> 'default',
            'sku'                 => $sku ?: null,
            'payment_type'        => $paymentType,
            'fulfillment_type'    => $fulfillmentType,
            'item_price'          => $itemPrice,
            'compare_price'       => $comparePrice,
            'item_cost'           => 0,
            'manage_cost'         => 'false',
            'manage_stock'        => $product->get_manage_stock() ? 1 : 0,
            'stock_status'        => $product->is_in_stock() ? 'in-stock' : 'out-of-stock',
            'total_stock'         => (int) $product->get_stock_quantity(),
            'available'           => max(0, (int) $product->get_stock_quantity()),
            'committed'           => 0,
            'on_hold'             => 0,
            'backorders'          => $product->get_backorders() === 'yes' ? 1 : 0,
            'downloadable'        => $product->is_downloadable() ? 'true' : 'false',
            'item_status'         => 'active',
            'sold_individually'   => $product->is_sold_individually() ? 1 : 0,
            'shipping_class'      => null,
            'media_id'            => self::getMediaId($product),
            'other_info'          => !empty($otherInfo) ? json_encode($otherInfo) : null,
        ];
    }

    /**
     * Convert a trial length + period to number of days.
     */
    private static function convertToDays(int $length, string $period): int
    {
        if ($length <= 0) {
            return 0;
        }

        $multiplier = [
            'day'   => 1,
            'week'  => 7,
            'month' => 30,
            'year'  => 365,
        ];

        return $length * ($multiplier[$period] ?? 1);
    }

    /**
     * Get the featured image attachment ID for media_id field.
     */
    private static function getMediaId($product): ?int
    {
        $imageId = $product->get_image_id();
        return $imageId ? (int) $imageId : null;
    }
}

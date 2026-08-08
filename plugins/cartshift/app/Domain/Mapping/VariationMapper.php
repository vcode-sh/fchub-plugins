<?php

declare(strict_types=1);

namespace CartShift\Domain\Mapping;

defined('ABSPATH') || exit;

use CartShift\Support\Enums\FcBillingInterval;
use CartShift\Support\MoneyHelper;

final class VariationMapper
{
    /**
     * What a variation with no attributes to speak of is called.
     *
     * The title mapSimple() writes for every simple product, and the fallback
     * variationTitle() lands on. Named because the mapping screen has to
     * describe a Woo product in the same vocabulary — see variationTitle().
     */
    public const string DEFAULT_VARIATION_TITLE = 'Default';

    /**
     * Memo for attribute term lookups: "taxonomy|slug" => term name, or null when the slug
     * resolves to no term (a custom, non-taxonomy attribute value).
     *
     * Every variation of a variable product repeats the same handful of attribute slugs, so
     * without this each variation re-runs get_term_by() — one query per attribute per variation.
     * Misses are memoised too: a product with custom attribute values would otherwise miss the
     * cache on every single lookup, which is the exact case that needs it most.
     *
     * @var array<string, string|null>
     */
    private array $termNameCache = [];

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

        $variationTitle = $this->variationTitle($variation);

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
     * The title FluentCart will know this variation by: attribute labels
     * joined with ' / '.
     *
     * Public, and that is the point. `WC_Product_Variation::get_name()` is the
     * generated post title — "Parent - Blue, Large", or bare "Parent" once a
     * product has three or more attributes — and nothing on the FluentCart
     * side ever looks like it. So the mapping screen, which has to pair Woo
     * variations with FC variants by name, cannot use get_name() and cannot
     * reimplement this either: a second copy of the label resolution would
     * agree with this one until the day a custom attribute value showed up.
     * One method, two callers, one vocabulary.
     */
    public function variationTitle(\WC_Product_Variation $variation): string
    {
        $titleParts = [];

        foreach ($variation->get_attributes() as $attrName => $attrValue) {
            if ($attrValue) {
                $taxonomy = str_replace('attribute_', '', (string) $attrName);
                $titleParts[] = $this->resolveAttributeLabel($taxonomy, (string) $attrValue);
            }
        }

        return $titleParts !== [] ? implode(' / ', $titleParts) : self::DEFAULT_VARIATION_TITLE;
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
            'variation_title'      => self::DEFAULT_VARIATION_TITLE,
            'variation_identifier' => sanitize_title(self::DEFAULT_VARIATION_TITLE),
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
     * Resolve an attribute slug to its human-readable term name, memoised per instance.
     *
     * Falls back to the raw slug when the value is not a taxonomy term.
     */
    private function resolveAttributeLabel(string $taxonomy, string $slug): string
    {
        $key = $taxonomy . '|' . $slug;

        if (!array_key_exists($key, $this->termNameCache)) {
            $term = get_term_by('slug', $slug, $taxonomy);

            $this->termNameCache[$key] = ($term && isset($term->name) && $term->name !== '')
                ? (string) $term->name
                : null;
        }

        return $this->termNameCache[$key] ?? $slug;
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

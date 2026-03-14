<?php

namespace CartShift\Mapper;

defined('ABSPATH') or die;

class CouponMapper
{
    /**
     * Map a WC coupon (WP_Post or WC_Coupon) to FluentCart coupon data.
     *
     * @param \WC_Coupon $coupon
     * @return array
     */
    public static function map(\WC_Coupon $coupon): array
    {
        $wcType = $coupon->get_discount_type();
        $fcType = StatusMapper::couponType($wcType);

        // For fixed types, convert to cents. For percentage, keep as-is.
        $amount = $fcType === 'fixed'
            ? ProductMapper::toCents($coupon->get_amount())
            : floatval($coupon->get_amount());

        // Build conditions from WC coupon settings.
        $conditions = [];

        $minAmount = $coupon->get_minimum_amount();
        if ($minAmount) {
            $conditions['minimum_amount'] = ProductMapper::toCents($minAmount);
        }

        $maxAmount = $coupon->get_maximum_amount();
        if ($maxAmount) {
            $conditions['maximum_amount'] = ProductMapper::toCents($maxAmount);
        }

        $usageLimit = $coupon->get_usage_limit();
        if ($usageLimit) {
            $conditions['max_uses'] = (int) $usageLimit;
        }

        $usageLimitPerUser = $coupon->get_usage_limit_per_user();
        if ($usageLimitPerUser) {
            $conditions['max_per_customer'] = (int) $usageLimitPerUser;
        }

        $excludeSaleItems = $coupon->get_exclude_sale_items();
        if ($excludeSaleItems) {
            $conditions['exclude_sale_items'] = true;
        }

        // Free shipping flag.
        if ($coupon->get_free_shipping()) {
            $conditions['free_shipping'] = true;
        }

        // Status.
        $status = 'active';
        $expiryDate = $coupon->get_date_expires();
        if ($expiryDate && $expiryDate->getTimestamp() < time()) {
            $status = 'expired';
        }

        return [
            'title'            => $coupon->get_code(),
            'code'             => strtoupper($coupon->get_code()),
            'status'           => $status,
            'type'             => $fcType,
            'amount'           => $amount,
            'conditions'       => !empty($conditions) ? json_encode($conditions) : null,
            'stackable'        => $coupon->get_individual_use() ? 'no' : 'yes',
            'priority'         => 10,
            'use_count'        => (int) $coupon->get_usage_count(),
            'notes'            => $coupon->get_description() ?: '',
            'show_on_checkout' => 'no',
            'start_date'       => $coupon->get_date_created()
                ? $coupon->get_date_created()->date('Y-m-d H:i:s')
                : null,
            'end_date'         => $expiryDate
                ? $expiryDate->date('Y-m-d H:i:s')
                : null,
        ];
    }
}

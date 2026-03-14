<?php

declare(strict_types=1);

namespace CartShift\Domain\Mapping;

defined('ABSPATH') or die;

use CartShift\Support\MoneyHelper;

final class CouponMapper
{
    public function __construct(
        private readonly string $currency,
    ) {}

    /**
     * Map a WC_Coupon to FluentCart coupon data.
     */
    public function map(\WC_Coupon $coupon): array
    {
        $wcType = $coupon->get_discount_type();
        $fcType = self::couponType($wcType);

        $amount = $fcType === 'fixed'
            ? MoneyHelper::toCents($coupon->get_amount(), $this->currency)
            : floatval($coupon->get_amount());

        $conditions = [];

        $minAmount = $coupon->get_minimum_amount();
        if ($minAmount) {
            $conditions['minimum_amount'] = MoneyHelper::toCents($minAmount, $this->currency);
        }

        $maxAmount = $coupon->get_maximum_amount();
        if ($maxAmount) {
            $conditions['maximum_amount'] = MoneyHelper::toCents($maxAmount, $this->currency);
        }

        $usageLimit = $coupon->get_usage_limit();
        if ($usageLimit) {
            $conditions['max_uses'] = (int) $usageLimit;
        }

        $usageLimitPerUser = $coupon->get_usage_limit_per_user();
        if ($usageLimitPerUser) {
            $conditions['max_per_customer'] = (int) $usageLimitPerUser;
        }

        if ($coupon->get_exclude_sale_items()) {
            $conditions['exclude_sale_items'] = true;
        }

        if ($coupon->get_free_shipping()) {
            $conditions['free_shipping'] = true;
        }

        $status     = 'active';
        $expiryDate = $coupon->get_date_expires();
        if ($expiryDate && $expiryDate->getTimestamp() < time()) {
            $status = 'expired';
        }

        $mapped = [
            'title'            => $coupon->get_code(),
            'code'             => strtoupper($coupon->get_code()),
            'status'           => $status,
            'type'             => $fcType,
            'amount'           => $amount,
            'conditions'       => !empty($conditions) ? $conditions : null,
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

        /** @see 'cartshift/mapper/coupon' */
        return apply_filters('cartshift/mapper/coupon', $mapped, $coupon);
    }

    /**
     * Map WC coupon discount type to FC coupon type.
     */
    private static function couponType(string $wcType): string
    {
        return match ($wcType) {
            'percent'                              => 'percent',
            'fixed_cart', 'fixed_product'           => 'fixed',
            'recurring_fee', 'recurring_percent',
            'sign_up_fee', 'sign_up_fee_percent'   => 'percent',
            default                                => 'percent',
        };
    }
}

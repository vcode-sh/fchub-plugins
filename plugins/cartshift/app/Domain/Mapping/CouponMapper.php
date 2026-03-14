<?php

declare(strict_types=1);

namespace CartShift\Domain\Mapping;

defined('ABSPATH') || exit;

use CartShift\Storage\IdMapRepository;
use CartShift\Support\Constants;
use CartShift\Support\MoneyHelper;

final class CouponMapper
{
    public function __construct(
        private readonly IdMapRepository $idMap,
        private readonly string $currency,
    ) {}

    /**
     * Map a WC_Coupon to FluentCart coupon data.
     * FIX M10: Use FC's real conditions schema keys.
     */
    public function map(\WC_Coupon $coupon): array
    {
        $wcType = $coupon->get_discount_type();
        $fcType = self::couponType($wcType);

        $amount = $fcType === 'fixed'
            ? MoneyHelper::toCents($coupon->get_amount(), $this->currency)
            : floatval($coupon->get_amount());

        $conditions = $this->mapConditions($coupon);

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
     * Build the FC conditions array using the correct schema keys.
     * FIX M10: min_purchase_amount (not minimum_amount), max_discount_amount (not maximum_amount),
     * included/excluded products/categories mapped via IdMapRepository.
     */
    private function mapConditions(\WC_Coupon $coupon): array
    {
        $conditions = [];

        // Min purchase amount (FC key: min_purchase_amount).
        $minAmount = $coupon->get_minimum_amount();
        if ($minAmount) {
            $conditions['min_purchase_amount'] = MoneyHelper::toCents($minAmount, $this->currency);
        }

        // Max discount amount (FC key: max_discount_amount).
        $maxAmount = $coupon->get_maximum_amount();
        if ($maxAmount) {
            $conditions['max_discount_amount'] = MoneyHelper::toCents($maxAmount, $this->currency);
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

        // Included products: map WC product IDs to FC product IDs.
        $wcIncludedProducts = $coupon->get_product_ids();
        if (!empty($wcIncludedProducts)) {
            $conditions['included_products'] = $this->mapProductIds($wcIncludedProducts);
        }

        // Excluded products: map WC product IDs to FC product IDs.
        $wcExcludedProducts = $coupon->get_excluded_product_ids();
        if (!empty($wcExcludedProducts)) {
            $conditions['excluded_products'] = $this->mapProductIds($wcExcludedProducts);
        }

        // Included categories: map WC category IDs to FC category IDs.
        $wcIncludedCategories = $coupon->get_product_categories();
        if (!empty($wcIncludedCategories)) {
            $conditions['included_categories'] = $this->mapCategoryIds($wcIncludedCategories);
        }

        // Excluded categories: map WC category IDs to FC category IDs.
        $wcExcludedCategories = $coupon->get_excluded_product_categories();
        if (!empty($wcExcludedCategories)) {
            $conditions['excluded_categories'] = $this->mapCategoryIds($wcExcludedCategories);
        }

        // Email restrictions: comma-separated string in FC.
        $emailRestrictions = $coupon->get_email_restrictions();
        if (!empty($emailRestrictions)) {
            $conditions['email_restrictions'] = implode(',', $emailRestrictions);
        }

        return $conditions;
    }

    /**
     * Map an array of WC product IDs to FC product IDs via IdMap.
     * Skips IDs that have no mapping (unmigrated products).
     *
     * @param int[] $wcProductIds
     * @return int[]
     */
    private function mapProductIds(array $wcProductIds): array
    {
        $fcIds = [];

        foreach ($wcProductIds as $wcId) {
            $fcId = $this->idMap->getFcId(Constants::ENTITY_PRODUCT, (string) $wcId);
            if ($fcId !== null) {
                $fcIds[] = $fcId;
            }
        }

        return $fcIds;
    }

    /**
     * Map an array of WC category IDs to FC category IDs via IdMap.
     * Skips IDs that have no mapping (unmigrated categories).
     *
     * @param int[] $wcCategoryIds
     * @return int[]
     */
    private function mapCategoryIds(array $wcCategoryIds): array
    {
        $fcIds = [];

        foreach ($wcCategoryIds as $wcId) {
            $fcId = $this->idMap->getFcId(Constants::ENTITY_CATEGORY, (string) $wcId);
            if ($fcId !== null) {
                $fcIds[] = $fcId;
            }
        }

        return $fcIds;
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

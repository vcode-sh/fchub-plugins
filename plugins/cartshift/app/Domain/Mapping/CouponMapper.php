<?php

declare(strict_types=1);

namespace CartShift\Domain\Mapping;

defined('ABSPATH') || exit;

use CartShift\Storage\IdMapRepository;
use CartShift\Support\Constants;
use CartShift\Support\Enums\MigrationErrorCode;
use CartShift\Support\MoneyHelper;

final class CouponMapper
{
    /**
     * WC discount type => FC coupon type.
     *
     * FluentCart only understands 'fixed' and 'percent' (app/Services/Coupon/DiscountService.php:252, :389).
     * For 'fixed' the stored amount is compared directly against a cart subtotal in integer
     * minor units, so fixed amounts go through MoneyHelper::toCents(). For 'percent' the stored
     * amount is a plain percentage number (DiscountService.php:396).
     *
     * WooCommerce Subscriptions names its coupon types by convention: the `_percent` suffix means
     * percentage, its absence means a fixed amount. WC Subscriptions is not installed in this
     * environment, so this mapping is derived from that naming convention alone — it has not been
     * verified against WC Subscriptions source.
     */
    private const COUPON_TYPE_MAP = [
        // WooCommerce core.
        'percent'             => 'percent',
        'fixed_cart'          => 'fixed',
        'fixed_product'       => 'fixed',
        // WooCommerce Subscriptions.
        'recurring_percent'   => 'percent',
        'recurring_fee'       => 'fixed',
        'sign_up_fee_percent' => 'percent',
        'sign_up_fee'         => 'fixed',
    ];

    /**
     * Warnings collected during the last map() call, each with its code.
     *
     * @var list<array{message: string, code: MigrationErrorCode}>
     */
    private array $warnings = [];

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
        $this->warnings = [];

        $wcType = $coupon->get_discount_type();
        $fcType = self::COUPON_TYPE_MAP[$wcType] ?? null;

        if ($fcType === null) {
            // Unrecognised third-party discount type. Guessing costs money either way, so pick
            // the inert option: a fixed discount of 0 is a no-op, whereas guessing 'percent' on
            // an amount meant as currency turns "50 off" into "50% off" and gives the shop away.
            // The coupon is still migrated (code, conditions, usage counts survive) so the store
            // owner only has to re-enter the amount; the warning tells them which ones.
            $this->warnings[] = [
                'message' => sprintf(
                    'Coupon "%s" uses unrecognised discount type "%s" — migrated as a fixed 0 discount. '
                    . 'Set the amount manually in FluentCart.',
                    $coupon->get_code(),
                    $wcType,
                ),
                'code' => MigrationErrorCode::UnknownCouponType,
            ];

            $fcType = 'fixed';
            $amount = 0;
        } elseif ($fcType === 'fixed') {
            $amount = MoneyHelper::toCents($coupon->get_amount(), $this->currency);
        } else {
            $amount = floatval($coupon->get_amount());
        }

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
            // UTC. FluentCart validates these with `strtotime($startDate) > time()`
            // (DiscountService.php:586, :590); WordPress pins PHP's default timezone to UTC, so a
            // naive date string is read as UTC. WC_DateTime::date() renders site-local, which would
            // shift every coupon window by the site's UTC offset.
            'start_date'       => self::toUtcString($coupon->get_date_created()),
            'end_date'         => self::toUtcString($expiryDate),
        ];

        /** @see 'cartshift/mapper/coupon' */
        return apply_filters('cartshift/mapper/coupon', $mapped, $coupon);
    }

    /**
     * Warnings collected during the last map() call.
     *
     * Plain sentences, because that is what every existing caller expects.
     * getCodedWarnings() is the one to reach for when the code matters too.
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
}

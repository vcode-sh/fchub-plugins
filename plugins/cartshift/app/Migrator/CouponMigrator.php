<?php

namespace CartShift\Migrator;

defined('ABSPATH') or die;

use CartShift\Mapper\CouponMapper;
use FluentCart\App\Models\Coupon;

class CouponMigrator extends AbstractMigrator
{
    protected string $entityType = 'coupons';

    protected function countTotal(): int
    {
        $counts = wp_count_posts('shop_coupon');
        return (int) $counts->publish + (int) $counts->draft + (int) $counts->private;
    }

    protected function fetchBatch(int $page): array
    {
        $offset = ($page - 1) * $this->batchSize;

        $couponIds = get_posts([
            'post_type'      => 'shop_coupon',
            'post_status'    => ['publish', 'draft', 'private'],
            'posts_per_page' => $this->batchSize,
            'offset'         => $offset,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'fields'         => 'ids',
        ]);

        $coupons = [];
        foreach ($couponIds as $couponId) {
            $coupon = new \WC_Coupon($couponId);
            if ($coupon->get_id()) {
                $coupons[] = $coupon;
            }
        }

        return $coupons;
    }

    /**
     * @param \WC_Coupon $coupon
     */
    protected function processRecord($coupon)
    {
        $wcId = $coupon->get_id();

        // Skip if already migrated.
        if ($this->idMap->getFcId('coupon', $wcId)) {
            $this->log($wcId, 'skipped', 'Already migrated.');
            return false;
        }

        // Check if FC already has a coupon with this code.
        $code = strtoupper($coupon->get_code());
        $existing = Coupon::query()->where('code', $code)->first();
        if ($existing) {
            $this->idMap->store('coupon', $wcId, $existing->id);
            $this->log($wcId, 'skipped', sprintf('Coupon code "%s" already exists in FluentCart.', $code));
            return false;
        }

        $mapped = CouponMapper::map($coupon);

        if ($this->dryRun) {
            $this->log($wcId, 'success', sprintf(
                '[DRY RUN] Would migrate coupon "%s" - Type: %s, Amount: %s.',
                $code,
                $mapped['type'],
                $mapped['amount']
            ));
            return 0;
        }

        $fcCoupon = Coupon::query()->create($mapped);
        $this->idMap->store('coupon', $wcId, $fcCoupon->id);

        $this->log($wcId, 'success', sprintf(
            'Migrated coupon "%s" (FC ID: %d) - Type: %s, Amount: %s.',
            $code,
            $fcCoupon->id,
            $mapped['type'],
            $mapped['amount']
        ));

        return $fcCoupon->id;
    }
}

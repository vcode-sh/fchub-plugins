<?php

declare(strict_types=1);

namespace CartShift\Migrator;

defined('ABSPATH') || exit;

use CartShift\Domain\Mapping\CouponMapper;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\Constants;
use FluentCart\App\Models\Coupon;

final class CouponMigrator extends AbstractMigrator
{
    private readonly CouponMapper $couponMapper;

    public function __construct(
        IdMapRepository $idMap,
        MigrationLogRepository $log,
        MigrationState $migrationState,
        string $migrationId,
        int $batchSize = Constants::DEFAULT_BATCH_SIZE,
    ) {
        parent::__construct($idMap, $log, $migrationState, $migrationId, $batchSize);

        $currency = get_woocommerce_currency();
        $this->couponMapper = new CouponMapper($idMap, $currency);
    }

    #[\Override]
    protected function getEntityType(): string
    {
        return Constants::ENTITY_COUPON;
    }

    #[\Override]
    protected function countTotal(): int
    {
        $counts = wp_count_posts('shop_coupon');

        return (int) $counts->publish + (int) $counts->draft + (int) $counts->private;
    }

    #[\Override]
    public function fetchBatch(int $offset, int $limit): array
    {
        $couponIds = get_posts([
            'post_type'      => 'shop_coupon',
            'post_status'    => ['publish', 'draft', 'private'],
            'posts_per_page' => $limit,
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
     * Validate a coupon without creating any FC records.
     *
     * @param \WC_Coupon $coupon
     */
    #[\Override]
    public function validateRecord(mixed $coupon): bool
    {
        $wcId = $coupon->get_id();

        if ($this->idMap->getFcId(Constants::ENTITY_COUPON, (string) $wcId)) {
            $this->writeLog($wcId, 'dry-run', 'dry-run: already migrated, would skip.');
            return false;
        }

        $code = strtoupper($coupon->get_code());

        if (empty($code)) {
            $this->writeLog($wcId, 'dry-run', 'dry-run: coupon code is empty, would fail.');
            return false;
        }

        $wcType = $coupon->get_discount_type();
        $validTypes = ['percent', 'fixed_cart', 'fixed_product', 'recurring_fee', 'recurring_percent', 'sign_up_fee', 'sign_up_fee_percent'];

        if (!in_array($wcType, $validTypes, true)) {
            $this->writeLog($wcId, 'dry-run', sprintf(
                'dry-run: unknown discount type "%s" for coupon "%s", would default to percent.',
                $wcType,
                $code,
            ));
        }

        $this->writeLog($wcId, 'dry-run', sprintf(
            'dry-run: would create coupon "%s".',
            $code,
        ));

        return true;
    }

    /**
     * @param \WC_Coupon $coupon
     */
    #[\Override]
    public function processRecord(mixed $coupon): int|false
    {
        $wcId = $coupon->get_id();

        if ($this->idMap->getFcId(Constants::ENTITY_COUPON, (string) $wcId)) {
            $this->writeLog($wcId, 'skipped', 'Already migrated.');
            return false;
        }

        // FIX C9: when mapping existing FC coupon, store with created_by_migration=false.
        $code = strtoupper($coupon->get_code());
        $existing = Coupon::query()->where('code', $code)->first();
        if ($existing) {
            $this->idMap->store(
                Constants::ENTITY_COUPON,
                (string) $wcId,
                $existing->id,
                $this->migrationId,
                false,
            );
            $this->writeLog($wcId, 'skipped', sprintf('Coupon code "%s" already exists in FluentCart.', $code));
            return false;
        }

        $mapped = $this->couponMapper->map($coupon);

        $fcCoupon = Coupon::query()->create($mapped);
        $this->idMap->store(
            Constants::ENTITY_COUPON,
            (string) $wcId,
            $fcCoupon->id,
            $this->migrationId,
            true,
        );

        $this->writeLog($wcId, 'success', sprintf(
            'Migrated coupon "%s" (FC ID: %d) - Type: %s.',
            $code,
            $fcCoupon->id,
            $mapped['type'],
        ));

        return $fcCoupon->id;
    }
}

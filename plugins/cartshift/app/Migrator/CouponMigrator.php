<?php

declare(strict_types=1);

namespace CartShift\Migrator;

defined('ABSPATH') || exit;

use CartShift\Domain\Mapping\CouponMapper;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\Constants;
use CartShift\Support\Enums\MigrationErrorCode;
use FluentCart\App\Models\Coupon;

final class CouponMigrator extends AbstractMigrator
{
    /**
     * fct_coupons.code is VARCHAR(50) NOT NULL UNIQUE.
     *
     * @see fluent-cart/database/Migrations/CouponsMigrator.php
     */
    private const int MAX_CODE_LENGTH = 50;

    private readonly CouponMapper $couponMapper;

    /** @var int|null Highest coupon ID covered by the ID page fetchBatch() last read. */
    private ?int $pageEndCursor = null;

    /**
     * Codes seen during a dry run, so WC-internal collisions get reported
     * before anyone starts a real migration. Keyed by normalised code.
     *
     * @var array<string, int>
     */
    private array $dryRunCodes = [];

    public function __construct(
        IdMapRepository $idMap,
        MigrationLogRepository $log,
        MigrationState $migrationState,
        int $batchSize = Constants::DEFAULT_BATCH_SIZE,
    ) {
        parent::__construct($idMap, $log, $migrationState, $batchSize);

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
        // No scope predicate here, and none is missing: this counts through
        // wp_count_posts() rather than a query of our own, and couponPredicate()
        // is empty under every mode anyway. See fetchBatch(), where the rule is
        // stated in code.
        $counts = wp_count_posts('shop_coupon');

        return (int) $counts->publish + (int) $counts->draft + (int) $counts->private;
    }

    /**
     * Keyset pagination over coupon post IDs.
     *
     * WP_Query has no `ID > x` clause — post__in is a set, not a range — so the
     * ID page is a direct indexed query against wp_posts. The status set is the
     * same publish/draft/private trio countTotal() adds up.
     */
    #[\Override]
    public function fetchBatch(string|int|null $cursor, int $limit): array
    {
        global $wpdb;

        $after = max(0, (int) $cursor);

        // Always empty. Coupons travel whole: one is cheap to migrate, and the
        // gap policy already disables a coupon whose restrictions did not
        // survive rather than letting it discount the shop. Expressed as a call
        // rather than a comment so a future scope mode cannot forget it exists.
        $selection = $this->scopeResolver()->couponPredicate();

        // Loops only when a whole page of coupon IDs fails to instantiate.
        while (true) {
            $couponIds = $wpdb->get_col($wpdb->prepare(
                "SELECT ID
                 FROM {$wpdb->posts}
                 WHERE post_type = 'shop_coupon'
                   AND post_status IN ('publish', 'draft', 'private')
                   AND ID > %d"
                . $selection->andSql()
                . " ORDER BY ID ASC
                 LIMIT %d",
                ...[$after, ...$selection->values(), $limit],
            ));

            if ($couponIds === []) {
                return [];
            }

            $after = (int) end($couponIds);
            $this->pageEndCursor = $after;

            $coupons = [];
            foreach ($couponIds as $couponId) {
                $coupon = new \WC_Coupon((int) $couponId);
                if ($coupon->get_id()) {
                    $coupons[] = $coupon;
                }
            }

            if ($coupons !== []) {
                return $coupons;
            }
        }
    }

    /**
     * Hydrate exactly these coupon IDs, for a retry run.
     *
     * One WC_Coupon per ID, with the same "did it actually instantiate?"
     * validity check fetchBatch() applies — a coupon that has been deleted since
     * the run that failed on it comes back with ID 0 and is dropped.
     *
     * The page cursor is deliberately left alone: a retry paginates through an
     * ID list, not through wp_posts.
     *
     * @param array<int, string|int> $wcIds
     *
     * @return list<\WC_Coupon>
     */
    #[\Override]
    public function fetchByIds(array $wcIds): array
    {
        $coupons = [];

        foreach (self::normalizeIntIds($wcIds) as $couponId) {
            $coupon = new \WC_Coupon($couponId);

            if ($coupon->get_id()) {
                $coupons[] = $coupon;
            }
        }

        return $coupons;
    }

    /**
     * The cursor is the end of the ID page, so a coupon that refuses to
     * instantiate is stepped over rather than retried for ever.
     */
    #[\Override]
    public function cursorFor(mixed $record): string|int
    {
        return $this->pageEndCursor ?? parent::cursorFor($record);
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
            $this->writeLog($wcId, 'dry-run', 'dry-run: already migrated, would skip.', MigrationErrorCode::AlreadyMigrated);
            return false;
        }

        $code = self::normalizeCode((string) ($this->couponMapper->map($coupon)['code'] ?? ''));

        // Everything the mapper flagged, flushed here so the dry run reports it
        // in the same order and under the same conditions the real run does.
        // An unrecognised discount type is the exception: the block further down
        // already says that in the dry run's own words. The rest — chiefly a
        // coupon whose restrictions collapsed, which is about to arrive
        // disabled — would otherwise only be discovered after the real run had
        // written the row.
        foreach ($this->couponMapper->getCodedWarnings() as $warning) {
            if ($warning['code'] === MigrationErrorCode::UnknownCouponType) {
                continue;
            }

            $this->writeLog($wcId, 'dry-run', 'dry-run: ' . $warning['message'], $warning['code']);
        }

        if ($code === '') {
            $this->writeLog($wcId, 'dry-run', 'dry-run: coupon code is empty, would fail.', MigrationErrorCode::CouponCodeMissing);
            return false;
        }

        if (self::codeLength($code) > self::MAX_CODE_LENGTH) {
            $this->writeLog($wcId, 'dry-run', sprintf(
                'dry-run: coupon code "%s" is %d characters, over the %d character FluentCart limit, would skip.',
                $code,
                self::codeLength($code),
                self::MAX_CODE_LENGTH,
            ), MigrationErrorCode::CouponCodeTooLong);
            return false;
        }

        $collationKey = self::collationKey($code);

        if (isset($this->dryRunCodes[$collationKey])) {
            $this->writeLog($wcId, 'dry-run', sprintf(
                'dry-run: coupon code "%s" collides with WC coupon #%d (FluentCart coupon codes are unique and case-insensitive), would skip.',
                $code,
                $this->dryRunCodes[$collationKey],
            ), MigrationErrorCode::CouponCodeCollision);
            return false;
        }

        if (Coupon::query()->where('code', $code)->first()) {
            $this->writeLog($wcId, 'dry-run', sprintf(
                'dry-run: coupon code "%s" already exists in FluentCart, would skip.',
                $code,
            ), MigrationErrorCode::AlreadyExistsInFluentCart);
            return false;
        }

        $this->dryRunCodes[$collationKey] = $wcId;

        $wcType = $coupon->get_discount_type();
        $validTypes = ['percent', 'fixed_cart', 'fixed_product', 'recurring_fee', 'recurring_percent', 'sign_up_fee', 'sign_up_fee_percent'];

        if (!in_array($wcType, $validTypes, true)) {
            $this->writeLog($wcId, 'dry-run', sprintf(
                'dry-run: unknown discount type "%s" for coupon "%s", would default to percent.',
                $wcType,
                $code,
            ), MigrationErrorCode::UnknownCouponType);
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
            $this->writeLog($wcId, 'skipped', 'Already migrated.', MigrationErrorCode::AlreadyMigrated);
            return false;
        }

        $mapped = $this->couponMapper->map($coupon);

        // Flush mapper warnings to the log. The dry run reports an unrecognised
        // discount type but the real run used to swallow it, so the one coupon
        // that silently became a fixed 0 discount was the one nobody was told
        // about.
        foreach ($this->couponMapper->getCodedWarnings() as $warning) {
            $this->writeLog($wcId, 'warning', $warning['message'], $warning['code']);
        }

        // Check the code the mapper actually produced, not a locally recomputed
        // one — 'cartshift/mapper/coupon' filters are allowed to rewrite it.
        $code = self::normalizeCode((string) ($mapped['code'] ?? ''));
        $mapped['code'] = $code;

        if ($code === '') {
            $this->writeLog($wcId, 'skipped', 'Coupon has no code.', MigrationErrorCode::CouponCodeMissing);
            return false;
        }

        if (self::codeLength($code) > self::MAX_CODE_LENGTH) {
            // Truncating would silently hand customers a code that does not
            // work, and could collide with another coupon on top of that.
            $this->writeLog($wcId, 'skipped', sprintf(
                'Coupon code "%s" is %d characters; FluentCart allows %d. Skipping rather than truncating.',
                $code,
                self::codeLength($code),
                self::MAX_CODE_LENGTH,
            ), MigrationErrorCode::CouponCodeTooLong);
            return false;
        }

        // FIX C9: when mapping existing FC coupon, store with created_by_migration=false.
        //
        // fct_coupons.code is UNIQUE, and MySQL's default collation makes it
        // case-insensitive, so 'save10' and 'SAVE10' are the same row. Detect
        // that here and skip, rather than letting the INSERT throw and land in
        // the log as a hard error. Skipping beats de-duplicating the way SKUs
        // are de-duplicated: a coupon code is customer-facing, and 'SAVE10-WC42'
        // is a coupon nobody can ever redeem.
        $existing = Coupon::query()->where('code', $code)->first();
        if ($existing) {
            $this->idMap->store(
                Constants::ENTITY_COUPON,
                (string) $wcId,
                $existing->id,
                $this->migrationId(),
                false,
            );
            $this->writeLog($wcId, 'skipped', sprintf(
                'Coupon code "%s" already exists in FluentCart (FC ID: %d). Mapped to the existing coupon.',
                $code,
                $existing->id,
            ), MigrationErrorCode::AlreadyExistsInFluentCart);
            return false;
        }

        try {
            $fcCoupon = Coupon::query()->create($mapped);
        } catch (\Throwable $e) {
            // Last line of defence: a binary collation, or a coupon created
            // between the lookup and the insert, can still trip the UNIQUE key.
            // That is a skip, not a migration failure.
            if (!self::isDuplicateKeyError($e)) {
                throw $e;
            }

            $this->writeLog($wcId, 'skipped', sprintf(
                'Coupon code "%s" collides with an existing FluentCart coupon code. Skipped.',
                $code,
            ), MigrationErrorCode::CouponCodeCollision);
            return false;
        }

        $this->idMap->store(
            Constants::ENTITY_COUPON,
            (string) $wcId,
            $fcCoupon->id,
            $this->migrationId(),
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

    /**
     * Trim a mapped coupon code and collapse internal whitespace runs.
     * Case is left to the mapper — FluentCart's UNIQUE index is what decides
     * whether two codes are "the same" anyway.
     */
    private static function normalizeCode(string $code): string
    {
        return trim(preg_replace('/\s+/u', ' ', $code) ?? $code);
    }

    /**
     * Key a coupon code the way MySQL's default *_ci collation would, so
     * 'save10' and 'SAVE10' are recognised as the same code.
     */
    private static function collationKey(string $code): string
    {
        return function_exists('mb_strtoupper') ? mb_strtoupper($code) : strtoupper($code);
    }

    /**
     * Character length of a coupon code, multibyte-aware where possible.
     */
    private static function codeLength(string $code): int
    {
        return function_exists('mb_strlen') ? mb_strlen($code) : strlen($code);
    }

    /**
     * Does this exception look like a UNIQUE constraint violation?
     */
    private static function isDuplicateKeyError(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'duplicate entry')
            || str_contains($message, 'unique constraint')
            || str_contains($message, 'integrity constraint violation')
            || $e->getCode() === '23000'
            || $e->getCode() === 1062;
    }
}

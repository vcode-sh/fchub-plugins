<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Migrator\Entity;

use CartShift\Migrator\CouponMigrator;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\Enums\MigrationErrorCode;
use CartShift\Tests\Unit\PluginTestCase;

require_once dirname(__DIR__, 3) . '/stubs/EntityMigratorStubs.php';
require_once dirname(__DIR__, 3) . '/stubs/MapperStubs.php';

/**
 * A coupon whose restrictions all fail to map is the one case where migration
 * can hand money away: FluentCart reads an empty restriction list as "applies to
 * everything". CouponMapper disables such a coupon — this is about the migrator
 * telling somebody, on the run that writes the row and on the run that only
 * pretends to.
 *
 * The coupons here carry no code, which stops each run before it reaches
 * FluentCart's models (there are none in a unit test). Both runs flush the
 * mapper's warnings before any skip decision, which is exactly the point: a
 * warning that only survives on the happy path is a warning nobody gets.
 */
final class CouponRestrictionWideningTest extends PluginTestCase
{
    private IdMapRepository $idMap;
    private MigrationLogRepository $log;
    private MigrationState $state;

    protected function setUp(): void
    {
        parent::setUp();

        $this->idMap = new IdMapRepository();
        $this->log   = new MigrationLogRepository();
        $this->state = new MigrationState();

        // Every ID map lookup misses: the stub $wpdb otherwise answers get_var()
        // with 0, which IdMapRepository reads as a perfectly good FC ID.
        $GLOBALS['_cartshift_test_get_var_callback'] = static fn (string $query): ?string => null;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_cartshift_test_get_var_callback']);

        parent::tearDown();
    }

    public function testTheRealRunLogsTheDisabledCouponWithItsCode(): void
    {
        $migrator = new CouponMigrator($this->idMap, $this->log, $this->state);

        $migrator->processRecord($this->couponRestrictedTo(61, [100, 200, 300]));

        $this->assertLogged(MigrationErrorCode::CouponDisabledMissingRestrictions);
    }

    public function testTheDryRunLogsTheDisabledCouponWithItsCode(): void
    {
        $migrator = new CouponMigrator($this->idMap, $this->log, $this->state);

        $migrator->validateRecord($this->couponRestrictedTo(62, [100, 200, 300]));

        $this->assertLogged(MigrationErrorCode::CouponDisabledMissingRestrictions);
    }

    public function testBothRunsLogNarrowedRestrictionsWithoutDisablingAnything(): void
    {
        $coupon = $this->couponRestrictedTo(63, []);
        $this->setProperty($coupon, 'excluded_product_ids', [900]);

        $migrator = new CouponMigrator($this->idMap, $this->log, $this->state);
        $migrator->processRecord($coupon);
        $migrator->validateRecord($coupon);

        $this->assertSame(2, $this->countLogged(MigrationErrorCode::CouponRestrictionsNarrowed));
        $this->assertSame(0, $this->countLogged(MigrationErrorCode::CouponDisabledMissingRestrictions));
    }

    /**
     * The dry run has always described an unrecognised discount type in its own
     * words, further down validateRecord(). The flush deliberately steps over
     * that one code so the same coupon is not reported twice in one dry run —
     * while the real run, which has no such block, still reports it once.
     */
    public function testTheFlushLeavesTheUnknownDiscountTypeToTheDryRunsOwnReport(): void
    {
        $coupon = $this->couponRestrictedTo(64, []);
        $this->setProperty($coupon, 'discount_type', 'some_third_party_type');

        $migrator = new CouponMigrator($this->idMap, $this->log, $this->state);
        $migrator->validateRecord($coupon);

        $this->assertSame(
            0,
            $this->countLogged(MigrationErrorCode::UnknownCouponType),
            'The flush repeated a warning the dry run reports in its own words.',
        );

        $migrator->processRecord($coupon);

        $this->assertSame(
            1,
            $this->countLogged(MigrationErrorCode::UnknownCouponType),
            'The real run has no report of its own, so the flush is the only one there is.',
        );
    }

    public function testACouponWithNoRestrictionsRaisesNeitherCode(): void
    {
        $migrator = new CouponMigrator($this->idMap, $this->log, $this->state);

        $migrator->processRecord($this->couponRestrictedTo(65, []));
        $migrator->validateRecord($this->couponRestrictedTo(66, []));

        $this->assertSame(0, $this->countLogged(MigrationErrorCode::CouponDisabledMissingRestrictions));
        $this->assertSame(0, $this->countLogged(MigrationErrorCode::CouponRestrictionsNarrowed));
    }

    /**
     * @param list<int> $includedProductIds
     */
    private function couponRestrictedTo(int $id, array $includedProductIds): \WC_Coupon
    {
        $coupon = new \WC_Coupon($id);

        $this->setProperty($coupon, 'code', '');
        $this->setProperty($coupon, 'discount_type', 'percent');
        $this->setProperty($coupon, 'amount', 20.0);
        $this->setProperty($coupon, 'product_ids', $includedProductIds);
        $this->setProperty($coupon, 'excluded_product_ids', []);

        return $coupon;
    }

    private function setProperty(\WC_Coupon $coupon, string $property, mixed $value): void
    {
        (new \ReflectionProperty(\WC_Coupon::class, $property))->setValue($coupon, $value);
    }

    private function assertLogged(MigrationErrorCode $code): void
    {
        $this->assertGreaterThan(
            0,
            $this->countLogged($code),
            sprintf('Expected a log row coded "%s"; none was written.', $code->value),
        );
    }

    private function countLogged(MigrationErrorCode $code): int
    {
        $count = 0;

        foreach ($GLOBALS['_cartshift_test_queries'] ?? [] as $query) {
            if (($query[0] ?? '') !== 'insert') {
                continue;
            }

            $row = $query[2] ?? [];

            if (($row[MigrationLogRepository::CODE_COLUMN] ?? null) === $code->value) {
                $count++;
            }
        }

        return $count;
    }
}

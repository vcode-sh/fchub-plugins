<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Mapping;

use CartShift\Domain\Mapping\CouponMapper;
use CartShift\Storage\IdMapRepository;
use CartShift\Support\Constants;
use CartShift\Support\Enums\MigrationErrorCode;
use CartShift\Tests\Unit\PluginTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/../../../stubs/MapperStubs.php';

final class CouponMapperTest extends PluginTestCase
{
    private CouponMapper $mapper;
    private IdMapRepository $idMap;

    protected function setUp(): void
    {
        parent::setUp();

        // Default: getFcId returns null (no mapping found)
        $GLOBALS['_cartshift_test_get_var_return'] = null;

        $this->idMap = new IdMapRepository();
        $this->mapper = new CouponMapper($this->idMap, 'USD');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_cartshift_test_get_var_callback']);
        unset($GLOBALS['_cartshift_test_get_var_return']);
        unset($GLOBALS['_cartshift_test_wc_products']);
        parent::tearDown();
    }

    public function testConditionsUseMinPurchaseAmountKey(): void
    {
        // M10: Must use 'min_purchase_amount', NOT 'minimum_amount'
        $coupon = $this->createCoupon([
            'code' => 'SAVE10',
            'discount_type' => 'percent',
            'amount' => 10.0,
            'minimum_amount' => 50.0,
        ]);

        $result = $this->mapper->map($coupon);

        $this->assertNotNull($result['conditions']);
        $this->assertArrayHasKey('min_purchase_amount', $result['conditions']);
        $this->assertArrayNotHasKey('minimum_amount', $result['conditions']);
        $this->assertSame(5000, $result['conditions']['min_purchase_amount']);
    }

    public function testConditionsUseMaxDiscountAmountKey(): void
    {
        // M10: Must use 'max_discount_amount', NOT 'maximum_amount'
        $coupon = $this->createCoupon([
            'code' => 'SAVE10',
            'discount_type' => 'percent',
            'amount' => 10.0,
            'maximum_amount' => 25.0,
        ]);

        $result = $this->mapper->map($coupon);

        $this->assertNotNull($result['conditions']);
        $this->assertArrayHasKey('max_discount_amount', $result['conditions']);
        $this->assertArrayNotHasKey('maximum_amount', $result['conditions']);
        $this->assertSame(2500, $result['conditions']['max_discount_amount']);
    }

    public function testConditionsIncludedProductsMappedToFcIds(): void
    {
        // Product restrictions resolve through ENTITY_VARIATION — that is the ID
        // space FluentCart's DiscountService actually compares cart items
        // against (see CouponMapper::mapProductIdsToVariationIds()).
        $GLOBALS['_cartshift_test_get_var_callback'] = function (string $query): ?string {
            if (str_contains($query, 'variation') && str_contains($query, "'100'")) {
                return '500';
            }
            if (str_contains($query, 'variation') && str_contains($query, "'200'")) {
                return '600';
            }
            return null;
        };

        $coupon = $this->createCoupon([
            'code' => 'PRODUCTONLY',
            'discount_type' => 'percent',
            'amount' => 15.0,
            'product_ids' => [100, 200, 999], // 999 has no mapping
        ]);

        $result = $this->mapper->map($coupon);

        $this->assertNotNull($result['conditions']);
        $this->assertArrayHasKey('included_products', $result['conditions']);
        $this->assertSame([500, 600], $result['conditions']['included_products']);
    }

    public function testConditionsIsArrayNotJsonString(): void
    {
        // C1: conditions must be an array, never a JSON string.
        $coupon = $this->createCoupon([
            'code' => 'ARRAYCHK',
            'discount_type' => 'fixed_cart',
            'amount' => 5.0,
            'minimum_amount' => 20.0,
        ]);

        $result = $this->mapper->map($coupon);

        $this->assertNotNull($result['conditions']);
        $this->assertIsArray($result['conditions']);
        $this->assertIsNotString($result['conditions']);
    }

    public function testEmailRestrictionsIncludedInConditions(): void
    {
        $coupon = $this->createCoupon([
            'code' => 'VIPONLY',
            'discount_type' => 'percent',
            'amount' => 20.0,
            'email_restrictions' => ['vip@example.com', 'admin@example.com'],
        ]);

        $result = $this->mapper->map($coupon);

        $this->assertNotNull($result['conditions']);
        $this->assertArrayHasKey('email_restrictions', $result['conditions']);
        $this->assertSame('vip@example.com,admin@example.com', $result['conditions']['email_restrictions']);
    }

    /**
     * The headline correctness guard.
     *
     * FluentCart's DiscountService compares included_products/excluded_products
     * against a cart item's object_id, which is the FluentCart *variation* ID
     * (fluent-cart CartHelper.php:54, :123 — object_id = $variation->id), not
     * the product post ID. Resolving through Constants::ENTITY_PRODUCT — as this
     * mapper used to — produces IDs that DiscountService never compares against,
     * so the restriction silently stops matching anything after migration.
     */
    public function testProductRestrictionsResolveToVariationIdsFluentCartActuallyCompares(): void
    {
        $this->idMap->store(Constants::ENTITY_PRODUCT, '101', 5001, 'm', true);
        $this->idMap->store(Constants::ENTITY_VARIATION, '101', 7001, 'm', true);

        $result = $this->mapper->map($this->couponRestrictedTo([101]));

        $this->assertSame([7001], $result['conditions']['included_products']);
    }

    /**
     * A variable WC product's restriction has to expand to every one of its
     * FluentCart variations — DiscountService checks the exact variation
     * object_id in the cart, not the parent product.
     */
    public function testProductRestrictionsExpandToAllVariationsOfAVariableProduct(): void
    {
        $variableProduct = new \WC_Product();
        $ref = new \ReflectionClass($variableProduct);
        $childrenProp = $ref->getProperty('children');
        $childrenProp->setValue($variableProduct, [201, 202]);
        $GLOBALS['_cartshift_test_wc_products'][300] = $variableProduct;

        $this->idMap->store(Constants::ENTITY_VARIATION, '201', 9001, 'm', true);
        $this->idMap->store(Constants::ENTITY_VARIATION, '202', 9002, 'm', true);

        $result = $this->mapper->map($this->couponRestrictedTo([300]));

        $this->assertSame([9001, 9002], $result['conditions']['included_products']);
    }

    public function testEmptyProductIdsHandledGracefully(): void
    {
        // Empty product_ids array should not produce included_products key.
        $coupon = $this->createCoupon([
            'code' => 'NOPRODS',
            'discount_type' => 'percent',
            'amount' => 10.0,
            'product_ids' => [],
        ]);

        $result = $this->mapper->map($coupon);

        if ($result['conditions'] !== null) {
            $this->assertArrayNotHasKey('included_products', $result['conditions']);
        } else {
            // No conditions at all is also fine.
            $this->assertNull($result['conditions']);
        }
    }

    // ──────────────────────────────────────────────
    // Discount type mapping
    // ──────────────────────────────────────────────

    /**
     * One case per WooCommerce discount type this migrator knows about.
     *
     * FluentCart only compares against the literals 'fixed' and 'percent'
     * (app/Services/Coupon/DiscountService.php:252, :389).
     */
    #[DataProvider('couponTypeProvider')]
    public function testCouponTypeMapping(string $wcType, string $expectedFcType): void
    {
        $coupon = $this->createCoupon([
            'code' => 'TYPECHECK',
            'discount_type' => $wcType,
            'amount' => 50.0,
        ]);

        $result = $this->mapper->map($coupon);

        $this->assertSame($expectedFcType, $result['type'], "WC type '{$wcType}' mapped wrongly");
        $this->assertSame([], $this->mapper->getWarnings(), "WC type '{$wcType}' should be recognised");
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function couponTypeProvider(): array
    {
        return [
            // WooCommerce core.
            'percent'             => ['percent', 'percent'],
            'fixed_cart'          => ['fixed_cart', 'fixed'],
            'fixed_product'       => ['fixed_product', 'fixed'],
            // WooCommerce Subscriptions. The `_percent` suffix is the whole signal:
            // present means percentage, absent means a fixed currency amount.
            'recurring_percent'   => ['recurring_percent', 'percent'],
            'recurring_fee'       => ['recurring_fee', 'fixed'],
            'sign_up_fee_percent' => ['sign_up_fee_percent', 'percent'],
            'sign_up_fee'         => ['sign_up_fee', 'fixed'],
        ];
    }

    public function testRecurringFeeIsFixedAmountNotPercentage(): void
    {
        // Regression guard: 'recurring_fee' used to map to 'percent', turning a
        // "50 off every renewal" coupon into "50% off every renewal".
        $coupon = $this->createCoupon([
            'code' => 'RECURRING50',
            'discount_type' => 'recurring_fee',
            'amount' => 50.0,
        ]);

        $result = $this->mapper->map($coupon);

        $this->assertSame('fixed', $result['type']);
        // Fixed amounts are stored in minor units — FluentCart compares them directly
        // against a cart subtotal in cents (DiscountService.php:389).
        $this->assertSame(5000, $result['amount']);
    }

    public function testSignUpFeeIsFixedAmountNotPercentage(): void
    {
        $coupon = $this->createCoupon([
            'code' => 'SIGNUP25',
            'discount_type' => 'sign_up_fee',
            'amount' => 25.0,
        ]);

        $result = $this->mapper->map($coupon);

        $this->assertSame('fixed', $result['type']);
        $this->assertSame(2500, $result['amount']);
    }

    public function testPercentTypesKeepPlainPercentageAmount(): void
    {
        foreach (['percent', 'recurring_percent', 'sign_up_fee_percent'] as $wcType) {
            $coupon = $this->createCoupon([
                'code' => 'PCT',
                'discount_type' => $wcType,
                'amount' => 15.0,
            ]);

            $result = $this->mapper->map($coupon);

            $this->assertSame('percent', $result['type'], $wcType);
            // Percentages are NOT converted to minor units (DiscountService.php:396).
            $this->assertSame(15.0, $result['amount'], $wcType);
        }
    }

    public function testUnknownTypeFallsBackToInertFixedZero(): void
    {
        // An unrecognised third-party type must never become a percentage: a wrong
        // percentage gives money away, a fixed 0 is a no-op the shop owner can correct.
        $coupon = $this->createCoupon([
            'code' => 'MYSTERY',
            'discount_type' => 'some_third_party_type',
            'amount' => 50.0,
        ]);

        $result = $this->mapper->map($coupon);

        $this->assertSame('fixed', $result['type']);
        $this->assertSame(0, $result['amount']);
        $this->assertNotSame('percent', $result['type']);
    }

    public function testUnknownTypeRecordsWarning(): void
    {
        $coupon = $this->createCoupon([
            'code' => 'MYSTERY',
            'discount_type' => 'some_third_party_type',
            'amount' => 50.0,
        ]);

        $this->mapper->map($coupon);

        $warnings = $this->mapper->getWarnings();
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('some_third_party_type', $warnings[0]);
        $this->assertStringContainsString('MYSTERY', $warnings[0]);
    }

    public function testUnknownTypeStillMigratesTheRestOfTheCoupon(): void
    {
        $coupon = $this->createCoupon([
            'code' => 'MYSTERY',
            'discount_type' => 'some_third_party_type',
            'amount' => 50.0,
            'usage_count' => 7,
            'minimum_amount' => 20.0,
        ]);

        $result = $this->mapper->map($coupon);

        $this->assertSame('MYSTERY', $result['code']);
        $this->assertSame(7, $result['use_count']);
        $this->assertSame(2000, $result['conditions']['min_purchase_amount']);
    }

    public function testWarningsAreResetBetweenMapCalls(): void
    {
        $this->mapper->map($this->createCoupon([
            'code' => 'MYSTERY',
            'discount_type' => 'some_third_party_type',
        ]));
        $this->assertCount(1, $this->mapper->getWarnings());

        $this->mapper->map($this->createCoupon([
            'code' => 'NORMAL',
            'discount_type' => 'percent',
            'amount' => 10.0,
        ]));
        $this->assertSame([], $this->mapper->getWarnings());
    }

    public function testEmptyDiscountTypeIsTreatedAsUnknown(): void
    {
        $coupon = $this->createCoupon([
            'code' => 'BLANK',
            'discount_type' => '',
            'amount' => 30.0,
        ]);

        $result = $this->mapper->map($coupon);

        $this->assertSame('fixed', $result['type']);
        $this->assertSame(0, $result['amount']);
        $this->assertCount(1, $this->mapper->getWarnings());
    }

    // ──────────────────────────────────────────────
    // Dates
    // ──────────────────────────────────────────────

    public function testCouponDatesAreWrittenInUtc(): void
    {
        // Site at UTC+2. FluentCart validates start/end with strtotime() against time(),
        // and WordPress pins PHP's default timezone to UTC — so these must be UTC.
        $coupon = $this->createCoupon([
            'code' => 'DATED',
            'discount_type' => 'percent',
            'amount' => 10.0,
            'date_created' => cartshift_test_wc_date('2024-01-15 10:30:00', 2),
            'date_expires' => cartshift_test_wc_date('2099-06-30 23:00:00', 2),
        ]);

        $result = $this->mapper->map($coupon);

        $this->assertSame('2024-01-15 10:30:00', $result['start_date']);
        $this->assertSame('2099-06-30 23:00:00', $result['end_date']);
    }

    public function testCouponDatesAreNullWhenAbsent(): void
    {
        $coupon = $this->createCoupon([
            'code' => 'UNDATED',
            'discount_type' => 'percent',
            'amount' => 10.0,
        ]);

        $result = $this->mapper->map($coupon);

        $this->assertNull($result['start_date']);
        $this->assertNull($result['end_date']);
    }

    public function testPastExpiryMarksCouponExpired(): void
    {
        $coupon = $this->createCoupon([
            'code' => 'GONE',
            'discount_type' => 'percent',
            'amount' => 10.0,
            'date_expires' => cartshift_test_wc_date('2001-01-01 00:00:00', 2),
        ]);

        $result = $this->mapper->map($coupon);

        $this->assertSame('expired', $result['status']);
        $this->assertSame('2001-01-01 00:00:00', $result['end_date']);
    }

    public function testMapAppliesFilter(): void
    {
        $filterCalled = false;
        $GLOBALS['_cartshift_test_filters']['cartshift/mapper/coupon'][] = static function (
            array $mapped,
            \WC_Coupon $coupon,
        ) use (&$filterCalled): array {
            $filterCalled = true;
            $mapped['code'] = 'MODIFIED';
            return $mapped;
        };

        $coupon = $this->createCoupon([
            'code' => 'ORIGINAL',
            'discount_type' => 'percent',
            'amount' => 10.0,
        ]);

        $result = $this->mapper->map($coupon);

        $this->assertTrue($filterCalled, 'Filter cartshift/mapper/coupon was not called');
        $this->assertSame('MODIFIED', $result['code']);
    }

    // ──────────────────────────────────────────────
    // Restrictions that do not survive the ID map
    // ──────────────────────────────────────────────

    /**
     * The headline regression guard.
     *
     * FluentCart reads an empty `included_products` as "no restriction"
     * (DiscountService::filterApplicableItems(), `if ($includedProducts && ...)`),
     * so "20% off these three clearance items" whose three IDs all fail to
     * resolve becomes "20% off the entire shop" the moment the row is written.
     * Grouped and external products are skipped by ProductMapper on every
     * ordinary full migration, so this is not a partial-run curiosity.
     */
    public function testACouponWhoseIncludedProductsAllVanishIsNotRedeemable(): void
    {
        $this->noMappings();

        $coupon = $this->createCoupon([
            'code' => 'CLEARANCE20',
            'discount_type' => 'percent',
            'amount' => 20.0,
            'product_ids' => [100, 200, 300],
        ]);

        $result = $this->mapper->map($coupon);

        $this->assertNotSame('active', $result['status'], 'A shop-wide discount was left redeemable.');
        $this->assertSame('disabled', $result['status']);
    }

    public function testTheDisabledCouponWarningCarriesItsCode(): void
    {
        $this->noMappings();

        $coupon = $this->createCoupon([
            'code' => 'CLEARANCE20',
            'discount_type' => 'percent',
            'amount' => 20.0,
            'product_ids' => [100, 200, 300],
        ]);

        $this->mapper->map($coupon);

        $coded = $this->mapper->getCodedWarnings();

        $this->assertCount(1, $coded);
        $this->assertSame(MigrationErrorCode::CouponDisabledMissingRestrictions, $coded[0]['code']);
        $this->assertStringContainsString('CLEARANCE20', $coded[0]['message']);
        $this->assertStringContainsString('included_products', $coded[0]['message']);
        $this->assertStringContainsString('3', $coded[0]['message']);
    }

    public function testACouponWhoseIncludedCategoriesAllVanishIsDisabled(): void
    {
        $this->noMappings();

        $coupon = $this->createCoupon([
            'code' => 'CATONLY',
            'discount_type' => 'percent',
            'amount' => 10.0,
            'product_categories' => [77],
        ]);

        $result = $this->mapper->map($coupon);

        $this->assertSame('disabled', $result['status']);
    }

    /**
     * An excluded category is the one exclusion that can widen. The term can be
     * missing from FluentCart while its products are present and for sale —
     * migrateCategories() skips 'uncategorized' outright, and logs
     * TermCreationFailed for anything wp_insert_term() refuses — so the
     * exclusion is lost on goods a customer can actually put in a basket.
     */
    public function testACouponWhoseExcludedCategoriesAllVanishIsDisabled(): void
    {
        $this->noMappings();

        $coupon = $this->createCoupon([
            'code' => 'NOTSALEITEMS',
            'discount_type' => 'percent',
            'amount' => 30.0,
            'excluded_product_categories' => [88, 99],
        ]);

        $result = $this->mapper->map($coupon);

        $this->assertSame('disabled', $result['status']);
    }

    /**
     * Some of the IDs resolving makes the coupon narrower than it was, which is
     * wrong but costs the shop nothing. It keeps working for what is left.
     */
    public function testPartiallyResolvedRestrictionsStayActiveAndNarrower(): void
    {
        $GLOBALS['_cartshift_test_get_var_callback'] = static function (string $query): ?string {
            return str_contains($query, "'variation'") && str_contains($query, "'100'") ? '500' : null;
        };

        $coupon = $this->createCoupon([
            'code' => 'PARTIAL',
            'discount_type' => 'percent',
            'amount' => 20.0,
            'product_ids' => [100, 200, 300],
        ]);

        $result = $this->mapper->map($coupon);

        $this->assertSame('active', $result['status']);
        $this->assertSame([500], $result['conditions']['included_products']);

        $coded = $this->mapper->getCodedWarnings();
        $this->assertCount(1, $coded);
        $this->assertSame(MigrationErrorCode::CouponRestrictionsNarrowed, $coded[0]['code']);
    }

    /**
     * An excluded product that did not migrate is not in FluentCart to be
     * discounted, so losing the exclusion changes nothing anyone can buy.
     */
    public function testLostExcludedProductsKeepTheCouponActive(): void
    {
        $this->noMappings();

        $coupon = $this->createCoupon([
            'code' => 'ALLBUTONE',
            'discount_type' => 'percent',
            'amount' => 15.0,
            'excluded_product_ids' => [100, 200],
        ]);

        $result = $this->mapper->map($coupon);

        $this->assertSame('active', $result['status']);
        $this->assertSame([], $result['conditions']['excluded_products']);

        $coded = $this->mapper->getCodedWarnings();
        $this->assertCount(1, $coded);
        $this->assertSame(MigrationErrorCode::CouponRestrictionsNarrowed, $coded[0]['code']);
    }

    public function testACouponWithNoRestrictionsIsUntouched(): void
    {
        $this->noMappings();

        $coupon = $this->createCoupon([
            'code' => 'SITEWIDE',
            'discount_type' => 'percent',
            'amount' => 10.0,
            'description' => 'Everything, on purpose.',
        ]);

        $result = $this->mapper->map($coupon);

        $this->assertSame('active', $result['status']);
        $this->assertSame('Everything, on purpose.', $result['notes']);
        $this->assertSame([], $this->mapper->getWarnings());
    }

    public function testFullyResolvedRestrictionsRaiseNothing(): void
    {
        $GLOBALS['_cartshift_test_get_var_callback'] = static fn (string $query): ?string => '500';

        $coupon = $this->createCoupon([
            'code' => 'RESOLVED',
            'discount_type' => 'percent',
            'amount' => 10.0,
            'product_ids' => [100],
            'description' => 'Untouched.',
        ]);

        $result = $this->mapper->map($coupon);

        $this->assertSame('active', $result['status']);
        $this->assertSame('Untouched.', $result['notes']);
        $this->assertSame([], $this->mapper->getWarnings());
    }

    /**
     * Whoever repairs the coupon needs to know what it was restricted to, and
     * after migration the WooCommerce IDs are the only record of that.
     */
    public function testOriginalRestrictionIdsArePreservedInTheNotes(): void
    {
        $GLOBALS['_cartshift_test_get_var_callback'] = static function (string $query): ?string {
            return str_contains($query, "'900'") ? '700' : null;
        };

        $coupon = $this->createCoupon([
            'code' => 'AUDIT',
            'discount_type' => 'percent',
            'amount' => 20.0,
            'product_ids' => [100, 200, 300],
            'excluded_product_ids' => [900],
            'description' => 'Clearance only.',
        ]);

        $result = $this->mapper->map($coupon);

        $this->assertStringContainsString('Clearance only.', $result['notes']);
        $this->assertStringContainsString('included_products: WooCommerce IDs [100, 200, 300]', $result['notes']);
        $this->assertStringContainsString('excluded_products: WooCommerce IDs [900] -> FluentCart IDs [700]', $result['notes']);
        $this->assertStringContainsString('disabled', $result['notes']);
    }

    public function testRestrictionAuditIsResetBetweenMapCalls(): void
    {
        $this->mapper->map($this->createCoupon([
            'code' => 'FIRST',
            'discount_type' => 'percent',
            'amount' => 10.0,
            'product_ids' => [100],
        ]));

        $result = $this->mapper->map($this->createCoupon([
            'code' => 'SECOND',
            'discount_type' => 'percent',
            'amount' => 10.0,
            'description' => 'Clean.',
        ]));

        $this->assertSame('active', $result['status']);
        $this->assertSame('Clean.', $result['notes']);
        $this->assertSame([], $this->mapper->getWarnings());
    }

    /**
     * Make every ID map lookup miss.
     *
     * The stub $wpdb answers get_var() with 0 rather than null when no callback
     * is set, and 0 is a hit as far as IdMapRepository is concerned. Tests about
     * unmapped IDs have to say so explicitly.
     */
    private function noMappings(): void
    {
        $GLOBALS['_cartshift_test_get_var_callback'] = static fn (string $query): ?string => null;
    }

    private function couponRestrictedTo(array $productIds): \WC_Coupon
    {
        return $this->createCoupon([
            'code' => 'RESTRICTED',
            'discount_type' => 'percent',
            'amount' => 10.0,
            'product_ids' => $productIds,
        ]);
    }

    private function createCoupon(array $overrides = []): \WC_Coupon
    {
        $coupon = new \WC_Coupon();
        $defaults = [
            'id' => 10,
            'code' => 'testcoupon',
            'discount_type' => 'percent',
            'amount' => 0.0,
            'usage_limit' => 0,
            'usage_limit_per_user' => 0,
            'usage_count' => 0,
            'product_ids' => [],
            'excluded_product_ids' => [],
            'product_categories' => [],
            'excluded_product_categories' => [],
            'email_restrictions' => [],
            'individual_use' => false,
            'exclude_sale_items' => false,
            'free_shipping' => false,
            'description' => '',
            'minimum_amount' => 0.0,
            'maximum_amount' => 0.0,
            'date_expires' => null,
            'date_created' => null,
            'meta' => [],
        ];

        $data = array_merge($defaults, $overrides);

        $ref = new \ReflectionClass($coupon);
        foreach ($data as $key => $value) {
            if ($ref->hasProperty($key)) {
                $prop = $ref->getProperty($key);
                $prop->setValue($coupon, $value);
            }
        }

        return $coupon;
    }
}

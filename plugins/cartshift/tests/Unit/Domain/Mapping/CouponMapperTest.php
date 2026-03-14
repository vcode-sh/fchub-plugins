<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Mapping;

use CartShift\Domain\Mapping\CouponMapper;
use CartShift\Storage\IdMapRepository;
use CartShift\Tests\Unit\PluginTestCase;

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
        // M10: WC product IDs mapped to FC IDs via IdMap.
        $GLOBALS['_cartshift_test_get_var_callback'] = function (string $query): ?string {
            if (str_contains($query, 'product') && str_contains($query, "'100'")) {
                return '500';
            }
            if (str_contains($query, 'product') && str_contains($query, "'200'")) {
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

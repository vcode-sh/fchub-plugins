<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Mapping;

use CartShift\Domain\Mapping\VariationMapper;
use CartShift\Tests\Unit\PluginTestCase;

final class VariationMapperTest extends PluginTestCase
{
    private VariationMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new VariationMapper('USD');
    }

    public function testBackordersNotifyMapsToOne(): void
    {
        // M11: 'notify' backorder status should map to 1 (enabled).
        $product = $this->createProduct(['backorders' => 'notify']);

        $result = $this->mapper->mapSimple($product);

        $this->assertSame(1, $result['backorders']);
    }

    public function testBackordersYesMapsToOne(): void
    {
        // M11: 'yes' backorder status should map to 1 (enabled).
        $product = $this->createProduct(['backorders' => 'yes']);

        $result = $this->mapper->mapSimple($product);

        $this->assertSame(1, $result['backorders']);
    }

    public function testBackordersNoMapsToZero(): void
    {
        // M11: 'no' backorder status should map to 0 (disabled).
        $product = $this->createProduct(['backorders' => 'no']);

        $result = $this->mapper->mapSimple($product);

        $this->assertSame(0, $result['backorders']);
    }

    public function testOtherInfoIsArrayNotJsonString(): void
    {
        // C1: other_info must be an array (or null), never a JSON string.
        $product = $this->createProduct([
            'price' => '19.99',
            'regular_price' => '19.99',
        ]);

        $result = $this->mapper->mapSimple($product);

        // For a simple non-subscription product, other_info should be null.
        $this->assertTrue(
            $result['other_info'] === null || is_array($result['other_info']),
            'other_info must be array or null, got: ' . gettype($result['other_info']),
        );

        if ($result['other_info'] !== null) {
            $this->assertIsNotString($result['other_info']);
        }
    }

    public function testWeightAndDimensionsMergedIntoOtherInfo(): void
    {
        $product = $this->createProduct([
            'price' => '29.99',
            'regular_price' => '29.99',
            'weight' => '1.5',
            'length' => '25',
            'width' => '15',
            'height' => '5',
        ]);

        $result = $this->mapper->mapSimple($product);

        // other_info should contain weight/dimension data.
        $this->assertNotNull($result['other_info']);
        $this->assertSame('1.5', $result['other_info']['weight']);
        $this->assertSame('25', $result['other_info']['length']);
        $this->assertSame('15', $result['other_info']['width']);
        $this->assertSame('5', $result['other_info']['height']);
        $this->assertArrayHasKey('weight_unit', $result['other_info']);
        $this->assertArrayHasKey('dimension_unit', $result['other_info']);
    }

    public function testShippingClassResolvedFromMap(): void
    {
        $mapper = new VariationMapper('USD', [10 => 99]);
        $product = $this->createProduct([
            'shipping_class_id' => 10,
        ]);

        $result = $mapper->mapSimple($product);

        $this->assertSame(99, $result['shipping_class']);
    }

    public function testShippingClassNullWhenNotInMap(): void
    {
        $mapper = new VariationMapper('USD', [10 => 99]);
        $product = $this->createProduct([
            'shipping_class_id' => 77,
        ]);

        $result = $mapper->mapSimple($product);

        $this->assertNull($result['shipping_class']);
    }

    public function testShippingClassNullWhenNoMap(): void
    {
        $mapper = new VariationMapper('USD', []);
        $product = $this->createProduct([
            'shipping_class_id' => 10,
        ]);

        $result = $mapper->mapSimple($product);

        $this->assertNull($result['shipping_class']);
    }

    public function testSubscriptionDataPreservedWithWeightMerge(): void
    {
        // When a product has both subscription data and weight/dimensions,
        // the merge must preserve both sets of data.
        // We can only test weight merge here since subscription detection
        // requires WC_Subscriptions_Product (not available in stubs).
        // Instead, test that mergeWeightDimensions doesn't destroy existing data.
        $product = $this->createProduct([
            'price' => '49.99',
            'regular_price' => '49.99',
            'weight' => '3.0',
            'length' => '',
            'width' => '',
            'height' => '',
        ]);

        $result = $this->mapper->mapSimple($product);

        // Weight should be present.
        $this->assertNotNull($result['other_info']);
        $this->assertSame('3.0', $result['other_info']['weight']);
        // Dimensions should NOT be present (empty strings).
        $this->assertArrayNotHasKey('length', $result['other_info']);
        $this->assertArrayNotHasKey('width', $result['other_info']);
        $this->assertArrayNotHasKey('height', $result['other_info']);
    }

    private function createProduct(array $overrides = []): \WC_Product
    {
        $product = new \WC_Product();
        $defaults = [
            'id' => 42,
            'name' => 'Test Product',
            'slug' => 'test-product',
            'type' => 'simple',
            'status' => 'publish',
            'price' => '19.99',
            'regular_price' => '19.99',
            'sale_price' => '',
            'sku' => 'TEST-001',
            'virtual' => false,
            'downloadable' => false,
            'in_stock' => true,
            'manage_stock' => false,
            'sold_individually' => false,
            'stock_quantity' => null,
            'backorders' => 'no',
        ];

        $data = array_merge($defaults, $overrides);

        $ref = new \ReflectionClass($product);
        foreach ($data as $key => $value) {
            if ($ref->hasProperty($key)) {
                $prop = $ref->getProperty($key);
                $prop->setValue($product, $value);
            }
        }

        return $product;
    }
}

<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Mapping;

use CartShift\Domain\Mapping\ProductMapper;
use CartShift\Tests\Unit\PluginTestCase;

final class ProductMapperTest extends PluginTestCase
{
    private ProductMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new ProductMapper('USD');
    }

    public function testMapReturnsNullForGroupedProduct(): void
    {
        $product = $this->createProduct(['type' => 'grouped']);

        $this->assertNull($this->mapper->map($product));
    }

    public function testMapReturnsNullForExternalProduct(): void
    {
        $product = $this->createProduct(['type' => 'external']);

        $this->assertNull($this->mapper->map($product));
    }

    public function testMapHandlesEmptyProductName(): void
    {
        $product = $this->createProduct([
            'name' => '',
            'price' => '10.00',
            'regular_price' => '10.00',
        ]);

        $result = $this->mapper->map($product);

        $this->assertNotNull($result);
        $this->assertSame('', $result['product']['post_title']);
        $this->assertArrayHasKey('detail', $result);
        $this->assertArrayHasKey('variations', $result);
    }

    public function testMapHandlesZeroPrice(): void
    {
        $product = $this->createProduct([
            'name' => 'Free Product',
            'price' => '0',
            'regular_price' => '0',
        ]);

        $result = $this->mapper->map($product);

        $this->assertNotNull($result);
        $this->assertNotEmpty($result['variations']);
        $this->assertSame(0, $result['variations'][0]['item_price']);
    }

    public function testMapHandlesZeroDecimalCurrencyJPY(): void
    {
        $mapper = new ProductMapper('JPY');
        $product = $this->createProduct([
            'name' => 'Japanese Product',
            'price' => '1000',
            'regular_price' => '1000',
        ]);

        $result = $mapper->map($product);

        $this->assertNotNull($result);
        // JPY: 1000 stays as 1000 (no multiplication by 100)
        $this->assertSame(1000, $result['variations'][0]['item_price']);
    }

    public function testDetailOtherInfoIsArrayNotJsonString(): void
    {
        $product = $this->createProduct([
            'name' => 'Test Product',
            'price' => '29.99',
            'regular_price' => '29.99',
        ]);

        $result = $this->mapper->map($product);

        $this->assertNotNull($result);
        $this->assertIsArray($result['detail']['other_info']);
        $this->assertIsNotString($result['detail']['other_info']);
        $this->assertArrayHasKey('sold_individually', $result['detail']['other_info']);
    }

    public function testWeightAndDimensionsInOtherInfo(): void
    {
        $product = $this->createProduct([
            'name' => 'Heavy Product',
            'price' => '49.99',
            'regular_price' => '49.99',
            'weight' => '2.5',
            'length' => '30',
            'width' => '20',
            'height' => '10',
        ]);

        $result = $this->mapper->map($product);

        $this->assertNotNull($result);
        $otherInfo = $result['detail']['other_info'];
        $this->assertSame('2.5', $otherInfo['weight']);
        $this->assertSame('30', $otherInfo['length']);
        $this->assertSame('20', $otherInfo['width']);
        $this->assertSame('10', $otherInfo['height']);
        $this->assertArrayHasKey('weight_unit', $otherInfo);
        $this->assertArrayHasKey('dimension_unit', $otherInfo);
    }

    public function testWeightOmittedWhenEmpty(): void
    {
        $product = $this->createProduct([
            'name' => 'Weightless Product',
            'price' => '9.99',
            'regular_price' => '9.99',
            'weight' => '',
            'length' => '',
            'width' => '',
            'height' => '',
        ]);

        $result = $this->mapper->map($product);

        $this->assertNotNull($result);
        $otherInfo = $result['detail']['other_info'];
        $this->assertArrayNotHasKey('weight', $otherInfo);
        $this->assertArrayNotHasKey('length', $otherInfo);
        $this->assertArrayNotHasKey('width', $otherInfo);
        $this->assertArrayNotHasKey('height', $otherInfo);
        $this->assertArrayNotHasKey('weight_unit', $otherInfo);
        $this->assertArrayNotHasKey('dimension_unit', $otherInfo);
    }

    public function testDimensionUnitsIncluded(): void
    {
        // When any dimension is present, units must be included.
        $GLOBALS['_cartshift_test_options']['woocommerce_weight_unit'] = 'lbs';
        $GLOBALS['_cartshift_test_options']['woocommerce_dimension_unit'] = 'in';

        $product = $this->createProduct([
            'name' => 'US Product',
            'price' => '19.99',
            'regular_price' => '19.99',
            'weight' => '1.0',
        ]);

        $result = $this->mapper->map($product);

        $this->assertNotNull($result);
        $otherInfo = $result['detail']['other_info'];
        $this->assertSame('lbs', $otherInfo['weight_unit']);
        $this->assertSame('in', $otherInfo['dimension_unit']);
    }

    public function testPrivateStatusPreserved(): void
    {
        $product = $this->createProduct([
            'status' => 'private',
            'catalog_visibility' => 'visible',
        ]);

        $result = $this->mapper->map($product);

        $this->assertNotNull($result);
        $this->assertSame('private', $result['product']['post_status']);
    }

    public function testHiddenVisibilityMapsToDraft(): void
    {
        $product = $this->createProduct([
            'status' => 'publish',
            'catalog_visibility' => 'hidden',
        ]);

        $result = $this->mapper->map($product);

        $this->assertNotNull($result);
        $this->assertSame('draft', $result['product']['post_status']);
    }

    public function testCatalogOnlyVisibilityStaysPublish(): void
    {
        $product = $this->createProduct([
            'status' => 'publish',
            'catalog_visibility' => 'catalog',
        ]);

        $result = $this->mapper->map($product);

        $this->assertNotNull($result);
        $this->assertSame('publish', $result['product']['post_status']);
    }

    public function testSearchOnlyVisibilityStaysPublish(): void
    {
        $product = $this->createProduct([
            'status' => 'publish',
            'catalog_visibility' => 'search',
        ]);

        $result = $this->mapper->map($product);

        $this->assertNotNull($result);
        $this->assertSame('publish', $result['product']['post_status']);
    }

    public function testPendingStatusMapsToDraft(): void
    {
        $product = $this->createProduct([
            'status' => 'pending',
        ]);

        $result = $this->mapper->map($product);

        $this->assertNotNull($result);
        $this->assertSame('draft', $result['product']['post_status']);
    }

    public function testMapAppliesCartshiftMapperProductFilter(): void
    {
        $filterCalled = false;
        $GLOBALS['_cartshift_test_filters']['cartshift/mapper/product'][] = static function (
            array $mapped,
            \WC_Product $product,
        ) use (&$filterCalled): array {
            $filterCalled = true;
            $mapped['product']['post_title'] = 'Filtered Title';
            return $mapped;
        };

        $product = $this->createProduct([
            'name' => 'Original Title',
            'price' => '10.00',
            'regular_price' => '10.00',
        ]);

        $result = $this->mapper->map($product);

        $this->assertTrue($filterCalled, 'Filter cartshift/mapper/product was not called');
        $this->assertSame('Filtered Title', $result['product']['post_title']);
    }

    /**
     * Create a WC_Product stub with reflection to set protected properties.
     */
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
            'description' => 'A test product',
            'short_description' => 'Short desc',
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

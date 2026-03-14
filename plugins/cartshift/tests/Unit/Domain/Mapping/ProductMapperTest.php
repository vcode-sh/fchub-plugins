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

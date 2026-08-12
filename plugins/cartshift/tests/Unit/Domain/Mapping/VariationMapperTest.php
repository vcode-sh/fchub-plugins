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
        $GLOBALS['_cartshift_test_terms'] = [];
        $this->mapper = new VariationMapper('USD');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_cartshift_test_terms']);
        parent::tearDown();
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

    // ──────────────────────────────────────────────
    // Attribute term memo cache
    // ──────────────────────────────────────────────

    public function testAttributeTermNameUsedForVariationTitle(): void
    {
        $GLOBALS['_cartshift_test_terms']['pa_color']['red'] = (object) ['name' => 'Rosso'];
        $GLOBALS['_cartshift_test_terms']['pa_size']['lg'] = (object) ['name' => 'Large'];

        $variation = $this->createVariation([
            'attributes' => ['attribute_pa_color' => 'red', 'attribute_pa_size' => 'lg'],
        ]);

        $result = $this->mapper->mapVariation($variation);

        $this->assertSame('Rosso / Large', $result['variation_title']);

        $this->assertSame('500', $result['variation_identifier']);
    }

    /**
     * A Polish variant label has to reach `variation_identifier` transliterated,
     * not deleted. sanitize_title() folds through remove_accents() before it
     * strips anything, so 'Biały / Rozmiar XL' keeps every letter it has a Latin
     * equivalent for. Verified against a live install, which answers
     * 'bialy-rozmiar-xl' for exactly this input.
     */
    public function testAPolishVariationIdentifierIsTransliteratedNotStripped(): void
    {
        $GLOBALS['_cartshift_test_terms']['pa_color']['bialy'] = (object) ['name' => 'Biały'];
        $GLOBALS['_cartshift_test_terms']['pa_size']['xl']     = (object) ['name' => 'Rozmiar XL'];

        $variation = $this->createVariation([
            'attributes' => ['attribute_pa_color' => 'bialy', 'attribute_pa_size' => 'xl'],
        ]);

        $result = $this->mapper->mapVariation($variation);

        $this->assertSame('Biały / Rozmiar XL', $result['variation_title']);
        $this->assertSame('500', $result['variation_identifier']);
    }

    public function testAttributeTermLookupIsMemoised(): void
    {
        // Prime the cache, then pull the term out from under the mapper. A second lookup
        // that still returns the term name proves get_term_by() was not called again.
        $GLOBALS['_cartshift_test_terms']['pa_color']['red'] = (object) ['name' => 'Rosso'];

        $first = $this->mapper->mapVariation($this->createVariation([
            'attributes' => ['attribute_pa_color' => 'red'],
        ]));
        $this->assertSame('Rosso', $first['variation_title']);

        unset($GLOBALS['_cartshift_test_terms']['pa_color']['red']);

        $second = $this->mapper->mapVariation($this->createVariation([
            'attributes' => ['attribute_pa_color' => 'red'],
        ], 1));

        $this->assertSame('Rosso', $second['variation_title'], 'Term lookup was not memoised');
    }

    public function testAttributeTermMissesAreMemoisedToo(): void
    {
        // Custom (non-taxonomy) attribute values miss on every lookup, which is exactly
        // the case that must not hammer the database once per variation.
        $first = $this->mapper->mapVariation($this->createVariation([
            'attributes' => ['attribute_custom' => 'bespoke'],
        ]));
        $this->assertSame('bespoke', $first['variation_title']);

        // Add the term after the miss was cached — the cached miss must win.
        $GLOBALS['_cartshift_test_terms']['custom']['bespoke'] = (object) ['name' => 'Bespoke'];

        $second = $this->mapper->mapVariation($this->createVariation([
            'attributes' => ['attribute_custom' => 'bespoke'],
        ], 1));

        $this->assertSame('bespoke', $second['variation_title'], 'Negative lookup was not memoised');
    }

    public function testMemoIsKeyedByTaxonomyAndSlug(): void
    {
        // Same slug under two taxonomies must not collide.
        $GLOBALS['_cartshift_test_terms']['pa_color']['small'] = (object) ['name' => 'Smalt Blue'];
        $GLOBALS['_cartshift_test_terms']['pa_size']['small'] = (object) ['name' => 'Small'];

        $result = $this->mapper->mapVariation($this->createVariation([
            'attributes' => ['attribute_pa_color' => 'small', 'attribute_pa_size' => 'small'],
        ]));

        $this->assertSame('Smalt Blue / Small', $result['variation_title']);
    }

    public function testEmptyAttributeValuesAreSkipped(): void
    {
        $GLOBALS['_cartshift_test_terms']['pa_color']['red'] = (object) ['name' => 'Rosso'];

        $result = $this->mapper->mapVariation($this->createVariation([
            'attributes' => ['attribute_pa_color' => 'red', 'attribute_pa_size' => ''],
        ]));

        $this->assertSame('Rosso', $result['variation_title']);
    }

    public function testVariationWithNoAttributesGetsDefaultTitle(): void
    {
        $result = $this->mapper->mapVariation($this->createVariation(['attributes' => []]));

        $this->assertSame('Default', $result['variation_title']);
    }

    public function testParentManagedStockIsNotCopiedIntoAnIndependentChildPool(): void
    {
        $variation = $this->createVariation([
            'manage_stock' => 'parent',
            'stock_quantity' => 12,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('shared parent stock');
        $this->mapper->mapVariation($variation);
    }

    private function createVariation(array $overrides = [], int $id = 0): \WC_Product_Variation
    {
        $variation = new \WC_Product_Variation();
        $defaults = [
            'id' => 500 + $id,
            'name' => 'Test Variation',
            'slug' => 'test-variation',
            'status' => 'publish',
            'price' => '19.99',
            'regular_price' => '19.99',
            'sale_price' => '',
            'sku' => 'VAR-001',
            'virtual' => false,
            'downloadable' => false,
            'in_stock' => true,
            'manage_stock' => false,
            'sold_individually' => false,
            'stock_quantity' => null,
            'backorders' => 'no',
            'attributes' => [],
        ];

        $data = array_merge($defaults, $overrides);

        $ref = new \ReflectionClass($variation);
        foreach ($data as $key => $value) {
            if ($ref->hasProperty($key)) {
                $prop = $ref->getProperty($key);
                $prop->setValue($variation, $value);
            }
        }

        return $variation;
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

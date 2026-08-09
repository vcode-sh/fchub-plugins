<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Mapping;

use CartShift\Domain\Mapping\ProductMapper;
use CartShift\Tests\Unit\PluginTestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

require_once __DIR__ . '/../../../stubs/MapperStubs.php';

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
        // FluentCart stores every currency as amount x 100 (Helper::toCent() takes no
        // currency at all), so JPY 1000 becomes 100000 and renders back as "1,000".
        $this->assertSame(100000, $result['variations'][0]['item_price']);
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

    public function testPostDateIsSiteLocalAndPostDateGmtIsUtc(): void
    {
        // Site at UTC+2. The same instant must render 12:30 local / 10:30 UTC.
        $product = $this->createProduct([
            'date_created' => cartshift_test_wc_date('2024-01-15 10:30:00', 2),
        ]);

        $result = $this->mapper->map($product);

        $this->assertNotNull($result);
        $this->assertSame('2024-01-15 12:30:00', $result['product']['post_date']);
        $this->assertSame('2024-01-15 10:30:00', $result['product']['post_date_gmt']);
        $this->assertNotSame(
            $result['product']['post_date'],
            $result['product']['post_date_gmt'],
            'post_date and post_date_gmt must differ on a site with a non-zero UTC offset',
        );
    }

    public function testPostDateGmtHandlesNegativeOffset(): void
    {
        // Site at UTC-5. Local time falls on the previous day.
        $product = $this->createProduct([
            'date_created' => cartshift_test_wc_date('2024-01-15 03:00:00', -5),
        ]);

        $result = $this->mapper->map($product);

        $this->assertNotNull($result);
        $this->assertSame('2024-01-14 22:00:00', $result['product']['post_date']);
        $this->assertSame('2024-01-15 03:00:00', $result['product']['post_date_gmt']);
    }

    public function testPostDatesMatchOnUtcSite(): void
    {
        $product = $this->createProduct([
            'date_created' => cartshift_test_wc_date('2024-06-01 08:15:45', 0),
        ]);

        $result = $this->mapper->map($product);

        $this->assertNotNull($result);
        $this->assertSame('2024-06-01 08:15:45', $result['product']['post_date']);
        $this->assertSame('2024-06-01 08:15:45', $result['product']['post_date_gmt']);
    }

    public function testMissingDateCreatedFallsBackToCurrentTime(): void
    {
        $product = $this->createProduct(['date_created' => null]);

        $result = $this->mapper->map($product);

        $this->assertNotNull($result);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $result['product']['post_date'],
        );
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $result['product']['post_date_gmt'],
        );
    }

    public function testAttributeTermMemoSurvivesAcrossProducts(): void
    {
        // ProductMapper shares one VariationMapper for its whole lifetime, so the term memo
        // spans every product in a run — not just the variations of a single one.
        $GLOBALS['_cartshift_test_terms']['pa_color']['red'] = (object) ['name' => 'Rosso'];

        $first = $this->mapper->map($this->createVariableProduct(1, 11));
        $this->assertSame('Rosso', $first['variations'][0]['variation_title']);

        unset($GLOBALS['_cartshift_test_terms']['pa_color']['red']);

        $second = $this->mapper->map($this->createVariableProduct(2, 22));
        $this->assertSame(
            'Rosso',
            $second['variations'][0]['variation_title'],
            'Term memo did not survive between products',
        );

        unset($GLOBALS['_cartshift_test_terms']);
    }

    /**
     * A variable product with a single 'red' variation, registered with the wc_get_product stub.
     */
    private function createVariableProduct(int $productId, int $variationId): \WC_Product
    {
        $variation = new \WC_Product_Variation();
        $varRef = new \ReflectionClass($variation);
        foreach ([
            'id' => $variationId,
            'status' => 'publish',
            'price' => '19.99',
            'regular_price' => '19.99',
            'attributes' => ['attribute_pa_color' => 'red'],
        ] as $key => $value) {
            if ($varRef->hasProperty($key)) {
                $varRef->getProperty($key)->setValue($variation, $value);
            }
        }

        $GLOBALS['_cartshift_test_wc_products'][$variationId] = $variation;

        return $this->createProduct([
            'id' => $productId,
            'type' => 'variable',
            'children' => [$variationId],
        ]);
    }

    // ──────────────────────────────────────────────
    // P1 regression: variable-subscription must not collapse
    // ──────────────────────────────────────────────
    //
    // ProductTypes::supported() has advertised `variable-subscription` as
    // migratable ever since WooCommerce Subscriptions was detected, but map()
    // used to decide "does this product have children" with a bare
    // `$type === 'variable'` — a comparison variable-subscription never
    // passes. The product fell to the `else` branch, mapSimple() ran instead
    // of every child being walked, and every variation but a single
    // pseudo-one — along with whichever cadence it did not happen to keep —
    // vanished silently. Two variations with two different cadences is the
    // smallest fixture that catches both halves of that: the collapse to one
    // variation, and the loss of a distinct plan even if it hadn't collapsed.

    /**
     * Isolated because it is the one test in this file that needs
     * WC_Subscriptions_Product to exist, which the same stub's own docblock
     * warns must never be require_once'd into the shared process — doing so
     * would flip VariationMapper's `class_exists()` gate to true for every
     * later test in the run, including this file's own dimension/weight test
     * and VariationMapperTest's, both of which document that gate as false on
     * purpose.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAVariableSubscriptionKeepsBothVariationsWithTheirOwnCadence(): void
    {
        require_once dirname(__DIR__, 3) . '/stubs/WcSubscriptionsProductStub.php';

        $monthly = $this->createSubscriptionVariation(201, 'red', 'month', 1);
        $yearly  = $this->createSubscriptionVariation(202, 'blue', 'year', 1);

        $GLOBALS['_cartshift_test_wc_products'][201] = $monthly;
        $GLOBALS['_cartshift_test_wc_products'][202] = $yearly;

        $product = $this->createProduct([
            'id'       => 500,
            'type'     => 'variable-subscription',
            'children' => [201, 202],
        ]);

        $result = $this->mapper->map($product);

        $this->assertNotNull($result);
        $this->assertCount(
            2,
            $result['variations'],
            'A variable-subscription product must keep every one of its variations, not collapse to a '
            . 'single pseudo-variation.',
        );

        [$first, $second] = $result['variations'];

        $this->assertSame('subscription', $first['payment_type']);
        $this->assertSame('subscription', $second['payment_type']);
        $this->assertSame('monthly', $first['other_info']['repeat_interval']);
        $this->assertSame('yearly', $second['other_info']['repeat_interval']);
        $this->assertNotSame(
            $first['other_info']['repeat_interval'],
            $second['other_info']['repeat_interval'],
            'The two cadences this product sold must stay distinguishable rather than merge into one.',
        );

        // The detail row's variation_type is the other half of the same
        // decision — ProductMapper::map() also used to gate this on the bare
        // literal, so a collapsed product's detail row still called itself
        // 'simple' even on the rare run where a stray variation survived.
        $this->assertSame('advanced_variations', $result['detail']['variation_type']);
    }

    private function createSubscriptionVariation(
        int $id,
        string $colorSlug,
        string $period,
        int $interval,
    ): \WC_Product_Variation {
        $variation = new \WC_Product_Variation();
        $ref = new \ReflectionClass($variation);

        foreach ([
            'id' => $id,
            'status' => 'publish',
            'price' => '19.99',
            'regular_price' => '19.99',
            'attributes' => ['attribute_pa_color' => $colorSlug],
            'meta' => [
                '_subscription_period'          => $period,
                '_subscription_period_interval' => (string) $interval,
            ],
        ] as $key => $value) {
            if ($ref->hasProperty($key)) {
                $ref->getProperty($key)->setValue($variation, $value);
            }
        }

        return $variation;
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

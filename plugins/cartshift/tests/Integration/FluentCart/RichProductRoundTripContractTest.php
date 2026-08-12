<?php

declare(strict_types=1);

namespace CartShift\Tests\Integration\FluentCart;

use CartShift\Tests\Integration\Contract\InstalledContractTestCase;

require_once dirname(__DIR__) . '/Contract/InstalledContractTestCase.php';

final class RichProductRoundTripContractTest extends InstalledContractTestCase
{
    public function testRichSimpleProductSurvivesInstalledTargetRoundTripWithoutLifecycleCalls(): void
    {
        $result = $this->runRuntimeContract('rich-product-roundtrip');

        self::assertSame([
            'title' => 'Rich round-trip Łapka',
            'content' => 'Exact long description with Unicode — persisted.',
            'excerpt' => 'Exact short description.',
            'status' => 'draft',
            'menu_order' => 17,
        ], $result['post']);
        self::assertSame([
            'fulfillment_type' => 'physical',
            'variation_type' => 'simple_variations',
            'min_price' => 0,
            'max_price' => 0,
            'catalog_visibility' => 'hidden',
            'featured' => true,
            'purchase_note' => 'Round-trip purchase note.',
            'source_product_sku' => 'RICH-ROUNDTRIP',
        ], $result['detail']);
        self::assertSame([
            'sku' => 'RICH-ROUNDTRIP',
            'item_price' => 0,
            'compare_price' => 1999,
            'manage_stock' => 1,
            'total_stock' => 4,
            'available' => 4,
            'sold_individually' => 1,
            'stock_status' => 'in-stock',
            'tax_class' => 'standard',
            'tax_exempt' => 'yes',
            'tax_inclusion' => 'excluded',
            'weight' => '1.75',
            'length' => '21.5',
            'width' => '14.5',
            'height' => '2.5',
            'weight_unit' => 'kg',
            'dimension_unit' => 'cm',
        ], $result['variation']);
        self::assertSame([[
            'taxonomy' => 'product-categories',
            'name' => 'Round-trip category',
            'slug' => 'round-trip-category',
            'description' => 'Exact category description.',
            'parent' => 0,
            'term_order' => 0,
        ]], $result['taxonomies']);
        self::assertTrue($result['retry_reused']);
        self::assertTrue($result['retry_byte_stable']);
        self::assertSame([], array_filter($result['side_effects']));
    }
}

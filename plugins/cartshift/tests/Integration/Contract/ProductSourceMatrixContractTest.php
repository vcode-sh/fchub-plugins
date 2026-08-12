<?php

declare(strict_types=1);

namespace CartShift\Tests\Integration\Contract;

require_once __DIR__ . '/InstalledContractTestCase.php';

final class ProductSourceMatrixContractTest extends InstalledContractTestCase
{
    public function testInstalledWooProductMatrixPreservesLiteralSourceSemanticsAndStopsOnLies(): void
    {
        $result = $this->runRuntimeContract('woo-product-source-matrix');

        self::assertTrue($result['census_contains_every_fixture']);
        self::assertTrue($result['missing_lookup_product_selected']);
        self::assertSame('product_hydration_failed', $result['hydration_failure']);

        self::assertSame('simple', $result['types']['simple']);
        self::assertSame('simple', $result['types']['digital']);
        self::assertSame('external', $result['types']['external']);
        self::assertSame('grouped', $result['types']['grouped']);
        self::assertSame('course', $result['types']['course']);
        self::assertSame('variable', $result['types']['stock_parent']);

        self::assertSame('publish', $result['statuses']['simple']);
        self::assertSame('private', $result['statuses']['blank']);
        self::assertSame('draft', $result['statuses']['draft']);
        self::assertSame('pending', $result['statuses']['pending']);
        self::assertSame('contract-review', $result['statuses']['custom_status']);
        self::assertSame('trash', $result['statuses']['trashed']);

        self::assertSame('Zero-price Unicode Łapka 🐕', $result['simple_name']);
        self::assertSame('Long description — exact source text.', $result['simple_description']);
        self::assertSame('Short description żółć.', $result['simple_short_description']);
        self::assertSame([
            'visibility' => 'hidden',
            'featured' => true,
            'menu_order' => 7,
            'purchase_note' => 'Exact purchase note.',
        ], $result['simple_catalogue']);
        self::assertSame([
            'weight' => '1.25',
            'length' => '12.5',
            'width' => '8.5',
            'height' => '3.5',
            'weight_unit' => 'kg',
            'dimension_unit' => 'cm',
        ], $result['simple_dimensions']);
        self::assertSame(['featured', 'gallery'], array_column($result['simple_media'], 'role'));
        self::assertSame([], array_filter(array_column($result['simple_media'], 'size'), static fn ($size): bool => $size <= 0));
        self::assertSame([], array_filter(
            array_column($result['simple_media'], 'hash'),
            static fn ($hash): bool => !is_string($hash) || preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1,
        ));

        self::assertSame(['effective' => 0, 'regular' => 1000, 'sale' => 0], $result['zero_price']);
        self::assertSame(['effective' => null, 'regular' => null, 'sale' => null], $result['blank_price']);
        self::assertSame('digital', $result['digital']['fulfilment']);
        self::assertSame(1, $result['digital']['download_count']);
        self::assertSame(2, $result['digital']['download_limit']);
        self::assertSame(14, $result['digital']['download_expiry_days']);
        self::assertSame($result['digital']['download_path_hash'], $result['digital']['download_hash']);

        self::assertSame(2000, $result['scheduled']['before']['effective']);
        self::assertSame(1200, $result['scheduled']['during']['effective']);
        self::assertSame(2000, $result['scheduled']['after']['effective']);
        foreach ($result['scheduled'] as $sale) {
            self::assertSame(2000, $sale['regular']);
            self::assertSame(1200, $sale['sale']);
            self::assertTrue($sale['starts']);
            self::assertTrue($sale['ends']);
        }

        self::assertSame(['exclusive' => false, 'inclusive' => true], [
            'exclusive' => $result['tax_settings']['exclusive'],
            'inclusive' => $result['tax_settings']['inclusive'],
        ]);
        self::assertSame(['parent', 'self'], $result['stock_modes']);
        self::assertSame([true, 'parent', false], $result['source_stock_values']);
        self::assertSame(11, $result['parent_stock_quantity']);
        self::assertSame('parent_stock_owner_unrepresentable', $result['parent_stock_block_reason']);
        self::assertSame('inherited', $result['variation_assets']['parent']['media'][0]['provenance']);
        self::assertSame('variation', $result['variation_assets']['parent']['media'][0]['role']);
        self::assertSame('own', $result['variation_assets']['self']['media'][0]['provenance']);
        self::assertSame('variation', $result['variation_assets']['self']['media'][0]['role']);
        self::assertSame(1, $result['variation_assets']['self']['downloads'][0]['limit']);
        self::assertSame(3, $result['variation_assets']['self']['downloads'][0]['expiry_days']);
        self::assertSame($result['digital']['download_hash'], $result['variation_assets']['self']['downloads'][0]['hash']);
        self::assertSame('unsupported_download_policy', $result['positive_day_expiry_reason']);

        foreach (['external', 'grouped', 'course'] as $type) {
            self::assertSame('blocked', $result['unsupported'][$type]['outcome']);
            self::assertSame('unsupported_product_type', $result['unsupported'][$type]['reason']);
            self::assertSame($type, $result['unsupported'][$type]['source_type']);
        }
    }
}

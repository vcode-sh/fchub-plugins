<?php

declare(strict_types=1);

namespace CartShift\Tests\Integration\FluentCart;

use CartShift\Tests\Integration\Contract\InstalledContractTestCase;

require_once dirname(__DIR__) . '/Contract/InstalledContractTestCase.php';

final class ProductVariationContractTest extends InstalledContractTestCase
{
    public function testTransactionalWriterIsDraftFirstAtomicIdempotentAndReconciled(): void
    {
        $result = $this->runRuntimeContract('transactional-product-writer');

        self::assertSame('draft', $result['post_status']);
        self::assertSame('simple_variations', $result['variation_type']);
        self::assertSame(2, $result['variation_count']);
        self::assertSame($result['expected_mapping_count'], $result['mapping_count']);
        self::assertGreaterThanOrEqual(3, $result['mapping_count']);
        self::assertSame(['reconciled'], $result['mapping_states']);
        self::assertSame($result['first_target_id'], $result['retry_target_id']);
        self::assertSame($result['first_fingerprint'], $result['retry_fingerprint']);
        self::assertTrue($result['retry_reused']);
        self::assertTrue($result['retry_database_unchanged']);
        self::assertSame('forced installed variation-map failure', $result['forced_failure']);
        self::assertTrue($result['forced_failure_left_no_rows']);
        self::assertTrue($result['draft_buy_section_rendered']);
        self::assertSame($result['draft_variation_ids'], $result['draft_cartable_variation_ids']);
        self::assertSame($result['draft_variation_ids'], $result['draft_checkout_object_ids']);
        self::assertSame('draft', $result['draft_restored_post_status']);
        self::assertSame(['draft'], $result['draft_restored_variation_statuses']);
        self::assertTrue($result['private_buy_section_rendered']);
        self::assertSame($result['private_variation_ids'], $result['private_cartable_variation_ids']);
        self::assertSame($result['private_variation_ids'], $result['private_checkout_object_ids']);
        self::assertSame('private', $result['private_restored_post_status']);
        self::assertSame(['draft'], $result['private_restored_variation_statuses']);
        self::assertSame('draft', $result['historical_post_status']);
        self::assertSame('draft', $result['historical_variation_status']);
        self::assertSame('out-of-stock', $result['historical_stock_status']);
        self::assertFalse($result['historical_buy_section_rendered']);
        self::assertSame([], $result['historical_cartable_variation_ids']);
        self::assertSame([], $result['historical_checkout_object_ids']);
        self::assertSame(
            [],
            array_filter($result['staging_side_effects']),
            wp_json_encode($result['staging_action_scheduler_traces'] ?? []),
        );
        self::assertSame([], array_filter($result['draft_side_effects']));
        self::assertSame([], array_filter($result['private_side_effects']));
        self::assertSame([], array_filter($result['historical_side_effects']));
    }

    public function testMigratedWooVariableProductRendersAndEachSourceVariationIsPurchasableOnce(): void
    {
        $result = $this->runRuntimeContract('variable-product-simple-variations');

        self::assertSame('simple_variations', $result['helper_constant']);
        self::assertSame('simple_variations', $result['variation_type']);
        self::assertSame(100, $result['identifier_column_length']);
        self::assertSame(30, $result['sku_column_length']);
        self::assertCount(2, $result['source_variation_ids']);
        self::assertSame(
            [],
            array_filter($result['source_variation_ids'], static fn (mixed $id): bool => !is_int($id)),
        );
        self::assertSame($result['source_variation_ids'], $result['mapped_source_variation_ids']);
        self::assertTrue($result['buy_section_rendered']);
        self::assertSame(2, $result['rendered_variant_count']);
        self::assertSame($result['source_variation_ids'], $result['cartable_source_variation_ids']);
        self::assertSame($result['target_variation_ids'], $result['checkout_object_ids']);
        self::assertSame(2, $result['target_variation_count']);
        self::assertFalse($result['has_attribute_config']);
        self::assertSame(0, $result['advanced_relation_count']);
        self::assertTrue($result['identifiers_unique_and_bounded']);
        self::assertTrue($result['bulk_insert_accepts_simple_variations']);
        self::assertTrue($result['bulk_insert_requires_titles']);
        self::assertTrue($result['bulk_update_accepts_simple_variations']);
        self::assertTrue($result['bulk_update_requires_titles']);
        self::assertTrue($result['woo_migrator_uses_source_variation_id']);
        self::assertSame('advanced_variations', $result['advanced_constant']);
        self::assertTrue($result['unconfigured_advanced_buy_section_hidden']);
    }

    public function testVariationMediaDownloadsAndParentStockSurviveTheInstalledWriterRoundTrip(): void
    {
        $result = $this->runRuntimeContract('asset-product-roundtrip');

        self::assertSame(4, $result['plan_media_count']);
        self::assertSame(3, $result['unique_media_source_count']);
        self::assertSame(2, $result['plan_download_count']);
        self::assertSame(4, $result['target_media_count']);
        self::assertSame(3, $result['target_attachment_count']);
        self::assertSame(['inherited', 'own'], $result['variation_media_provenance']);
        self::assertSame($result['source_variation_identities'], $result['variation_media_owners']);
        self::assertSame(['featured', 'gallery'], $result['product_media_roles']);
        self::assertSame([
            $result['source_product_identity'],
            $result['source_product_identity'],
        ], $result['product_media_owners']);
        self::assertSame(['own', 'own'], $result['product_media_provenance']);
        self::assertSame([
            'Asset size: Large' => [[
                'source_key' => 'asset-size',
                'label' => 'Asset size',
                'value' => 'Large',
                'value_label' => 'Large',
                'kind' => 'custom',
            ]],
            'Asset size: Small' => [[
                'source_key' => 'asset-size',
                'label' => 'Asset size',
                'value' => 'Small',
                'value_label' => 'Small',
                'kind' => 'custom',
            ]],
        ], $result['variation_attributes']);
        self::assertSame(['none', 'self'], $result['stock_ownership']);
        self::assertSame([3, null], $result['stock_quantities']);
        self::assertSame(2, $result['target_download_count']);
        self::assertSame($result['target_variation_ids'], $result['download_variation_ids']);
        self::assertSame([[
            'download_limit' => '2',
            'download_expiry' => '',
        ], [
            'download_limit' => '2',
            'download_expiry' => '',
        ]], $result['download_settings']);
        self::assertSame($result['source_download_hashes'], $result['target_download_hashes']);
        self::assertSame($result['first_media_ids'], $result['retry_media_ids']);
        self::assertSame($result['first_download_ids'], $result['retry_download_ids']);
        self::assertTrue($result['retry_reused']);
        self::assertTrue($result['retry_byte_stable']);
        self::assertSame([], array_filter($result['side_effects']));
    }
}
